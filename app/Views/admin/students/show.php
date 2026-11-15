<div class="card">
  <div class="idcard"><span class="av lg t"><?= e(initials($s['full_name'])) ?></span><div><b><?= e($s['full_name']) ?></b><span><?= e(t('NIS')) ?> <?= e($s['nis']) ?><?= $s['nisn'] ? ' · NISN ' . e($s['nisn']) : '' ?></span></div><span class="bdg <?= $s['status'] === 'Aktif' ? 'bdg-ok' : 'bdg-mute' ?>"><i></i><?= e(t($s['status'])) ?></span></div>
  <dl class="kv">
    <div><dt><?= e(t('Kelas (:y)', ['y' => $activeYear['name']])) ?></dt><dd><?= $class ? '<a href="' . e(url('admin/kelas/' . $class['id'])) . '">' . e($class['name']) . '</a>' : '<span class="bdg bdg-warn">' . e(t('Tanpa kelas')) . '</span>' ?></dd></div>
    <div><dt><?= e(t('Jenis kelamin')) ?></dt><dd><?= e($s['gender'] === 'L' ? t('Laki-laki') : t('Perempuan')) ?></dd></div>
    <div><dt><?= e(t('Tanggal lahir')) ?></dt><dd><?= e($s['birth_date'] ? date_id($s['birth_date']) : '—') ?></dd></div>
    <div><dt><?= e(t('Orang tua/wali')) ?></dt><dd><?= e($s['parent_name'] ?: '—') ?></dd></div>
    <div><dt><?= e(t('HP orang tua')) ?></dt><dd class="mono"><?= e($s['parent_phone'] ?: '—') ?></dd></div>
    <div><dt><?= e(t('Terdaftar')) ?></dt><dd><?= e(datetime_id($s['created_at'])) ?></dd></div>
  </dl>
  <?php if ($canManage): ?><div class="acts">
    <a class="btn btn-outline" href="<?= e(url('admin/siswa/' . $s['id'] . '/ubah')) ?>"><?= icon('edit', 18) ?><?= e(t('Ubah')) ?></a>
    <?= post_button('admin/siswa/' . $s['id'] . '/hapus', 'Hapus', [], 'btn btn-ghost', 'Hapus ' . $s['full_name'] . ' secara permanen? Untuk siswa lulus/pindah, lebih baik ubah statusnya.', 'trash') ?>
  </div><?php endif; ?>
</div>
<div class="card">
  <div class="card-h"><h3><?= e(t('Riwayat kelas')) ?></h3></div>
  <?php if (!$history): ?><p class="muted small"><?= e(t('Belum pernah ditempatkan di kelas.')) ?></p>
  <?php else: ?><div class="sched"><?php foreach ($history as $h): ?><div><span class="mono"><?= e($h['year_name']) ?></span><span><a href="<?= e(url('admin/kelas/' . $h['id'])) ?>"><?= e($h['name']) ?></a><?= (int) $h['is_active'] === 1 ? ' <span class="bdg bdg-ok">' . e(t('Aktif')) . '</span>' : '' ?></span></div><?php endforeach; ?></div><?php endif; ?>
</div>
