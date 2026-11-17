<div class="portal">
  <?= \App\Core\View::partial('portal_head', ['school' => $school, 'kids' => $kids]) ?>
  <div class="card">
    <div class="banner bad" role="alert"><?= icon('lock') ?><div class="grow"><b><?= e(t('Akses ditolak')) ?></b><div class="small"><?= e(t('Anda hanya bisa melihat kehadiran anak yang sudah dibuka dengan NIS dan kode aksesnya.')) ?></div></div></div>
    <div class="list"><?php foreach ($kids as $k): ?><a class="nm" href="<?= e(url('portal/siswa/' . $k['id'])) ?>"><?= e($k['full_name']) ?></a><?php endforeach; ?></div>
    <a class="btn btn-outline sm" href="<?= e(url('portal', ['tambah' => 1])) ?>"><?= icon('plus', 16) ?><?= e(t('Buka anak lain dengan kode akses')) ?></a>
  </div>
</div>
