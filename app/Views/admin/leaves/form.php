<?php
use App\Models\Leave;
?>
<div class="pagehead"><div><h2><?= e(t('Catat Izin/Sakit')) ?></h2><p><?= e(t('Berlaku langsung untuk semua sesi siswa pada rentang tanggal ini, termasuk yang sudah lewat (kecuali yang sudah dikoreksi).')) ?></p></div></div>
<div class="card" style="max-width:720px">
  <form method="get" action="<?= e(url('admin/izin/baru')) ?>" class="fld">
    <label><?= e(t('Kelas')) ?></label>
    <select class="inp" name="kelas" onchange="this.form.submit()"><option value=""><?= e(t('Pilih kelas')) ?></option><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($classId, (int) $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <noscript><button class="btn btn-outline sm" type="submit"><?= e(t('Pilih')) ?></button></noscript>
  </form>
  <?php if ($classId): ?>
  <form method="post" action="<?= e(url('admin/izin')) ?>" enctype="multipart/form-data" novalidate><?= csrf_field() ?>
    <input type="hidden" name="class_id" value="<?= (int) $classId ?>">
    <div class="fld"><label><?= e(t('Siswa')) ?></label><select class="inp" name="student_id" required><option value=""><?= e(t('Pilih siswa')) ?></option><?php foreach ($students as $st): ?><option value="<?= (int) $st['id'] ?>"<?= selected((int) ($old['student_id'] ?? 0), (int) $st['id']) ?>><?= e($st['full_name'] . ' · ' . $st['nis']) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label><?= e(t('Jenis')) ?></label><div class="row">
      <?php foreach (Leave::KINDS as $k => $v): ?><label class="chk"><input type="radio" name="kind" value="<?= $k ?>"<?= checked($old['kind'] ?? 'S', $k) ?>> <?= e(t($v)) ?></label><?php endforeach; ?>
    </div><?= field_error($errors, 'kind') ?></div>
    <div class="g-2">
      <div class="fld"><label><?= e(t('Dari tanggal')) ?></label><input class="inp" type="date" name="date_from" value="<?= e((string) ($old['date_from'] ?? today())) ?>" required><?= field_error($errors, 'date_from') ?></div>
      <div class="fld"><label><?= e(t('Sampai tanggal')) ?></label><input class="inp" type="date" name="date_to" value="<?= e((string) ($old['date_to'] ?? today())) ?>" required><?= field_error($errors, 'date_to') ?></div>
    </div>
    <div class="fld"><label><?= e(t('Keterangan')) ?></label><input class="inp" name="reason" maxlength="255" value="<?= e((string) ($old['reason'] ?? '')) ?>" required placeholder="<?= e(t('Contoh: demam, surat dokter; acara keluarga; lomba OSN')) ?>"><?= field_error($errors, 'reason') ?></div>
    <div class="fld"><label><?= e(t('Lampiran surat (opsional)')) ?></label><input class="inp" type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf"><span class="hint"><?= e(t('Foto atau PDF, maksimal :n MB.', ['n' => Leave::ATTACH_MB])) ?></span></div>
    <div class="acts"><a class="btn btn-outline" href="<?= e(url('admin/izin')) ?>"><?= e(t('Batal')) ?></a><button class="btn btn-primary" type="submit"><?= e(t('Simpan')) ?></button></div>
  </form>
  <?php endif; ?>
</div>
