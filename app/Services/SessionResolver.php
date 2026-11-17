<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Models\AcademicYear;
use App\Models\Master;
use App\Models\Semester;
use App\Models\Session;
use App\Models\Setting;

/**
 * Menentukan sesi absensi yang berlaku untuk sebuah ruangan pada suatu waktu (PRD Fase 3).
 *
 * - Mode "per_jam": jadwal pelajaran di ruangan itu (ruangan jadwal, atau ruang kelas bila jadwal tidak menyebut ruangan),
 *   hari ini, yang jam pelajarannya sedang berlangsung. Scan diterima sejak GRACE_BEFORE menit sebelum jam mulai.
 * - Mode "masuk_pulang": kelas yang menempati ruangan itu, pada jendela jam masuk / jam pulang dari pengaturan.
 * - Mode "keduanya": gabungan keduanya; satu scan dicatat ke semua sesi yang berlaku.
 * - Sesi tambahan (dibuat guru/TU) di ruangan itu ikut berlaku selama jendelanya.
 *
 * Hari libur dan akhir pekan: tidak ada sesi. Sesi yang dibatalkan (jam kosong) atau sudah ditutup dilewati.
 * specs() murni (tanpa menulis DB) agar mudah diuji; resolve() membuat sesi bila belum ada (aman dari balapan lewat slot_key).
 */
final class SessionResolver
{
    public const GRACE_BEFORE = 10; // menit sebelum jam mulai scan sudah diterima

    /** @return array<int, array> spesifikasi sesi yang berlaku (belum tentu ada di tabel sessions) */
    public static function specs(int $roomId, ?string $now = null): array
    {
        $now ??= now();
        $date = substr($now, 0, 10);
        if (Master::isHoliday($date) || in_array((int) date('N', strtotime($date)), Setting::weekendDays(), true)) {
            return [];
        }
        $mode = Setting::get('attendance_mode');
        $tolerance = max(0, Setting::int('late_tolerance_minutes'));
        $out = [];
        if ($mode === Setting::ATT_MODE_PERIOD || $mode === Setting::ATT_MODE_BOTH) {
            $out = array_merge($out, self::periodSpecs($roomId, $now, $tolerance));
        }
        if ($mode === Setting::ATT_MODE_DAILY || $mode === Setting::ATT_MODE_BOTH) {
            $out = array_merge($out, self::dailySpecs($roomId, $now, $tolerance));
        }
        return $out;
    }

    private static function periodSpecs(int $roomId, string $now, int $tolerance): array
    {
        $semester = Semester::active();
        if (!$semester) {
            return [];
        }
        $date = substr($now, 0, 10);
        $day = (int) date('N', strtotime($date));
        $periods = Master::periods();
        $rows = DB::all('SELECT h.*, c.name AS class_name FROM schedules h JOIN classes c ON c.id = h.class_id
            WHERE h.semester_id = ? AND h.day_of_week = ? AND (h.room_id = ? OR (h.room_id IS NULL AND c.room_id = ?)) ORDER BY h.period_start',
            [(int) $semester['id'], $day, $roomId, $roomId]);
        $current = [];
        $upcoming = [];
        foreach ($rows as $h) {
            $ps = $periods[(int) $h['period_start']] ?? null;
            $pe = $periods[(int) $h['period_end']] ?? $ps;
            if (!$ps || !$pe) {
                continue;
            }
            $starts = $date . ' ' . substr((string) $ps['start_time'], 0, 5) . ':00';
            $ends = $date . ' ' . substr((string) $pe['end_time'], 0, 5) . ':00';
            $scanFrom = self::addMinutes($starts, -self::GRACE_BEFORE);
            if ($now < $scanFrom || $now >= $ends) {
                continue;
            }
            $spec = [
                'slot_key' => 'j:' . $h['id'] . ':' . $date,
                'schedule_id' => (int) $h['id'],
                'semester_id' => (int) $semester['id'],
                'class_id' => (int) $h['class_id'],
                'room_id' => $roomId,
                'subject_id' => (int) $h['subject_id'],
                'teacher_id' => $h['teacher_id'] !== null ? (int) $h['teacher_id'] : null,
                'session_date' => $date,
                'period_start' => (int) $h['period_start'],
                'period_end' => (int) $h['period_end'],
                'scan_from' => $scanFrom,
                'starts_at' => $starts,
                'late_after' => self::addMinutes($starts, $tolerance),
                'ends_at' => $ends,
                'kind' => 'jadwal',
            ];
            if ($now >= $starts) {
                $current[] = $spec;
            } else {
                $upcoming[] = $spec;
            }
        }
        // Jam yang sedang berjalan didahulukan; jam berikutnya hanya bila tidak ada yang berjalan (masa tenggang sebelum mulai).
        return $current ?: array_slice($upcoming, 0, 1);
    }

