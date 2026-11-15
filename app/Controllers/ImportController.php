<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\Semester;
use App\Services\Exporter;
use App\Services\Importer;
use App\Services\Spreadsheet;

/** Impor massal siswa dan jadwal dari Excel/CSV, dengan laporan baris yang gagal. */
final class ImportController extends Controller
{
    private const MAX_MB = 5;

    public function index(): void
    {
        $this->allow('master');
        $this->needYear();
        $this->render('admin/import/index', [
            'title' => t('Impor Data'), 'active' => 'import',
            'result' => $_SESSION['import_result'] ?? null, 'semester' => Semester::active(),
            'studentColumns' => Importer::STUDENT_COLUMNS, 'scheduleColumns' => Importer::SCHEDULE_COLUMNS,
        ]);
        unset($_SESSION['import_result']);
    }

    /** @return array{headers:string[], rows:array}|null */
    private function sheet(): ?array
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('bad', 'Pilih file terlebih dahulu');
            return null;
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            flash('bad', 'Unggahan gagal', t('Kode kesalahan :code. Periksa ukuran file (maksimal :mb MB).', ['code' => $f['error'], 'mb' => self::MAX_MB]));
            return null;
        }
        if ($f['size'] > self::MAX_MB * 1024 * 1024) {
            flash('bad', 'File terlalu besar', t('Maksimal :mb MB.', ['mb' => self::MAX_MB]));
            return null;
        }
        try {
            return Spreadsheet::read($f['tmp_name'], (string) $f['name']);
        } catch (\RuntimeException $e) {
            flash('bad', 'File tidak dapat dibaca', $e->getMessage());
            return null;
        }
    }

    public function students(): void
    {
        $user = $this->allow('master');
        $year = $this->needYear();
        $sheet = $this->sheet();
        if ($sheet === null) {
            redirect('admin/impor');
        }
        try {
            $res = Importer::students($sheet, (int) $year['id'], input('mode', 'update') === 'update');
        } catch (\RuntimeException $e) {
            flash('bad', 'Impor dibatalkan', $e->getMessage());
            redirect('admin/impor');
        }
        AuditLog::add($user, 'student.import', null, 'students', null, ['created' => $res['created'], 'updated' => $res['updated'], 'placed' => $res['placed'], 'failed' => count($res['failed'])]);
        $_SESSION['import_result'] = ['kind' => 'siswa', 'file' => (string) $_FILES['file']['name'], 'rows' => count($sheet['rows'])] + $res;
        flash($res['failed'] ? 'warn' : 'ok', 'Impor siswa selesai', t(':c ditambahkan, :u diperbarui, :f gagal.', ['c' => $res['created'], 'u' => $res['updated'], 'f' => count($res['failed'])]));
        redirect('admin/impor');
    }

    public function schedules(): void
    {
        $user = $this->allow('master');
        $year = $this->needYear();
        $sem = Semester::active();
        if (!$sem) {
            flash('bad', 'Belum ada semester aktif');
            redirect('admin/tahun-ajaran');
        }
        $sheet = $this->sheet();
        if ($sheet === null) {
            redirect('admin/impor');
        }
        try {
            $res = Importer::schedules($sheet, (int) $sem['id'], (int) $year['id'], input('mode') === 'replace');
        } catch (\RuntimeException $e) {
            flash('bad', 'Impor dibatalkan', $e->getMessage());
            redirect('admin/impor');
        }
        \App\Services\Access::forget();
        AuditLog::add($user, 'schedule.import', null, 'schedules', null, ['created' => $res['created'], 'failed' => count($res['failed'])]);
        $_SESSION['import_result'] = ['kind' => 'jadwal', 'file' => (string) $_FILES['file']['name'], 'rows' => count($sheet['rows']), 'updated' => 0, 'placed' => 0] + $res;
        flash($res['failed'] ? 'warn' : 'ok', 'Impor jadwal selesai', t(':c jadwal ditambahkan, :f gagal.', ['c' => $res['created'], 'f' => count($res['failed'])]));
        redirect('admin/impor');
    }

    public function template(string $kind): void
    {
        $this->allow('master');
        $rows = $kind === 'jadwal' ? Importer::scheduleTemplate() : Importer::studentTemplate();
        $headers = array_shift($rows);
        $name = 'template-impor-' . ($kind === 'jadwal' ? 'jadwal' : 'siswa');
        if (query('format') === 'csv' || !Exporter::xlsxAvailable()) {
            Exporter::csv($name . '.csv', $headers, $rows);
        }
        Exporter::xlsx($name . '.xlsx', $headers, $rows, 'Template');
    }
}
