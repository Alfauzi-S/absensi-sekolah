<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\Master;

/** Data master sederhana (ruangan, mapel, jam pelajaran, hari libur): satu halaman per jenis dengan form tambah/ubah. */
final class MasterController extends Controller
{
    private function type(string $key): array
    {
        return Master::type($key) ?? abort(404, 'Halaman tidak ditemukan');
    }

    public function index(string $type): void
    {
        $this->allow('master');
        $t = $this->type($type);
        $editing = query('ubah') !== '' ? Master::find($type, (int) query('ubah')) : null;
        $old = $_SESSION['master_old'][$type] ?? null;
        $this->render('admin/master/index', [
            'title' => t($t['plural']), 'active' => 'master-' . $type, 'type' => $type, 't' => $t,
            'items' => Master::all($type), 'editing' => $editing,
            'd' => $old ?? ($editing ? array_map(static fn ($v) => (string) ($v ?? ''), $editing) : array_fill_keys(array_keys($t['fields']), '')),
            'errors' => $_SESSION['master_errors'][$type] ?? [],
        ]);
        unset($_SESSION['master_errors'][$type], $_SESSION['master_old'][$type]);
    }

    private function input(array $t): array
    {
        $d = [];
        foreach ($t['fields'] as $f => $def) {
            $d[$f] = trim(input($f));
        }
        return $d;
    }

    private function back(string $type, array $errors, array $d, int $id = 0): never
    {
        $_SESSION['master_errors'][$type] = $errors;
        $_SESSION['master_old'][$type] = $d;
        flash('bad', 'Data belum disimpan', t('Periksa kolom yang ditandai.'));
        redirect('admin/master/' . $type, $id ? ['ubah' => $id] : []);
    }

    public function store(string $type): void
    {
        $user = $this->allow('master');
        $t = $this->type($type);
        $d = $this->input($t);
        $errors = Master::validate($type, $d);
        if ($errors) {
            $this->back($type, $errors, $d);
        }
        $id = Master::create($type, $d);
        AuditLog::add($user, 'master.create', null, $t['table'], $id, $d);
        flash('ok', t(':label ditambahkan', ['label' => t($t['label'])]), (string) $d[$t['title']]);
        redirect('admin/master/' . $type);
    }

    public function update(string $type, string $id): void
    {
        $user = $this->allow('master');
        $t = $this->type($type);
        $row = Master::find($type, (int) $id) ?? abort(404, 'Data tidak ditemukan');
        $d = $this->input($t);
        $errors = Master::validate($type, $d, (int) $row['id']);
        if ($errors) {
            $this->back($type, $errors, $d, (int) $row['id']);
        }
        Master::update($type, (int) $row['id'], $d);
        AuditLog::add($user, 'master.update', null, $t['table'], $row['id'], $d);
        flash('ok', t(':label diperbarui', ['label' => t($t['label'])]), (string) $d[$t['title']]);
        redirect('admin/master/' . $type);
    }

    public function destroy(string $type, string $id): void
    {
        $user = $this->allow('master');
        $t = $this->type($type);
        $row = Master::find($type, (int) $id) ?? abort(404, 'Data tidak ditemukan');
        $used = Master::usage($type, $row);
        if ($used > 0) {
            flash('bad', t(':label masih dipakai', ['label' => t($t['label'])]), t('Dipakai oleh :n jadwal. Ubah jadwal itu terlebih dahulu.', ['n' => $used]));
            redirect('admin/master/' . $type);
        }
        Master::delete($type, (int) $row['id']);
        AuditLog::add($user, 'master.delete', null, $t['table'], $row['id'], ['title' => (string) $row[$t['title']]]);
        flash('ok', t(':label dihapus', ['label' => t($t['label'])]), (string) $row[$t['title']]);
        redirect('admin/master/' . $type);
    }
}
