<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Setting;
use App\Services\Notifier;
use App\Services\Phone;
use App\Services\WaGateway;

/** Notifikasi WhatsApp (Super Admin): status antrean, coba ulang yang gagal, kirim sekarang, pesan uji. */
final class NotificationController extends Controller
{
    public function index(): void
    {
        $this->allow('settings');
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $from = preg_match($re, query('dari')) ? query('dari') : date('Y-m-d', strtotime(today() . ' -6 days'));
        $to = preg_match($re, query('sampai')) ? query('sampai') : today();
        $status = query('st');
        $kind = query('jenis');
        $q = mb_substr(query('q'), 0, 60);
        $total = Notification::count($from, $to, $status, $kind, $q);
        $per = 50;
        $page = $this->page();
        $this->render('admin/notifications', [
            'title' => t('Notifikasi WhatsApp'), 'active' => 'notifications',
            'from' => $from, 'to' => $to, 'status' => $status, 'kind' => $kind, 'q' => $q,
            'counts' => Notification::counts($from, $to), 'rows' => Notification::search($from, $to, $status, $kind, $q, $per, ($page - 1) * $per),
            'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)),
            'mode' => Setting::get('wa_notify'), 'provider' => Setting::get('wa_provider', 'sandbox'),
        ]);
    }

    public function send(): void
    {
        $user = $this->allow('settings');
        $r = Notifier::run();
        AuditLog::add($user, 'notification.send', null, null, null, $r);
        flash('ok', 'Antrean diproses', t(':s terkirim, :r dijadwalkan ulang, :f gagal, :k dilewati, :q pesan Alpa baru diantrekan.',
            ['s' => $r['sent'], 'r' => $r['retry'], 'f' => $r['failed'], 'k' => $r['skipped'], 'q' => $r['queued']]));
        redirect('admin/notifikasi');
    }

    public function retry(): void
    {
        $user = $this->allow('settings');
        $id = (int) input('id');
        $n = Notification::retryFailed($id > 0 ? $id : null);
        AuditLog::add($user, 'notification.retry', null, 'notification', $id ?: null, ['rows' => $n]);
        flash('ok', 'Dikembalikan ke antrean', t(':n pesan gagal akan dikirim ulang pada putaran berikutnya.', ['n' => $n]));
        redirect('admin/notifikasi', ['st' => 'pending']);
    }

    public function test(): void
    {
        $user = $this->allow('settings');
        $phone = Phone::normalize(input('phone'));
        if (!$phone) {
            flash('bad', 'Nomor tidak valid', t('Tulis nomor WhatsApp, misalnya 0812xxxxxxx.'));
            redirect('admin/notifikasi');
        }
        $school = Setting::get('school_name') ?: (string) config('app_name', 'Absensi Sekolah');
        $r = WaGateway::send($phone, t('Pesan uji dari :s. Bila pesan ini sampai, notifikasi WhatsApp absensi sudah tersambung.', ['s' => $school]));
        AuditLog::add($user, 'notification.test', null, null, null, ['provider' => Setting::get('wa_provider', 'sandbox'), 'ok' => $r['ok'], 'error' => $r['error']], $r['ok'] ? 'ok' : 'fail');
        if ($r['ok']) {
            flash('ok', 'Pesan uji terkirim', Setting::get('wa_provider', 'sandbox') === 'sandbox'
                ? t('Mode sandbox: pesan tidak benar-benar dikirim, hanya dicatat di storage/logs/wa-sandbox.log.') : t('Periksa WhatsApp nomor :p.', ['p' => Phone::mask($phone)]));
        } else {
            flash('bad', 'Pesan uji gagal', (string) $r['error']);
        }
        redirect('admin/notifikasi');
    }
}
