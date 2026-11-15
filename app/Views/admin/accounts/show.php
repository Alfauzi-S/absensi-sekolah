<?php use App\Core\Auth; $self = (int) $u['id'] === (int) $user['id']; ?>
<?php if ($cred): ?>
  <div class="card">
    <div class="card-h"><h3><?= e(t('Password sementara')) ?></h3><span class="bdg bdg-warn"><?= e(t('Tampil sekali')) ?></span></div>
    <div class="cred"><span><?= e(t('Username')) ?> <b class="mono"><?= e($cred['username']) ?></b></span><span><?= e(t('Password')) ?> <b class="mono" id="cred-pass"><?= e($cred['password']) ?></b></span><button type="button" class="btn btn-outline sm" data-copy="#cred-pass"><?= icon('copy', 16) ?><?= e(t('Salin')) ?></button></div>
    <p class="small muted"><?= e(t('Sampaikan ke pemilik akun secara pribadi. Password ini wajib diganti saat login pertama.')) ?></p>
  </div>
<?php endif; ?>
<div class="card">
  <div class="idcard"><span class="av lg<?= $u['role'] === Auth::SUPER ? '' : ' t' ?>"><?= e(initials($u['name'])) ?></span><div><b><?= e($u['name']) ?></b><span>@<?= e($u['username']) ?> · <?= e(Auth::roleLabel($u['role'])) ?></span></div>
    <span class="bdg <?= $u['status'] === 'Aktif' ? 'bdg-ok' : 'bdg-mute' ?>"><i></i><?= e(t($u['status'])) ?></span></div>
  <dl class="kv">
    <div><dt><?= e(t('Peran')) ?></dt><dd><?= e(Auth::roleLabel($u['role'])) ?></dd></div>
    <div><dt><?= e(t('NIP / NUPTK')) ?></dt><dd class="mono"><?= e($u['nip'] ?: '—') ?></dd></div>
    <div><dt><?= e(t('Nomor HP')) ?></dt><dd><?= e($u['phone'] ?: '—') ?></dd></div>
    <div><dt><?= e(t('Login terakhir')) ?></dt><dd><?= e(datetime_id($u['last_login_at'])) ?></dd></div>
    <div><dt><?= e(t('Password')) ?></dt><dd><?= (int) $u['must_change_password'] === 1 ? '<span class="bdg bdg-warn">' . e(t('Sementara, belum diganti')) . '</span>' : e(t('Sudah diganti pemilik')) ?></dd></div>
    <div><dt><?= e(t('Dibuat')) ?></dt><dd><?= e(datetime_id($u['created_at'])) ?></dd></div>
  </dl>
  <p class="small muted"><?= e(t(Auth::ROLE_HINTS[$u['role']] ?? '')) ?></p>
  <div class="acts">
    <a class="btn btn-outline" href="<?= e(url('admin/akun/' . $u['id'] . '/ubah')) ?>"><?= icon('edit', 18) ?><?= e(t('Ubah')) ?></a>
    <?= post_button('admin/akun/' . $u['id'] . '/reset', 'Reset password', [], 'btn btn-outline', 'Buat password sementara baru untuk akun ini? Password lama tidak berlaku lagi.', 'key') ?>
    <?php if (!$self): ?>
      <?= post_button('admin/akun/' . $u['id'] . '/status', $u['status'] === 'Aktif' ? 'Nonaktifkan' : 'Aktifkan', [], $u['status'] === 'Aktif' ? 'btn btn-danger' : 'btn btn-secondary', $u['status'] === 'Aktif' ? 'Nonaktifkan akun ini? Pemilik tidak bisa login sampai diaktifkan lagi.' : '', 'power') ?>
    <?php endif; ?>
  </div>
</div>
<?php if ($u['role'] === Auth::GURU): ?>
<div class="g-2">
  <div class="card">
    <div class="card-h"><h3><?= e(t('Wali kelas')) ?></h3></div>
    <?php if (!$homerooms): ?><p class="muted small"><?= e(t('Bukan wali kelas pada tahun ajaran aktif. Tetapkan lewat halaman kelas.')) ?></p>
    <?php else: ?><div class="list"><?php foreach ($homerooms as $c): ?><a class="pc" href="<?= e(url('admin/kelas/' . $c['id'])) ?>"><span class="who"><b><?= e($c['name']) ?></b><span><?= e(t(':n siswa', ['n' => (int) $c['student_count']])) ?></span></span></a><?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-h"><h3><?= e(t('Jadwal mengajar')) ?></h3><span class="sub"><?= e(t(':n jadwal', ['n' => count($schedules)])) ?></span></div>
    <?php if (!$schedules): ?><p class="muted small"><?= e(t('Belum ada jadwal di semester aktif.')) ?></p>
    <?php else: ?><div class="sched"><?php foreach ($schedules as $s): ?>
      <div><span class="mono"><?= e(day_name((int) $s['day_of_week'], true)) ?> <?= e($s['period_start']) ?>–<?= e($s['period_end']) ?></span><span><b><?= e($s['subject_name']) ?></b> · <a href="<?= e(url('admin/kelas/' . $s['class_id'])) ?>"><?= e($s['class_name']) ?></a></span></div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>
