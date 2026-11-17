<?php use App\Core\Auth; use App\Models\AuditLog; ?>
<?php if (!empty($pendingMigrations)): ?>
  <div class="banner warn" role="status"><?= icon('alert') ?><div class="grow"><b><?= e(t('Pembaruan database tersedia')) ?></b><?= e(t('Migrasi aditif belum dijalankan (:list). Cadangkan database terlebih dahulu.', ['list' => implode(', ', $pendingMigrations)])) ?></div>
    <?= post_button('admin/migrasi', t('Jalankan migrasi'), [], 'btn btn-primary sm', t('Jalankan migrasi database sekarang? Pastikan database sudah dicadangkan.'), 'refresh') ?></div>
<?php endif; ?>
<?php if (!$activeYear): ?>
  <div class="card"><div class="empty"><span class="eic"><?= icon('calendar', 26) ?></span><h3><?= e(t('Belum ada tahun ajaran aktif')) ?></h3><p><?= e(t('Mulai dengan membuat tahun ajaran dan semester. Setelah itu tambahkan kelas, siswa, dan jadwal.')) ?></p>
    <?php if (can('master')): ?><a class="btn btn-primary" href="<?= e(url('admin/tahun-ajaran')) ?>"><?= e(t('Buat tahun ajaran')) ?></a><?php endif; ?></div></div>
