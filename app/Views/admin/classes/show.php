<div class="pagehead">
  <div><h2><?= e($c['name']) ?> <?php if ($isHomeroom): ?><span class="bdg bdg-teal"><?= e(t('Anda wali kelas')) ?></span><?php endif; ?></h2><p><?= e(t('Tingkat :g · Wali kelas: :w · Ruangan: :r', ['g' => $c['grade'], 'w' => $c['homeroom_name'] ?: t('belum ada'), 'r' => $c['room_code'] ? $c['room_code'] . ' ' . $c['room_name'] : t('belum ada')])) ?></p></div>
  <?php if ($canManage): ?><div class="row">
    <a class="btn btn-outline" href="<?= e(url('admin/kelas/' . $c['id'] . '/ubah')) ?>"><?= icon('edit', 18) ?><?= e(t('Ubah')) ?></a>
    <a class="btn btn-outline" href="<?= e(url('admin/jadwal', ['kelas' => $c['id']])) ?>"><?= icon('calendar', 18) ?><?= e(t('Jadwal')) ?></a>
    <a class="btn btn-outline" href="<?= e(url('admin/kartu/cetak', ['kelas' => $c['id']])) ?>" target="_blank"><?= icon('qr', 18) ?><?= e(t('Cetak kartu')) ?></a>
    <?= post_button('admin/kelas/' . $c['id'] . '/kode-portal', 'Kode portal (CSV)', [], 'btn btn-outline', 'Buat kode akses portal orang tua untuk siswa yang belum punya, lalu unduh CSV-nya?', 'key') ?>
    <?php if ((int) $c['student_count'] === 0): ?><?= post_button('admin/kelas/' . $c['id'] . '/hapus', 'Hapus', [], 'btn btn-ghost', 'Hapus kelas ' . $c['name'] . '?', 'trash') ?><?php endif; ?>
  </div><?php endif; ?>
</div>
<div class="g-2">
  <div class="card">
    <div class="card-h"><h3><?= e(t('Siswa')) ?></h3><span class="sub"><?= e(t(':n siswa', ['n' => count($members)])) ?></span></div>
    <?php if (!$members): ?><p class="muted small"><?= e(t('Belum ada siswa di kelas ini.')) ?></p>
    <?php else: ?><div class="tblw"><table class="tbl">
      <thead><tr><th>#</th><th><?= e(t('NIS')) ?></th><th><?= e(t('Nama')) ?></th><th><?= e(t('L/P')) ?></th><th><?= e(t('Status')) ?></th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
      <tbody><?php foreach ($members as $i => $s): ?>
        <tr><td class="num muted"><?= $i + 1 ?></td><td class="mono"><?= e($s['nis']) ?></td><td><a class="nm" href="<?= e(url('admin/siswa/' . $s['id'])) ?>"><?= e($s['full_name']) ?></a></td><td><?= e($s['gender']) ?></td><td><span class="bdg <?= $s['status'] === 'Aktif' ? 'bdg-ok' : 'bdg-mute' ?>"><?= e(t($s['status'])) ?></span></td>
        <?php if ($canManage): ?><td class="r"><?= post_button('admin/kelas/' . $c['id'] . '/anggota/' . $s['id'] . '/keluarkan', 'Keluarkan', [], 'btn btn-ghost sm', 'Keluarkan ' . $s['full_name'] . ' dari ' . $c['name'] . '? Data siswa tetap ada, hanya tidak punya kelas.') ?></td><?php endif; ?></tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </div>
  <div class="stack">
    <?php if ($canManage): ?>
    <div class="card">
      <div class="card-h"><h3><?= e(t('Tambah siswa ke kelas')) ?></h3><a class="btn btn-ghost sm" href="<?= e(url('admin/siswa/baru', ['kelas' => $c['id']])) ?>"><?= icon('userplus', 16) ?><?= e(t('Siswa baru')) ?></a></div>
      <form method="get" action="<?= e(url('admin/kelas/' . $c['id'])) ?>" class="inp-ic"><?= icon('search', 18) ?><input class="inp" type="search" name="cari" value="<?= e($q) ?>" placeholder="<?= e(t('Cari siswa aktif tanpa kelas (NIS/nama)…')) ?>" aria-label="<?= e(t('Cari')) ?>"></form>
      <?php if (!$unplaced): ?><p class="muted small" style="margin-top:10px"><?= e($q !== '' ? t('Tidak ada siswa tanpa kelas yang cocok.') : t('Semua siswa aktif sudah punya kelas.')) ?></p>
      <?php else: ?>
      <form method="post" action="<?= e(url('admin/kelas/' . $c['id'] . '/anggota')) ?>" class="stack" style="margin-top:10px"><?= csrf_field() ?>
        <div class="list" style="max-height:320px;overflow:auto"><?php foreach ($unplaced as $s): ?>
          <label class="chk"><input type="checkbox" name="student_ids[]" value="<?= (int) $s['id'] ?>"><span><b><?= e($s['full_name']) ?></b> <span class="mono small muted"><?= e($s['nis']) ?></span></span></label>
        <?php endforeach; ?></div>
        <div class="acts"><button class="btn btn-primary" type="submit"><?= icon('plus', 18) ?><?= e(t('Masukkan ke :c', ['c' => $c['name']])) ?></button></div>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="card">
      <div class="card-h"><h3><?= e(t('Jadwal')) ?></h3><span class="sub"><?= $activeSemester ? e(t('Semester :s', ['s' => $activeSemester['name']])) : e(t('Belum ada semester aktif')) ?></span></div>
      <?php if (!$schedules): ?><p class="muted small"><?= e(t('Belum ada jadwal.')) ?></p><?php if ($canManage): ?><a class="btn btn-outline sm" href="<?= e(url('admin/jadwal', ['kelas' => $c['id']])) ?>"><?= e(t('Susun jadwal')) ?></a><?php endif; ?>
      <?php else: ?><div class="sched"><?php $lastDay = 0; foreach ($schedules as $s): ?>
        <div><span class="mono"><?= (int) $s['day_of_week'] !== $lastDay ? e(day_name((int) $s['day_of_week'], true)) : '' ?> <?= e($s['period_start']) ?>–<?= e($s['period_end']) ?></span><span><b><?= e($s['subject_name']) ?></b><?= $s['teacher_name'] ? ' · ' . e($s['teacher_name']) : '' ?><?= $s['room_code'] ? ' · ' . e($s['room_code']) : '' ?></span></div>
      <?php $lastDay = (int) $s['day_of_week']; endforeach; ?></div><?php endif; ?>
    </div>
  </div>
</div>
