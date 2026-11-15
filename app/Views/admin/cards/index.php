<div class="pagehead">
  <div><h2><?= e(t('Kartu QR Siswa')) ?></h2><p><?= e(t('Setiap siswa punya kode kartu acak 12 karakter (bukan NIS). Kartu hilang diganti dari halaman siswa; kartu lama langsung tidak berlaku.')) ?></p></div>
  <div class="row">
    <?php if ($totals['missing'] > 0): ?><?= post_button('admin/kartu/terbitkan', t('Terbitkan :n kartu yang belum ada', ['n' => $totals['missing']]), [], 'btn btn-primary', t('Terbitkan kartu untuk :n siswa aktif yang belum punya kartu?', ['n' => $totals['missing']]), 'qr') ?><?php endif; ?>
    <?php if ($totals['with_card'] > 0): ?><a class="btn btn-outline" href="<?= e(url('admin/kartu/cetak', ['kelas' => 'semua'])) ?>" target="_blank"><?= icon('download', 18) ?><?= e(t('PDF seluruh sekolah')) ?></a><?php endif; ?>
  </div>
</div>
<div class="g-stats">
  <div class="stat"><span class="k"><?= e(t('Siswa aktif berkelas')) ?></span><span class="v"><?= (int) $totals['students'] ?></span></div>
  <div class="stat"><span class="k"><?= e(t('Sudah punya kartu')) ?></span><span class="v t-ok"><?= (int) $totals['with_card'] ?></span></div>
  <div class="stat"><span class="k"><?= e(t('Belum punya kartu')) ?></span><span class="v<?= $totals['missing'] ? ' t-warn' : '' ?>"><?= (int) $totals['missing'] ?></span></div>
  <div class="stat"><span class="k"><?= e(t('Kartu dicabut')) ?></span><span class="v"><?= (int) $totals['revoked'] ?></span><span class="s"><?= e(t('hilang/diganti')) ?></span></div>
</div>
<?php if (!$photoOk): ?><div class="banner warn"><?= icon('alert') ?><div class="grow"><b><?= e(t('Ekstensi GD tidak aktif')) ?></b><?= e(t('Foto siswa tidak bisa diunggah; kartu tetap bisa dicetak tanpa foto.')) ?></div></div><?php endif; ?>
<div class="banner info"><?= icon('info') ?><div class="grow"><b><?= e(t('Cetak')) ?></b><?= e(t('PDF berisi 10 kartu CR80 (85,6 × 54 mm) per halaman A4 dengan garis potong. Cetak 100% (tanpa "fit to page") di kertas tebal atau laminasi. QR berukuran 26 mm agar terbaca scanner USB maupun kamera.')) ?></div></div>
<?php if (!$classes): ?>
  <div class="card"><div class="empty"><span class="eic"><?= icon('qr', 26) ?></span><h3><?= e(t('Belum ada kelas')) ?></h3><p><?= e(t('Buat kelas dan masukkan siswa terlebih dahulu.')) ?></p></div></div>
<?php else: ?>
<div class="tblw"><table class="tbl">
  <thead><tr><th><?= e(t('Kelas')) ?></th><th class="r"><?= e(t('Siswa')) ?></th><th class="r"><?= e(t('Punya kartu')) ?></th><th class="r"><?= e(t('Belum dicetak')) ?></th><th><?= e(t('Status')) ?></th><th class="r"><?= e(t('Aksi')) ?></th></tr></thead>
  <tbody><?php foreach ($classes as $c): $missing = (int) $c['students'] - (int) $c['with_card']; ?>
    <tr>
      <td><a class="nm" href="<?= e(url('admin/kelas/' . $c['id'])) ?>"><?= e($c['name']) ?></a></td>
      <td class="r num"><?= (int) $c['students'] ?></td>
      <td class="r num"><?= (int) $c['with_card'] ?></td>
      <td class="r num"><?= (int) $c['unprinted'] ?></td>
      <td><?php if ((int) $c['students'] === 0): ?><span class="bdg bdg-mute"><?= e(t('Kosong')) ?></span><?php elseif ($missing > 0): ?><span class="bdg bdg-warn"><?= e(t(':n belum punya kartu', ['n' => $missing])) ?></span><?php elseif ((int) $c['unprinted'] > 0): ?><span class="bdg bdg-info"><?= e(t('Siap cetak')) ?></span><?php else: ?><span class="bdg bdg-ok"><i></i><?= e(t('Lengkap')) ?></span><?php endif; ?></td>
      <td class="r"><div class="row" style="justify-content:flex-end">
        <?php if ($missing > 0): ?><?= post_button('admin/kartu/terbitkan', 'Terbitkan', ['class_id' => $c['id']], 'btn btn-outline sm', '', 'qr') ?><?php endif; ?>
        <?php if ((int) $c['with_card'] > 0): ?>
          <a class="btn btn-outline sm" href="<?= e(url('admin/kartu/cetak', ['kelas' => $c['id']])) ?>" target="_blank"><?= icon('download', 16) ?><?= e(t('PDF kelas')) ?></a>
          <?php if ((int) $c['unprinted'] > 0 && (int) $c['unprinted'] < (int) $c['with_card']): ?><a class="btn btn-ghost sm" href="<?= e(url('admin/kartu/cetak', ['kelas' => $c['id'], 'belum' => 1])) ?>" target="_blank"><?= e(t('Hanya yang belum dicetak')) ?></a><?php endif; ?>
        <?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
