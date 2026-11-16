<?php
use App\Controllers\SpeedController;
use App\Models\ScanMetric;
$ms = static fn ($v) => $v === null ? '—' : ($v < 1 ? '< 1 ms' : number_format((float) $v, $v < 10 ? 1 : 0, ',', '.') . ' ms');
$tone = static fn (?float $v, int $target) => $v === null ? 'mute' : ($v <= $target ? 'ok' : ($v <= $target * 1.5 ? 'warn' : 'bad'));
$fb = $total['feedback']; $tt = $total['total']; $rt = $total['roundtrip'];
?>
<div class="pagehead">
  <div><h2><?= e(t('Kecepatan Scan')) ?></h2><p><?= e(t('Diukur oleh tiap kiosk untuk setiap scan. Target: tanda di layar p95 ≤ :u ms (scanner USB) atau ≤ :c ms (kamera); server ≤ 50 ms.', ['u' => ScanMetric::TARGET_USB, 'c' => ScanMetric::TARGET_CAMERA])) ?></p></div>
  <div class="row"><?php foreach (SpeedController::RANGES as $k => $label): ?><a class="chip<?= (int) $k === $days ? ' on' : '' ?>" href="<?= e(url('admin/kecepatan', ['hari' => $k])) ?>"><?= e(t($label)) ?></a><?php endforeach; ?></div>
</div>
<div class="g-stats">
  <div class="stat"><span class="k"><span class="dot t-ok"><?= icon('scan', 14) ?></span><?= e(t('Tanda di layar (p95)')) ?></span><span class="v"><?= e($ms($fb['p95'])) ?></span><span class="s"><?= e(t('p50 :a · :n scan', ['a' => $ms($fb['p50']), 'n' => $total['n']])) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-navy"><?= icon('check', 14) ?></span><?= e(t('Sampai tersimpan (p95)')) ?></span><span class="v"><?= e($ms($tt['p95'])) ?></span><span class="s"><?= e(t('p50 :a · bolak-balik jaringan p95 :b', ['a' => $ms($tt['p50']), 'b' => $ms($rt['p95'])])) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-warn"><?= icon('clock', 14) ?></span><?= e(t('Server (p95)')) ?></span><span class="v"><?= e($ms($server['p95'])) ?></span><span class="s"><?= e(t('rata-rata :a · :n scan diterima', ['a' => $ms($server['avg']), 'n' => $server['n']])) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-bad"><?= icon('wifioff', 14) ?></span><?= e(t('Lewat antrean offline')) ?></span><span class="v"><?= e(pct($total['queued'], $total['n'])) ?></span><span class="s"><?= e(t(':q dari :n scan · :l ditandai dari roster lokal', ['q' => $total['queued'], 'n' => $total['n'], 'l' => $total['local']])) ?></span></div>
