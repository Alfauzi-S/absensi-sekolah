<?php
use App\Models\Leave;
?>
<div class="pagehead">
  <div><h2><?= e(t('Izin dan Sakit')) ?></h2><p><?= e(t('Surat izin, sakit, dan dispensasi berlaku untuk rentang tanggal. Statusnya mengalahkan scan dan Alpa otomatis pada tanggal itu, kecuali yang sudah dikoreksi guru.')) ?></p></div>
  <?php if ($canCreate): ?><a class="btn btn-primary sm" href="<?= e(url('admin/izin/baru', $classId ? ['kelas' => $classId] : [])) ?>"><?= icon('plus', 16) ?><?= e(t('Catat izin/sakit')) ?></a><?php endif; ?>
</div>
<form class="filters" method="get" action="<?= e(url('admin/izin')) ?>">
  <select class="inp" name="kelas" onchange="this.form.submit()"><option value=""><?= e(t('Semua kelas')) ?></option><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($classId, (int) $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
  <select class="inp" name="st" onchange="this.form.submit()"><option value="active"<?= selected($status, 'active') ?>><?= e(t('Berlaku')) ?></option><option value="cancelled"<?= selected($status, 'cancelled') ?>><?= e(t('Dibatalkan')) ?></option><option value="all"<?= selected($status, 'all') ?>><?= e(t('Semua')) ?></option></select>
  <input class="inp" type="date" name="dari" value="<?= e($from) ?>" aria-label="<?= e(t('Dari')) ?>">
  <input class="inp" type="date" name="sampai" value="<?= e($to) ?>" aria-label="<?= e(t('Sampai')) ?>">
  <input class="inp" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Cari nama atau NIS')) ?>">
  <button class="btn btn-outline sm" type="submit"><?= icon('search', 16) ?><?= e(t('Cari')) ?></button>
</form>
<div class="card">
  <?php if (!$leaves): ?><p class="muted small"><?= e(t('Tidak ada surat izin/sakit untuk filter ini.')) ?></p>
  <?php else: ?><div class="tblw"><table class="tbl">
    <thead><tr><th><?= e(t('Tanggal')) ?></th><th><?= e(t('Siswa')) ?></th><th><?= e(t('Kelas')) ?></th><th><?= e(t('Jenis')) ?></th><th><?= e(t('Keterangan')) ?></th><th><?= e(t('Dicatat')) ?></th><th class="r"></th></tr></thead>
    <tbody><?php foreach ($leaves as $l): ?>
      <tr<?= $l['status'] === 'cancelled' ? ' class="muted"' : '' ?>>
        <td class="mono small nowrap"><?= e(date_id($l['date_from'])) ?><?= $l['date_to'] !== $l['date_from'] ? '<br>– ' . e(date_id($l['date_to'])) : '' ?></td>
        <td><a class="nm" href="<?= e(url('admin/rekap/siswa/' . $l['student_id'])) ?>"><?= e($l['full_name']) ?></a><div class="muted small mono"><?= e($l['nis']) ?></div></td>
        <td><?= e((string) $l['class_name']) ?></td>
        <td><?= status_badge($l['kind']) ?><?= $l['status'] === 'cancelled' ? ' <span class="bdg bdg-mute">' . e(t('Dibatalkan')) . '</span>' : '' ?></td>
        <td class="small"><?= e($l['reason']) ?><?= $l['attachment'] ? ' · <a href="' . e(url('admin/izin/' . $l['id'] . '/lampiran')) . '" target="_blank" rel="noopener">' . e(t('Lampiran')) . '</a>' : '' ?><?= $l['cancel_reason'] ? '<div class="muted">' . e(t('Batal: :r', ['r' => $l['cancel_reason']])) . '</div>' : '' ?></td>
        <td class="small muted"><?= e((string) $l['created_by_name']) ?><br><?= e(substr($l['created_at'], 0, 16)) ?></td>
        <td class="r"><?php if ($l['status'] === 'active' && can('class:manage', (int) $l['class_id'])): ?>
          <form method="post" action="<?= e(url('admin/izin/' . $l['id'] . '/batal')) ?>" class="row" data-confirm="<?= e(t('Batalkan surat ini? Status kehadiran kembali ke hasil scan atau Alpa.')) ?>"><?= csrf_field() ?>
            <input class="inp sm" name="reason" required minlength="3" maxlength="200" placeholder="<?= e(t('Alasan batal')) ?>" style="width:140px"><button class="btn btn-ghost sm" type="submit"><?= icon('x', 14) ?><?= e(t('Batalkan')) ?></button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</div>
