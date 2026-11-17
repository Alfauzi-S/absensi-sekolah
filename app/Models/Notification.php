<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/** Antrean pesan WhatsApp ke orang tua: paling banyak satu baris (satu pesan) per siswa per hari. */
final class Notification
{
    public const STATUSES = ['pending' => 'Menunggu', 'sending' => 'Sedang dikirim', 'sent' => 'Terkirim', 'failed' => 'Gagal', 'skipped' => 'Dilewati'];
    public const KINDS = ['A' => 'Alpa', 'T' => 'Terlambat'];

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM notifications WHERE id = ?', [$id]);
    }

    public static function forStudentDate(int $studentId, string $date): ?array
    {
        return DB::one('SELECT * FROM notifications WHERE student_id = ? AND notify_date = ?', [$studentId, $date]);
    }

    /** Sisipkan; null bila siswa itu sudah punya baris pada tanggal tersebut (UNIQUE student_id + notify_date). */
    public static function tryInsert(array $d): ?int
    {
        try {
            return DB::insert('notifications', $d + ['attempts' => 0, 'created_at' => now()]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
    }

    /** @return array<string,int> jumlah per status pada rentang tanggal */
    public static function counts(string $from, string $to): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (DB::all('SELECT status, COUNT(*) AS n FROM notifications WHERE notify_date BETWEEN ? AND ? GROUP BY status', [$from, $to]) as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    public static function search(string $from, string $to, string $status, string $kind, string $q, int $limit = 200, int $offset = 0): array
    {
        [$w, $p] = self::where($from, $to, $status, $kind, $q);
        return DB::all("SELECT n.*, s.full_name, s.nis, c.name AS class_name FROM notifications n JOIN students s ON s.id = n.student_id
            LEFT JOIN class_members m ON m.student_id = s.id AND m.academic_year_id = (SELECT id FROM academic_years WHERE is_active = 1 LIMIT 1)
            LEFT JOIN classes c ON c.id = m.class_id
            WHERE $w ORDER BY n.notify_date DESC, n.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset, $p);
    }

    public static function count(string $from, string $to, string $status, string $kind, string $q): int
    {
        [$w, $p] = self::where($from, $to, $status, $kind, $q);
        return (int) DB::value("SELECT COUNT(*) FROM notifications n JOIN students s ON s.id = n.student_id WHERE $w", $p);
    }

    /** Gagal → dicoba lagi dari awal. @return int jumlah baris */
    public static function retryFailed(?int $id = null): int
    {
        $sql = "UPDATE notifications SET status = 'pending', attempts = 0, next_attempt_at = NULL WHERE status = 'failed'";
        $p = [];
        if ($id !== null) {
            $sql .= ' AND id = ?';
            $p[] = $id;
        }
        return DB::run($sql, $p)->rowCount();
    }

    /** @return array{0:string,1:array} */
    private static function where(string $from, string $to, string $status, string $kind, string $q): array
    {
        $w = ['n.notify_date BETWEEN ? AND ?'];
        $p = [$from, $to];
        if (isset(self::STATUSES[$status])) {
            $w[] = 'n.status = ?';
            $p[] = $status;
        }
        if (isset(self::KINDS[$kind])) {
            $w[] = 'n.kind = ?';
            $p[] = $kind;
        }
        if ($q !== '') {
            $w[] = '(s.full_name LIKE ? OR s.nis LIKE ?)';
            $p[] = '%' . $q . '%';
            $p[] = '%' . $q . '%';
        }
        return [implode(' AND ', $w), $p];
    }
}
