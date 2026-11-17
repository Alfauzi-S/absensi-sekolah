<?php $cls = static fn (string $k) => 'fld' . (isset($errors[$k]) ? ' is-err' : ''); $statusTone = ['active' => 'ok', 'pending' => 'warn', 'revoked' => 'bad']; $statusLabel = ['active' => 'Aktif', 'pending' => 'Menunggu pasangan', 'revoked' => 'Dicabut']; ?>
<div class="pagehead">
  <div><h2><?= e(t('Perangkat Scanner')) ?></h2><p><?= e(t('Satu perangkat (HP/tablet/PC) per ruangan. Buka :url di perangkat, masukkan kode pasangan sekali; setelah itu scanner langsung siap.', ['url' => $kioskUrl])) ?></p></div>
  <div class="row"><a class="btn btn-outline" href="<?= e(url('kiosk')) ?>" target="_blank"><?= icon('scan', 18) ?><?= e(t('Buka kiosk')) ?></a></div>
</div>
<?php if ($pair): ?>
<div class="card" style="border-color:var(--cyan);background:var(--cyan-bg)">
  <div class="card-h"><h3><?= e(t('Kode pasangan untuk :n', ['n' => $pair['name']])) ?></h3><span class="sub"><?= e(t('Berlaku sampai :t', ['t' => time_only($pair['pair_expires_at'])])) ?></span></div>
  <div class="row" style="gap:18px;align-items:center">
    <span class="mono" style="font-size:40px;font-weight:700;letter-spacing:.18em;color:var(--navy)"><?= e(substr($pair['pair_code'], 0, 3)) ?> <?= e(substr($pair['pair_code'], 3)) ?></span>
    <div class="small"><?= e(t('Di perangkat kiosk (ruangan :r): buka', ['r' => $pair['room_code'] ?: '—'])) ?> <code><?= e($kioskUrl) ?></code>, <?= e(t('ketik kode ini, lalu tekan Pasangkan. Kode hanya bisa dipakai sekali.')) ?></div>
  </div>