    private static function dailySpecs(int $roomId, string $now, int $tolerance): array
    {
        $year = AcademicYear::active();
        if (!$year) {
            return [];
        }
        $date = substr($now, 0, 10);
        $classes = DB::all('SELECT id, name FROM classes WHERE academic_year_id = ? AND room_id = ? ORDER BY name', [(int) $year['id'], $roomId]);
        if (!$classes) {
            return [];
        }
        $t = static fn (string $key): string => $date . ' ' . substr(Setting::get($key), 0, 5) . ':00';
        $windows = [
            'harian_masuk' => ['scan_from' => $t('checkin_start'), 'starts_at' => $t('late_after'), 'late_after' => self::addMinutes($t('late_after'), $tolerance), 'ends_at' => $t('checkin_end')],
            'harian_pulang' => ['scan_from' => $t('checkout_start'), 'starts_at' => $t('checkout_start'), 'late_after' => $t('checkout_end'), 'ends_at' => $t('checkout_end')],
        ];
        $out = [];
        $semester = Semester::active();
        foreach ($windows as $kind => $w) {
            if ($now < $w['scan_from'] || $now >= $w['ends_at']) {
                continue;
            }
            foreach ($classes as $c) {
                $out[] = $w + [
                    'slot_key' => 'd:' . $kind . ':' . $c['id'] . ':' . $date,
                    'schedule_id' => null, 'semester_id' => $semester ? (int) $semester['id'] : null,
                    'class_id' => (int) $c['id'], 'room_id' => $roomId, 'subject_id' => null, 'teacher_id' => null,
                    'session_date' => $date, 'period_start' => null, 'period_end' => null, 'kind' => $kind,
                ];
            }
        }
        return $out;
    }

    /**
     * Sesi yang berlaku untuk ruangan (dibuat bila belum ada) + sesi tambahan yang sedang berjalan di ruangan itu.
     * @return array<int, array> baris sesi; yang pertama adalah sesi utama untuk tampilan kiosk
     */
    public static function resolve(int $roomId, ?string $now = null): array
    {
        $now ??= now();
        $out = [];
        foreach (self::specs($roomId, $now) as $spec) {
            $s = Session::createOrGet($spec);
            if ($s['status'] === 'open') {
                $out[] = $s;
            }
        }
        $extra = DB::all("SELECT id FROM sessions WHERE kind = 'tambahan' AND status = 'open' AND room_id = ? AND scan_from <= ? AND ends_at > ? ORDER BY starts_at",
            [$roomId, $now, $now]);
        foreach ($extra as $e) {
            $out[] = Session::find((int) $e['id']);
        }
        return $out;
    }

    public static function addMinutes(string $dt, int $minutes): string
    {
        return date('Y-m-d H:i:s', strtotime($dt) + $minutes * 60);
    }

    /** Teks singkat untuk kiosk / daftar: "Matematika · X-1 · Jam 1–2" atau "Masuk · X-1". */
    public static function label(array $s): string
    {
        $parts = [];
        if ($s['kind'] === 'jadwal') {
            $parts[] = $s['subject_name'] ?? t('Jam pelajaran');
        } elseif ($s['kind'] === 'tambahan') {
            $parts[] = $s['note'] ?: t('Sesi tambahan');
        } else {
            $parts[] = t(Session::KINDS[$s['kind']]);
        }
        $parts[] = $s['class_name'] ?? '';
        if ($s['period_start']) {
            $parts[] = (int) $s['period_start'] === (int) $s['period_end'] ? t('Jam ke-:n', ['n' => $s['period_start']]) : t('Jam :a–:b', ['a' => $s['period_start'], 'b' => $s['period_end']]);
        }
        return implode(' · ', array_filter($parts));
    }
}