<?php else: ?>
  <div class="pagehead">
    <div><h2><?= e(\App\Models\Setting::get('school_name') ?: t('Dashboard')) ?></h2><p><?= e(t('Tahun ajaran :y', ['y' => $activeYear['name']])) ?><?= $activeSemester ? ' · ' . e(t('Semester :s', ['s' => $activeSemester['name']])) : ' · ' . e(t('Belum ada semester aktif')) ?></p></div>
    <?php if (can('master')): ?><div class="row"><a class="btn btn-outline" href="<?= e(url('admin/impor')) ?>"><?= icon('upload', 18) ?><?= e(t('Impor data')) ?></a><a class="btn btn-primary" href="<?= e(url('admin/siswa/baru')) ?>"><?= icon('userplus', 18) ?><?= e(t('Siswa baru')) ?></a></div><?php endif; ?>
  </div>
  <?php if (!empty($att)): ?><?php include __DIR__ . '/../partials/attendance_overview.php'; ?><p class="sec-t" style="margin:22px 0 12px"><?= e(t('Data sekolah')) ?></p><?php endif; ?>
  <div class="g-stats">
    <a class="stat" href="<?= e(url('admin/siswa')) ?>"><span class="k"><span class="dot t-ok"><?= icon('users', 14) ?></span><?= e(t('Siswa aktif')) ?></span><span class="v"><?= (int) $counts['students']['active'] ?></span><span class="s"><?= e(t(':n total terdaftar', ['n' => (int) $counts['students']['total']])) ?></span></a>
    <a class="stat" href="<?= e(url('admin/kelas')) ?>"><span class="k"><span class="dot t-navy"><?= icon('building', 14) ?></span><?= e(t('Kelas')) ?></span><span class="v"><?= (int) $counts['classes'] ?></span><span class="s"><?= e(t(':n tanpa wali kelas', ['n' => (int) $counts['classesNoHomeroom']])) ?></span></a>
    <a class="stat" href="<?= e(url('admin/akun', ['peran' => Auth::GURU])) ?>"><span class="k"><span class="dot t-info"><?= icon('user', 14) ?></span><?= e(t('Guru aktif')) ?></span><span class="v"><?= (int) $counts['teachers'] ?></span><span class="s"><?= e(t(':n ruangan · :m mapel', ['n' => (int) $counts['rooms'], 'm' => (int) $counts['subjects']])) ?></span></a>
    <a class="stat" href="<?= e(url('admin/jadwal')) ?>"><span class="k"><span class="dot t-warn"><?= icon('calendar', 14) ?></span><?= e(t('Jadwal semester ini')) ?></span><span class="v"><?= (int) $counts['schedules'] ?></span><span class="s"><?= e(t(':n kelas belum punya jadwal', ['n' => (int) $counts['classesNoSchedule']])) ?></span></a>
  </div>
  <?php if ($counts['unplaced'] > 0 && can('master')): ?>
    <div class="banner info"><?= icon('info') ?><div class="grow"><b><?= e(t(':n siswa aktif belum masuk kelas', ['n' => (int) $counts['unplaced']])) ?></b><?= e(t('Siswa tanpa kelas tidak bisa absen. Tempatkan lewat halaman kelas atau impor.')) ?></div><a class="btn btn-outline sm" href="<?= e(url('admin/siswa', ['kelas' => 'tanpa'])) ?>"><?= e(t('Lihat')) ?></a></div>
  <?php endif; ?>
  <div class="g-2">
    <div class="card">
      <div class="card-h"><h3><?= e(t('Kelas')) ?></h3><a class="btn btn-ghost sm" href="<?= e(url('admin/kelas')) ?>"><?= e(t('Semua kelas')) ?></a></div>
      <?php if (!$classes): ?><p class="muted small"><?= e(t('Belum ada kelas di tahun ajaran ini.')) ?></p>
      <?php else: ?>
      <div class="tblw"><table class="tbl">
        <thead><tr><th><?= e(t('Kelas')) ?></th><th><?= e(t('Wali kelas')) ?></th><th><?= e(t('Ruangan')) ?></th><th class="r"><?= e(t('Siswa')) ?></th></tr></thead>
        <tbody><?php foreach (array_slice($classes, 0, 12) as $c): ?>
          <tr><td><a class="nm" href="<?= e(url('admin/kelas/' . $c['id'])) ?>"><?= e($c['name']) ?></a></td><td style="white-space:normal"><?= e($c['homeroom_name'] ?? '—') ?></td><td><?= e($c['room_code'] ?? '—') ?></td><td class="r num"><?= (int) $c['student_count'] ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php if (count($classes) > 12): ?><p class="small muted"><?= e(t('Menampilkan 12 dari :n kelas.', ['n' => count($classes)])) ?></p><?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-h"><h3><?= e(t('Kelengkapan data master')) ?></h3></div>
      <dl class="kv">
        <div><dt><?= e(t('Jam pelajaran')) ?></dt><dd><?= (int) $counts['periods'] ?: '<span class="bdg bdg-warn">' . e(t('Belum ada')) . '</span>' ?></dd></div>
        <div><dt><?= e(t('Ruangan')) ?></dt><dd><?= (int) $counts['rooms'] ?: '<span class="bdg bdg-warn">' . e(t('Belum ada')) . '</span>' ?></dd></div>
        <div><dt><?= e(t('Mata pelajaran')) ?></dt><dd><?= (int) $counts['subjects'] ?: '<span class="bdg bdg-warn">' . e(t('Belum ada')) . '</span>' ?></dd></div>
        <div><dt><?= e(t('Semester aktif')) ?></dt><dd><?= $activeSemester ? e($activeSemester['name']) : '<span class="bdg bdg-warn">' . e(t('Belum ada')) . '</span>' ?></dd></div>
      </dl>
      <?php if (can('master')): ?><div class="stack" style="gap:8px;margin-top:10px">
        <a class="btn btn-outline block" href="<?= e(url('admin/master/jam')) ?>"><?= icon('clock', 18) ?><?= e(t('Atur jam pelajaran')) ?></a>
        <a class="btn btn-outline block" href="<?= e(url('admin/jadwal')) ?>"><?= icon('calendar', 18) ?><?= e(t('Susun jadwal')) ?></a>
      </div><?php endif; ?>
    </div>
  </div>
  <?php if ($recent): ?>
  <div class="card">
    <div class="card-h"><h3><?= e(t('Aktivitas terbaru')) ?></h3><a class="btn btn-ghost sm" href="<?= e(url('admin/audit')) ?>"><?= e(t('Audit log')) ?></a></div>
    <div class="hist"><?php foreach ($recent as $l): ?>
      <div class="sched" style="display:grid;grid-template-columns:120px minmax(0,1fr);gap:12px;padding:8px 0;border-bottom:1px solid var(--border);font-size:14px"><span class="mono small muted"><?= e(datetime_id($l['created_at'])) ?></span><span><b><?= e(AuditLog::label($l['action'])) ?></b> <span class="muted">· @<?= e($l['username'] ?? '—') ?><?= $l['class_name'] ? ' · ' . e($l['class_name']) : '' ?></span></span></div>
    <?php endforeach; ?></div>
  </div>
  <?php endif; ?>
<?php endif; ?>
