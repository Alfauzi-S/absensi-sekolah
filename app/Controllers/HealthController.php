<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;
use App\Services\Backup;
use App\Services\Health;

/** Kesehatan sistem dan cadangan (Super Admin), plus /healthz untuk pemantau uptime. */
final class HealthController extends Controller
{
    public function index(): void
    {
        $this->allow('settings');
        $checks = Health::checks();
        $this->render('admin/health', [
            'title' => t('Kesehatan Sistem'), 'active' => 'health', 'checks' => $checks, 'backups' => Backup::list(),
            'worst' => in_array('bad', array_column($checks, 'level'), true) ? 'bad' : (in_array('warn', array_column($checks, 'level'), true) ? 'warn' : 'ok'),
        ]);
    }

    /** GET /healthz → 200 {"ok":true,...} atau 503. Tidak memuat data pribadi. */
    public function healthz(): void
    {
        $s = Health::summary();
        json_out($s, $s['ok'] ? 200 : 503);
    }

    public function backup(): void
    {
        $user = $this->allow('settings');
        @set_time_limit(600);
        try {
            $r = Backup::create('manual');
            Backup::prune();
        } catch (\Throwable $e) {
            AuditLog::add($user, 'backup.create', null, null, null, ['error' => $e->getMessage()], 'fail');
            flash('bad', 'Cadangan gagal dibuat', $e->getMessage());
            redirect('admin/kesehatan');
        }
        AuditLog::add($user, 'backup.create', null, 'backup', basename($r['path']), ['rows' => $r['rows'], 'files' => $r['files']]);
        flash('ok', 'Cadangan dibuat', t(':f · :r baris, :n file, :s detik', ['f' => basename($r['path']), 'r' => $r['rows'], 'n' => $r['files'], 's' => $r['seconds']]));
        redirect('admin/kesehatan');
    }

    /** Unduh file cadangan (berisi seluruh data sekolah; hanya Super Admin, tercatat di audit log). */
    public function download(string $name): void
    {
        $user = $this->allow('settings');
        // Nama di alamat tanpa ".zip" (server PHP bawaan menganggap alamat berakhiran ekstensi sebagai file statis)
        $name = preg_replace('/\.zip$/', '', $name) . '.zip';
        $path = Backup::path($name) ?? abort(404, 'Cadangan tidak ditemukan');
        AuditLog::add($user, 'backup.download', null, 'backup', $name);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }
}
