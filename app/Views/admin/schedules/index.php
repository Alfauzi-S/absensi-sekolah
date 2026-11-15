<div class="pagehead">
  <div><h2><?= e(t('Jadwal Pelajaran')) ?></h2><p><?= e(t('Semester :s :y', ['s' => $semester['name'], 'y' => $activeYear['name']])) ?><?= $class && $class['homeroom_name'] ? ' · ' . e(t('Wali kelas: :w', ['w' => $class['homeroom_name']])) : '' ?></p></div>
  <div class="row">
    <?php if ($canManage): ?><a class="btn btn-outline" href="<?= e(url('admin/impor')) ?>"><?= icon('upload', 18) ?><?= e(t('Impor jadwal')) ?></a><?php endif; ?>
    <?php if ($canManage && $class): ?><a class="btn btn-primary" href="<?= e(url('admin/jadwal/baru', ['kelas' => $class['id']])) ?>"><?= icon('plus', 18) ?><?= e(t('Tambah jadwal')) ?></a><?php endif; ?>
  </div>
</div>
<?php if ($mine): ?>
<div class="card">
  <div class="card-h"><h3><?= e(t('Jadwal mengajar saya')) ?></h3><span class="sub"><?= e(t(':n jadwal per minggu', ['n' => count($mine)])) ?></span></div>
  <div class="sched"><?php foreach ($mine as $s): ?><div><span class="mono"><?= e(day_name((int) $s['day_of_week'], true)) ?> <?= e($s['period_start']) ?>–<?= e($s['period_end']) ?></span><span><b><?= e($s['subject_name']) ?></b> · <a href="<?= e(url('admin/jadwal', ['kelas' => $s['class_id']])) ?>"><?= e($s['class_name']) ?></a><?= $s['room_code'] ? ' · ' . e($s['room_code']) : '' ?></span></div><?php endforeach; ?></div>
</div>
<?php endif; ?>
<?php if (!$classes): ?>
  <div class="card"><div class="empty"><span class="eic"><?= icon('calendar', 26) ?></span><h3><?= e(t('Belum ada kelas')) ?></h3><p><?= e(t('Buat kelas terlebih dahulu sebelum menyusun jadwal.')) ?></p></div></div>
<?php else: ?>
<form method="get" action="<?= e(url('admin/jadwal')) ?>" class="filters">
  <div class="frow">
    <select class="inp" name="kelas" aria-label="<?= e(t('Kelas')) ?>" data-autosubmit-field><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($class['id'] ?? 0, $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <button type="submit" class="btn btn-primary"><?= e(t('Tampilkan')) ?></button>
  </div>
</form>
<?php if (!$periods): ?>
  <div class="banner warn"><?= icon('alert') ?><div class="grow"><b><?= e(t('Jam pelajaran belum diatur')) ?></b><?= e(t('Jadwal membutuhkan daftar jam pelajaran (jam ke-1, ke-2, …).')) ?></div><?php if ($canManage): ?><a class="btn btn-outline sm" href="<?= e(url('admin/master/jam')) ?>"><?= e(t('Atur jam')) ?></a><?php endif; ?></div>
<?php elseif ($class): ?>
  <div class="tblw"><table class="tbl" style="table-layout:fixed;min-width:760px">
    <thead><tr><th style="width:110px"><?= e(t('Jam')) ?></th><?php foreach ($days as $dd): ?><th><?= e(day_name($dd)) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($periods as $n => $p): ?>
      <tr><td><b><?= (int) $n ?></b><br><span class="small muted mono"><?= e(substr($p['start_time'], 0, 5)) ?>–<?= e(substr($p['end_time'], 0, 5)) ?></span></td>
      <?php foreach ($days as $dd): $cell = $grid[$dd][$n] ?? null; ?>
        <?php if ($cell === null): ?><td style="white-space:normal;vertical-align:top"><?php if ($canManage): ?><a class="btn btn-ghost sm" href="<?= e(url('admin/jadwal/baru', ['kelas' => $class['id'], 'hari' => $dd, 'jam' => $n])) ?>" aria-label="<?= e(t('Tambah')) ?>"><?= icon('plus', 14) ?></a><?php endif; ?></td>
        <?php elseif ($cell['first']): ?><td rowspan="<?= (int) $cell['period_end'] - (int) $cell['period_start'] + 1 ?>" style="white-space:normal;vertical-align:top;background:var(--slate-bg)">
          <b><?= e($cell['subject_name']) ?></b><br><span class="small"><?= e($cell['teacher_name'] ?? '—') ?></span><?= $cell['room_code'] ? '<br><span class="small mono muted">' . e($cell['room_code']) . '</span>' : '' ?>
          <?php if ($canManage): ?><br><a class="small" href="<?= e(url('admin/jadwal/' . $cell['id'] . '/ubah')) ?>"><?= e(t('Ubah')) ?></a><?php endif; ?></td>
        <?php endif; ?>
      <?php endforeach; ?></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <p class="small muted"><?= e(t(':n jadwal untuk :c', ['n' => count($rows), 'c' => $class['name']])) ?></p>
<?php endif; ?>
<?php endif; ?>
