<?php use App\Core\Auth; ?>
<div class="pagehead">
  <div><h2><?= e(t('Akun Pengguna')) ?></h2><p><?= e(t('Guru, TU/Staff, Kepala Sekolah, dan Super Admin. Wali kelas ditentukan di halaman kelas.')) ?></p></div>
  <div class="row">
    <a class="btn btn-outline" href="<?= e(url('admin/akun/baru', ['peran' => Auth::TU])) ?>"><?= icon('userplus', 18) ?><?= e(t('TU / Staff')) ?></a>
    <a class="btn btn-primary" href="<?= e(url('admin/akun/baru', ['peran' => Auth::GURU])) ?>"><?= icon('userplus', 18) ?><?= e(t('Guru')) ?></a>
  </div>
</div>
<form method="get" action="<?= e(url('admin/akun')) ?>" class="filters">
  <input type="hidden" name="peran" value="<?= e($role) ?>">
  <div class="search inp-ic"><?= icon('search', 18) ?><input class="inp" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Cari nama, username, NIP…')) ?>" aria-label="<?= e(t('Cari')) ?>"></div>
  <div class="chips">
    <a class="chip<?= $role === '' ? ' on' : '' ?>" href="<?= e(url('admin/akun', ['q' => $q])) ?>"><?= e(t('Semua')) ?> <span class="muted"><?= array_sum($counts) ?></span></a>
    <?php foreach (Auth::ROLE_LABELS as $k => $label): ?>
      <a class="chip<?= $role === $k ? ' on' : '' ?>" href="<?= e(url('admin/akun', ['peran' => $k, 'q' => $q])) ?>"><?= e(t($label)) ?> <span class="muted"><?= (int) ($counts[$k] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </div>
</form>
<?php if (!$users): ?>
  <div class="card"><div class="empty"><span class="eic"><?= icon('shield', 26) ?></span><h3><?= e(t('Tidak ada akun')) ?></h3><p><?= e(t('Buat akun untuk guru dan staf, atau impor jadwal yang otomatis mencocokkan NIP guru.')) ?></p></div></div>
<?php else: ?>
  <div class="tblw only-wide">
    <table class="tbl">
      <thead><tr><th><?= e(t('Nama')) ?></th><th><?= e(t('Username')) ?></th><th><?= e(t('Peran')) ?></th><th><?= e(t('NIP')) ?></th><th><?= e(t('Tugas')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Login terakhir')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($users as $a): ?>
        <tr>
          <td><a class="nm" href="<?= e(url('admin/akun/' . $a['id'])) ?>"><?= e($a['name']) ?></a></td>
          <td class="mono">@<?= e($a['username']) ?></td>
          <td><span class="bdg <?= $a['role'] === Auth::SUPER ? 'bdg-navy' : ($a['role'] === Auth::GURU ? 'bdg-teal' : 'bdg-info') ?>"><?= e(Auth::roleLabel($a['role'])) ?></span></td>
          <td class="mono small"><?= e($a['nip'] ?: '—') ?></td>
          <td style="white-space:normal"><?= $a['role'] === Auth::GURU ? e(t(':h kelas perwalian · :s jadwal', ['h' => (int) $a['homeroom_count'], 's' => (int) $a['schedule_count']])) : '—' ?></td>
          <td><span class="bdg <?= $a['status'] === 'Aktif' ? 'bdg-ok' : 'bdg-mute' ?>"><i></i><?= e(t($a['status'])) ?></span><?php if ((int) $a['must_change_password'] === 1): ?> <span class="bdg bdg-warn"><?= e(t('Password sementara')) ?></span><?php endif; ?></td>
          <td><?= e(datetime_id($a['last_login_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="list only-narrow">
    <?php foreach ($users as $a): ?>
      <a class="pc" href="<?= e(url('admin/akun/' . $a['id'])) ?>"><span class="av t"><?= e(initials($a['name'])) ?></span><span class="who"><b><?= e($a['name']) ?></b><span>@<?= e($a['username']) ?> · <?= e(Auth::roleLabel($a['role'])) ?></span></span><span class="bdg <?= $a['status'] === 'Aktif' ? 'bdg-ok' : 'bdg-mute' ?>"><?= e(t($a['status'])) ?></span></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
