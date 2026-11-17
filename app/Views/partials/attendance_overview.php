<?php
/** Ringkasan kehadiran untuk dashboard: hari ini per kelas, % hadir bulan ini, siswa alpa ≥ 3. Variabel: $att = [today, month, frequent, monthLabel, single] */
$sum = ['H' => 0, 'T' => 0, 'X' => 0, 'A' => 0, 'none' => 0];
$withSessions = 0;
foreach ($att['today'] as $r) {
    if ($r['has_sessions']) {
        $withSessions++;
        foreach ($sum as $k => $_) {
            $sum[$k] += $r['counts'][$k];
        }
    }
}
$totStudents = array_sum(array_map(static fn ($r) => $r['has_sessions'] ? $r['students'] : 0, $att['today']));
$monthPct = null;
$num = $den = 0;
foreach ($att['month'] as $r) {
    if ($r['pct'] !== null) {
        $num += $r['sum']['H'] + $r['sum']['T'];
        $den += $r['students'] * $r['effective'];
    }
}
$monthPct = $den ? round($num / $den * 100, 1) : null;
$low = array_values(array_filter($att['month'], static fn ($r) => $r['pct'] !== null));
usort($low, static fn ($a, $b) => $a['pct'] <=> $b['pct']);
?>
<div class="g-stats">
  <div class="stat"><span class="k"><span class="dot t-ok"><?= icon('check', 14) ?></span><?= e(t('Hadir hari ini')) ?></span><span class="v"><?= $sum['H'] + $sum['T'] ?></span><span class="s"><?= e($withSessions ? t('dari :n siswa · :t terlambat', ['n' => $totStudents, 't' => $sum['T']]) : t('Belum ada sesi hari ini')) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-navy"><?= icon('file', 14) ?></span><?= e(t('Izin / sakit')) ?></span><span class="v"><?= $sum['X'] ?></span><span class="s"><?= e(t(':n belum tercatat', ['n' => $sum['none']])) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-bad"><?= icon('x', 14) ?></span><?= e(t('Alpa hari ini')) ?></span><span class="v"><?= $sum['A'] ?></span><span class="s"><?= e(t('setelah sesi ditutup')) ?></span></div>
  <a class="stat" href="<?= e(url($att['single'] ? 'admin/rekap/kelas/' . $att['today'][0]['class']['id'] : 'admin/rekap/sekolah')) ?>"><span class="k"><span class="dot t-warn"><?= icon('chart', 14) ?></span><?= e(t('Kehadiran :m', ['m' => $att['monthLabel']])) ?></span><span class="v"><?= $monthPct === null ? '—' : e(number_format($monthPct, 1, ',', '.')) . '%' ?></span><span class="s"><?= e(t('(H + T) ÷ hari efektif')) ?></span></a>
</div>
<div class="g-2">
  <?php if (!$att['single']): ?>
  <div class="card">
    <div class="card-h"><h3><?= e(t('Kehadiran per kelas bulan ini')) ?></h3><a class="btn btn-ghost sm" href="<?= e(url('admin/rekap/sekolah')) ?>"><?= e(t('Ringkasan sekolah')) ?></a></div>
    <?php if (!$low): ?><p class="muted small"><?= e(t('Belum ada hari efektif bulan ini.')) ?></p><?php else: ?>
    <div class="tblw"><table class="tbl"><thead><tr><th><?= e(t('Kelas')) ?></th><th class="r"><?= e(t('% hadir')) ?></th><th class="r">A</th><th class="r"><?= e(t('Alpa ≥ 3')) ?></th></tr></thead><tbody>
      <?php foreach (array_slice($low, 0, 8) as $r): ?><tr><td><a class="nm" href="<?= e(url('admin/rekap/kelas/' . $r['class']['id'])) ?>"><?= e($r['class']['name']) ?></a></td><td class="r num"><b><?= e(number_format((float) $r['pct'], 1, ',', '.')) ?>%</b></td><td class="r num"><?= (int) $r['sum']['A'] ?></td><td class="r num<?= $r['frequent_absent'] ? ' bad' : '' ?>"><?= (int) $r['frequent_absent'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div><p class="muted small"><?= e(t('Diurutkan dari persentase terendah.')) ?></p><?php endif; ?>
  </div>
  <?php else: $c = $att['today'][0]; ?>
  <div class="card">
    <div class="card-h"><h3><?= e(t('Kelas :c hari ini', ['c' => $c['class']['name']])) ?></h3><a class="btn btn-ghost sm" href="<?= e(url('admin/rekap/kelas/' . $c['class']['id'], ['jenis' => 'harian'])) ?>"><?= e(t('Rekap harian')) ?></a></div>
    <div class="row wrap"><span class="bdg bdg-ok"><?= e(t('Hadir :n', ['n' => $c['counts']['H']])) ?></span><span class="bdg bdg-warn"><?= e(t('Terlambat :n', ['n' => $c['counts']['T']])) ?></span><span class="bdg bdg-info"><?= e(t('Izin/sakit :n', ['n' => $c['counts']['X']])) ?></span><span class="bdg bdg-bad"><?= e(t('Alpa :n', ['n' => $c['counts']['A']])) ?></span><span class="bdg bdg-mute"><?= e(t('Belum :n', ['n' => $c['counts']['none']])) ?></span></div>
    <div class="row wrap" style="margin-top:12px"><a class="btn btn-outline sm" href="<?= e(url('admin/rekap/kelas/' . $c['class']['id'])) ?>"><?= icon('chart', 16) ?><?= e(t('Rekap bulanan')) ?></a><a class="btn btn-outline sm" href="<?= e(url('admin/izin/baru', ['kelas' => $c['class']['id']])) ?>"><?= icon('plus', 16) ?><?= e(t('Catat izin/sakit')) ?></a></div>
  </div>
  <?php endif; ?>
  <div class="card">
    <div class="card-h"><h3><?= e(t('Siswa alpa 3 hari atau lebih')) ?></h3><span class="sub"><?= e(t('semester ini')) ?></span></div>
    <?php if (!$att['frequent']): ?><p class="muted small"><?= e(t('Tidak ada siswa dengan alpa 3 hari atau lebih.')) ?></p><?php else: ?>
    <div class="list"><?php foreach (array_slice($att['frequent'], 0, 10) as $f): ?>
      <a class="pc" href="<?= e(url('admin/rekap/siswa/' . $f['student_id'])) ?>"><span class="av" style="background:var(--bad-bg);color:var(--bad-ink)"><?= (int) $f['n'] ?></span><span class="who"><b><?= e($f['full_name']) ?></b><span><?= e($f['class_name'] . ' · ' . t('terakhir :d', ['d' => date_id($f['last'])])) ?></span></span></a>
    <?php endforeach; ?></div><?php if (count($att['frequent']) > 10): ?><p class="muted small"><?= e(t('dan :n siswa lain', ['n' => count($att['frequent']) - 10])) ?></p><?php endif; ?><?php endif; ?>
  </div>
</div>
