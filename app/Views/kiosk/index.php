<?php
use App\Core\View;
$camera = in_array($scannerMode, ['camera', 'both'], true);
if ($camera) {
    View::script('vendor/html5-qrcode.min.js');
}
View::script('js/kiosk.js');
?>
<div class="kiosk" id="kiosk"
     data-pair="<?= e(url('api/device/pair')) ?>" data-me="<?= e(url('api/device/me')) ?>" data-scan="<?= e(url('api/scan')) ?>"
     data-photo="<?= e(url('kiosk/foto')) ?>" data-camera="<?= $camera ? '1' : '0' ?>" data-version="3.0">
  <header class="k-top">
    <div class="k-brand"><?= logo_mark(36) ?><div><b><?= e($schoolName) ?></b><span id="k-device"><?= e(t('Scanner belum dipasangkan')) ?></span></div></div>
    <div class="k-clock" id="k-clock" aria-live="off">--:--</div>
    <div class="k-net" id="k-net" title="<?= e(t('Koneksi ke server')) ?>"><i></i><span><?= e(t('Memeriksa…')) ?></span></div>
  </header>

  <section class="k-pair" id="k-pair" hidden>
    <div class="k-pair-card">
      <span class="k-pair-ic"><?= icon('key', 34) ?></span>
      <h1><?= e(t('Pasangkan perangkat ini')) ?></h1>
      <p><?= e(t('Minta kode pasangan 6 digit dari Super Admin (menu Perangkat Scanner). Kode hanya dipakai sekali; setelah itu perangkat ini dikenali otomatis.')) ?></p>
      <form id="k-pair-form" autocomplete="off">
        <input class="k-code" id="k-code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000" aria-label="<?= e(t('Kode pasangan')) ?>" autofocus>
        <button type="submit" class="btn btn-accent lg"><?= e(t('Pasangkan')) ?></button>
      </form>
      <p class="k-pair-err" id="k-pair-err" role="alert" hidden></p>
    </div>
  </section>

  <main class="k-main" id="k-main" hidden>
    <section class="k-result" id="k-result" data-state="ready" aria-live="polite">
      <div class="k-photo" id="k-photo"><span class="k-ic" id="k-ic"><?= icon('scan', 56) ?></span></div>
      <div class="k-text">
        <h1 id="k-title"><?= e(t('Siap memindai')) ?></h1>
        <p class="k-sub" id="k-sub"><?= e(t('Tempelkan kartu QR siswa ke scanner')) ?></p>
        <p class="k-meta" id="k-meta"></p>
      </div>
    </section>
    <aside class="k-side">
      <div class="k-session" id="k-session">
        <span class="k-lbl"><?= e(t('Sesi sekarang')) ?></span>
        <b id="k-session-t"><?= e(t('Belum ada jadwal di ruangan ini')) ?></b>
        <span id="k-session-s" class="k-session-s"></span>
        <div class="k-count" id="k-count" hidden><span><?= e(t('Tercatat')) ?> <b id="k-count-h">0</b></span><span><?= e(t('di antaranya terlambat')) ?> <b id="k-count-t">0</b></span></div>
      </div>
      <?php if ($camera): ?>
      <div class="k-cam" id="k-cam">
        <div id="reader"></div>
        <div class="k-cam-msg" id="k-cam-msg"><span class="spin"></span><?= e(t('Menyalakan kamera…')) ?></div>
      </div>
      <div class="row k-cam-acts"><button type="button" class="btn btn-ondark sm" id="k-cam-switch" hidden><?= icon('refresh', 16) ?><?= e(t('Ganti kamera')) ?></button><button type="button" class="btn btn-ondark sm" id="k-cam-retry" hidden><?= icon('refresh', 16) ?><?= e(t('Coba nyalakan kamera')) ?></button></div>
      <?php endif; ?>
      <form class="k-manual" id="k-manual" autocomplete="off">
        <input class="inp mono" id="k-input" name="code" placeholder="<?= e(t('Scanner USB: ketik otomatis di sini')) ?>" aria-label="<?= e(t('Kode kartu')) ?>" autocapitalize="characters" spellcheck="false">
        <button type="submit" class="btn btn-ondark"><?= e(t('Cek')) ?></button>
      </form>
    </aside>
  </main>

  <footer class="k-foot">
    <span id="k-stats"><?= e(t('Belum ada scan')) ?></span>
    <span class="k-foot-r"><button type="button" class="k-link" id="k-sound" hidden><?= e(t('Aktifkan suara')) ?></button><button type="button" class="k-link" id="k-full"><?= e(t('Layar penuh')) ?></button><button type="button" class="k-link" id="k-unpair"><?= e(t('Lepas perangkat')) ?></button></span>
  </footer>
  <template id="k-icons">
    <span data-i="scan"><?= icon('scan', 56) ?></span><span data-i="check"><?= icon('check', 56) ?></span><span data-i="clock"><?= icon('clock', 56) ?></span><span data-i="info"><?= icon('info', 56) ?></span>
    <span data-i="x"><?= icon('x', 56) ?></span><span data-i="alert"><?= icon('alert', 56) ?></span><span data-i="wifioff"><?= icon('wifioff', 56) ?></span><span data-i="user"><?= icon('user', 56) ?></span><span data-i="lock"><?= icon('lock', 56) ?></span>
  </template>
</div>
