<?php
use App\Core\View;
use App\Models\Notification;
use App\Models\Setting;
use App\Services\Phone;
$tone = ['pending' => 'bdg-info', 'sending' => 'bdg-navy', 'sent' => 'bdg-ok', 'failed' => 'bdg-bad', 'skipped' => 'bdg-mute'];
$qs = ['dari' => $from, 'sampai' => $to, 'st' => $status, 'jenis' => $kind, 'q' => $q];
?>
<div class="pagehead">
  <div><h2><?= e(t('Notifikasi WhatsApp')) ?></h2><p><?= e(t('Pesan ke orang tua saat anak Alpa atau Terlambat. Paling banyak satu pesan per siswa per hari; yang gagal dicoba ulang otomatis.')) ?></p></div>
  <div class="row wrap">
    <?= post_button('admin/notifikasi/kirim', t('Kirim sekarang'), [], 'btn btn-primary sm', '', 'send') ?>
    <?php if ($counts['failed'] > 0): ?><?= post_button('admin/notifikasi/coba-ulang', t('Coba ulang semua yang gagal'), [], 'btn btn-outline sm', t('Kembalikan semua pesan gagal ke antrean?'), 'refresh') ?><?php endif; ?>
  </div>
</div>
<?php if ($mode === 'off'): ?>
  <div class="banner warn"><?= icon('alert') ?><div class="grow"><?= e(t('Notifikasi WhatsApp sedang mati. Nyalakan di Pengaturan → Notifikasi WhatsApp.')) ?> <a href="<?= e(url('admin/pengaturan')) ?>#wa"><?= e(t('Buka pengaturan')) ?></a></div></div>
<?php elseif ($provider === 'sandbox'): ?>
  <div class="banner info"><?= icon('info') ?><div class="grow"><?= e(t('Mode sandbox: pesan tidak benar-benar dikirim, hanya dicatat. Pilih penyedia gateway di Pengaturan bila sudah siap.')) ?></div></div>
<?php endif; ?>
<div class="g-stats">
  <div class="stat"><span class="k"><span class="dot t-ok"><?= icon('check', 14) ?></span><?= e(t('Terkirim')) ?></span><span class="v"><?= (int) $counts['sent'] ?></span><span class="s"><?= e(date_id($from) . ' – ' . date_id($to)) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-info"><?= icon('clock', 14) ?></span><?= e(t('Menunggu')) ?></span><span class="v"><?= (int) $counts['pending'] + (int) $counts['sending'] ?></span><span class="s"><?= e(t('termasuk yang dijadwalkan ulang')) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-bad"><?= icon('x', 14) ?></span><?= e(t('Gagal')) ?></span><span class="v"><?= (int) $counts['failed'] ?></span><span class="s"><?= e(t('setelah :n kali percobaan', ['n' => \App\Services\Notifier::MAX_ATTEMPTS])) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-navy"><?= icon('eye', 14) ?></span><?= e(t('Dilewati')) ?></span><span class="v"><?= (int) $counts['skipped'] ?></span><span class="s"><?= e(t('nomor kosong atau status berubah')) ?></span></div>
