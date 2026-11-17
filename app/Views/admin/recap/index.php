<div class="pagehead"><div><h2><?= e(t('Rekap Kehadiran')) ?></h2><p><?= e(t('Format buku absen: kode H/T/S/I/A/D per tanggal, jumlah per kode, dan persentase hadir = (H + T) ÷ hari efektif. Semua rekap bisa diunduh sebagai Excel (A4 landscape, dengan kop sekolah) atau CSV.')) ?></p></div></div>
<?php if (!$classes && !$canSchool): ?><div class="banner warn"><?= icon('alert') ?><div class="grow"><?= e(t('Belum ada kelas yang bisa direkap.')) ?></div></div><?php endif; ?>
<?php if ($classes): ?>
<div class="g-3">
  <form class="card" method="get" action="<?= e(url('admin/rekap/kelas')) ?>" data-recap>
    <div class="card-h"><h3><?= e(t('Bulanan per kelas')) ?></h3></div>
    <p class="muted small"><?= e(t('Format utama: satu baris per siswa, satu kolom per tanggal.')) ?></p>
    <input type="hidden" name="jenis" value="bulanan">
    <div class="fld"><label><?= e(t('Kelas')) ?></label><select class="inp" name="id"><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label><?= e(t('Bulan')) ?></label><input class="inp" type="month" name="bulan" value="<?= e($month) ?>"></div>
    <button class="btn btn-primary sm" type="submit"><?= e(t('Lihat rekap')) ?></button>
  </form>
  <form class="card" method="get" action="<?= e(url('admin/rekap/kelas')) ?>" data-recap>
    <div class="card-h"><h3><?= e(t('Harian per kelas')) ?></h3></div>
    <p class="muted small"><?= e(t('Status setiap siswa pada satu tanggal; mode per jam: satu kolom per jam pelajaran.')) ?></p>
    <input type="hidden" name="jenis" value="harian">
    <div class="fld"><label><?= e(t('Kelas')) ?></label><select class="inp" name="id"><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label><?= e(t('Tanggal')) ?></label><input class="inp" type="date" name="tanggal" value="<?= e($date) ?>"></div>
    <button class="btn btn-primary sm" type="submit"><?= e(t('Lihat rekap')) ?></button>
  </form>
  <form class="card" method="get" action="<?= e(url('admin/rekap/kelas')) ?>" data-recap>
    <div class="card-h"><h3><?= e(t('Semester per kelas')) ?></h3></div>
    <p class="muted small"><?= e(t('Jumlah H/T/S/I/A/D per bulan dan persentase semester (untuk rapor).')) ?></p>
    <input type="hidden" name="jenis" value="semester">
    <div class="fld"><label><?= e(t('Kelas')) ?></label><select class="inp" name="id"><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label><?= e(t('Semester')) ?></label><select class="inp" name="semester"><?php foreach ($semesters as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected((int) ($semester['id'] ?? 0), (int) $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary sm" type="submit"><?= e(t('Lihat rekap')) ?></button>
  </form>
</div>
<?php endif; ?>
<?php if ($canSchool): ?>
<div class="card">
  <div class="card-h"><h3><?= e(t('Seluruh sekolah')) ?></h3></div>
  <div class="row wrap">
    <a class="btn btn-outline sm" href="<?= e(url('admin/rekap/sekolah', ['bulan' => $month])) ?>"><?= icon('chart', 16) ?><?= e(t('Ringkasan bulan ini per kelas')) ?></a>
    <a class="btn btn-outline sm" href="<?= e(url('admin/rekap/sekolah', ['jenis' => 'semester'])) ?>"><?= icon('chart', 16) ?><?= e(t('Ringkasan semester per kelas')) ?></a>
    <a class="btn btn-primary sm" href="<?= e(url('admin/rekap/sekolah/semester')) ?>" data-loading="<?= e(t('Menyiapkan…')) ?>"><?= icon('download', 16) ?><?= e(t('Unduh rekap semester semua kelas (Excel)')) ?></a>
  </div>
</div>
<?php endif; ?>
