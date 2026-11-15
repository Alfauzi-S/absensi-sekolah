<?php use App\Core\Auth; ?>
<div class="card">
  <div class="state s-warn">
    <span class="sic"><?= icon('lock', 30) ?></span>
    <span class="bdg bdg-mute mono"><?= e(t('Kode :code', ['code' => 403])) ?></span>
    <h3><?= e(t('Akses ditolak')) ?></h3>
    <?php if (($user['role'] ?? '') === Auth::GURU): ?>
      <p><?= e(t('Akun Guru hanya dapat membuka kelas yang diajar atau diwalikan. Data lain dikelola oleh TU/Staff.')) ?></p>
    <?php elseif (($user['role'] ?? '') === Auth::KEPSEK): ?>
      <p><?= e(t('Akun Kepala Sekolah hanya melihat data dan rekap; perubahan data dilakukan oleh TU/Staff atau Super Admin.')) ?></p>
    <?php else: ?>
      <p><?= e(t('Halaman ini hanya untuk peran tertentu. Hubungi Super Admin bila Anda merasa seharusnya punya akses.')) ?></p>
    <?php endif; ?>
    <div class="acts"><a class="btn btn-primary" href="<?= e(url('admin')) ?>"><?= e(t('Kembali ke beranda')) ?></a></div>
  </div>
</div>
