<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Impor siswa dari Excel/CSV. Kolom (judul bebas huruf besar/kecil):
 * NIS (wajib), Nama (wajib), L/P, Kelas, NISN, No HP Ortu.
 * NIS yang sudah ada diperbarui; kelas yang belum ada dibuat otomatis.
 */
class StudentImporter
{
    private const ALIASES = [
        'nis' => ['nis', 'no_induk', 'nomor_induk'],
        'name' => ['nama', 'nama_lengkap', 'nama_siswa', 'name', 'full_name'],
        'gender' => ['l_p', 'lp', 'jk', 'jenis_kelamin', 'gender'],
        'class' => ['kelas', 'class', 'rombel'],
        'nisn' => ['nisn'],
        'parent_phone' => ['no_hp_ortu', 'hp_ortu', 'no_hp_orang_tua', 'telepon_ortu', 'parent_phone', 'no_hp'],
    ];

    /** @return array{created:int, updated:int, errors:string[]} */
    public function import(string $path, string $originalName): array
    {
        $sheet = Spreadsheet::read($path, $originalName);
        $col = [];
        foreach (self::ALIASES as $field => $names) {
            foreach ($names as $n) {
                $idx = array_search($n, $sheet['headers'], true);
                if ($idx !== false) {
                    $col[$field] = $idx;
                    break;
                }
            }
        }
        if (! isset($col['nis'], $col['name'])) {
            throw new \RuntimeException('Kolom NIS dan Nama wajib ada di baris pertama.');
        }

        $created = $updated = 0;
        $errors = [];
        $classes = SchoolClass::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtoupper($name) => $id])->all();

        DB::transaction(function () use ($sheet, $col, &$created, &$updated, &$errors, &$classes) {
            foreach ($sheet['rows'] as $i => $r) {
                $line = $i + 2;
                $get = fn (string $f) => isset($col[$f]) ? trim((string) ($r[$col[$f]] ?? '')) : '';
                $nis = $get('nis');
                $name = $get('name');
                if ($nis === '' || $name === '') {
                    $errors[] = "Baris {$line}: NIS dan Nama wajib diisi.";

                    continue;
                }
                if (mb_strlen($nis) > 30 || mb_strlen($name) > 120) {
                    $errors[] = "Baris {$line}: NIS atau Nama terlalu panjang.";

                    continue;
                }
                $data = ['name' => $name];
                $g = strtoupper(mb_substr($get('gender'), 0, 1));
                if (in_array($g, ['L', 'P'], true)) {
                    $data['gender'] = $g;
                }
                if (($nisn = $get('nisn')) !== '') {
                    $data['nisn'] = mb_substr($nisn, 0, 30);
                }
                if (($phone = $get('parent_phone')) !== '') {
                    $data['parent_phone'] = mb_substr($phone, 0, 20);
                }
                if (($className = $get('class')) !== '') {
                    $key = mb_strtoupper($className);
                    if (! isset($classes[$key])) {
                        $grade = preg_match('/^(XII|XI|X|IX|VIII|VII|\d+)/i', $className, $m) ? self::grade($m[1]) : 10;
                        $classes[$key] = SchoolClass::create(['name' => mb_substr($className, 0, 30), 'grade' => $grade])->id;
                    }
                    $data['class_id'] = $classes[$key];
                }

                $student = Student::where('nis', $nis)->first();
                if ($student) {
                    $student->update($data);
                    $updated++;
                } else {
                    Student::create($data + ['nis' => $nis, 'status' => 'aktif']);
                    $created++;
                }
            }
        });

        return compact('created', 'updated', 'errors');
    }

    private static function grade(string $g): int
    {
        $roman = ['VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10, 'XI' => 11, 'XII' => 12];

        return $roman[strtoupper($g)] ?? max(1, min(12, (int) $g));
    }

    public static function template(): Xlsx
    {
        $x = new Xlsx;
        $x->sheet('Siswa', [
            [['v' => 'NIS', 's' => 'th'], ['v' => 'Nama', 's' => 'th'], ['v' => 'L/P', 's' => 'th'], ['v' => 'Kelas', 's' => 'th'], ['v' => 'NISN', 's' => 'th'], ['v' => 'No HP Ortu', 's' => 'th']],
            ['2026001', 'Ahmad Fauzi', 'L', 'X-1', '0091234567', '081234567890'],
            ['2026002', 'Siti Rahma', 'P', 'X-1', '', ''],
        ], ['widths' => [12, 30, 6, 10, 14, 16], 'landscape' => false]);

        return $x;
    }
}
