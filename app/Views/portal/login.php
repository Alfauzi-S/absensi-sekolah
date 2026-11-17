<div class="portal">
  <?= \App\Core\View::partial('portal_head', ['school' => $school, 'kids' => $kids]) ?>
  <?php if ($kids): ?>
  <div class="card">
    <div class="card-h"><h3><?= e(t('Anak yang sudah dibuka')) ?></h3></div>
    <div class="list"><?php foreach ($kids as $k): ?><a class="nm" href="<?= e(url('portal/siswa/' . $k['id'])) ?>"><?= e($k['full_name']) ?> <span class="muted small mono">NIS <?= e($k['nis']) ?></span></a><?php endforeach; ?></div>
  </div>
  <?php endif; ?>
  <div class="card">
    <div class="card-h"><h3><?= e($kids ? t('Tambah anak lain') : t('Lihat riwayat kehadiran anak')) ?></h3></div>
    <p class="small muted"><?= e(t('Masukkan NIS dan kode akses dari wali kelas atau TU. Halaman ini hanya menampilkan kehadiran anak yang kodenya Anda miliki.')) ?></p>
    <?php if ($error !== ''): ?><div class="banner bad" role="alert"><?= icon('alert') ?><div class="grow"><?= e($error) ?></div></div><?php endif; ?>
    <form method="post" action="<?= e(url('portal/masuk')) ?>" class="stack" data-loading="<?= e(t('Memeriksa…')) ?>"><?= csrf_field() ?>
      <div class="fld"><label for="nis"><?= e(t('NIS')) ?></label><input class="inp mono" id="nis" name="nis" inputmode="numeric" autocomplete="off" required maxlength="30" value="<?= e($nis) ?>"></div>
      <div class="fld"><label for="code"><?= e(t('Kode akses')) ?></label><input class="inp mono" id="code" name="code" autocomplete="off" autocapitalize="characters" spellcheck="false" required maxlength="12" placeholder="ABCD-EFGH" style="text-transform:uppercase;letter-spacing:.08em"></div>
      <button type="submit" class="btn btn-primary lg block"><?= e(t('Lihat kehadiran')) ?></button>
    </form>
  </div>
  <p class="small muted center"><?= e(t('Lupa kode? Minta kode baru ke wali kelas atau TU; kode lama otomatis tidak berlaku.')) ?></p>
</div>
