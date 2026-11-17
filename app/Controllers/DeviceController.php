<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Master;
use App\Models\ScanLog;

/** Perangkat scanner (Super Admin): buat, pasangkan, cabut. */
final class DeviceController extends Controller
{
    public function index(): void
    {
        $user = $this->allow('accounts');
        $pair = null;
        if (($pid = (int) query('kode')) > 0) {
            $d = Device::find($pid);
            if ($d && $d['status'] === 'pending' && $d['pair_code'] && $d['pair_expires_at'] > now()) {
                $pair = $d;
            }
        }
        $this->render('admin/devices/index', [
            'title' => t('Perangkat Scanner'), 'active' => 'devices',
            'devices' => Device::all(), 'rooms' => Master::all('ruangan'), 'pair' => $pair,
            'kioskUrl' => full_url('kiosk'), 'recent' => ScanLog::recent(12), 'stats' => ScanLog::serverStats(date('Y-m-d 00:00:00')),
            'errors' => $_SESSION['_errors'] ?? [], 'old' => $_SESSION['_old'] ?? [],
        ]);
        unset($_SESSION['_errors'], $_SESSION['_old']);
    }

    private function validate(): array
    {
        $name = mb_substr(input('name'), 0, 80);
        $roomId = (int) input('room_id');
        $e = [];
        if ($name === '') {
            $e['name'] = t('Nama perangkat wajib diisi.');
        }
        if ($roomId <= 0 || !Master::find('ruangan', $roomId)) {
            $e['room_id'] = t('Pilih ruangan tempat scanner dipasang.');
        }
        return [$name, $roomId, $e];
    }

    public function store(): void
    {
        $user = $this->allow('accounts');
        [$name, $roomId, $e] = $this->validate();
        if ($e) {
            $_SESSION['_errors'] = $e;
            $_SESSION['_old'] = $_POST;
            redirect('admin/perangkat');
        }
        $res = Device::create($name, $roomId, (int) $user['id']);
        AuditLog::add($user, 'device.create', null, 'device', $res['id'], ['name' => $name, 'room_id' => $roomId]);
        flash('ok', 'Perangkat dibuat', t('Masukkan kode pasangan di halaman kiosk dalam 15 menit.'));
        redirect('admin/perangkat', ['kode' => $res['id']]);
    }

    public function update(string $id): void
    {
        $user = $this->allow('accounts');
        $d = Device::find((int) $id) ?? abort(404, 'Perangkat tidak ditemukan');
        [$name, $roomId, $e] = $this->validate();
        if ($e) {
            flash('bad', 'Perangkat belum diubah', implode(' ', $e));
            redirect('admin/perangkat');
        }
        Device::update((int) $d['id'], $name, $roomId);
        AuditLog::add($user, 'device.update', null, 'device', $d['id'], ['name' => $name, 'room_id' => $roomId]);
        flash('ok', 'Perangkat diperbarui');
        redirect('admin/perangkat');
    }

    /** Kode pasangan baru (perangkat baru atau pasang ulang; token lama langsung tidak berlaku). */
    public function pairCode(string $id): void
    {
        $user = $this->allow('accounts');
        $d = Device::find((int) $id) ?? abort(404, 'Perangkat tidak ditemukan');
        Device::newPairCode((int) $d['id']);
        AuditLog::add($user, 'device.repair', null, 'device', $d['id'], ['name' => $d['name']]);
        redirect('admin/perangkat', ['kode' => $d['id']]);
    }

    public function revoke(string $id): void
    {
        $user = $this->allow('accounts');
        $d = Device::find((int) $id) ?? abort(404, 'Perangkat tidak ditemukan');
        Device::revoke((int) $d['id']);
        AuditLog::add($user, 'device.revoke', null, 'device', $d['id'], ['name' => $d['name']]);
        flash('ok', 'Perangkat dicabut', t('Kiosk itu tidak bisa lagi mengirim scan sampai dipasangkan ulang.'));
        redirect('admin/perangkat');
    }

    public function destroy(string $id): void
    {
        $user = $this->allow('accounts');
        $d = Device::find((int) $id) ?? abort(404, 'Perangkat tidak ditemukan');
        Device::delete((int) $d['id']);
        AuditLog::add($user, 'device.delete', null, 'device', $d['id'], ['name' => $d['name']]);
        flash('ok', 'Perangkat dihapus');
        redirect('admin/perangkat');
    }
}
