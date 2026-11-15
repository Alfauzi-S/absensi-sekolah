<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/**
 * Kartu QR siswa (PRD 4.2, Fase 2). Isi QR = kode acak 12 karakter, bukan NIS, agar tidak bisa ditebak.
 * Setiap penerbitan ulang menaikkan versi dan mencabut kartu lama, sehingga kartu hilang langsung tidak berlaku.
 */
final class Card
{
    /** 32 karakter tanpa huruf/angka yang mirip (0/O, 1/I/L) agar mudah dibaca dan diketik ulang bila perlu. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    public const LENGTH = 12;

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }
        return $code;
    }

    /** Kode yang unik di database (ulangi bila kebetulan tabrakan; peluangnya ~1/10^18). */
    private static function uniqueCode(): string
    {
        do {
            $code = self::generate();
        } while (DB::value('SELECT id FROM cards WHERE code = ?', [$code]));
        return $code;
    }

    /** Normalisasi hasil scan/ketikan: huruf besar, buang spasi dan strip. */
    public static function normalize(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    }

    /** Tampilan kode untuk manusia: ABCD-EFGH-JKLM. */
    public static function pretty(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    public static function isValidFormat(string $code): bool
    {
        return (bool) preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $code);
    }

    public static function activeFor(int $studentId): ?array
    {
        return DB::one("SELECT * FROM cards WHERE student_id = ? AND status = 'active' ORDER BY version DESC LIMIT 1", [$studentId]);
    }

    public static function history(int $studentId): array
    {
        return DB::all('SELECT c.*, u.name AS issued_by_name FROM cards c LEFT JOIN users u ON u.id = c.issued_by WHERE c.student_id = ? ORDER BY c.version DESC', [$studentId]);
    }

    /** Kartu beserta siswanya berdasarkan kode; dipakai scanner (Fase 3). Kartu dicabut tetap dikembalikan dengan status-nya. */
    public static function findByCode(string $code): ?array
    {
        return DB::one('SELECT c.*, s.nis, s.full_name, s.status AS student_status FROM cards c JOIN students s ON s.id = c.student_id WHERE c.code = ?', [$code]);
    }

    /**
     * Terbitkan kartu baru untuk siswa: kartu aktif sebelumnya dicabut, versi naik.
     * @return array{id:int, code:string, version:int, replaced:bool}
     */
    public static function issue(int $studentId, ?int $userId, string $reason = ''): array
    {
        return DB::transaction(static function () use ($studentId, $userId, $reason): array {
            $prev = self::activeFor($studentId);
            $version = (int) DB::value('SELECT COALESCE(MAX(version), 0) FROM cards WHERE student_id = ?', [$studentId]) + 1;
            if ($prev) {
                DB::update('cards', ['status' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => mb_substr($reason !== '' ? $reason : 'Diganti kartu baru', 0, 120)], 'id = ?', [(int) $prev['id']]);
            }
            $code = self::uniqueCode();
            $id = DB::insert('cards', ['student_id' => $studentId, 'code' => $code, 'version' => $version, 'status' => 'active', 'issued_by' => $userId, 'issued_at' => now()]);
            return ['id' => $id, 'code' => $code, 'version' => $version, 'replaced' => (bool) $prev];
        });
    }

    /** Cabut kartu aktif tanpa menerbitkan pengganti (mis. siswa keluar). */
    public static function revoke(int $cardId, string $reason): void
    {
        DB::update('cards', ['status' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => mb_substr($reason, 0, 120)], "id = ? AND status = 'active'", [$cardId]);
    }

    public static function markPrinted(array $cardIds): void
    {
        foreach (array_chunk(array_map('intval', $cardIds), 500) as $chunk) {
            DB::run('UPDATE cards SET printed_at = ? WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', array_merge([now()], $chunk));
        }
    }

    /** Siswa aktif di kelas beserta kartu aktifnya (null bila belum punya). */
    public static function forClass(int $classId): array
    {
        return DB::all("SELECT s.id, s.nis, s.full_name, s.gender, s.photo_path, s.status, c.id AS card_id, c.code, c.version, c.issued_at, c.printed_at
            FROM class_members m JOIN students s ON s.id = m.student_id
            LEFT JOIN cards c ON c.student_id = s.id AND c.status = 'active'
            WHERE m.class_id = ? ORDER BY s.full_name ASC", [$classId]);
    }

    /** Ringkasan per kelas pada tahun ajaran: jumlah siswa, sudah punya kartu, belum dicetak. */
    public static function statsByClass(int $yearId, ?array $onlyClassIds = null): array
    {
        $w = '';
        $p = [$yearId];
        if ($onlyClassIds !== null) {
            if (!$onlyClassIds) {
                return [];
            }
            $w = ' AND k.id IN (' . implode(',', array_fill(0, count($onlyClassIds), '?')) . ')';
            $p = array_merge($p, array_map('intval', $onlyClassIds));
        }
        return DB::all("SELECT k.id, k.name, k.grade,
                (SELECT COUNT(*) FROM class_members m WHERE m.class_id = k.id) AS students,
                (SELECT COUNT(*) FROM class_members m JOIN cards c ON c.student_id = m.student_id AND c.status = 'active' WHERE m.class_id = k.id) AS with_card,
                (SELECT COUNT(*) FROM class_members m JOIN cards c ON c.student_id = m.student_id AND c.status = 'active' WHERE m.class_id = k.id AND c.printed_at IS NULL) AS unprinted
            FROM classes k WHERE k.academic_year_id = ?" . $w . ' ORDER BY k.grade ASC, LENGTH(k.name) ASC, k.name ASC', $p);
    }

    /** Id siswa aktif (status Aktif, punya kelas di tahun ajaran) yang belum punya kartu aktif. */
    public static function missingStudentIds(int $yearId, ?int $classId = null): array
    {
        $w = $classId ? ' AND m.class_id = ?' : '';
        $p = $classId ? [$yearId, $classId] : [$yearId];
        $rows = DB::all("SELECT s.id FROM students s JOIN class_members m ON m.student_id = s.id AND m.academic_year_id = ?
            WHERE s.status = 'Aktif' AND NOT EXISTS (SELECT 1 FROM cards c WHERE c.student_id = s.id AND c.status = 'active')" . $w . ' ORDER BY s.id', $p);
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    public static function totals(int $yearId): array
    {
        $students = (int) DB::value("SELECT COUNT(*) FROM students s JOIN class_members m ON m.student_id = s.id AND m.academic_year_id = ? WHERE s.status = 'Aktif'", [$yearId]);
        $with = (int) DB::value("SELECT COUNT(*) FROM students s JOIN class_members m ON m.student_id = s.id AND m.academic_year_id = ? WHERE s.status = 'Aktif' AND EXISTS (SELECT 1 FROM cards c WHERE c.student_id = s.id AND c.status = 'active')", [$yearId]);
        return ['students' => $students, 'with_card' => $with, 'missing' => $students - $with, 'revoked' => (int) DB::value("SELECT COUNT(*) FROM cards WHERE status = 'revoked'")];
    }
}
