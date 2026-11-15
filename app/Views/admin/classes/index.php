<div class="pagehead">
  <div><h2><?= e(t('Kelas')) ?></h2><p><?= e(t('Tahun ajaran :y · :n kelas', ['y' => $activeYear['name'], 'n' => count($classes)])) ?></p></div>
  <?php if ($canManage): ?><div class="row"><a class="btn btn-outline" href="<?= e(url('admin/impor')) ?>"><?= icon('upload', 18) ?><?= e(t('Impor')) ?></a><a class="btn btn-primary" href="<?= e(url('admin/kelas/baru')) ?>"><?= icon('plus', 18) ?><?= e(t('Kelas baru')) ?></a></div><?php endif; ?>
</div>
<?php if (!$classes): ?>
  <div class="card"><div class="empty"><span class="eic"><?= icon('building', 26) ?></span><h3><?= e(t('Belum ada kelas')) ?></h3><p><?= e($canManage ? t('Buat kelas terlebih dahulu; setelah itu siswa bisa diimpor langsung ke kelasnya.') : t('Anda belum ditugaskan ke kelas mana pun semester ini.')) ?></p></div></div>
<?php else: ?>
  <div class="tblw only-wide"><table class="tbl">
    <thead><tr><th><?= e(t('Kelas')) ?></th><th><?= e(t('Tingkat')) ?></th><th><?= e(t('Wali kelas')) ?></th><th><?= e(t('Ruangan')) ?></th><th class="r"><?= e(t('Siswa')) ?></th></tr></thead>
    <tbody><?php foreach ($classes as $c): ?>
      <tr><td><a class="nm" href="<?= e(url('admin/kelas/' . $c['id'])) ?>"><?= e($c['name']) ?></a></td><td><?= (int) $c['grade'] ?></td><td style="white-space:normal"><?= $c['homeroom_name'] ? e($c['homeroom_name']) : '<span class="bdg bdg-warn">' . e(t('Belum ada')) . '</span>' ?></td><td><?= e($c['room_code'] ? $c['room_code'] . ' · ' . $c['room_name'] : '—') ?></td><td class="r num"><?= (int) $c['student_count'] ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <div class="list only-narrow"><?php foreach ($classes as $c): ?>
    <a class="pc" href="<?= e(url('admin/kelas/' . $c['id'])) ?>"><span class="av t"><?= e(mb_substr($c['name'], 0, 3)) ?></span><span class="who"><b><?= e($c['name']) ?></b><span><?= e($c['homeroom_name'] ?: t('Belum ada wali kelas')) ?><?= $c['room_code'] ? ' · ' . e($c['room_code']) : '' ?></span></span><span class="bdg bdg-mute"><?= (int) $c['student_count'] ?></span></a>
  <?php endforeach; ?></div>
<?php endif; ?>
