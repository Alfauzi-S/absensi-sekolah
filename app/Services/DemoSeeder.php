<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\User;

/**
 * Data demo sesuai skala PRD: 30 kelas × 36 siswa (1.080 siswa), 30 ruangan, 60 guru,
 * 12 mapel, 10 jam pelajaran, dan jadwal satu minggu penuh tanpa bentrok guru/ruangan.
 * Dipakai untuk mencoba aplikasi dan sebagai data uji beban.
 */
final class DemoSeeder
{
    public const CLASSES = 30;
    public const PER_CLASS = 36;
    public const TEACHERS = 60;

    private const SUBJECTS = [
        ['MTK', 'Matematika'], ['BIND', 'Bahasa Indonesia'], ['BING', 'Bahasa Inggris'], ['FIS', 'Fisika'],
        ['KIM', 'Kimia'], ['BIO', 'Biologi'], ['SEJ', 'Sejarah'], ['PAI', 'Pendidikan Agama'],
        ['PKN', 'PPKn'], ['PJOK', 'Penjasorkes'], ['INF', 'Informatika'], ['SBK', 'Seni Budaya'],
    ];

    private const FIRST = ['Adi', 'Aisyah', 'Bagas', 'Bunga', 'Citra', 'Dimas', 'Dewi', 'Eka', 'Fajar', 'Fitri', 'Gilang', 'Hana', 'Ilham', 'Intan', 'Joko',
        'Kirana', 'Lutfi', 'Maya', 'Nabila', 'Oka', 'Putri', 'Rafi', 'Rina', 'Salsa', 'Tegar', 'Umar', 'Vina', 'Wahyu', 'Yusuf', 'Zahra', 'Anggi', 'Bayu', 'Dinda', 'Farhan', 'Galih', 'Laras'];
    private const LAST = ['Pratama', 'Saputra', 'Santoso', 'Wijaya', 'Ramadhan', 'Lestari', 'Nugroho', 'Hidayat', 'Maulana', 'Permata', 'Kusuma', 'Anggraini',
        'Setiawan', 'Rahayu', 'Firmansyah', 'Puspita', 'Wibowo', 'Utami', 'Siregar', 'Nasution'];
    private const FEMALE = ['Aisyah', 'Bunga', 'Citra', 'Dewi', 'Fitri', 'Hana', 'Intan', 'Kirana', 'Maya', 'Nabila', 'Putri', 'Rina', 'Salsa', 'Vina', 'Zahra', 'Anggi', 'Dinda', 'Laras', 'Eka'];

