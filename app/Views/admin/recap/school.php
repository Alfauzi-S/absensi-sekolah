<?php
$q = $range === 'semester' ? ['jenis' => 'semester'] : ['bulan' => $month];
?>
<div class="pagehead">
  <div><h2><?= e(t('Ringkasan Sekolah')) ?></h2><p><?= e($label) ?> · <?= e(t('jumlah hari-siswa per kode; % hadir = (H + T) ÷ (siswa × hari efektif)')) ?></p></div>
  <div class="row wrap">
    <a class="chip<?= $range === 'bulanan' ? ' on' : '' ?>" href="<?= e(url('admin/rekap/sekolah', ['bulan' => $month])) ?>"><?= e(t('Bulanan')) ?></a>
    <a class="chip<?= $range === 'semester' ? ' on' : '' ?>" href="<?= e(url('admin/rekap/sekolah', ['jenis' => 'semester'])) ?>"><?= e(t('Semester')) ?></a>
    <?php if ($range === 'bulanan'): ?><form method="get" action="<?= e(url('admin/rekap/sekolah')) ?>"><input class="inp" type="month" name="bulan" value="<?= e($month) ?>" onchange="this.form.submit()" style="width:auto"></form><?php endif; ?>
    <a class="btn btn-outline sm" href="<?= e(url('admin/rekap/sekolah', $q + ['format' => 'csv'])) ?>"><?= icon('download', 16) ?>CSV</a>
    <a class="btn btn-primary sm" href="<?= e(url('admin/rekap/sekolah', $q + ['format' => 'xlsx'])) ?>"><?= icon('download', 16) ?>Excel</a>
  </div>
</div>
<div class="card">
  <div class="tblw"><table class="tbl">
    <thead><tr><th><?= e(t('Kelas')) ?></th><th><?= e(t('Wali kelas')) ?></th><th class="r"><?= e(t('Siswa')) ?></th><th class="r"><?= e(t('Hari efektif')) ?></th><?php foreach (['H', 'T', 'S', 'I', 'A', 'D'] as $k): ?><th class="r"><?= $k ?></th><?php endforeach; ?><th class="r"><?= e(t('% hadir')) ?></th><th class="r"><?= e(t('Alpa ≥ 3')) ?></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><a class="nm" href="<?= e(url('admin/rekap/kelas/' . $r['class']['id'], $range === 'semester' ? ['jenis' => 'semester'] : ['bulan' => $month])) ?>"><?= e($r['class']['name']) ?></a></td><td class="small"><?= e((string) ($r['class']['homeroom_name'] ?? '—')) ?></td>
        <td class="r num"><?= (int) $r['students'] ?></td><td class="r num"><?= (int) $r['effective'] ?></td>
        <?php foreach (['H', 'T', 'S', 'I', 'A', 'D'] as $k): ?><td class="r num"><?= (int) $r['sum'][$k] ?></td><?php endforeach; ?>
        <td class="r num"><b><?= $r['pct'] === null ? '—' : e(number_format((float) $r['pct'], 1, ',', '.')) . '%' ?></b></td>
        <td class="r num<?= $r['frequent_absent'] ? ' bad' : '' ?>"><?= (int) $r['frequent_absent'] ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<div class="card">
  <div class="card-h"><h3><?= e(t('Siswa dengan Alpa 3 hari atau lebih')) ?></h3><span class="sub"><?= e(t(':n siswa', ['n' => count($frequent)])) ?></span></div>
  <?php if (!$frequent): ?><p class="muted small"><?= e(t('Tidak ada.')) ?></p><?php else: ?><div class="tblw"><table class="tbl">
    <thead><tr><th><?= e(t('Siswa')) ?></th><th><?= e(t('Kelas')) ?></th><th class="r"><?= e(t('Hari alpa')) ?></th><th><?= e(t('Terakhir')) ?></th></tr></thead>
    <tbody><?php foreach ($frequent as $f): ?><tr><td><a class="nm" href="<?= e(url('admin/rekap/siswa/' . $f['student_id'])) ?>"><?= e($f['full_name']) ?></a> <span class="muted small mono"><?= e($f['nis']) ?></span></td><td><?= e($f['class_name']) ?></td><td class="r num bad"><b><?= (int) $f['n'] ?></b></td><td class="small"><?= e(date_id($f['last'])) ?></td></tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</div>
