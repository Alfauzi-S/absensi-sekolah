<?php use App\Models\Student; $cls = static fn (string $k) => 'fld' . (isset($errors[$k]) ? ' is-err' : ''); ?>
<form method="post" action="<?= e(url($editing ? 'admin/siswa/' . $editing['id'] . '/ubah' : 'admin/siswa/baru')) ?>" class="card narrow" enctype="multipart/form-data" novalidate data-loading="<?= e(t('Menyimpan…')) ?>">
  <?= csrf_field() ?>
  <div class="card-h"><h3><?= e($editing ? t('Ubah data :n', ['n' => $editing['full_name']]) : t('Siswa baru')) ?></h3></div>
  <div class="g-form two">
    <div class="<?= $cls('nis') ?>"><label for="nis"><?= e(t('NIS')) ?><span class="en"><?= e(t('unik, dicetak di kartu QR')) ?></span></label><input class="inp mono" id="nis" name="nis" maxlength="20" required value="<?= e($d['nis']) ?>"><?= field_error($errors, 'nis') ?></div>
    <div class="<?= $cls('nisn') ?>"><label for="nisn"><?= e(t('NISN')) ?><span class="en"><?= e(t('opsional')) ?></span></label><input class="inp mono" id="nisn" name="nisn" maxlength="20" value="<?= e($d['nisn']) ?>"><?= field_error($errors, 'nisn') ?></div>
  </div>
  <div class="<?= $cls('full_name') ?>"><label for="full_name"><?= e(t('Nama lengkap')) ?></label><input class="inp" id="full_name" name="full_name" maxlength="120" required value="<?= e($d['full_name']) ?>"><?= field_error($errors, 'full_name') ?></div>
  <div class="g-form two">
    <div class="<?= $cls('gender') ?>"><label for="gender"><?= e(t('Jenis kelamin')) ?></label><select class="inp" id="gender" name="gender"><option value=""><?= e(t('— Pilih —')) ?></option><?php foreach (Student::GENDERS as $g => $label): ?><option value="<?= e($g) ?>"<?= selected($d['gender'], $g) ?>><?= e(t($label)) ?></option><?php endforeach; ?></select><?= field_error($errors, 'gender') ?></div>
    <div class="<?= $cls('birth_date') ?>"><label for="birth_date"><?= e(t('Tanggal lahir')) ?><span class="en"><?= e(t('opsional')) ?></span></label><input class="inp" id="birth_date" name="birth_date" type="date" value="<?= e($d['birth_date']) ?>"><?= field_error($errors, 'birth_date') ?></div>
  </div>
  <div class="g-form two">
    <div class="<?= $cls('parent_name') ?>"><label for="parent_name"><?= e(t('Nama orang tua/wali')) ?></label><input class="inp" id="parent_name" name="parent_name" maxlength="120" value="<?= e($d['parent_name']) ?>"><?= field_error($errors, 'parent_name') ?></div>
    <div class="<?= $cls('parent_phone') ?>"><label for="parent_phone"><?= e(t('HP orang tua')) ?><span class="en"><?= e(t('untuk notifikasi WA')) ?></span></label><input class="inp" id="parent_phone" name="parent_phone" inputmode="tel" value="<?= e($d['parent_phone']) ?>"><?= field_error($errors, 'parent_phone') ?></div>
  </div>
  <div class="g-form two">
    <div class="<?= $cls('class_id') ?>"><label for="class_id"><?= e(t('Kelas (:y)', ['y' => $activeYear['name']])) ?></label><select class="inp" id="class_id" name="class_id"><option value=""><?= e(t('— Tanpa kelas —')) ?></option><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($d['class_id'], $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><?= field_error($errors, 'class_id') ?></div>
    <div class="<?= $cls('status') ?>"><label for="status"><?= e(t('Status')) ?></label><select class="inp" id="status" name="status"><?php foreach (Student::STATUSES as $s): ?><option value="<?= e($s) ?>"<?= selected($d['status'], $s) ?>><?= e(t($s)) ?></option><?php endforeach; ?></select><?= field_error($errors, 'status') ?></div>
  </div>
  <div class="fld"><label for="photo"><?= e(t('Foto (pas foto)')) ?><span class="en"><?= e(t('opsional, JPG/PNG maks :mb MB, dipotong 3:4 otomatis', ['mb' => \App\Services\Photo::MAX_MB])) ?></span></label><input class="inp" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"></div>
  <div class="acts"><a class="btn btn-ghost" href="<?= e(url($back)) ?>"><?= e(t('Batal')) ?></a><button type="submit" class="btn btn-primary"><?= e($editing ? t('Simpan perubahan') : t('Simpan siswa')) ?></button></div>
</form>
