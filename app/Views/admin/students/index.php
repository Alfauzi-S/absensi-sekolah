<?php use App\Core\View; use App\Models\Student; $qs = ['q' => $q, 'kelas' => $classId === -1 ? 'tanpa' : ($classId ?: ''), 'st' => $status]; ?>
<div class="pagehead">
  <div><h2><?= e(t('Siswa')) ?></h2><p><?= e(t('Tahun ajaran :y · :n siswa sesuai filter', ['y' => $activeYear['name'], 'n' => $total])) ?></p></div>
  <div class="row">
    <?php if (can('reports')): ?><a class="btn btn-outline" href="<?= e(url('admin/siswa/ekspor', $qs)) ?>"><?= icon('download', 18) ?><?= e(t('Ekspor')) ?></a><?php endif; ?>
    <?php if ($canManage): ?><a class="btn btn-outline" href="<?= e(url('admin/impor')) ?>"><?= icon('upload', 18) ?><?= e(t('Impor')) ?></a><a class="btn btn-primary" href="<?= e(url('admin/siswa/baru')) ?>"><?= icon('userplus', 18) ?><?= e(t('Siswa baru')) ?></a><?php endif; ?>
  </div>
</div>
<form method="get" action="<?= e(url('admin/siswa')) ?>" class="filters">
  <div class="search inp-ic"><?= icon('search', 18) ?><input class="inp" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Cari NIS, NISN, atau nama…')) ?>" aria-label="<?= e(t('Cari')) ?>"></div>
  <div class="frow">
    <select class="inp" name="kelas" aria-label="<?= e(t('Kelas')) ?>" data-autosubmit-field>
      <option value=""><?= e(t('Semua kelas')) ?></option>
      <?php if ($canManage): ?><option value="tanpa"<?= selected($classId, -1) ?>><?= e(t('Tanpa kelas')) ?></option><?php endif; ?>
      <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($classId, $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <select class="inp" name="st" aria-label="<?= e(t('Status')) ?>" data-autosubmit-field>
      <?php foreach (Student::STATUSES as $s): ?><option value="<?= e($s) ?>"<?= selected($status, $s) ?>><?= e(t($s)) ?></option><?php endforeach; ?>
      <option value="all"<?= selected($status, 'all') ?>><?= e(t('Semua status')) ?></option>
    </select>
    <button type="submit" class="btn btn-primary"><?= e(t('Cari')) ?></button>
  </div>
</form>
<?php if (!$students): ?>
  <div class="card"><div class="empty"><span class="eic"><?= icon('users', 26) ?></span><h3><?= e(t('Tidak ada siswa')) ?></h3><p><?= e($q !== '' ? t('Tidak ada yang cocok dengan pencarian.') : t('Tambahkan siswa satu per satu atau impor dari Excel/CSV.')) ?></p></div></div>
<?php else: ?>
  <div class="tblw only-wide"><table class="tbl">
    <thead><tr><th><?= e(t('NIS')) ?></th><th><?= e(t('Nama')) ?></th><th><?= e(t('L/P')) ?></th><th><?= e(t('Kelas')) ?></th><th><?= e(t('HP orang tua')) ?></th><th><?= e(t('Status')) ?></th></tr></thead>
    <tbody><?php foreach ($students as $s): ?>
      <tr><td class="mono"><?= e($s['nis']) ?></td><td><a class="nm" href="<?= e(url('admin/siswa/' . $s['id'])) ?>"><?= e($s['full_name']) ?></a></td><td><?= e($s['gender']) ?></td><td><?= $s['class_name'] ? e($s['class_name']) : '<span class="bdg bdg-warn">' . e(t('Tanpa kelas')) . '</span>' ?></td><td class="mono small"><?= e($s['parent_phone'] ?: '—') ?></td><td><span class="bdg <?= $s['status'] === 'Aktif' ? 'bdg-ok' : 'bdg-mute' ?>"><?= e(t($s['status'])) ?></span></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <div class="list only-narrow"><?php foreach ($students as $s): ?>
    <a class="pc" href="<?= e(url('admin/siswa/' . $s['id'])) ?>"><span class="av t"><?= e(initials($s['full_name'])) ?></span><span class="who"><b><?= e($s['full_name']) ?></b><span><?= e($s['nis']) ?> · <?= e($s['class_name'] ?: t('Tanpa kelas')) ?></span></span><span class="bdg <?= $s['status'] === 'Aktif' ? 'bdg-ok' : 'bdg-mute' ?>"><?= e(t($s['status'])) ?></span></a>
  <?php endforeach; ?></div>
<?php endif; ?>
<?= View::partial('pager', ['page' => $page, 'pages' => $pages, 'total' => $total, 'path' => 'admin/siswa', 'query' => $qs]) ?>
