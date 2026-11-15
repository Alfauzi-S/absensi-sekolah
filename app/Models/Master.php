<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/**
 * Data master sederhana yang bentuknya sama: ruangan, mata pelajaran, jam pelajaran, hari libur.
 * Setiap jenis punya tabel, kolom, dan aturan validasi sendiri (lihat TYPES).
 */
final class Master
{
    public const TYPES = [
        'ruangan' => [
            'table' => 'rooms', 'label' => 'Ruangan', 'plural' => 'Ruangan', 'order' => 'code ASC',
            'fields' => [
                'code' => ['label' => 'Kode', 'type' => 'text', 'max' => 20, 'required' => true, 'unique' => true, 'hint' => 'Contoh: R-01, LAB-1'],
                'name' => ['label' => 'Nama ruangan', 'type' => 'text', 'max' => 80, 'required' => true, 'hint' => 'Contoh: Ruang X-1, Lab Komputer 1'],
            ],
            'title' => 'name', 'timestamps' => true,
            'usage' => 'SELECT COUNT(*) FROM schedules WHERE room_id = ?',
        ],
        'mapel' => [
            'table' => 'subjects', 'label' => 'Mata pelajaran', 'plural' => 'Mata Pelajaran', 'order' => 'name ASC',
            'fields' => [
                'code' => ['label' => 'Kode', 'type' => 'text', 'max' => 20, 'required' => true, 'unique' => true, 'hint' => 'Contoh: MTK, BIND'],
                'name' => ['label' => 'Nama mata pelajaran', 'type' => 'text', 'max' => 80, 'required' => true],
            ],
            'title' => 'name', 'timestamps' => true,
            'usage' => 'SELECT COUNT(*) FROM schedules WHERE subject_id = ?',
        ],
        'jam' => [
            'table' => 'periods', 'label' => 'Jam pelajaran', 'plural' => 'Jam Pelajaran', 'order' => 'number ASC',
            'fields' => [
                'number' => ['label' => 'Jam ke-', 'type' => 'int', 'min' => 1, 'max' => 20, 'required' => true, 'unique' => true],
                'start_time' => ['label' => 'Mulai', 'type' => 'time', 'required' => true],
                'end_time' => ['label' => 'Selesai', 'type' => 'time', 'required' => true, 'after' => 'start_time'],
            ],
            'title' => 'number', 'timestamps' => false,
            'usage' => 'SELECT COUNT(*) FROM schedules WHERE period_start <= ? AND period_end >= ?',
            'usage_params' => 'number',
        ],
        'libur' => [
            'table' => 'holidays', 'label' => 'Hari libur', 'plural' => 'Hari Libur', 'order' => 'holiday_date ASC',
            'fields' => [
                'holiday_date' => ['label' => 'Tanggal', 'type' => 'date', 'required' => true, 'unique' => true],
                'name' => ['label' => 'Keterangan', 'type' => 'text', 'max' => 120, 'required' => true, 'hint' => 'Contoh: Hari Raya Idul Fitri'],
            ],
            'title' => 'name', 'timestamps' => false,
            'usage' => null,
        ],
    ];

    public static function type(string $key): ?array
    {
        return self::TYPES[$key] ?? null;
    }

    public static function all(string $key): array
    {
        $t = self::TYPES[$key];
        return DB::all('SELECT * FROM ' . $t['table'] . ' ORDER BY ' . $t['order']);
    }

    public static function find(string $key, int $id): ?array
    {
        return DB::one('SELECT * FROM ' . self::TYPES[$key]['table'] . ' WHERE id = ?', [$id]);
    }

    public static function create(string $key, array $d): int
    {
        $t = self::TYPES[$key];
        $row = array_intersect_key($d, $t['fields']);
        if ($t['timestamps']) {
            $row['created_at'] = now();
        }
        return DB::insert($t['table'], $row);
    }

    public static function update(string $key, int $id, array $d): void
    {
        $t = self::TYPES[$key];
        DB::update($t['table'], array_intersect_key($d, $t['fields']), 'id = ?', [$id]);
    }

    public static function delete(string $key, int $id): void
    {
        DB::run('DELETE FROM ' . self::TYPES[$key]['table'] . ' WHERE id = ?', [$id]);
    }

    public static function taken(string $key, string $field, string $value, int $exceptId = 0): bool
    {
        return (bool) DB::value('SELECT id FROM ' . self::TYPES[$key]['table'] . ' WHERE ' . $field . ' = ? AND id <> ?', [$value, $exceptId]);
    }

    /** Jumlah data lain yang memakai baris ini (menghalangi penghapusan). */
    public static function usage(string $key, array $row): int
    {
        $t = self::TYPES[$key];
        if (!$t['usage']) {
            return 0;
        }
        $param = isset($t['usage_params']) ? (int) $row[$t['usage_params']] : (int) $row['id'];
        $n = substr_count($t['usage'], '?');
        return (int) DB::value($t['usage'], array_fill(0, $n, $param));
    }

    /** Validasi generik berdasarkan definisi kolom. @return array<string,string> */
    public static function validate(string $key, array $d, int $exceptId = 0): array
    {
        $t = self::TYPES[$key];
        $e = [];
        foreach ($t['fields'] as $f => $def) {
            $v = trim((string) ($d[$f] ?? ''));
            if ($v === '') {
                if (!empty($def['required'])) {
                    $e[$f] = t(':field wajib diisi.', ['field' => t($def['label'])]);
                }
                continue;
            }
            switch ($def['type']) {
                case 'int':
                    if (!ctype_digit($v) || (int) $v < $def['min'] || (int) $v > $def['max']) {
                        $e[$f] = t('Isi angka :min–:max.', ['min' => $def['min'], 'max' => $def['max']]);
                    }
                    break;
                case 'time':
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                        $e[$f] = t('Format jam JJ:MM.');
                    } elseif (!empty($def['after']) && !isset($e[$def['after']]) && $v <= trim((string) ($d[$def['after']] ?? ''))) {
                        $e[$f] = t('Harus setelah :field.', ['field' => t($t['fields'][$def['after']]['label'])]);
                    }
                    break;
                case 'date':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) {
                        $e[$f] = t('Format tanggal tidak valid.');
                    }
                    break;
                default:
                    if (mb_strlen($v) > $def['max']) {
                        $e[$f] = t('Maksimal :n karakter.', ['n' => $def['max']]);
                    }
            }
            if (!isset($e[$f]) && !empty($def['unique']) && self::taken($key, $f, $v, $exceptId)) {
                $e[$f] = t(':field sudah dipakai.', ['field' => t($def['label'])]);
            }
        }
        return $e;
    }

    /** Daftar jam pelajaran: nomor => [mulai, selesai]. */
    public static function periods(): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM periods ORDER BY number ASC') as $p) {
            $out[(int) $p['number']] = $p;
        }
        return $out;
    }

    public static function isHoliday(string $date): bool
    {
        return (bool) DB::value('SELECT id FROM holidays WHERE holiday_date = ?', [$date]);
    }
}