</div>
<?php endif; ?>
<div class="g-2">
  <div class="stack">
    <div class="card">
      <div class="card-h"><h3><?= e(t('Daftar perangkat')) ?></h3><span class="sub"><?= e(t(':n perangkat', ['n' => count($devices)])) ?></span></div>
      <?php if (!$devices): ?><p class="muted small"><?= e(t('Belum ada perangkat. Buat lewat form di samping, lalu pasangkan di kiosk.')) ?></p>
      <?php else: ?><div class="tblw"><table class="tbl">
        <thead><tr><th><?= e(t('Nama')) ?></th><th><?= e(t('Ruangan')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Terakhir aktif')) ?></th><th class="r"></th></tr></thead>
        <tbody><?php foreach ($devices as $d): ?>
          <tr>
            <td><b><?= e($d['name']) ?></b><?php if ($d['app_version']): ?> <span class="muted small mono">v<?= e($d['app_version']) ?></span><?php endif; ?></td>
            <td><?= $d['room_code'] ? '<span class="mono">' . e($d['room_code']) . '</span> ' . e($d['room_name']) : '<span class="muted">—</span>' ?></td>
            <td><span class="bdg bdg-<?= $statusTone[$d['status']] ?>"><i></i><?= e(t($statusLabel[$d['status']])) ?></span></td>
            <td class="small muted"><?= $d['last_seen_at'] ? e(datetime_id($d['last_seen_at'])) . ($d['last_ip'] ? ' · <span class="mono">' . e($d['last_ip']) . '</span>' : '') : '—' ?></td>
            <td class="r"><div class="row" style="justify-content:flex-end;flex-wrap:nowrap">
              <?= post_button('admin/perangkat/' . $d['id'] . '/kode', $d['status'] === 'active' ? 'Pasang ulang' : 'Kode baru', [], 'btn btn-ghost sm', $d['status'] === 'active' ? 'Pasang ulang ' . $d['name'] . '? Token lama langsung tidak berlaku.' : '', 'key') ?>
              <?php if ($d['status'] === 'active'): ?><?= post_button('admin/perangkat/' . $d['id'] . '/cabut', 'Cabut', [], 'btn btn-ghost sm', 'Cabut perangkat ' . $d['name'] . '? Kiosk itu tidak bisa lagi mengirim scan.', 'power') ?><?php endif; ?>
              <?= post_button('admin/perangkat/' . $d['id'] . '/hapus', 'Hapus', [], 'btn btn-ghost sm', 'Hapus perangkat ' . $d['name'] . '?', 'trash') ?>
            </div></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
    <div class="card">
      <div class="card-h"><h3><?= e(t('Scan terakhir')) ?></h3><span class="sub"><?= $stats['n'] ? e(t('Hari ini :n scan · server rata-rata :a ms · p95 :p ms', ['n' => $stats['n'], 'a' => $stats['avg'], 'p' => $stats['p95']])) : e(t('Belum ada scan hari ini')) ?></span></div>
      <?php if (!$recent): ?><p class="muted small"><?= e(t('Hasil scan dari kiosk akan tampil di sini.')) ?></p>
      <?php else: ?><div class="tblw"><table class="tbl">
        <thead><tr><th><?= e(t('Waktu')) ?></th><th><?= e(t('Perangkat')) ?></th><th><?= e(t('Siswa')) ?></th><th><?= e(t('Hasil')) ?></th><th class="r"><?= e(t('Server')) ?></th></tr></thead>
        <tbody><?php $tone = ['ok' => 'ok', 'late' => 'warn', 'dup' => 'info', 'wrongclass' => 'bad', 'invalid' => 'bad', 'nosession' => 'mute']; $lbl = ['ok' => 'Hadir', 'late' => 'Terlambat', 'dup' => 'Duplikat', 'wrongclass' => 'Salah kelas', 'invalid' => 'Kartu tidak berlaku', 'nosession' => 'Tidak ada sesi']; foreach ($recent as $l): ?>
          <tr><td class="mono small"><?= e(substr($l['received_at'], 11, 8)) ?></td><td class="small"><?= e($l['device_name'] ?: '—') ?></td><td><?= e($l['full_name'] ?: ($l['code'] ? \App\Models\Card::pretty($l['code']) : '—')) ?></td>
            <td><span class="bdg bdg-<?= $tone[$l['result']] ?? 'mute' ?>"><?= e(t($lbl[$l['result']] ?? $l['result'])) ?></span></td><td class="r mono small"><?= e(number_format((float) $l['t_server_ms'], 1)) ?> ms</td></tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>
  <form method="post" action="<?= e(url('admin/perangkat/baru')) ?>" class="card" novalidate data-loading="<?= e(t('Menyimpan…')) ?>">
    <?= csrf_field() ?>
    <div class="card-h"><h3><?= e(t('Tambah perangkat')) ?></h3></div>
    <div class="<?= $cls('name') ?>"><label for="f-name"><?= e(t('Nama perangkat')) ?></label><input class="inp" id="f-name" name="name" maxlength="80" value="<?= e($old['name'] ?? '') ?>" placeholder="<?= e(t('Tablet X-1 / HP Pak Budi')) ?>" required><?= field_error($errors, 'name') ?></div>
    <div class="<?= $cls('room_id') ?>"><label for="f-room"><?= e(t('Ruangan')) ?></label>
      <select class="inp" id="f-room" name="room_id" required><option value=""><?= e(t('— pilih ruangan —')) ?></option><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['id'] ?>"<?= selected($old['room_id'] ?? '', $r['id']) ?>><?= e($r['code'] . ' · ' . $r['name']) ?></option><?php endforeach; ?></select>
      <p class="hint"><?= e(t('Scanner hanya menerima siswa dari kelas yang dijadwalkan di ruangan ini.')) ?></p><?= field_error($errors, 'room_id') ?></div>
    <div><button class="btn btn-primary" type="submit"><?= icon('plus', 18) ?><?= e(t('Buat & tampilkan kode')) ?></button></div>
    <p class="hint"><?= e(t('Cara pakai: pasang HP/tablet lama atau PC dengan scanner USB di kelas, buka halaman kiosk di browser (Chrome), masukkan kode, lalu pasang di mode layar penuh. Kiosk mengingat token sampai perangkat dicabut.')) ?></p>
  </form>
</div>