    /** @return array{accounts: array<int, array{name:string,username:string,password:string,role:string}>} */
    public static function run(array $super): array
    {
        mt_srand(2026);
        $accounts = [];
        $year = (int) date('Y');
        $start = (int) date('n') >= 7 ? $year : $year - 1;
        $yearName = $start . '/' . ($start + 1);
        $yearId = (int) (DB::value('SELECT id FROM academic_years WHERE name = ?', [$yearName]) ?: AcademicYear::create([
            'name' => $yearName, 'start_date' => $start . '-07-13', 'end_date' => ($start + 1) . '-06-27',
        ]));
        if (!DB::value('SELECT id FROM semesters WHERE academic_year_id = ?', [$yearId])) {
            Semester::create($yearId, ['name' => 'Ganjil', 'start_date' => $start . '-07-13', 'end_date' => $start . '-12-20']);
            Semester::create($yearId, ['name' => 'Genap', 'start_date' => ($start + 1) . '-01-05', 'end_date' => ($start + 1) . '-06-27']);
        }
        AcademicYear::activate($yearId);
        // Semester aktif mengikuti bulan sekarang
        $sem = DB::one('SELECT id FROM semesters WHERE academic_year_id = ? AND name = ?', [$yearId, (int) date('n') >= 7 || (int) date('n') <= 0 ? 'Ganjil' : ((int) date('n') >= 1 && (int) date('n') <= 6 ? 'Genap' : 'Ganjil')]);
        Semester::activate((int) $sem['id']);
        $semesterId = (int) $sem['id'];

        Setting::setMany(['school_name' => 'SMA Negeri Contoh', 'headmaster_name' => 'Drs. Hadi Purnomo, M.Pd.']);

        DB::transaction(static function () use ($yearId, $semesterId, $super, &$accounts): void {
            // Jam pelajaran: 10 jam @45 menit, istirahat setelah jam 3 dan 6
            $t = 7 * 60;
            for ($n = 1; $n <= 10; $n++) {
                if (!DB::value('SELECT id FROM periods WHERE number = ?', [$n])) {
                    DB::insert('periods', ['number' => $n, 'start_time' => sprintf('%02d:%02d', intdiv($t, 60), $t % 60), 'end_time' => sprintf('%02d:%02d', intdiv($t + 45, 60), ($t + 45) % 60)]);
                }
                $t += 45 + (($n === 3 || $n === 6) ? 15 : 0);
            }
            // Mapel
            $subjectIds = [];
            foreach (self::SUBJECTS as [$code, $name]) {
                $subjectIds[] = (int) (DB::value('SELECT id FROM subjects WHERE code = ?', [$code]) ?: DB::insert('subjects', ['code' => $code, 'name' => $name, 'created_at' => now()]));
            }
            // Ruangan: satu per kelas
            $roomIds = [];
            for ($i = 1; $i <= self::CLASSES; $i++) {
                $code = sprintf('R-%02d', $i);
                $roomIds[] = (int) (DB::value('SELECT id FROM rooms WHERE code = ?', [$code]) ?: DB::insert('rooms', ['code' => $code, 'name' => 'Ruang ' . $i, 'created_at' => now()]));
            }
            // Guru: 60 akun, 5 guru per mapel
            $teacherIds = [];
            for ($i = 0; $i < self::TEACHERS; $i++) {
                $username = sprintf('guru%02d', $i + 1);
                $u = User::findByUsername($username);
                if ($u) {
                    $teacherIds[] = (int) $u['id'];
                    continue;
                }
                $name = self::FIRST[$i % count(self::FIRST)] . ' ' . self::LAST[($i * 7) % count(self::LAST)];
                $password = $i === 0 ? 'guru12345' : User::randomPassword(10);
                $id = User::create(['name' => $name, 'username' => $username, 'password' => $password, 'role' => 'guru',
                    'nip' => sprintf('19%02d%02d%02d20%02d%02d%03d', 70 + $i % 20, 1 + $i % 12, 1 + $i % 28, 5 + $i % 15, 1 + $i % 2, $i + 1), 'status' => 'Aktif']);
                $teacherIds[] = $id;
                if ($i === 0) {
                    $accounts[] = ['name' => $name, 'username' => $username, 'password' => $password, 'role' => 'Guru (wali kelas X-1)'];
                }
            }
            // Akun TU dan Kepsek
            foreach ([['tu', 'Tata Usaha', 'tu.sekolah', 'tu12345678', 'TU / Staff'], ['kepsek', 'Drs. Hadi Purnomo, M.Pd.', 'kepsek', 'kepsek12345', 'Kepala Sekolah']] as [$role, $name, $username, $password, $label]) {
                if (!User::findByUsername($username)) {
                    User::create(['name' => $name, 'username' => $username, 'password' => $password, 'role' => $role, 'status' => 'Aktif']);
                    $accounts[] = ['name' => $name, 'username' => $username, 'password' => $password, 'role' => $label];
                }
            }
            // Kelas: X-1..X-10, XI-1..XI-10, XII-1..XII-10 ; wali kelas = guru ke-i
            $classIds = [];
            $grades = [10 => 'X', 11 => 'XI', 12 => 'XII'];
            $c = 0;
            foreach ($grades as $grade => $label) {
                for ($k = 1; $k <= self::CLASSES / 3; $k++) {
                    $name = $label . '-' . $k;
                    $id = DB::value('SELECT id FROM classes WHERE academic_year_id = ? AND name = ?', [$yearId, $name]);
                    if (!$id) {
                        $id = DB::insert('classes', ['academic_year_id' => $yearId, 'name' => $name, 'grade' => $grade,
                            'homeroom_teacher_id' => $teacherIds[$c], 'room_id' => $roomIds[$c], 'created_at' => now()]);
                    }
                    $classIds[] = (int) $id;
                    $c++;
                }
            }
            // Siswa: 36 per kelas
            if ((int) DB::value('SELECT COUNT(*) FROM students') < self::CLASSES * self::PER_CLASS) {
                $n = 0;
                foreach ($classIds as $ci => $classId) {
                    $grade = 10 + intdiv($ci, 10);
                    for ($s = 0; $s < self::PER_CLASS; $s++) {
                        $n++;
                        $nis = (string) (240000 + $n + ($grade - 10) * 0);
                        $nis = sprintf('%d%04d', 26 - ($grade - 10), $n);
                        if (DB::value('SELECT id FROM students WHERE nis = ?', [$nis])) {
                            continue;
                        }
                        $first = self::FIRST[($n * 13 + $s) % count(self::FIRST)];
                        $last = self::LAST[($n * 11 + $ci) % count(self::LAST)];
                        $gender = in_array($first, self::FEMALE, true) ? 'P' : 'L';
                        $birthYear = 2026 - 15 - ($grade - 10);
                        $sid = DB::insert('students', [
                            'nis' => $nis, 'nisn' => sprintf('00%08d', 81000000 + $n), 'full_name' => $first . ' ' . $last, 'gender' => $gender,
                            'birth_date' => sprintf('%d-%02d-%02d', $birthYear, 1 + $n % 12, 1 + $n % 28), 'parent_name' => 'Orang tua ' . $last,
                            'parent_phone' => '08' . sprintf('%010d', 1200000000 + $n), 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        DB::insert('class_members', ['class_id' => $classId, 'student_id' => $sid, 'academic_year_id' => $yearId, 'created_at' => now()]);
                    }
                }
            }
            // Jadwal: Senin–Jumat, 4 blok @2 jam per hari (jam 1–8). Mapel s = (blok + kelas) % 12, guru = guru mapel ke-(kelas % 5)
            // → tidak pernah bentrok guru (lihat PRD/komentar) dan ruangan = ruang kelas sendiri.
            if (!DB::value('SELECT id FROM schedules WHERE semester_id = ? LIMIT 1', [$semesterId])) {
                foreach ($classIds as $ci => $classId) {
                    for ($day = 1; $day <= 5; $day++) {
                        for ($b = 0; $b < 4; $b++) {
                            $block = ($day - 1) * 4 + $b;
                            $s = ($block + $ci) % count($subjectIds);
                            $teacher = $teacherIds[$s * 5 + ($ci % 5)];
                            DB::insert('schedules', [
                                'semester_id' => $semesterId, 'class_id' => $classId, 'subject_id' => $subjectIds[$s], 'teacher_id' => $teacher,
                                'room_id' => $roomIds[$ci], 'day_of_week' => $day, 'period_start' => $b * 2 + 1, 'period_end' => $b * 2 + 2, 'created_at' => now(),
                            ]);
                        }
                    }
                }
            }
            // Hari libur contoh
            foreach ([[date('Y') . '-12-25', 'Hari Raya Natal'], [((int) date('Y') + 1) . '-01-01', 'Tahun Baru Masehi']] as [$d, $name]) {
                if (!DB::value('SELECT id FROM holidays WHERE holiday_date = ?', [$d])) {
                    DB::insert('holidays', ['holiday_date' => $d, 'name' => $name]);
                }
            }
        });
        Access::forget();
        return ['accounts' => $accounts];
    }
}