</div>
<form class="filters" method="get" action="<?= e(url('admin/notifikasi')) ?>"><div class="frow">
  <select class="inp" name="st" onchange="this.form.submit()"><option value=""><?= e(t('Semua status')) ?></option><?php foreach (Notification::STATUSES as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($status, $k) ?>><?= e(t($v)) ?></option><?php endforeach; ?></select>
  <select class="inp" name="jenis" onchange="this.form.submit()"><option value=""><?= e(t('Alpa dan Terlambat')) ?></option><?php foreach (Notification::KINDS as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($kind, $k) ?>><?= e(t($v)) ?></option><?php endforeach; ?></select>
  <input class="inp" type="date" name="dari" value="<?= e($from) ?>" aria-label="<?= e(t('Dari')) ?>">
  <input class="inp" type="date" name="sampai" value="<?= e($to) ?>" aria-label="<?= e(t('Sampai')) ?>">
  <input class="inp" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Cari nama atau NIS')) ?>">
  <button class="btn btn-outline sm" type="submit"><?= icon('search', 16) ?><?= e(t('Cari')) ?></button>
</div></form>
<div class="g-2">
    <div class="card">
      <div class="card-h"><h3><?= e(t('Pengaturan saat ini')) ?></h3></div>
      <div class="kv">
        <div><span class="muted"><?= e(t('Kirim untuk')) ?></span><b><?= e(t(Setting::WA_MODES[$mode] ?? $mode)) ?></b></div>
        <div><span class="muted"><?= e(t('Penyedia')) ?></span><b><?= e(t(Setting::WA_PROVIDERS[$provider] ?? $provider)) ?></b></div>
      </div>
      <p class="muted small"><?= e(t('Pesan Alpa diantrekan setelah semua sesi kelas itu hari ini ditutup. Pesan Terlambat diantrekan saat scan. Sebelum dikirim status diperiksa ulang: bila sudah berubah (izin dicatat, koreksi), pesan dilewati.')) ?></p>
      <a class="btn btn-outline sm" href="<?= e(url('admin/pengaturan')) ?>#wa"><?= icon('sliders', 16) ?><?= e(t('Ubah pengaturan')) ?></a>
    </div>
    <div class="card">
      <div class="card-h"><h3><?= e(t('Kirim pesan uji')) ?></h3></div>
      <form method="post" action="<?= e(url('admin/notifikasi/uji')) ?>" class="stack"><?= csrf_field() ?>
        <div class="fld"><label for="phone"><?= e(t('Nomor WhatsApp')) ?></label><input class="inp" id="phone" name="phone" inputmode="tel" placeholder="0812xxxxxxx" required maxlength="20"></div>
        <button class="btn btn-outline sm" type="submit"><?= icon('send', 16) ?><?= e(t('Kirim uji')) ?></button>
      </form>
    </div>
</div>
  <div class="card">
    <?php if (!$rows): ?><p class="muted small"><?= e(t('Belum ada notifikasi untuk filter ini.')) ?></p>
    <?php else: ?><div class="tblw"><table class="tbl">
      <thead><tr><th><?= e(t('Tanggal')) ?></th><th><?= e(t('Siswa')) ?></th><th><?= e(t('Jenis')) ?></th><th><?= e(t('Nomor')) ?></th><th><?= e(t('Status')) ?></th><th class="r"></th></tr></thead>
      <tbody><?php foreach ($rows as $n): ?>
        <tr>
          <td class="mono small nowrap"><?= e(date_id($n['notify_date'])) ?></td>
          <td><a class="nm" href="<?= e(url('admin/rekap/siswa/' . $n['student_id'])) ?>"><?= e($n['full_name']) ?></a><div class="muted small mono"><?= e($n['nis'] . ($n['class_name'] ? ' · ' . $n['class_name'] : '')) ?></div></td>
          <td><?= status_badge($n['kind']) ?></td>
          <td class="mono small"><?= e(Phone::mask($n['phone'])) ?></td>
          <td><span class="bdg <?= $tone[$n['status']] ?? 'bdg-mute' ?>"><?= e(t(Notification::STATUSES[$n['status']] ?? $n['status'])) ?></span>
            <div class="muted small"><?= $n['sent_at'] ? e(t('Terkirim :t', ['t' => substr($n['sent_at'], 11, 5)])) : '' ?><?= (int) $n['attempts'] > 0 && $n['status'] !== 'sent' ? e(t(':n× dicoba', ['n' => $n['attempts']])) : '' ?><?= $n['status'] === 'pending' && $n['next_attempt_at'] ? ' · ' . e(t('lagi :t', ['t' => substr($n['next_attempt_at'], 11, 5)])) : '' ?></div>
            <?php if ($n['last_error'] && $n['status'] !== 'sent'): ?><div class="small" style="color:var(--bad-ink)"><?= e(t($n['last_error'])) ?></div><?php endif; ?></td>
          <td class="r"><details class="small"><summary><?= e(t('Pesan')) ?></summary><div style="max-width:320px;white-space:pre-wrap;text-align:left"><?= e($n['message']) ?></div></details>
            <?php if ($n['status'] === 'failed'): ?><?= post_button('admin/notifikasi/coba-ulang', t('Coba ulang'), ['id' => $n['id']], 'btn btn-ghost sm', '', 'refresh') ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
    <?= View::partial('pager', ['page' => $page, 'pages' => $pages, 'total' => $total, 'path' => 'admin/notifikasi', 'query' => $qs]) ?>
  </div>
