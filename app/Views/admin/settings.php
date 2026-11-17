<?php use App\Models\Setting; $cls = static fn (string $k) => 'fld' . (isset($errors[$k]) ? ' is-err' : ''); $weekend = array_map('intval', array_filter(explode(',', $s['weekend_days']))); ?>
<div class="card">
  <div class="idcard"><span class="av lg"><?= e(initials($user['name'])) ?></span><div><b><?= e($user['name']) ?></b><span>@<?= e($user['username']) ?> · <?= e(\App\Core\Auth::roleLabel($user['role'])) ?></span></div></div>
  <dl class="kv"><div><dt><?= e(t('Login terakhir')) ?></dt><dd><?= e(datetime_id($user['last_login_at'])) ?></dd></div><div><dt><?= e(t('Zona waktu sistem')) ?></dt><dd><?= e(config('timezone')) ?> (<?= e(config('timezone_label')) ?>)</dd></div></dl>
</div>
<form method="post" action="<?= e(url('admin/pengaturan/profil')) ?>" class="card">
  <?= csrf_field() ?>
  <div class="card-h"><h3><?= e(t('Profil')) ?></h3></div>
  <div class="g-form two">
    <div class="fld"><label for="name"><?= e(t('Nama tampilan')) ?></label><input class="inp" id="name" name="name" maxlength="120" required value="<?= e($user['name']) ?>"></div>
    <div class="fld"><label for="phone"><?= e(t('Nomor HP')) ?></label><input class="inp" id="phone" name="phone" inputmode="tel" value="<?= e((string) $user['phone']) ?>"></div>
  </div>
  <div><button class="btn btn-primary" type="submit"><?= e(t('Simpan profil')) ?></button></div>
