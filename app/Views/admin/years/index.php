<?php $cls = static fn (string $k) => 'fld' . (isset($errors[$k]) ? ' is-err' : ''); $editYear = isset($old['id']) ? (int) $old['id'] : 0; $editSem = isset($old['semester_id']) ? (int) $old['semester_id'] : 0; $semYear = isset($old['semester_year']) ? (int) $old['semester_year'] : 0; $newOpen = $errors && !$editYear && !$editSem && !$semYear; ?>
<div class="pagehead">
  <div><h2><?= e(t('Tahun Ajaran & Semester')) ?></h2><p><?= e(t('Satu tahun ajaran aktif menentukan daftar kelas; satu semester aktif menentukan jadwal yang berlaku.')) ?></p></div>
  <button type="button" class="btn btn-primary" data-open="year-new"><?= icon('plus', 18) ?><?= e(t('Tahun ajaran baru')) ?></button>
</div>
<?php if (!$years): ?>
  <div class="card"><div class="empty"><span class="eic"><?= icon('calendar', 26) ?></span><h3><?= e(t('Belum ada tahun ajaran')) ?></h3><p><?= e(t('Buat tahun ajaran pertama. Dua semester (Ganjil, Genap) dibuat otomatis dan bisa diubah tanggalnya.')) ?></p><button type="button" class="btn btn-primary" data-open="year-new"><?= e(t('Buat tahun ajaran')) ?></button></div></div>
