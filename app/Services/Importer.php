<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Models\AcademicYear;
use App\Models\Master;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;

/**
 * Impor massal dari file tabel. Baris yang valid disimpan, baris yang gagal dilaporkan per baris
 * (nomor baris + alasan) tanpa membatalkan baris lain.
 */
final class Importer
{
    public const STUDENT_COLUMNS = ['nis', 'nisn', 'nama', 'jk', 'kelas', 'tanggal_lahir', 'nama_ortu', 'hp_ortu'];
    public const SCHEDULE_COLUMNS = ['kelas', 'hari', 'jam_mulai', 'jam_selesai', 'mapel', 'nip_guru', 'ruangan'];

    private const DAYS = ['senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, "jum'at" => 5, 'sabtu' => 6, 'minggu' => 7, 'ahad' => 7];

    /**
     * @return array{created:int, updated:int, placed:int, failed:array<int,array{row:int,nis:string,error:string}>}
     */
    public static function students(array $sheet, int $yearId, bool $updateExisting = true): array
    {
        $headers = $sheet['headers'];
        $missing = array_diff(['nis', 'nama', 'jk'], $headers);
        if ($missing) {
            throw new \RuntimeException(t('Kolom wajib tidak ada: :cols. Unduh template agar judul kolom sesuai.', ['cols' => implode(', ', $missing)]));
        }
        $classes = [];
        foreach (SchoolClass::forYear($yearId) as $c) {
            $classes[mb_strtolower($c['name'])] = (int) $c['id'];
        }
        $res = ['created' => 0, 'updated' => 0, 'placed' => 0, 'failed' => []];
        $seen = [];
        foreach ($sheet['rows'] as $i => $raw) {
            $line = $i + 2;
            $r = Spreadsheet::assoc($headers, $raw);
            $d = [
                'nis' => $r['nis'] ?? '',
                'nisn' => $r['nisn'] ?? '',
                'full_name' => $r['nama'] ?? '',
                'gender' => strtoupper(substr($r['jk'] ?? '', 0, 1)),
                'birth_date' => self::date($r['tanggal_lahir'] ?? ''),
                'parent_name' => $r['nama_ortu'] ?? '',
                'parent_phone' => ($r['hp_ortu'] ?? '') !== '' ? Validator::normalizePhone($r['hp_ortu']) : '',
                'status' => 'Aktif',
            ];
            if (isset($seen[$d['nis']])) {
                $res['failed'][] = ['row' => $line, 'nis' => $d['nis'], 'error' => t('NIS ganda di dalam file (baris :n).', ['n' => $seen[$d['nis']]])];
                continue;
            }
            $existing = $d['nis'] !== '' ? Student::findByNis($d['nis']) : null;
            $errors = Validator::student($d, $existing ? (int) $existing['id'] : 0);
            $className = trim($r['kelas'] ?? '');
            $classId = null;
            if ($className !== '') {
                $classId = $classes[mb_strtolower($className)] ?? null;
                if ($classId === null) {
                    $errors['kelas'] = t('Kelas ":name" tidak ada di tahun ajaran aktif.', ['name' => $className]);
                }
            }
            if ($errors) {
                $res['failed'][] = ['row' => $line, 'nis' => $d['nis'], 'error' => implode(' ', $errors)];
                continue;
            }
            $seen[$d['nis']] = $line;
            try {
                DB::transaction(static function () use ($existing, $d, $classId, $yearId, $updateExisting, &$res): void {
                    if ($existing) {
                        if ($updateExisting) {
                            $d['status'] = $existing['status'];
                            Student::update((int) $existing['id'], $d);
                            $res['updated']++;
                        }
                        $sid = (int) $existing['id'];
                    } else {
                        $sid = Student::create($d);
                        $res['created']++;
                    }
                    if ($classId !== null) {
                        $state = SchoolClass::addMember($classId, $sid, $yearId);
                        if ($state !== 'exists') {
                            $res['placed']++;
                        }
                    }
                });
            } catch (\Throwable $e) {
                $res['failed'][] = ['row' => $line, 'nis' => $d['nis'], 'error' => t('Gagal disimpan: :msg', ['msg' => $e->getMessage()])];
            }
        }
        return $res;
    }

    /**
     * @return array{created:int, failed:array<int,array{row:int,nis:string,error:string}>}
     */
    public static function schedules(array $sheet, int $semesterId, int $yearId, bool $replaceClass = false): array
    {
        $headers = $sheet['headers'];
        $missing = array_diff(['kelas', 'hari', 'jam_mulai', 'jam_selesai', 'mapel'], $headers);
        if ($missing) {
            throw new \RuntimeException(t('Kolom wajib tidak ada: :cols. Unduh template agar judul kolom sesuai.', ['cols' => implode(', ', $missing)]));
        }
        $classes = [];
        foreach (SchoolClass::forYear($yearId) as $c) {
            $classes[mb_strtolower($c['name'])] = $c;
        }
        $subjects = [];
        foreach (Master::all('mapel') as $s) {
            $subjects[mb_strtolower($s['code'])] = (int) $s['id'];
            $subjects[mb_strtolower($s['name'])] = (int) $s['id'];
        }
        $rooms = [];
        foreach (Master::all('ruangan') as $rm) {
            $rooms[mb_strtolower($rm['code'])] = (int) $rm['id'];
            $rooms[mb_strtolower($rm['name'])] = (int) $rm['id'];
        }
        $periods = Master::periods();
        $res = ['created' => 0, 'failed' => []];
        $cleared = [];
        foreach ($sheet['rows'] as $i => $raw) {
            $line = $i + 2;
            $r = Spreadsheet::assoc($headers, $raw);
            $errors = [];
            $cls = $classes[mb_strtolower(trim($r['kelas'] ?? ''))] ?? null;
            if (!$cls) {
                $errors[] = t('Kelas ":name" tidak ada di tahun ajaran aktif.', ['name' => $r['kelas'] ?? '']);
            }
            $dayRaw = mb_strtolower(trim($r['hari'] ?? ''));
            $day = self::DAYS[$dayRaw] ?? (ctype_digit($dayRaw) ? (int) $dayRaw : 0);
            if ($day < 1 || $day > 7) {
                $errors[] = t('Hari ":d" tidak dikenal (Senin–Minggu).', ['d' => $r['hari'] ?? '']);
            }
            $subjectId = $subjects[mb_strtolower(trim($r['mapel'] ?? ''))] ?? null;
            if (!$subjectId) {
                $errors[] = t('Mata pelajaran ":s" tidak terdaftar.', ['s' => $r['mapel'] ?? '']);
            }
            $teacherId = null;
            if (($r['nip_guru'] ?? '') !== '') {
                $tch = User::findTeacherByNip(trim($r['nip_guru']));
                if (!$tch) {
                    $errors[] = t('Guru dengan NIP :nip tidak ditemukan.', ['nip' => $r['nip_guru']]);
                } else {
                    $teacherId = (int) $tch['id'];
                }
            }
            $roomId = null;
            if (($r['ruangan'] ?? '') !== '') {
                $roomId = $rooms[mb_strtolower(trim($r['ruangan']))] ?? null;
                if (!$roomId) {
                    $errors[] = t('Ruangan ":r" tidak terdaftar.', ['r' => $r['ruangan']]);
                }
            } elseif ($cls && $cls['room_id']) {
                $roomId = (int) $cls['room_id'];
            }
            $d = [
                'semester_id' => $semesterId,
                'class_id' => $cls ? (int) $cls['id'] : 0,
                'subject_id' => $subjectId ?? 0,
                'teacher_id' => $teacherId,
                'room_id' => $roomId,
                'day_of_week' => (string) $day,
                'period_start' => trim($r['jam_mulai'] ?? ''),
                'period_end' => trim($r['jam_selesai'] ?? ''),
            ];
            if (!$errors) {
                $errors = array_values(Validator::schedule($d, $periods));
            }
            if ($errors) {
                $res['failed'][] = ['row' => $line, 'nis' => $r['kelas'] ?? '', 'error' => implode(' ', $errors)];
                continue;
            }
            if ($replaceClass && !isset($cleared[$d['class_id']])) {
                Schedule::deleteForClass($semesterId, $d['class_id']);
                $cleared[$d['class_id']] = true;
            }
            $conf = Schedule::conflicts($d);
            if ($conf) {
                $labels = ['class' => 'kelas', 'teacher' => 'guru', 'room' => 'ruangan'];
                $parts = [];
                foreach ($conf as $k => $row) {
                    $parts[] = t('bentrok :what dengan :cls (:subj, jam :a–:b)', ['what' => t($labels[$k]), 'cls' => $row['class_name'], 'subj' => $row['subject_name'], 'a' => $row['period_start'], 'b' => $row['period_end']]);
                }
                $res['failed'][] = ['row' => $line, 'nis' => $r['kelas'] ?? '', 'error' => ucfirst(implode('; ', $parts)) . '.'];
                continue;
            }
            Schedule::create($d);
            $res['created']++;
        }
        return $res;
    }

    /** Terima 2026-01-31, 31/01/2026, 31-01-2026; selain itu dikembalikan apa adanya agar ditolak validator. */
    private static function date(string $v): string
    {
        $v = trim($v);
        if ($v === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $v, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (ctype_digit($v) && (int) $v > 20000 && (int) $v < 80000) { // serial tanggal Excel
            return date('Y-m-d', ((int) $v - 25569) * 86400);
        }
        return $v;
    }

    /** Baris contoh untuk template unduhan. */
    public static function studentTemplate(): array
    {
        return [
            self::STUDENT_COLUMNS,
            ['24001', '0081234567', 'Aisyah Putri', 'P', 'X-1', '2010-05-14', 'Budi Santoso', '081234567890'],
            ['24002', '0081234568', 'Bagas Pratama', 'L', 'X-1', '2010-08-02', 'Sri Lestari', '081298765432'],
        ];
    }

    public static function scheduleTemplate(): array
    {
        return [
            self::SCHEDULE_COLUMNS,
            ['X-1', 'Senin', '1', '2', 'MTK', '198001012005011001', 'R-01'],
            ['X-1', 'Senin', '3', '4', 'BIND', '', ''],
        ];
    }
}
