<?php
use App\Services\Recap;
$labels = ['H' => 'Hadir', 'T' => 'Terlambat', 'S' => 'Sakit', 'I' => 'Izin', 'A' => 'Alpa', 'D' => 'Dispensasi'];
?>
<div class="pagehead">
  <div><h2><?= e(t('Banding absen manual · :c', ['c' => $class['name']])) ?></h2><p><?= e(t('Isi sesuai absen kertas guru pada :d. Selisih dengan sistem diberi warna dan wajib diberi penjelasan (misal: lupa scan, kartu tertinggal, guru salah catat).', ['d' => date_id($date, true)])) ?></p></div>
  <form method="get" action="<?= e(url('admin/pilot/banding/' . $class['id'])) ?>" class="row"><input class="inp" type="date" name="tanggal" value="<?= e($date) ?>" style="width:auto"><button class="btn btn-outline sm" type="submit"><?= e(t('Buka')) ?></button></form>
</div>
<div class="g-stats">
  <div class="stat"><span class="k"><?= e(t('Diisi')) ?></span><span class="v"><?= (int) $filled ?>/<?= count($rows) ?></span><span class="s"><?= e(t('siswa')) ?></span></div>
  <div class="stat"><span class="k"><?= e(t('Cocok')) ?></span><span class="v"><?= (int) ($filled - $diff) ?></span><span class="s"><?= e(t('siswa')) ?></span></div>
  <div class="stat"><span class="k"><?= e(t('Selisih')) ?></span><span class="v"><?= (int) $diff ?></span><span class="s"><?= e(t('perlu penjelasan')) ?></span></div>
</div>
<form method="post" action="<?= e(url('admin/pilot/banding/' . $class['id'])) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="tanggal" value="<?= e($date) ?>">
  <?php if ($canEdit): ?><div class="row wrap" style="justify-content:space-between;margin-bottom:8px">
    <span class="small muted"><?= e(t('Tip: tombol "Samakan yang kosong dengan sistem" mengisi siswa yang belum diisi, lalu ubah yang berbeda dengan kertas.')) ?></span>
    <button class="btn btn-outline sm" type="button" data-fill-system><?= icon('copy', 14) ?><?= e(t('Samakan yang kosong dengan sistem')) ?></button>
  </div><?php endif; ?>
  <div class="tblw"><table class="tbl">
    <thead><tr><th>#</th><th><?= e(t('Siswa')) ?></th><th><?= e(t('Sistem')) ?></th><th><?= e(t('Jam scan')) ?></th><th><?= e(t('Absen manual')) ?></th><th><?= e(t('Penjelasan selisih')) ?></th></tr></thead>
    <tbody><?php foreach ($rows as $i => $r): ?>
      <tr class="<?= $r['match'] === false ? 'row-diff' : '' ?>" data-sys="<?= e($r['system']) ?>">
        <td class="muted small"><?= $i + 1 ?></td>
        <td><?= e($r['full_name']) ?><div class="muted small mono"><?= e($r['nis']) ?></div></td>
        <td><?= $r['system'] !== '' ? status_badge($r['system']) : '<span class="muted small">' . e(t('belum ada')) . '</span>' ?></td>
        <td class="mono small"><?= e($r['first_scan'] ?? '—') ?></td>
        <td><?php if ($canEdit): ?><select class="inp sm" name="status[<?= (int) $r['student_id'] ?>]" aria-label="<?= e(t('Absen manual :n', ['n' => $r['full_name']])) ?>"><option value="">—</option><?php foreach (Recap::CODES as $k): ?><option value="<?= $k ?>"<?= selected($r['manual'], $k) ?>><?= $k ?> · <?= e(t($labels[$k])) ?></option><?php endforeach; ?></select>
          <?php else: ?><?= $r['manual'] ? status_badge($r['manual']) : '—' ?><?php endif; ?></td>
        <td><?php if ($r['match'] === false): ?><?php if ($canEdit): ?><input class="inp sm" name="explain[<?= (int) $r['student_id'] ?>]" maxlength="500" value="<?= e((string) $r['explanation']) ?>" placeholder="<?= e(t('Wajib diisi')) ?>"><?php else: ?><?= e((string) $r['explanation']) ?: '<span class="bdg bdg-warn">' . e(t('belum dijelaskan')) . '</span>' ?><?php endif; ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if ($canEdit): ?><div class="row" style="margin-top:12px"><button class="btn btn-primary" type="submit"><?= e(t('Simpan absen manual')) ?></button></div><?php endif; ?>
</form>
<?php if ($canEdit): ?>
<form method="post" action="<?= e(url('admin/pilot/banding/' . $class['id'] . '/impor', ['tanggal' => $date])) ?>" enctype="multipart/form-data" class="card"><?= csrf_field() ?>
  <div class="card-h"><h3><?= e(t('Impor dari Excel/CSV')) ?></h3></div>
  <p class="small muted"><?= e(t('Kolom: nis, tanggal (2026-11-17 atau 17/11/2026), status (H/T/S/I/A/D atau Hadir/Sakit/…). Bisa beberapa tanggal sekaligus.')) ?></p>
  <div class="row wrap"><input class="inp" type="file" name="file" accept=".csv,.xlsx" required style="width:auto"><button class="btn btn-outline sm" type="submit"><?= icon('upload', 14) ?><?= e(t('Impor')) ?></button></div>
</form>
<?php endif; ?>
<script>
document.querySelector('[data-fill-system]')?.addEventListener('click', () => {
  document.querySelectorAll('tr[data-sys]').forEach((tr) => {
    const sel = tr.querySelector('select');
    if (sel && !sel.value && tr.dataset.sys) sel.value = tr.dataset.sys;
  });
});
</script>
