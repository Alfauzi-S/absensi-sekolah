<?php $cls = static fn (string $k) => 'fld' . (isset($errors[$k]) ? ' is-err' : ''); $hints = ['ruangan' => 'Ruangan dipakai untuk scanner kelas dan jadwal. Kode harus unik.', 'mapel' => 'Mata pelajaran dipakai pada jadwal pelajaran.', 'jam' => 'Jam pelajaran menentukan slot jadwal dan batas absensi per jam.', 'libur' => 'Hari libur tidak dihitung dalam rekap kehadiran.']; ?>
<div class="pagehead"><div><h2><?= e(t($t['plural'])) ?></h2><p><?= e(t($hints[$type] ?? '')) ?></p></div></div>
<div class="g-2">
  <div class="card">
    <div class="card-h"><h3><?= e(t('Daftar')) ?></h3><span class="sub"><?= e(t(':n data', ['n' => count($items)])) ?></span></div>
    <?php if (!$items): ?><p class="muted small"><?= e(t('Belum ada data. Isi lewat form di samping.')) ?></p>
    <?php else: ?><div class="tblw"><table class="tbl">
      <thead><tr><?php foreach ($t['fields'] as $f => $def): ?><th><?= e(t($def['label'])) ?></th><?php endforeach; ?><th class="r"></th></tr></thead>
      <tbody><?php foreach ($items as $it): ?>
        <tr<?= $editing && (int) $editing['id'] === (int) $it['id'] ? ' style="background:var(--slate-bg)"' : '' ?>>
          <?php foreach ($t['fields'] as $f => $def): $v = (string) ($it[$f] ?? ''); ?><td<?= $def['type'] === 'text' && $f === 'name' ? ' style="white-space:normal"' : ' class="mono"' ?>><?= e($def['type'] === 'date' ? date_id($v, true) : ($def['type'] === 'time' ? substr($v, 0, 5) : $v)) ?></td><?php endforeach; ?>
          <td class="r"><div class="row" style="justify-content:flex-end"><a class="btn btn-ghost sm" href="<?= e(url('admin/master/' . $type, ['ubah' => $it['id']])) ?>"><?= icon('edit', 16) ?><?= e(t('Ubah')) ?></a><?= post_button('admin/master/' . $type . '/' . $it['id'] . '/hapus', 'Hapus', [], 'btn btn-ghost sm', 'Hapus ' . $it[$t['title']] . '?', 'trash') ?></div></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </div>
  <form method="post" action="<?= e(url($editing ? 'admin/master/' . $type . '/' . $editing['id'] . '/ubah' : 'admin/master/' . $type . '/baru')) ?>" class="card" novalidate data-loading="<?= e(t('Menyimpan…')) ?>">
    <?= csrf_field() ?>
    <div class="card-h"><h3><?= e($editing ? t('Ubah :l', ['l' => t($t['label'])]) : t('Tambah :l', ['l' => t($t['label'])])) ?></h3><?php if ($editing): ?><a class="btn btn-ghost sm" href="<?= e(url('admin/master/' . $type)) ?>"><?= e(t('Batal ubah')) ?></a><?php endif; ?></div>
    <?php foreach ($t['fields'] as $f => $def): $v = (string) ($d[$f] ?? ''); ?>
      <div class="<?= $cls($f) ?>"><label for="f-<?= e($f) ?>"><?= e(t($def['label'])) ?><?php if (empty($def['required'])): ?><span class="en"><?= e(t('opsional')) ?></span><?php endif; ?></label>
        <?php if ($def['type'] === 'int'): ?><input class="inp" id="f-<?= e($f) ?>" name="<?= e($f) ?>" type="number" min="<?= (int) ($def['min'] ?? 0) ?>" max="<?= (int) ($def['max'] ?? 999) ?>" value="<?= e($v) ?>"<?= !empty($def['required']) ? ' required' : '' ?>>
        <?php elseif ($def['type'] === 'time'): ?><input class="inp" id="f-<?= e($f) ?>" name="<?= e($f) ?>" type="time" value="<?= e(substr($v, 0, 5)) ?>"<?= !empty($def['required']) ? ' required' : '' ?>>
        <?php elseif ($def['type'] === 'date'): ?><input class="inp" id="f-<?= e($f) ?>" name="<?= e($f) ?>" type="date" value="<?= e($v) ?>"<?= !empty($def['required']) ? ' required' : '' ?>>
        <?php else: ?><input class="inp<?= $f === 'code' ? ' mono' : '' ?>" id="f-<?= e($f) ?>" name="<?= e($f) ?>" maxlength="<?= (int) ($def['max'] ?? 120) ?>" value="<?= e($v) ?>"<?= !empty($def['required']) ? ' required' : '' ?>><?php endif; ?>
        <?php if (!empty($def['hint'])): ?><p class="hint"><?= e(t($def['hint'])) ?></p><?php endif; ?><?= field_error($errors, $f) ?></div>
    <?php endforeach; ?>
    <div><button class="btn btn-primary" type="submit"><?= e($editing ? t('Simpan perubahan') : t('Tambah')) ?></button></div>
  </form>
</div>
