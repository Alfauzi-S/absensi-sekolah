<?php $cls = static fn (string $k) => 'fld' . (isset($errors[$k]) ? ' is-err' : ''); ?>
<form method="post" action="<?= e(url($editing ? 'admin/kelas/' . $editing['id'] . '/ubah' : 'admin/kelas/baru')) ?>" class="card narrow" novalidate data-loading="<?= e(t('Menyimpan…')) ?>">
  <?= csrf_field() ?>
  <div class="card-h"><h3><?= e($editing ? t('Ubah kelas :n', ['n' => $editing['name']]) : t('Kelas baru')) ?></h3><span class="sub"><?= e(t('Tahun ajaran :y', ['y' => $activeYear['name']])) ?></span></div>
  <div class="g-form two">
    <div class="<?= $cls('name') ?>"><label for="name"><?= e(t('Nama kelas')) ?></label><input class="inp" id="name" name="name" maxlength="30" placeholder="X-1, XI IPA 2" required value="<?= e($d['name']) ?>"><?= field_error($errors, 'name') ?></div>
    <div class="<?= $cls('grade') ?>"><label for="grade"><?= e(t('Tingkat')) ?></label><select class="inp" id="grade" name="grade"><?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13] as $g): ?><option value="<?= $g ?>"<?= selected($d['grade'], $g) ?>><?= $g ?></option><?php endforeach; ?></select><?= field_error($errors, 'grade') ?></div>
  </div>
  <div class="<?= $cls('homeroom_teacher_id') ?>"><label for="homeroom_teacher_id"><?= e(t('Wali kelas')) ?></label>
    <select class="inp" id="homeroom_teacher_id" name="homeroom_teacher_id"><option value=""><?= e(t('— Belum ditentukan —')) ?></option><?php foreach ($teachers as $g): ?><option value="<?= (int) $g['id'] ?>"<?= selected($d['homeroom_teacher_id'], $g['id']) ?>><?= e($g['name']) ?><?= $g['nip'] ? ' (' . e($g['nip']) . ')' : '' ?></option><?php endforeach; ?></select>
    <p class="hint"><?= e(t('Wali kelas otomatis bisa mengelola izin dan koreksi di kelas ini. Guru dibuat di menu Akun Pengguna.')) ?></p><?= field_error($errors, 'homeroom_teacher_id') ?></div>
  <div class="<?= $cls('room_id') ?>"><label for="room_id"><?= e(t('Ruangan tetap')) ?><span class="en"><?= e(t('untuk scanner kelas')) ?></span></label>
    <select class="inp" id="room_id" name="room_id"><option value=""><?= e(t('— Tidak ada —')) ?></option><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['id'] ?>"<?= selected($d['room_id'], $r['id']) ?>><?= e($r['code'] . ' · ' . $r['name']) ?></option><?php endforeach; ?></select><?= field_error($errors, 'room_id') ?></div>
  <div class="acts"><a class="btn btn-ghost" href="<?= e(url($back)) ?>"><?= e(t('Batal')) ?></a><button type="submit" class="btn btn-primary"><?= e($editing ? t('Simpan perubahan') : t('Buat kelas')) ?></button></div>
</form>
