<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

/** Audit log tindakan penting. Tidak pernah menyimpan password, token, atau rahasia. */
final class AuditLog
{
    public const LABELS = [
        'access.denied' => 'Akses ditolak',
        'auth.login' => 'Login berhasil',
        'auth.login_failed' => 'Login gagal',
        'account.create' => 'Akun dibuat',
        'account.update' => 'Akun diubah',
        'account.status' => 'Status akun diubah',
        'account.reset_password' => 'Password sementara dibuat ulang',
        'settings.update' => 'Pengaturan sekolah diubah',
        'card.issue' => 'Kartu QR diterbitkan',
        'card.issue_bulk' => 'Kartu QR diterbitkan massal',
        'card.revoke' => 'Kartu QR dicabut',
        'card.print' => 'Kartu QR dicetak (PDF)',
        'student.photo' => 'Foto siswa diubah',
        'year.create' => 'Tahun ajaran dibuat',
        'year.update' => 'Tahun ajaran diubah',
        'year.activate' => 'Tahun ajaran diaktifkan',
        'year.delete' => 'Tahun ajaran dihapus',
        'semester.create' => 'Semester dibuat',
        'semester.activate' => 'Semester diaktifkan',
        'semester.delete' => 'Semester dihapus',
        'class.create' => 'Kelas dibuat',
        'class.update' => 'Kelas diubah',
        'class.delete' => 'Kelas dihapus',
        'class.member_add' => 'Siswa dimasukkan ke kelas',
        'class.member_remove' => 'Siswa dikeluarkan dari kelas',
        'student.create' => 'Siswa ditambahkan',
        'student.update' => 'Data siswa diubah',
        'student.delete' => 'Siswa dihapus',
        'student.import' => 'Impor siswa',
        'master.create' => 'Data master ditambahkan',
        'master.update' => 'Data master diubah',
        'master.delete' => 'Data master dihapus',
        'schedule.create' => 'Jadwal ditambahkan',
        'schedule.update' => 'Jadwal diubah',
        'schedule.delete' => 'Jadwal dihapus',
        'schedule.import' => 'Impor jadwal',
        'device.create' => 'Perangkat scanner dibuat',
        'device.update' => 'Perangkat scanner diubah',
        'device.pair' => 'Perangkat scanner dipasangkan',
        'device.repair' => 'Kode pasangan perangkat dibuat ulang',
        'device.revoke' => 'Perangkat scanner dicabut',
        'device.delete' => 'Perangkat scanner dihapus',
        'session.create' => 'Sesi tambahan dibuat',
        'session.close' => 'Sesi ditutup',
        'session.cancel' => 'Sesi dibatalkan (jam kosong)',
        'session.reopen' => 'Sesi dibuka kembali',
        'attendance.manual' => 'Kehadiran dicatat manual',
        'system.migrate' => 'Migrasi database dijalankan',
        'notification.send' => 'Antrean WhatsApp diproses manual',
        'notification.retry' => 'Notifikasi gagal dikirim ulang',
        'notification.test' => 'Pesan uji WhatsApp',
        'portal.code' => 'Kode portal orang tua dibuat',
        'portal.code_bulk' => 'Kode portal orang tua dibuat massal',
        'portal.login' => 'Orang tua masuk portal',
        'portal.login_failed' => 'Orang tua gagal masuk portal',
        'portal.denied' => 'Portal: akses data anak lain ditolak',
    ];

    public const GROUPS = ['' => 'Semua', 'access.' => 'Akses ditolak', 'auth.' => 'Login', 'account.' => 'Akun', 'settings.' => 'Pengaturan',
        'student.' => 'Siswa', 'class.' => 'Kelas', 'schedule.' => 'Jadwal', 'master.' => 'Data master', 'year.' => 'Tahun ajaran', 'card.' => 'Kartu QR', 'device.' => 'Perangkat', 'session.' => 'Sesi', 'attendance.' => 'Kehadiran', 'notification.' => 'Notifikasi', 'portal.' => 'Portal orang tua'];

    public static function label(string $action): string
    {
        return t(self::LABELS[$action] ?? $action);
    }

    private const SECRET_KEYS = '/pass|token|secret|hash|csrf|cookie|session/i';

    public static function add(?array $user, string $action, ?int $classId = null, ?string $objectType = null, mixed $objectId = null, array $meta = [], string $result = 'ok'): void
    {
        if (!DB::tableExists('audit_logs')) {
            return;
        }
        try {
            DB::insert('audit_logs', [
                'user_id' => $user ? (int) $user['id'] : null,
                'username' => $user['username'] ?? null,
                'role' => $user['role'] ?? null,
                'class_id' => $classId,
                'action' => mb_substr($action, 0, 60),
                'object_type' => $objectType !== null ? mb_substr($objectType, 0, 40) : null,
                'object_id' => $objectId !== null ? mb_substr((string) $objectId, 0, 64) : null,
                'result' => mb_substr($result, 0, 20),
                'meta' => $meta ? json_encode(self::clean($meta), JSON_UNESCAPED_UNICODE) : null,
                'ip' => client_ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            error_log('audit log gagal: ' . $e->getMessage());
        }
    }

    private static function clean(array $meta): array
    {
        $out = [];
        foreach ($meta as $k => $v) {
            if (preg_match(self::SECRET_KEYS, (string) $k)) {
                continue;
            }
            $out[$k] = is_array($v) ? self::clean($v) : (is_string($v) ? mb_substr($v, 0, 500) : $v);
        }
        return $out;
    }

    private static function where(string $prefix, string $q): array
    {
        $w = ['1 = 1'];
        $p = [];
        if ($prefix !== '') {
            $w[] = 'a.action LIKE ?';
            $p[] = $prefix . '%';
        }
        if ($q !== '') {
            $w[] = '(a.username LIKE ? OR a.object_id LIKE ? OR a.meta LIKE ?)';
            array_push($p, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        return [implode(' AND ', $w), $p];
    }

    public static function list(string $prefix = '', string $q = '', int $limit = 50, int $offset = 0): array
    {
        [$w, $p] = self::where($prefix, $q);
        return DB::all('SELECT a.*, c.name AS class_name FROM audit_logs a LEFT JOIN classes c ON c.id = a.class_id WHERE ' . $w .
            ' ORDER BY a.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $p);
    }

    public static function count(string $prefix = '', string $q = ''): int
    {
        [$w, $p] = self::where($prefix, $q);
        return (int) DB::value('SELECT COUNT(*) FROM audit_logs a WHERE ' . $w, $p);
    }
}