</div>
<div class="card">
  <div class="card-h"><h3><?= e(t('Per perangkat')) ?></h3><span class="sub"><?= e(t('Perangkat ditandai lambat bila p95 tanda di layar melewati target (minimal 20 scan).')) ?></span></div>
  <?php if (!$devices): ?><p class="muted small"><?= e(t('Belum ada perangkat scanner.')) ?></p>
  <?php else: ?><div class="tblw"><table class="tbl">
    <thead><tr><th><?= e(t('Perangkat')) ?></th><th><?= e(t('Ruangan')) ?></th><th class="r"><?= e(t('Scan')) ?></th><th class="r"><?= e(t('Tanda p50')) ?></th><th class="r"><?= e(t('Tanda p95')) ?></th><th class="r"><?= e(t('Tersimpan p95')) ?></th><th class="r"><?= e(t('Jaringan p95')) ?></th><th class="r"><?= e(t('Server p95')) ?></th><th class="r"><?= e(t('Antrean')) ?></th><th class="r"><?= e(t('Selisih jam')) ?></th><th><?= e(t('Kondisi')) ?></th></tr></thead>
    <tbody><?php foreach ($devices as $d): $m = $d['m']; $off = $d['clock_offset_ms'] !== null ? (int) round($d['clock_offset_ms'] / 1000) : null; ?>
      <tr>
        <td><b><?= e($d['name']) ?></b> <span class="muted small"><?= e($d['main_source'] === 'camera' ? t('kamera') : t('USB')) ?></span></td>
        <td class="mono small"><?= e($d['room_code'] ?: '—') ?></td>
        <td class="r num"><?= (int) $m['n'] ?></td>
        <td class="r mono"><?= e($ms($m['feedback']['p50'])) ?></td>
        <td class="r"><span class="bdg bdg-<?= $tone($m['feedback']['p95'], $d['target']) ?>"><?= e($ms($m['feedback']['p95'])) ?></span></td>
        <td class="r mono"><?= e($ms($m['total']['p95'])) ?></td>
        <td class="r mono"><?= e($ms($m['roundtrip']['p95'])) ?></td>
        <td class="r mono"><?= e($ms($m['server']['p95'])) ?></td>
        <td class="r num"><?= (int) $d['queue_size'] > 0 ? '<span class="bdg bdg-warn">' . (int) $d['queue_size'] . '</span>' : '0' ?><?= $m['n'] ? ' <span class="muted small">(' . e(pct($m['queued'], $m['n'])) . ')</span>' : '' ?></td>
        <td class="r mono small"><?= $off === null ? '—' : ($off === 0 ? '0' : ($off > 0 ? '+' : '') . $off . ' ' . e(t('dtk'))) ?></td>
        <td><?php if ($d['status'] !== 'active'): ?><span class="bdg bdg-mute"><?= e(t($d['status'] === 'revoked' ? 'Dicabut' : 'Belum dipasangkan')) ?></span>
          <?php elseif ($d['slow']): ?><span class="bdg bdg-bad"><i></i><?= e(t('Lambat')) ?></span>
          <?php elseif ($d['stale']): ?><span class="bdg bdg-warn"><i></i><?= e(t('Tidak terhubung')) ?></span>
          <?php elseif ($off !== null && abs($off) > 60): ?><span class="bdg bdg-warn"><i></i><?= e(t('Jam perangkat salah')) ?></span>
          <?php else: ?><span class="bdg bdg-ok"><i></i><?= e(t('Baik')) ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</div>
<div class="card">
  <div class="card-h"><h3><?= e(t('Per hari')) ?></h3><span class="sub"><?= e(t(':n baris metrik', ['n' => $rowCount])) ?></span></div>
  <?php if (!$byDay): ?><p class="muted small"><?= e(t('Belum ada metrik pada rentang ini. Metrik terkirim otomatis dari kiosk setelah scan.')) ?></p>
  <?php else: ?><div class="tblw"><table class="tbl">
    <thead><tr><th><?= e(t('Tanggal')) ?></th><th class="r"><?= e(t('Scan')) ?></th><th class="r"><?= e(t('Tanda p50')) ?></th><th class="r"><?= e(t('Tanda p95')) ?></th><th class="r"><?= e(t('Tersimpan p50')) ?></th><th class="r"><?= e(t('Tersimpan p95')) ?></th><th class="r"><?= e(t('Server p95')) ?></th><th class="r"><?= e(t('Lewat antrean')) ?></th></tr></thead>
    <tbody><?php foreach ($byDay as $day => $m): ?>
      <tr><td><?= e(date_id($day, true)) ?></td><td class="r num"><?= (int) $m['n'] ?></td><td class="r mono"><?= e($ms($m['feedback']['p50'])) ?></td>
        <td class="r"><span class="bdg bdg-<?= $tone($m['feedback']['p95'], ScanMetric::TARGET_USB) ?>"><?= e($ms($m['feedback']['p95'])) ?></span></td>
        <td class="r mono"><?= e($ms($m['total']['p50'])) ?></td><td class="r mono"><?= e($ms($m['total']['p95'])) ?></td><td class="r mono"><?= e($ms($m['server']['p95'])) ?></td><td class="r num"><?= e(pct($m['queued'], $m['n'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</div>
