<?php
$tone = ['ok' => ['t-ok', 'check', 'bdg-ok', 'Baik'], 'warn' => ['t-warn', 'alert', 'bdg-warn', 'Perlu dicek'], 'bad' => ['t-bad', 'x', 'bdg-bad', 'Bermasalah']];
?>
<div class="pagehead">
  <div><h2><?= e(t('Kesehatan Sistem')) ?></h2><p><?= e(t('Pantau setiap pagi selama minggu pertama go-live: penjadwal, cadangan, perangkat scanner, dan kecepatan scan.')) ?></p></div>
  <div class="row wrap">
    <a class="btn btn-outline sm" href="<?= e(url('admin/kesehatan')) ?>"><?= icon('refresh', 16) ?><?= e(t('Periksa ulang')) ?></a>
    <?= post_button('admin/kesehatan/cadangan', t('Buat cadangan sekarang'), [], 'btn btn-primary sm', '', 'download') ?>
  </div>
</div>
<div class="banner <?= $worst === 'ok' ? 'ok' : ($worst === 'warn' ? 'warn' : 'bad') ?>" role="status"><?= icon($tone[$worst][1]) ?><div class="grow"><b><?= e($worst === 'ok' ? t('Semua pemeriksaan baik.') : ($worst === 'warn' ? t('Ada yang perlu dicek.') : t('Ada masalah yang perlu ditangani.'))) ?></b> <span class="small"><?= e(t('Diperiksa :t', ['t' => datetime_id(now())])) ?></span></div></div>
<div class="hc-grid">
<?php foreach ($checks as $c): [$dot, $ic, $bdg, $lbl] = $tone[$c['level']] ?? $tone['warn']; ?>
  <div class="card hc" data-check="<?= e($c['key']) ?>" data-level="<?= e($c['level']) ?>">
    <div class="card-h"><h3><span class="dot <?= $dot ?>"><?= icon($ic, 14) ?></span> <?= e($c['label']) ?></h3><span class="bdg <?= $bdg ?>"><?= e(t($lbl)) ?></span></div>
    <div class="hc-v"><?= e($c['value']) ?></div>
    <p class="small muted"><?= e($c['detail']) ?></p>
    <?php if (!empty($c['list'])): ?><ul class="small hc-list"><?php foreach (array_slice($c['list'], 0, 8) as $li): ?><li><?= e($li) ?></li><?php endforeach; ?><?= count($c['list']) > 8 ? '<li class="muted">' . e(t('dan :n lainnya', ['n' => count($c['list']) - 8])) . '</li>' : '' ?></ul><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<div class="card">
  <div class="card-h"><h3><?= e(t('File cadangan')) ?></h3><span class="sub"><?= e(t('Dibuat otomatis tiap malam, :n terbaru disimpan', ['n' => \App\Services\Backup::KEEP])) ?></span></div>
  <?php if (!$backups): ?><p class="muted small"><?= e(t('Belum ada cadangan.')) ?></p><?php else: ?>
  <div class="tblw"><table class="tbl">
    <thead><tr><th><?= e(t('File')) ?></th><th><?= e(t('Waktu')) ?></th><th class="r"><?= e(t('Ukuran')) ?></th><th class="r"></th></tr></thead>
    <tbody><?php foreach ($backups as $b): ?><tr><td class="mono small"><?= e($b['name']) ?></td><td class="small"><?= e(datetime_id(date('Y-m-d H:i:s', $b['time']))) ?></td><td class="r small"><?= e(\App\Services\Health::bytes($b['size'])) ?></td>
      <td class="r"><a class="btn btn-ghost sm" href="<?= e(url('admin/kesehatan/cadangan/' . basename($b['name'], '.zip'))) ?>"><?= icon('download', 14) ?><?= e(t('Unduh')) ?></a></td></tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
  <p class="small muted"><?= e(t('Pulihkan di server baru: salin file ke server, lalu jalankan php bin/restore.php <file> --yes. Berisi seluruh data sekolah; simpan di tempat yang aman.')) ?></p>
</div>