</form>
<?= \App\Core\View::partial('password_form', ['action' => 'admin/pengaturan/password', 'mustChange' => (int) $user['must_change_password'] === 1]) ?>
<?php if ($canSchool): ?>
<form method="post" action="<?= e(url('admin/pengaturan/sekolah')) ?>" class="card" novalidate data-loading="<?= e(t('Menyimpan…')) ?>">
  <?= csrf_field() ?>
  <div class="card-h"><h3><?= e(t('Pengaturan sekolah')) ?></h3><span class="sub"><?= e(t('Khusus Super Admin')) ?></span></div>
  <?php if ($errors): ?><div class="banner bad"><?= icon('alert') ?><div class="grow"><?= e(t('Periksa kolom yang ditandai.')) ?></div></div><?php endif; ?>
  <h4 class="sec-t"><?= e(t('Identitas')) ?></h4>
  <div class="g-form two">
    <div class="<?= $cls('school_name') ?>"><label for="school_name"><?= e(t('Nama sekolah')) ?></label><input class="inp" id="school_name" name="school_name" maxlength="120" value="<?= e($s['school_name']) ?>"><?= field_error($errors, 'school_name') ?></div>
    <div class="<?= $cls('school_npsn') ?>"><label for="school_npsn"><?= e(t('NPSN')) ?></label><input class="inp mono" id="school_npsn" name="school_npsn" maxlength="20" value="<?= e($s['school_npsn']) ?>"><?= field_error($errors, 'school_npsn') ?></div>
    <div class="<?= $cls('headmaster_name') ?>"><label for="headmaster_name"><?= e(t('Nama kepala sekolah')) ?><span class="en"><?= e(t('untuk tanda tangan rekap')) ?></span></label><input class="inp" id="headmaster_name" name="headmaster_name" maxlength="120" value="<?= e($s['headmaster_name']) ?>"></div>
    <div class="<?= $cls('school_address') ?>"><label for="school_address"><?= e(t('Alamat')) ?></label><input class="inp" id="school_address" name="school_address" maxlength="200" value="<?= e($s['school_address']) ?>"></div>
  </div>
  <h4 class="sec-t"><?= e(t('Mode absensi')) ?></h4>
  <div class="g-form two">
    <div class="<?= $cls('attendance_mode') ?>"><label for="attendance_mode"><?= e(t('Cara mencatat kehadiran')) ?></label>
      <select class="inp" id="attendance_mode" name="attendance_mode"><?php foreach (Setting::ATT_MODES as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($s['attendance_mode'], $k) ?>><?= e(t($label)) ?></option><?php endforeach; ?></select>
      <p class="hint"><?= e(t('Masuk/pulang: satu scan pagi dan satu scan sore. Per jam pelajaran: scan di setiap pergantian jam sesuai jadwal.')) ?></p><?= field_error($errors, 'attendance_mode') ?></div>
    <div class="<?= $cls('scanner_mode') ?>"><label for="scanner_mode"><?= e(t('Perangkat scanner di kelas')) ?></label>
      <select class="inp" id="scanner_mode" name="scanner_mode"><?php foreach (Setting::SCANNER_MODES as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($s['scanner_mode'], $k) ?>><?= e(t($label)) ?></option><?php endforeach; ?></select><?= field_error($errors, 'scanner_mode') ?></div>
  </div>
  <h4 class="sec-t"><?= e(t('Jam absen masuk & pulang')) ?></h4>
  <div class="g-form two">
    <div class="<?= $cls('checkin_start') ?>"><label for="checkin_start"><?= e(t('Absen masuk dibuka')) ?></label><input class="inp" id="checkin_start" name="checkin_start" type="time" value="<?= e($s['checkin_start']) ?>"><?= field_error($errors, 'checkin_start') ?></div>
    <div class="<?= $cls('checkin_end') ?>"><label for="checkin_end"><?= e(t('Absen masuk ditutup')) ?></label><input class="inp" id="checkin_end" name="checkin_end" type="time" value="<?= e($s['checkin_end']) ?>"><?= field_error($errors, 'checkin_end') ?></div>
    <div class="<?= $cls('late_after') ?>"><label for="late_after"><?= e(t('Dihitung terlambat setelah')) ?></label><input class="inp" id="late_after" name="late_after" type="time" value="<?= e($s['late_after']) ?>"><?= field_error($errors, 'late_after') ?></div>
    <div class="<?= $cls('late_tolerance_minutes') ?>"><label for="late_tolerance_minutes"><?= e(t('Toleransi terlambat (menit)')) ?></label><input class="inp" id="late_tolerance_minutes" name="late_tolerance_minutes" type="number" min="0" max="120" value="<?= e($s['late_tolerance_minutes']) ?>"><?= field_error($errors, 'late_tolerance_minutes') ?></div>
    <div class="<?= $cls('checkout_start') ?>"><label for="checkout_start"><?= e(t('Absen pulang dibuka')) ?></label><input class="inp" id="checkout_start" name="checkout_start" type="time" value="<?= e($s['checkout_start']) ?>"><?= field_error($errors, 'checkout_start') ?></div>
    <div class="<?= $cls('checkout_end') ?>"><label for="checkout_end"><?= e(t('Absen pulang ditutup')) ?></label><input class="inp" id="checkout_end" name="checkout_end" type="time" value="<?= e($s['checkout_end']) ?>"><?= field_error($errors, 'checkout_end') ?></div>
  </div>
  <div class="fld"><label><?= e(t('Hari libur mingguan')) ?></label>
    <div class="chips"><?php for ($d = 1; $d <= 7; $d++): ?><label class="chip<?= in_array($d, $weekend, true) ? ' on' : '' ?>"><input type="checkbox" name="weekend_days[]" value="<?= $d ?>"<?= in_array($d, $weekend, true) ? ' checked' : '' ?> style="margin:0"><?= e(day_name($d)) ?></label><?php endfor; ?></div></div>
  <h4 class="sec-t" id="wa"><?= e(t('Notifikasi WhatsApp ke orang tua')) ?></h4>
  <div class="g-form two">
    <div class="<?= $cls('wa_notify') ?>"><label for="wa_notify"><?= e(t('Kirim notifikasi')) ?></label>
      <select class="inp" id="wa_notify" name="wa_notify"><?php foreach (Setting::WA_MODES as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($s['wa_notify'], $k) ?>><?= e(t($label)) ?></option><?php endforeach; ?></select>
      <p class="hint"><?= e(t('Satu pesan per siswa per hari. Terlambat dikirim saat scan; Alpa dikirim setelah semua sesi hari itu ditutup. Butuh cron bin/close-sessions.php tiap 5 menit.')) ?></p><?= field_error($errors, 'wa_notify') ?></div>
    <div class="<?= $cls('wa_provider') ?>"><label for="wa_provider"><?= e(t('Penyedia gateway')) ?></label>
      <select class="inp" id="wa_provider" name="wa_provider"><?php foreach (Setting::WA_PROVIDERS as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($s['wa_provider'], $k) ?>><?= e(t($label)) ?></option><?php endforeach; ?></select>
      <p class="hint"><?= e(t('Fonnte: isi token dari dasbor Fonnte (URL boleh kosong). Webhook: aplikasi mengirim POST JSON {phone, message} ke URL dengan header Authorization: Bearer token.')) ?></p><?= field_error($errors, 'wa_provider') ?></div>
    <div class="<?= $cls('wa_gateway_url') ?>"><label for="wa_gateway_url"><?= e(t('URL gateway')) ?></label><input class="inp mono" id="wa_gateway_url" name="wa_gateway_url" placeholder="https://" value="<?= e($s['wa_gateway_url']) ?>"><?= field_error($errors, 'wa_gateway_url') ?></div>
    <div class="<?= $cls('wa_gateway_token') ?>"><label for="wa_gateway_token"><?= e(t('Token gateway')) ?></label><input class="inp mono" id="wa_gateway_token" name="wa_gateway_token" type="password" autocomplete="off" placeholder="<?= $s['wa_gateway_token'] !== '' ? e(t('tersimpan; kosongkan bila tidak diubah')) : '' ?>"><?= field_error($errors, 'wa_gateway_token') ?></div>
    <div class="<?= $cls('wa_template_alpa') ?>"><label for="wa_template_alpa"><?= e(t('Pesan Alpa')) ?></label><textarea class="inp" id="wa_template_alpa" name="wa_template_alpa" rows="3" maxlength="600"><?= e($s['wa_template_alpa']) ?></textarea><?= field_error($errors, 'wa_template_alpa') ?></div>
    <div class="<?= $cls('wa_template_late') ?>"><label for="wa_template_late"><?= e(t('Pesan Terlambat')) ?></label><textarea class="inp" id="wa_template_late" name="wa_template_late" rows="3" maxlength="600"><?= e($s['wa_template_late']) ?></textarea><?= field_error($errors, 'wa_template_late') ?></div>
  </div>
  <p class="hint"><?= e(t('Isian yang bisa dipakai: {nama} {kelas} {tanggal} {jam} {menit} {sekolah}. Halaman riwayat dan antrean pesan: menu Notifikasi WA.')) ?></p>
  <h4 class="sec-t"><?= e(t('Portal orang tua')) ?></h4>
  <div class="g-form two">
    <div class="<?= $cls('portal_enabled') ?>"><label for="portal_enabled"><?= e(t('Portal riwayat kehadiran')) ?></label>
      <select class="inp" id="portal_enabled" name="portal_enabled"><option value="0"<?= selected($s['portal_enabled'], '0') ?>><?= e(t('Mati')) ?></option><option value="1"<?= selected($s['portal_enabled'], '1') ?>><?= e(t('Nyala')) ?></option></select>
      <p class="hint"><?= e(t('Orang tua/siswa masuk di :u dengan NIS dan kode akses yang dibuat TU (halaman siswa atau kelas). Hanya bisa melihat riwayat anak sendiri.', ['u' => full_url('portal')])) ?></p><?= field_error($errors, 'portal_enabled') ?></div>
  </div>
  <div><button class="btn btn-primary" type="submit"><?= e(t('Simpan pengaturan sekolah')) ?></button></div>
</form>
<?php endif; ?>
<form method="post" action="<?= e(url('keluar')) ?>" data-confirm="<?= e(t('Keluar dari akun ini?')) ?>"><?= csrf_field() ?><button class="btn btn-outline block" type="submit"><?= icon('logout', 18) ?><?= e(t('Keluar')) ?></button></form>
