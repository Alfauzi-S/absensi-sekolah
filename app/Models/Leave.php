<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/** Izin (I), Sakit (S), Dispensasi (D) per rentang tanggal, dengan lampiran surat opsional. */
final class Leave
{
    public const KINDS = ['I' => 'Izin', 'S' => 'Sakit', 'D' => 'Dispensasi'];
    public const MAX_DAYS = 60;
    public const ATTACH_MB = 3;
    private const ATTACH_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    private const SELECT = 'SELECT l.*, st.full_name, st.nis, c.name AS class_name, u.name AS created_by_name, x.name AS cancelled_by_name
        FROM leaves l JOIN students st ON st.id = l.student_id LEFT JOIN classes c ON c.id = l.class_id
        LEFT JOIN users u ON u.id = l.created_by LEFT JOIN users x ON x.id = l.cancelled_by';

    public static function find(int $id): ?array
    {
        return DB::one(self::SELECT . ' WHERE l.id = ?', [$id]);
    }

    /** Izin aktif yang mencakup tanggal ini (yang terbaru menang bila tumpang tindih). */
    public static function activeFor(int $studentId, string $date): ?array
    {
        return DB::one("SELECT id, kind FROM leaves WHERE student_id = ? AND status = 'active' AND date_from <= ? AND date_to >= ? ORDER BY id DESC LIMIT 1",
            [$studentId, $date, $date]);
    }

    /** @return array<int, array{id:int, kind:string}> student_id => izin aktif pada tanggal itu, untuk satu kelas. */
    public static function activeForClass(int $classId, string $date): array
    {
        $out = [];
        foreach (DB::all("SELECT l.id, l.kind, l.student_id FROM leaves l JOIN class_members m ON m.student_id = l.student_id AND m.class_id = ?
            WHERE l.status = 'active' AND l.date_from <= ? AND l.date_to >= ? ORDER BY l.id ASC", [$classId, $date, $date]) as $r) {
            $out[(int) $r['student_id']] = ['id' => (int) $r['id'], 'kind' => $r['kind']];
        }
        return $out;
    }

    /** @param int[]|null $classIds null = semua kelas */
    public static function search(?array $classIds, int $classId, string $status, string $from, string $to, string $q, int $limit = 200): array
    {
        if ($classIds !== null && $classIds === []) {
            return [];
        }
        $w = ['1 = 1'];
        $p = [];
        if ($classIds !== null) {
            $w[] = 'l.class_id IN (' . implode(',', array_map('intval', $classIds)) . ')';
        }
        if ($classId) {
            $w[] = 'l.class_id = ?';
            $p[] = $classId;
        }
        if (in_array($status, ['active', 'cancelled'], true)) {
            $w[] = 'l.status = ?';
            $p[] = $status;
        }
        if ($from !== '') {
            $w[] = 'l.date_to >= ?';
            $p[] = $from;
        }
        if ($to !== '') {
            $w[] = 'l.date_from <= ?';
            $p[] = $to;
        }
        if ($q !== '') {
            $w[] = '(st.full_name LIKE ? OR st.nis LIKE ?)';
            $p[] = '%' . $q . '%';
            $p[] = $q . '%';
        }
        return DB::all(self::SELECT . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY l.date_from DESC, l.id DESC LIMIT ' . $limit, $p);
    }

    /** Izin/sakit satu siswa yang beririsan dengan rentang tanggal. */
    public static function forStudent(int $studentId, string $from, string $to): array
    {
        return DB::all(self::SELECT . ' WHERE l.student_id = ? AND l.date_to >= ? AND l.date_from <= ? ORDER BY l.date_from ASC', [$studentId, $from, $to]);
    }

    public static function create(array $d): int
    {
        return DB::insert('leaves', [
            'student_id' => (int) $d['student_id'], 'class_id' => $d['class_id'] ?? null, 'kind' => $d['kind'],
            'date_from' => $d['date_from'], 'date_to' => $d['date_to'], 'reason' => mb_substr(trim($d['reason']), 0, 255),
            'status' => 'active', 'created_by' => $d['created_by'] ?? null, 'created_at' => now(),
        ]);
    }

    public static function markCancelled(int $id, ?int $userId, string $reason): void
    {
        DB::update('leaves', ['status' => 'cancelled', 'cancelled_by' => $userId, 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$id]);
    }

    /** Validasi isian form. @return array<string,string> kesalahan per kolom */
    public static function validate(array $d): array
    {
        $err = [];
        if (!isset(self::KINDS[$d['kind'] ?? ''])) {
            $err['kind'] = t('Pilih jenis: Izin, Sakit, atau Dispensasi.');
        }
        $okDate = static fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
        if (!$okDate($d['date_from'] ?? null)) {
            $err['date_from'] = t('Tanggal mulai tidak valid.');
        }
        if (!$okDate($d['date_to'] ?? null)) {
            $err['date_to'] = t('Tanggal selesai tidak valid.');
        } elseif (!isset($err['date_from'])) {
            if ($d['date_to'] < $d['date_from']) {
                $err['date_to'] = t('Tanggal selesai tidak boleh sebelum tanggal mulai.');
            } elseif ((strtotime($d['date_to']) - strtotime($d['date_from'])) / 86400 + 1 > self::MAX_DAYS) {
                $err['date_to'] = t('Maksimal :n hari per surat.', ['n' => self::MAX_DAYS]);
            }
        }
        if (mb_strlen(trim((string) ($d['reason'] ?? ''))) < 3) {
            $err['reason'] = t('Tulis keterangan (minimal 3 huruf).');
        }
        return $err;
    }

    public static function attachDir(): string
    {
        $dir = STORAGE_PATH . '/leaves';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** Simpan lampiran (foto/PDF surat). null = sukses atau tidak ada file; string = pesan kesalahan. */
    public static function storeAttachment(int $id, ?array $file): ?string
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return t('Unggahan lampiran gagal (kode :c).', ['c' => $file['error']]);
        }
        if ($file['size'] > self::ATTACH_MB * 1024 * 1024) {
            return t('Lampiran maksimal :mb MB.', ['mb' => self::ATTACH_MB]);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!isset(self::ATTACH_TYPES[$mime])) {
            return t('Lampiran harus JPG, PNG, WebP, atau PDF.');
        }
        $name = $id . '-' . bin2hex(random_bytes(6)) . '.' . self::ATTACH_TYPES[$mime];
        if (!@move_uploaded_file($file['tmp_name'], self::attachDir() . '/' . $name) && !@rename($file['tmp_name'], self::attachDir() . '/' . $name)) {
            return t('Lampiran tidak bisa disimpan.');
        }
        DB::update('leaves', ['attachment' => $name], 'id = ?', [$id]);
        return null;
    }

    public static function attachmentPath(array $leave): ?string
    {
        $n = (string) ($leave['attachment'] ?? '');
        return $n !== '' && preg_match('/^[0-9]+-[0-9a-f]{12}\.(jpg|png|webp|pdf)$/', $n) && is_file(self::attachDir() . '/' . $n) ? self::attachDir() . '/' . $n : null;
    }
}