<?php endif; ?>
<?php foreach ($years as $y): $yid = (int) $y['id']; $isActive = (int) $y['is_active'] === 1; ?>
<div class="card">
  <div class="card-h"><h3><?= e($y['name']) ?> <?php if ($isActive): ?><span class="bdg bdg-ok"><i></i><?= e(t('Aktif')) ?></span><?php endif; ?></h3><span class="sub"><?= e(date_id($y['start_date'])) ?> – <?= e(date_id($y['end_date'])) ?></span></div>
  <?php if ($editYear === $yid): ?>
    <form method="post" action="<?= e(url('admin/tahun-ajaran/' . $yid . '/ubah')) ?>" class="g-form two" novalidate><?= csrf_field() ?>
      <div class="<?= $cls('name') ?>"><label><?= e(t('Nama')) ?></label><input class="inp" name="name" value="<?= e($old['name']) ?>" required><?= field_error($errors, 'name') ?></div>
      <div class="<?= $cls('start_date') ?>"><label><?= e(t('Mulai')) ?></label><input class="inp" type="date" name="start_date" value="<?= e($old['start_date']) ?>"><?= field_error($errors, 'start_date') ?></div>
      <div class="<?= $cls('end_date') ?>"><label><?= e(t('Selesai')) ?></label><input class="inp" type="date" name="end_date" value="<?= e($old['end_date']) ?>"><?= field_error($errors, 'end_date') ?></div>
      <div class="acts"><a class="btn btn-ghost" href="<?= e(url('admin/tahun-ajaran')) ?>"><?= e(t('Batal')) ?></a><button class="btn btn-primary" type="submit"><?= e(t('Simpan')) ?></button></div>
    </form>
  <?php endif; ?>
  <div class="tblw"><table class="tbl">
    <thead><tr><th><?= e(t('Semester')) ?></th><th><?= e(t('Mulai')) ?></th><th><?= e(t('Selesai')) ?></th><th><?= e(t('Status')) ?></th><th class="r"><?= e(t('Aksi')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($semesters[$yid] ?? [] as $s): $sid = (int) $s['id']; ?>
      <?php if ($editSem === $sid): ?>
      <tr><td colspan="5"><form method="post" action="<?= e(url('admin/semester/' . $sid . '/ubah')) ?>" class="row" style="flex-wrap:wrap;gap:8px" novalidate><?= csrf_field() ?>
        <b><?= e($s['name']) ?></b>
        <input class="inp" type="date" name="start_date" value="<?= e($old['start_date']) ?>" style="width:auto"><input class="inp" type="date" name="end_date" value="<?= e($old['end_date']) ?>" style="width:auto">
        <button class="btn btn-primary sm" type="submit"><?= e(t('Simpan')) ?></button><a class="btn btn-ghost sm" href="<?= e(url('admin/tahun-ajaran')) ?>"><?= e(t('Batal')) ?></a>
        <?= field_error($errors, 'start_date') ?><?= field_error($errors, 'end_date') ?></form></td></tr>
      <?php else: ?>
      <tr>
        <td><b><?= e($s['name']) ?></b></td><td><?= e(date_id($s['start_date'])) ?></td><td><?= e(date_id($s['end_date'])) ?></td>
        <td><?= (int) $s['is_active'] === 1 ? '<span class="bdg bdg-ok"><i></i>' . e(t('Aktif')) . '</span>' : '<span class="bdg bdg-mute">' . e(t('Tidak aktif')) . '</span>' ?></td>
        <td class="r"><div class="row" style="justify-content:flex-end">
          <?php if ((int) $s['is_active'] !== 1 && $isActive): ?><?= post_button('admin/semester/' . $sid . '/aktifkan', 'Aktifkan', [], 'btn btn-outline sm', 'Jadikan semester ' . $s['name'] . ' ' . $y['name'] . ' sebagai semester aktif? Jadwal dan absensi akan mengikuti semester ini.') ?><?php endif; ?>
          <a class="btn btn-ghost sm" href="<?= e(url('admin/tahun-ajaran', ['ubah_semester' => $sid])) ?>"><?= icon('edit', 16) ?><?= e(t('Ubah')) ?></a>
          <?php if ((int) $s['is_active'] !== 1): ?><?= post_button('admin/semester/' . $sid . '/hapus', 'Hapus', [], 'btn btn-ghost sm', 'Hapus semester ' . $s['name'] . '?', 'trash') ?><?php endif; ?>
        </div></td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($semYear === $yid): ?>
    <form method="post" action="<?= e(url('admin/tahun-ajaran/' . $yid . '/semester')) ?>" class="g-form two" novalidate><?= csrf_field() ?>
      <div class="<?= $cls('name') ?>"><label><?= e(t('Semester')) ?></label><select class="inp" name="name"><option value="Ganjil"<?= selected($old['name'], 'Ganjil') ?>>Ganjil</option><option value="Genap"<?= selected($old['name'], 'Genap') ?>>Genap</option></select><?= field_error($errors, 'name') ?></div>
      <div class="<?= $cls('start_date') ?>"><label><?= e(t('Mulai')) ?></label><input class="inp" type="date" name="start_date" value="<?= e($old['start_date']) ?>"><?= field_error($errors, 'start_date') ?></div>
      <div class="<?= $cls('end_date') ?>"><label><?= e(t('Selesai')) ?></label><input class="inp" type="date" name="end_date" value="<?= e($old['end_date']) ?>"><?= field_error($errors, 'end_date') ?></div>
      <div class="acts"><a class="btn btn-ghost" href="<?= e(url('admin/tahun-ajaran')) ?>"><?= e(t('Batal')) ?></a><button class="btn btn-primary" type="submit"><?= e(t('Tambah semester')) ?></button></div>
    </form>
  <?php endif; ?>
  <div class="acts">
    <?php if (!$isActive): ?><?= post_button('admin/tahun-ajaran/' . $yid . '/aktifkan', 'Jadikan aktif', [], 'btn btn-secondary sm', 'Jadikan ' . $y['name'] . ' tahun ajaran aktif? Semua halaman kelas dan siswa akan mengikuti tahun ini.', 'check') ?><?php endif; ?>
    <a class="btn btn-outline sm" href="<?= e(url('admin/tahun-ajaran', ['ubah' => $yid])) ?>"><?= icon('edit', 16) ?><?= e(t('Ubah')) ?></a>
    <?php if (count($semesters[$yid] ?? []) < 2): ?><a class="btn btn-outline sm" href="<?= e(url('admin/tahun-ajaran', ['semester_baru' => $yid])) ?>"><?= icon('plus', 16) ?><?= e(t('Tambah semester')) ?></a><?php endif; ?>
    <?php if (!$isActive): ?><?= post_button('admin/tahun-ajaran/' . $yid . '/hapus', 'Hapus', [], 'btn btn-ghost sm', 'Hapus tahun ajaran ' . $y['name'] . '? Hanya bisa bila belum punya kelas.', 'trash') ?><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<div class="ov" id="year-new"<?= $newOpen ? '' : ' hidden' ?> role="dialog" aria-modal="true" aria-label="<?= e(t('Tahun ajaran baru')) ?>">
  <div class="sheet">
    <div class="grab"></div>
    <div class="sh-h"><div><h3><?= e(t('Tahun ajaran baru')) ?></h3><p><?= e(t('Semester Ganjil dan Genap dibuat otomatis.')) ?></p></div><button type="button" class="iconbtn plain" data-close aria-label="<?= e(t('Tutup')) ?>"><?= icon('x') ?></button></div>
    <?php $ny = (int) date('n') >= 7 ? (int) date('Y') : (int) date('Y') - 1; $o = $newOpen ? $old : ['name' => $ny . '/' . ($ny + 1), 'start_date' => $ny . '-07-13', 'end_date' => ($ny + 1) . '-06-27']; $ne = $newOpen ? $errors : []; ?>
    <form method="post" action="<?= e(url('admin/tahun-ajaran/baru')) ?>" class="stack" novalidate><?= csrf_field() ?>
      <div class="<?= 'fld' . (isset($ne['name']) ? ' is-err' : '') ?>"><label for="ny-name"><?= e(t('Nama')) ?></label><input class="inp" id="ny-name" name="name" placeholder="2026/2027" value="<?= e($o['name']) ?>" required><?= field_error($ne, 'name') ?></div>
      <div class="g-form two">
        <div class="<?= 'fld' . (isset($ne['start_date']) ? ' is-err' : '') ?>"><label for="ny-start"><?= e(t('Mulai')) ?></label><input class="inp" id="ny-start" type="date" name="start_date" value="<?= e($o['start_date']) ?>"><?= field_error($ne, 'start_date') ?></div>
        <div class="<?= 'fld' . (isset($ne['end_date']) ? ' is-err' : '') ?>"><label for="ny-end"><?= e(t('Selesai')) ?></label><input class="inp" id="ny-end" type="date" name="end_date" value="<?= e($o['end_date']) ?>"><?= field_error($ne, 'end_date') ?></div>
      </div>
      <div class="acts"><button type="button" class="btn btn-outline" data-close><?= e(t('Batal')) ?></button><button class="btn btn-primary" type="submit"><?= e(t('Buat')) ?></button></div>
    </form>
  </div>
</div>
