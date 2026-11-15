<?php use App\Core\Auth; $cls = static fn (string $k) => 'fld' . (isset($errors[$k]) ? ' is-err' : ''); ?>
<form method="post" action="<?= e(url($editing ? 'admin/akun/' . $editing['id'] . '/ubah' : 'admin/akun/baru')) ?>" class="card narrow" novalidate data-loading="<?= e(t('Menyimpan…')) ?>">
  <?= csrf_field() ?>
  <div class="card-h"><h3><?= e($editing ? t('Ubah akun @:u', ['u' => $editing['username']]) : t('Akun baru')) ?></h3></div>
  <div class="<?= $cls('role') ?>"><label for="role"><?= e(t('Peran')) ?></label>
    <select class="inp" id="role" name="role"<?= $editing && (int) $editing['id'] === (int) $user['id'] ? ' disabled' : '' ?>>
      <?php foreach (Auth::ROLE_LABELS as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($d['role'], $k) ?>><?= e(t($label)) ?></option><?php endforeach; ?>
    </select>
    <?php if ($editing && (int) $editing['id'] === (int) $user['id']): ?><input type="hidden" name="role" value="<?= e($d['role']) ?>"><?php endif; ?>
    <p class="hint"><?= e(t(Auth::ROLE_HINTS[$d['role']] ?? '')) ?></p><?= field_error($errors, 'role') ?></div>
  <div class="<?= $cls('name') ?>"><label for="name"><?= e(t('Nama lengkap')) ?></label><input class="inp" id="name" name="name" maxlength="120" required value="<?= e($d['name']) ?>"><?= field_error($errors, 'name') ?></div>
  <div class="g-form two">
    <div class="<?= $cls('username') ?>"><label for="username"><?= e(t('Username')) ?></label>
      <?php if ($editing): ?><input class="inp mono" id="username" value="<?= e($d['username']) ?>" disabled><p class="hint"><?= e(t('Username tidak bisa diubah.')) ?></p>
      <?php else: ?><input class="inp mono" id="username" name="username" autocapitalize="none" spellcheck="false" placeholder="<?= e(t('kosongkan = otomatis dari nama')) ?>" value="<?= e($d['username']) ?>"><p class="hint"><?= e(t('4–40 karakter: huruf kecil, angka, titik.')) ?></p><?php endif; ?>
      <?= field_error($errors, 'username') ?></div>
    <div class="<?= $cls('nip') ?>"><label for="nip"><?= e(t('NIP / NUPTK')) ?><span class="en"><?= e(t('opsional, dipakai saat impor jadwal')) ?></span></label><input class="inp mono" id="nip" name="nip" maxlength="30" value="<?= e($d['nip']) ?>"><?= field_error($errors, 'nip') ?></div>
  </div>
  <div class="g-form two">
    <div class="<?= $cls('phone') ?>"><label for="phone"><?= e(t('Nomor HP')) ?><span class="en"><?= e(t('opsional')) ?></span></label><input class="inp" id="phone" name="phone" inputmode="tel" value="<?= e($d['phone']) ?>"><?= field_error($errors, 'phone') ?></div>
    <div class="<?= $cls('status') ?>"><label for="status"><?= e(t('Status')) ?></label>
      <select class="inp" id="status" name="status"<?= $editing && (int) $editing['id'] === (int) $user['id'] ? ' disabled' : '' ?>><option value="Aktif"<?= selected($d['status'], 'Aktif') ?>><?= e(t('Aktif')) ?></option><option value="Nonaktif"<?= selected($d['status'], 'Nonaktif') ?>><?= e(t('Nonaktif')) ?></option></select>
      <?php if ($editing && (int) $editing['id'] === (int) $user['id']): ?><input type="hidden" name="status" value="Aktif"><?php endif; ?></div>
  </div>
  <?php if (!$editing): ?>
  <div class="<?= $cls('password') ?>"><label for="password"><?= e(t('Password sementara')) ?></label>
    <div class="copyrow"><input class="inp mono" id="password" name="password" value="<?= e($d['password']) ?>" required><button type="button" class="btn btn-outline" data-copy="#password"><?= icon('copy', 18) ?><?= e(t('Salin')) ?></button></div>
    <p class="hint"><?= e(t('Minimal 10 karakter, huruf dan angka. Pemilik akun wajib menggantinya saat login pertama.')) ?></p><?= field_error($errors, 'password') ?></div>
  <?php endif; ?>
  <div class="acts"><a class="btn btn-ghost" href="<?= e(url($back)) ?>"><?= e(t('Batal')) ?></a><button type="submit" class="btn btn-primary"><?= e($editing ? t('Simpan perubahan') : t('Buat akun')) ?></button></div>
</form>
