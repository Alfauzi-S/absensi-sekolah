<?php
use App\Controllers\RecapController;
$base = 'admin/rekap/kelas/' . $class['id'];
$q = array_filter(['jenis' => $type, 'bulan' => $month ?? null, 'tanggal' => $date ?? null, 'semester' => isset($sem) ? $sem['id'] : null]);
$cell = static fn (string $c) => $c === '' ? '' : '<span class="rc rc-' . $c . '">' . $c . '</span>';
?>
<div class="pagehead">
  <div><h2><?= e($table['title']) ?></h2><p><?= e($table['sub']) ?></p></div>
  <div class="row wrap">
    <?php foreach (RecapController::TYPES as $k => $v): ?><a class="chip<?= $k === $type ? ' on' : '' ?>" href="<?= e(url($base, ['jenis' => $k])) ?>"><?= e(t($v)) ?></a><?php endforeach; ?>
    <a class="btn btn-outline sm" href="<?= e(url($base, $q + ['format' => 'csv'])) ?>"><?= icon('download', 16) ?>CSV</a>
    <a class="btn btn-primary sm" href="<?= e(url($base, $q + ['format' => 'xlsx'])) ?>"><?= icon('download', 16) ?>Excel</a>
  </div>
</div>
<div class="card">
  <div class="card-h">
    <?php if ($type === 'bulanan'): $p = date('Y-m', strtotime($month . '-01 -1 month')); $n = date('Y-m', strtotime($month . '-01 +1 month')); ?>
      <div class="row"><a class="btn btn-outline sm" href="<?= e(url($base, ['jenis' => 'bulanan', 'bulan' => $p])) ?>"><?= icon('back', 16) ?></a><h3><?= e(month_id($month)) ?></h3><a class="btn btn-outline sm" href="<?= e(url($base, ['jenis' => 'bulanan', 'bulan' => $n])) ?>"><?= icon('chev', 16) ?></a></div>
      <span class="sub"><?= e(t(':n hari efektif · :s siswa', ['n' => $d['effective'], 's' => count($d['students'])])) ?></span>
    <?php elseif ($type === 'harian'): $p = date('Y-m-d', strtotime($date . ' -1 day')); $n = date('Y-m-d', strtotime($date . ' +1 day')); ?>
      <div class="row"><a class="btn btn-outline sm" href="<?= e(url($base, ['jenis' => 'harian', 'tanggal' => $p])) ?>"><?= icon('back', 16) ?></a>
        <form method="get" action="<?= e(url($base)) ?>" class="inline"><input type="hidden" name="jenis" value="harian"><input class="inp" type="date" name="tanggal" value="<?= e($date) ?>" style="min-height:38px;padding:6px 10px;width:auto" onchange="this.form.submit()"></form>
        <a class="btn btn-outline sm" href="<?= e(url($base, ['jenis' => 'harian', 'tanggal' => $n])) ?>"><?= icon('chev', 16) ?></a></div>
      <span class="sub"><?= e(t(':n sesi', ['n' => count($d['columns'])])) ?></span>
    <?php else: ?>
      <form method="get" action="<?= e(url($base)) ?>" class="row"><input type="hidden" name="jenis" value="semester"><select class="inp" name="semester" onchange="this.form.submit()" style="width:auto"><?php foreach ($semesters as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected((int) $sem['id'], (int) $s['id']) ?>><?= e(t('Semester :s', ['s' => $s['name']])) ?></option><?php endforeach; ?></select></form>
      <span class="sub"><?= e(t(':n hari efektif sampai :d', ['n' => $d['effective'], 'd' => date_id($to)])) ?></span>
    <?php endif; ?>
  </div>
  <?php if (!$d['students']): ?><p class="muted small"><?= e(t('Kelas ini belum punya siswa.')) ?></p>
  <?php elseif ($type === 'bulanan'): ?>
  <div class="tblw"><table class="tbl rg">
    <thead><tr><th>#</th><th><?= e(t('Nama')) ?></th><?php foreach ($d['dates'] as $dt => $info): ?><th class="c<?= $info['holiday'] || ($info['weekend'] && !$info['sessions']) ? ' off' : '' ?>" title="<?= e(date_id($dt, true)) ?>"><?= (int) substr($dt, 8, 2) ?></th><?php endforeach; ?>
      <?php foreach (['H', 'T', 'S', 'I', 'A', 'D'] as $k): ?><th class="c"><?= $k ?></th><?php endforeach; ?><th class="r">%</th></tr></thead>
    <tbody><?php foreach ($d['students'] as $i => $st): $sid = (int) $st['id']; $t = $d['totals'][$sid]; ?>
      <tr><td class="muted"><?= $i + 1 ?></td><td class="nm"><a href="<?= e(url('admin/rekap/siswa/' . $sid)) ?>"><?= e($st['full_name']) ?></a></td>
        <?php foreach ($d['dates'] as $dt => $info): ?><td class="c<?= $info['holiday'] || ($info['weekend'] && !$info['sessions']) ? ' off' : '' ?>"><?= $cell($d['codes'][$sid][$dt] ?? '') ?></td><?php endforeach; ?>
        <?php foreach (['H', 'T', 'S', 'I', 'A', 'D'] as $k): ?><td class="c num<?= $k === 'A' && $t['A'] >= 3 ? ' bad' : '' ?>"><?= $t[$k] ?: '' ?></td><?php endforeach; ?>
        <td class="r num"><b><?= e(number_format((float) $t['pct'], 1, ',', '.')) ?></b></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><td></td><td class="small muted"><?= e(t('Hadir per hari')) ?></td><?php foreach ($d['dates'] as $dt => $info): ?><td class="c small"><?= $info['effective'] ? $d['present_per_day'][$dt] : '' ?></td><?php endforeach; ?><td colspan="7"></td></tr></tfoot>
  </table></div>
  <?php elseif ($type === 'harian'): ?>
  <?php if (!$d['columns']): ?><p class="muted small"><?= e(t('Tidak ada sesi pada tanggal ini.')) ?></p><?php else: ?>
  <div class="tblw"><table class="tbl rg">
    <thead><tr><th>#</th><th><?= e(t('Nama')) ?></th><?php foreach ($d['columns'] as $c): ?><th class="c"><a href="<?= e(url('admin/sesi/' . $c['id'])) ?>"><?= e($c['label']) ?></a><div class="muted small"><?= e(substr($c['starts_at'], 11, 5)) ?></div></th><?php endforeach; ?><th class="c"><?= e(t('Hari ini')) ?></th></tr></thead>
    <tbody><?php foreach ($d['students'] as $i => $st): $sid = (int) $st['id']; ?>
      <tr><td class="muted"><?= $i + 1 ?></td><td class="nm"><a href="<?= e(url('admin/rekap/siswa/' . $sid)) ?>"><?= e($st['full_name']) ?></a></td>
        <?php foreach ($d['columns'] as $c): $x = $d['cells'][$sid][(int) $c['id']] ?? null; ?><td class="c"><?= $x ? $cell($x['status']) . ($x['status'] === 'T' ? '<span class="muted small"> ' . (int) $x['late_minutes'] . '\'</span>' : '') : '<span class="muted">·</span>' ?></td><?php endforeach; ?>
        <td class="c"><?= $cell($d['codes'][$sid][$date] ?? '') ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
  <?php else: ?>
  <div class="tblw"><table class="tbl rg">
    <thead><tr><th rowspan="2">#</th><th rowspan="2"><?= e(t('Nama')) ?></th><?php foreach ($d['months'] as $m): ?><th class="c" colspan="6"><?= e(month_id($m)) ?></th><?php endforeach; ?><th class="c" colspan="7"><?= e(t('Semester')) ?></th></tr>
      <tr><?php foreach ($d['months'] as $m): foreach (['H', 'T', 'S', 'I', 'A', 'D'] as $k): ?><th class="c small"><?= $k ?></th><?php endforeach; endforeach; ?><?php foreach (['H', 'T', 'S', 'I', 'A', 'D'] as $k): ?><th class="c"><?= $k ?></th><?php endforeach; ?><th class="r">%</th></tr></thead>
    <tbody><?php foreach ($d['students'] as $i => $st): $sid = (int) $st['id']; $t = $d['totals'][$sid]; ?>
      <tr><td class="muted"><?= $i + 1 ?></td><td class="nm"><a href="<?= e(url('admin/rekap/siswa/' . $sid)) ?>"><?= e($st['full_name']) ?></a></td>
        <?php foreach ($d['months'] as $m): foreach (['H', 'T', 'S', 'I', 'A', 'D'] as $k): ?><td class="c num small"><?= $d['by_month'][$sid][$m][$k] ?: '' ?></td><?php endforeach; endforeach; ?>
        <?php foreach (['H', 'T', 'S', 'I', 'A', 'D'] as $k): ?><td class="c num<?= $k === 'A' && $t['A'] >= 3 ? ' bad' : '' ?>"><b><?= $t[$k] ?></b></td><?php endforeach; ?>
        <td class="r num"><b><?= e(number_format((float) $t['pct'], 1, ',', '.')) ?></b></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <p class="muted small"><?= e(t('H Hadir · T Terlambat · S Sakit · I Izin · A Alpa · D Dispensasi. Kolom abu-abu: libur. Hari dihitung efektif setelah sesinya ditutup.')) ?></p>
</div>
