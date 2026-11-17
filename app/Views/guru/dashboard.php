<div class="pagehead">
  <div><h2><?= e(t('Halo, :name', ['name' => $user['name']])) ?></h2><p><?= e(date_id(today(), true)) ?><?= $isHoliday ? ' · <span class="bdg bdg-warn">' . e(t('Hari libur')) . '</span>' : '' ?></p></div>
</div>
<?php if (!$activeYear || !$activeSemester): ?>
  <div class="banner warn"><?= icon('alert') ?><div class="grow"><b><?= e(t('Tahun ajaran/semester belum aktif')) ?></b><?= e(t('Hubungi TU agar jadwal dan kelas bisa ditampilkan.')) ?></div></div>
<?php endif; ?>
<?php foreach ($atts as $att): ?><?php if ($att['today']): ?><?php include __DIR__ . '/../partials/attendance_overview.php'; ?><?php endif; ?><?php endforeach; ?>
<div class="g-stats">
  <div class="stat"><span class="k"><span class="dot t-ok"><?= icon('calendar', 14) ?></span><?= e(t('Jam mengajar hari ini')) ?></span><span class="v"><?= count($todaySchedules) ?></span><span class="s"><?= e(t(':n jadwal per minggu', ['n' => (int) $weekCount])) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-navy"><?= icon('building', 14) ?></span><?= e(t('Kelas yang diajar')) ?></span><span class="v"><?= count($classes) ?></span><span class="s"><?= e(t('Semester :s', ['s' => $activeSemester['name'] ?? '—'])) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-warn"><?= icon('shield', 14) ?></span><?= e(t('Wali kelas')) ?></span><span class="v"><?= $homerooms ? e(implode(', ', array_column($homerooms, 'name'))) : '—' ?></span><span class="s"><?= e($homerooms ? t(':n siswa', ['n' => array_sum(array_column($homerooms, 'student_count'))]) : t('Bukan wali kelas')) ?></span></div>
</div>
<div class="g-2">
  <div class="card">
    <div class="card-h"><h3><?= e(t('Jadwal hari ini')) ?></h3><a class="btn btn-ghost sm" href="<?= e(url('admin/jadwal')) ?>"><?= e(t('Jadwal lengkap')) ?></a></div>
    <?php if (!$todaySchedules): ?><p class="muted small"><?= e(t('Tidak ada jam mengajar hari ini.')) ?></p>
    <?php else: ?><div class="sched"><?php foreach ($todaySchedules as $s): ?>
      <div><span class="mono"><?= e(t('Jam :a–:b', ['a' => $s['period_start'], 'b' => $s['period_end']])) ?></span><span><b><?= e($s['subject_name']) ?></b> · <a href="<?= e(url('admin/kelas/' . $s['class_id'])) ?>"><?= e($s['class_name']) ?></a><?= $s['room_code'] ? ' · ' . e($s['room_code']) : '' ?></span></div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-h"><h3><?= e(t('Kelas saya')) ?></h3></div>
    <?php if (!$classes): ?><p class="muted small"><?= e(t('Belum ada kelas yang diajar atau diwalikan semester ini.')) ?></p>
    <?php else: ?><div class="list"><?php foreach ($classes as $c): $hr = in_array((int) $c['id'], array_map('intval', array_column($homerooms, 'id')), true); ?>
      <a class="pc" href="<?= e(url('admin/kelas/' . $c['id'])) ?>"><span class="av t"><?= e(mb_substr($c['name'], 0, 3)) ?></span><span class="who"><b><?= e($c['name']) ?></b><span><?= e(t(':n siswa', ['n' => (int) $c['student_count']])) ?><?= $c['room_code'] ? ' · ' . e($c['room_code']) : '' ?></span></span><?php if ($hr): ?><span class="bdg bdg-teal"><?= e(t('Wali kelas')) ?></span><?php endif; ?></a>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
</div>
