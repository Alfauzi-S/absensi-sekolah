<?php $appName = config('app_name', 'Absensi Sekolah'); $school = \App\Models\Setting::get('school_name'); ?>
<div class="login">
  <div class="art">
    <div class="row" style="position:relative"><?= logo_mark(40) ?><b style="font-family:var(--f-display);color:#fff"><?= e($appName) ?></b></div>
    <div class="stack" style="position:relative">
      <h2><?= e(t('Absensi siswa dengan kartu QR, cepat dan tercatat.')) ?></h2>
      <p><?= e($school !== '' ? $school : t('Masuk sebagai Guru, Wali Kelas, TU/Staff, Kepala Sekolah, atau Super Admin sesuai akun yang diberikan sekolah.')) ?></p>
    </div>
    <img class="city" src="<?= e(asset('img/city.svg')) ?>" alt="">
  </div>
  <div class="form">
    <div class="only-narrow row"><?= logo_mark(40) ?><b style="font-family:var(--f-display)"><?= e($appName) ?></b></div>
    <div><h2 style="font-size:26px"><?= e(t('Masuk')) ?></h2><p class="muted"><?= e(t('Siswa tidak perlu login; cukup pindai kartu QR di ruang kelas. Halaman ini untuk guru dan staf sekolah.')) ?></p></div>
    <?php if ($error !== ''): ?>
      <div class="banner bad" role="alert"><?= icon('alert') ?><div class="grow"><?= e(t($error)) ?></div></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('masuk')) ?>" class="stack" data-loading="<?= e(t('Memeriksa akun…')) ?>">
      <?= csrf_field() ?>
      <div class="fld"><label for="username"><?= e(t('Username')) ?></label>
        <div class="inp-ic"><?= icon('user', 18) ?><input class="inp" id="username" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required value="<?= e($username) ?>"></div></div>
      <div class="fld"><label for="password"><?= e(t('Password')) ?></label>
        <div class="inp-ic"><?= icon('lock', 18) ?><input class="inp" id="password" name="password" type="password" autocomplete="current-password" required></div></div>
      <label class="chk"><input type="checkbox" data-show-password="#password"><?= e(t('Tampilkan password')) ?></label>
      <button type="submit" class="btn btn-primary lg block"><?= e(t('Masuk')) ?></button>
    </form>
    <p class="small muted"><?= e(t('Lupa password? Hubungi Super Admin atau TU untuk membuat password sementara.')) ?></p>
  </div>
</div>
