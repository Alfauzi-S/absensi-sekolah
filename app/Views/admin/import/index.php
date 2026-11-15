<div class="pagehead"><div><h2><?= e(t('Impor Data')) ?></h2><p><?= e(t('Unggah Excel (.xlsx) atau CSV. Baris pertama adalah nama kolom; baris yang gagal dilaporkan tanpa membatalkan baris lain.')) ?></p></div></div>
<?php if ($result): ?>
<div class="card">
  <div class="card-h"><h3><?= e(t('Hasil impor :k', ['k' => $result['kind']])) ?></h3><span class="sub mono"><?= e($result['file']) ?></span></div>
  <div class="g-stats">
    <div class="stat"><span class="k"><?= e(t('Baris dibaca')) ?></span><span class="v"><?= (int) $result['rows'] ?></span></div>
    <div class="stat"><span class="k"><?= e(t('Ditambahkan')) ?></span><span class="v t-ok"><?= (int) $result['created'] ?></span></div>
    <div class="stat"><span class="k"><?= e($result['kind'] === 'siswa' ? t('Diperbarui') : t('Diperbarui')) ?></span><span class="v"><?= (int) $result['updated'] ?></span><?php if ($result['kind'] === 'siswa'): ?><span class="s"><?= e(t(':n ditempatkan ke kelas', ['n' => (int) $result['placed']])) ?></span><?php endif; ?></div>
    <div class="stat"><span class="k"><?= e(t('Gagal')) ?></span><span class="v<?= $result['failed'] ? ' t-warn' : '' ?>"><?= count($result['failed']) ?></span></div>
  </div>
  <?php if ($result['failed']): ?>
  <div class="tblw" style="margin-top:12px"><table class="tbl">
    <thead><tr><th><?= e(t('Baris')) ?></th><th><?= e(t('Kunci')) ?></th><th><?= e(t('Masalah')) ?></th></tr></thead>
    <tbody><?php foreach ($result['failed'] as $f): ?><tr><td class="num"><?= (int) $f['row'] ?></td><td class="mono"><?= e($f['nis'] ?? $f['key'] ?? '') ?></td><td style="white-space:normal"><?= e($f['error']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="small muted"><?= e(t('Perbaiki baris di atas pada file, lalu unggah ulang. Baris yang sudah berhasil tidak akan digandakan.')) ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="g-2">
  <form method="post" action="<?= e(url('admin/impor/siswa')) ?>" enctype="multipart/form-data" class="card" data-loading="<?= e(t('Mengimpor…')) ?>">
    <?= csrf_field() ?>
    <div class="card-h"><h3><?= e(t('Impor siswa')) ?></h3><a class="btn btn-ghost sm" href="<?= e(url('admin/impor/template/siswa')) ?>"><?= icon('download', 16) ?><?= e(t('Template')) ?></a></div>
    <p class="small muted"><?= e(t('Kolom:')) ?> <span class="mono"><?= e(implode(', ', $studentColumns)) ?></span>. <?= e(t('Wajib: nis, nama, jk (L/P). Kolom "kelas" menempatkan siswa ke kelas pada tahun ajaran aktif; nama kelas harus sudah ada di menu Kelas.')) ?></p>
    <div class="fld"><label for="file-siswa"><?= e(t('File (.xlsx atau .csv, maks 5 MB)')) ?></label><input class="inp" id="file-siswa" name="file" type="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div>
    <div class="fld"><label><?= e(t('Jika NIS sudah ada')) ?></label>
      <label class="chk"><input type="radio" name="mode" value="update" checked><?= e(t('Perbarui data siswa dan kelasnya')) ?></label>
      <label class="chk"><input type="radio" name="mode" value="skip"><?= e(t('Lewati baris itu (hanya tambah siswa baru)')) ?></label></div>
    <div><button class="btn btn-primary" type="submit"><?= icon('upload', 18) ?><?= e(t('Impor siswa')) ?></button></div>
  </form>
  <form method="post" action="<?= e(url('admin/impor/jadwal')) ?>" enctype="multipart/form-data" class="card" data-loading="<?= e(t('Mengimpor…')) ?>">
    <?= csrf_field() ?>
    <div class="card-h"><h3><?= e(t('Impor jadwal')) ?></h3><a class="btn btn-ghost sm" href="<?= e(url('admin/impor/template/jadwal')) ?>"><?= icon('download', 16) ?><?= e(t('Template')) ?></a></div>
    <p class="small muted"><?= e(t('Kolom:')) ?> <span class="mono"><?= e(implode(', ', $scheduleColumns)) ?></span>. <?= e(t('Mapel dan ruangan dicocokkan dengan kode; guru dicocokkan dengan NIP. Jadwal masuk ke semester aktif')) ?><?= $semester ? ' (' . e($semester['name']) . ')' : '' ?>.</p>
    <?php if (!$semester): ?><div class="banner warn"><?= icon('alert') ?><div class="grow"><?= e(t('Belum ada semester aktif.')) ?></div></div><?php endif; ?>
    <div class="fld"><label for="file-jadwal"><?= e(t('File (.xlsx atau .csv, maks 5 MB)')) ?></label><input class="inp" id="file-jadwal" name="file" type="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required<?= $semester ? '' : ' disabled' ?>></div>
    <div class="fld"><label><?= e(t('Jadwal lama di kelas yang ada di file')) ?></label>
      <label class="chk"><input type="radio" name="mode" value="append" checked><?= e(t('Pertahankan, tambahkan jadwal baru (bentrok dilaporkan)')) ?></label>
      <label class="chk"><input type="radio" name="mode" value="replace"><?= e(t('Hapus dulu, ganti dengan isi file')) ?></label></div>
    <div><button class="btn btn-primary" type="submit"<?= $semester ? '' : ' disabled' ?>><?= icon('upload', 18) ?><?= e(t('Impor jadwal')) ?></button></div>
  </form>
</div>
