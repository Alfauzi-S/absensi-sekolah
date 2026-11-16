<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Models\Session;
use App\Models\Setting;

/**
 * Roster lokal kiosk (PRD Fase 4): daftar kartu siswa yang dijadwalkan di ruangan perangkat hari ini + jendela sesinya,
 * sehingga kiosk bisa menampilkan tanda Hadir/Terlambat seketika tanpa menunggu server, juga saat jaringan putus.
 * Server tetap sumber kebenaran: setiap scan dikirim dan jawabannya mengoreksi tampilan bila berbeda.
 */
final class Roster
{
    /** @return array{date:string, sessions:array, students:array, classes:array<int,string>, version:string} */
    public static function forRoom(int $roomId, ?string $now = null): array
    {
        $now ??= now();
        $date = substr($now, 0, 10);
        $sessions = [];
        foreach (SessionResolver::daySpecs($roomId, $date) as $spec) {
            $sessions[$spec['slot_key']] = [
                'key' => $spec['slot_key'], 'id' => null, 'kind' => $spec['kind'], 'class_id' => (int) $spec['class_id'], 'class' => $spec['class_name'],
                'label' => SessionResolver::label($spec), 'scan_from' => $spec['scan_from'], 'starts' => $spec['starts_at'], 'late' => $spec['late_after'], 'ends' => $spec['ends_at'],
                'status' => 'open', 'present' => [],
            ];
        }
        // Sesi yang sudah ada di tabel: status (jam kosong/ditutup), id, dan siapa yang sudah tercatat; plus sesi tambahan di ruangan ini.
        $rows = DB::all("SELECT s.*, c.name AS class_name, sb.name AS subject_name FROM sessions s JOIN classes c ON c.id = s.class_id LEFT JOIN subjects sb ON sb.id = s.subject_id
            WHERE s.session_date = ? AND s.room_id = ?", [$date, $roomId]);
        $present = Session::presentIds(array_map(static fn ($r) => (int) $r['id'], $rows));
        foreach ($rows as $r) {
            $key = $r['slot_key'] ?? ('x:' . $r['id']);
            if (!isset($sessions[$key])) {
                if ($r['kind'] !== 'tambahan') {
                    continue;
                }
                $sessions[$key] = ['key' => $key, 'kind' => $r['kind'], 'class_id' => (int) $r['class_id'], 'class' => $r['class_name'], 'label' => SessionResolver::label($r),
                    'scan_from' => $r['scan_from'], 'starts' => $r['starts_at'], 'late' => $r['late_after'], 'ends' => $r['ends_at']];
            }
            $sessions[$key]['id'] = (int) $r['id'];
            $sessions[$key]['status'] = $r['status'];
            $sessions[$key]['present'] = $present[(int) $r['id']] ?? [];
        }
        // Urutan dipertahankan sama dengan SessionResolver::resolve (jadwal, harian, tambahan) agar keputusan lokal kiosk = server.
        $sessions = array_map(static function (array $x): array {
            $ms = static fn (string $dt): int => strtotime($dt) * 1000;
            return $x + ['from_ms' => $ms($x['scan_from']), 'starts_ms' => $ms($x['starts']), 'late_ms' => $ms($x['late']), 'ends_ms' => $ms($x['ends'])];
        }, array_values($sessions));

        $classIds = array_values(array_unique(array_column($sessions, 'class_id')));
        $students = [];
        $classes = [];
        if ($classIds) {
            $in = implode(',', array_map('intval', $classIds));
            foreach (DB::all("SELECT id, name FROM classes WHERE id IN ($in)") as $c) {
                $classes[(int) $c['id']] = $c['name'];
            }
            // Bentuk ringkas [kode, id, nama, kelas, punya foto] agar unduhan kecil (≈ 60 byte per siswa)
            foreach (DB::all("SELECT k.code, s.id, s.full_name, m.class_id, s.photo_path FROM class_members m
                JOIN students s ON s.id = m.student_id AND s.status = 'Aktif'
                JOIN cards k ON k.student_id = s.id AND k.status = 'active'
                WHERE m.class_id IN ($in) ORDER BY m.class_id, s.full_name") as $r) {
                $students[] = [$r['code'], (int) $r['id'], $r['full_name'], (int) $r['class_id'], $r['photo_path'] ? 1 : 0];
            }
        }
        $core = ['date' => $date, 'mode' => Setting::get('attendance_mode'), 'sessions' => $sessions, 'students' => $students, 'classes' => $classes];
        return $core + ['version' => substr(sha1(json_encode($core)), 0, 16)];
    }
}
