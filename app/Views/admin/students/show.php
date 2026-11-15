<div class="card">
  <div class="idcard"><?php if ($hasPhoto): ?><img src="<?= e(url('admin/siswa/' . $s['id'] . '/foto')) ?>" alt="" style="width:54px;height:72px;object-fit:cover;border-radius:10px;border:1px solid var(--border)"><?php else: ?><span class="av lg t"><?= e(initials($s['full_name'])) ?></span><?php endif; ?><div><b><?= e($s['full_name']) ?></b><span><?= e(t('NIS')) ?> <?= e($s['nis']) ?><?= $s['nisn'] ? ' · NISN ' . e($s['nisn']) : '' ?></span></div><span class="bdg <?= $s['status'] === 'Aktif' ? 'bdg-ok' : 'bdg-mute' ?>"><i></i><?= e(t($s['status'])) ?></span></div>
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
    <?php if ($hasPhoto): ?><?= post_button('admin/siswa/' . $s['id'] . '/foto/hapus', 'Hapus foto', [], 'btn btn-ghost', 'Hapus foto siswa ini?', 'image') ?><?php endif; ?>
    <?= post_button('admin/siswa/' . $s['id'] . '/hapus', 'Hapus', [], 'btn btn-ghost', 'Hapus ' . $s['full_name'] . ' secara permanen? Untuk siswa lulus/pindah, lebih baik ubah statusnya.', 'trash') ?>
  </div><?php endif; ?>
</div>
<div class="card">
  <div class="card-h"><h3><?= e(t('Kartu QR')) ?></h3><?php if ($card): ?><span class="bdg bdg-ok"><i></i><?= e(t('Aktif · versi :v', ['v' => $card['version']])) ?></span><?php else: ?><span class="bdg bdg-warn"><?= e(t('Belum ada kartu aktif')) ?></span><?php endif; ?></div>
  <?php if ($card): ?>
    <div class="cred"><span><?= e(t('Kode kartu')) ?> <b class="mono"><?= e(\App\Models\Card::pretty($card['code'])) ?></b></span><span class="small muted"><?= e(t('Diterbitkan :d', ['d' => datetime_id($card['issued_at'])])) ?><?= $card['printed_at'] ? ' · ' . e(t('dicetak :d', ['d' => datetime_id($card['printed_at'])])) : ' · ' . e(t('belum dicetak')) ?></span></div>
    <p class="small muted"><?= e(t('QR pada kartu berisi kode ini, bukan NIS. Jika kartu hilang, terbitkan kartu pengganti: kode lama otomatis ditolak scanner.')) ?></p>
  <?php else: ?>
    <p class="small muted"><?= e(t('Siswa belum bisa absen sebelum kartu diterbitkan dan dicetak.')) ?></p>
  <?php endif; ?>
  <?php if ($canManage): ?>
  <div class="acts">
    <?php if ($card): ?>
      <a class="btn btn-outline" href="<?= e(url('admin/kartu/cetak', ['siswa' => $s['id']])) ?>" target="_blank"><?= icon('download', 18) ?><?= e(t('PDF kartu')) ?></a>
      <form method="post" action="<?= e(url('admin/siswa/' . $s['id'] . '/kartu')) ?>" class="row" data-confirm="<?= e(t('Terbitkan kartu pengganti? Kartu versi :v langsung tidak berlaku.', ['v' => $card['version']])) ?>"><?= csrf_field() ?>
        <select class="inp" name="reason" style="width:auto;min-height:40px"><option value="Hilang"><?= e(t('Hilang')) ?></option><option value="Rusak"><?= e(t('Rusak')) ?></option><option value="Lainnya"><?= e(t('Lainnya')) ?></option></select>
        <button class="btn btn-secondary" type="submit"><?= icon('refresh', 18) ?><?= e(t('Ganti kartu')) ?></button></form>
      <?= post_button('admin/siswa/' . $s['id'] . '/kartu/cabut', 'Cabut tanpa pengganti', ['reason' => 'Dicabut oleh TU'], 'btn btn-ghost', 'Cabut kartu aktif? Siswa tidak bisa absen sampai kartu baru diterbitkan.', 'x') ?>
    <?php else: ?>
      <?= post_button('admin/siswa/' . $s['id'] . '/kartu', 'Terbitkan kartu', [], 'btn btn-primary', '', 'qr') ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if (count($cards) > 1 || ($cards && !$card)): ?>
  <div class="tblw" style="margin-top:10px"><table class="tbl"><thead><tr><th><?= e(t('Versi')) ?></th><th><?= e(t('Kode')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Diterbitkan')) ?></th><th><?= e(t('Dicabut')) ?></th></tr></thead><tbody>
    <?php foreach ($cards as $k): ?><tr><td><?= (int) $k['version'] ?></td><td class="mono"><?= e(\App\Models\Card::pretty($k['code'])) ?></td><td><span class="bdg <?= $k['status'] === 'active' ? 'bdg-ok' : 'bdg-mute' ?>"><?= e($k['status'] === 'active' ? t('Aktif') : t('Dicabut')) ?></span></td><td><?= e(datetime_id($k['issued_at'])) ?><?= $k['issued_by_name'] ? '<br><span class="small muted">' . e($k['issued_by_name']) . '</span>' : '' ?></td><td><?= $k['revoked_at'] ? e(datetime_id($k['revoked_at'])) . '<br><span class="small muted">' . e((string) $k['revoke_reason']) . '</span>' : '—' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<div class="card">
  <div class="card-h"><h3><?= e(t('Riwayat kelas')) ?></h3></div>
  <?php if (!$history): ?><p class="muted small"><?= e(t('Belum pernah ditempatkan di kelas.')) ?></p>
  <?php else: ?><div class="sched"><?php foreach ($history as $h): ?><div><span class="mono"><?= e($h['year_name']) ?></span><span><a href="<?= e(url('admin/kelas/' . $h['id'])) ?>"><?= e($h['name']) ?></a><?= (int) $h['is_active'] === 1 ? ' <span class="bdg bdg-ok">' . e(t('Aktif')) . '</span>' : '' ?></span></div><?php endforeach; ?></div><?php endif; ?>
</div>
