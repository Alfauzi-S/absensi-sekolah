<?php
$t = $d['totals'] ?? null;
$st2 = $semTotals;
?>
<div class="portal">
  <?= \App\Core\View::partial('portal_head', ['school' => $school, 'kids' => $kids]) ?>
  <div class="pagehead">
    <div><h2><?= e($st['full_name'] ?? '') ?></h2><p><?= e('NIS ' . ($st['nis'] ?? '') . ($class ? ' · ' . $class['name'] : '')) ?></p></div>
    <?php if (count($kids) > 1): ?>
      <div class="row wrap"><?php foreach ($kids as $k): if ((int) $k['id'] === (int) $st['id']) continue; ?><a class="btn btn-outline sm" href="<?= e(url('portal/siswa/' . $k['id'], ['bulan' => $month])) ?>"><?= icon('user', 16) ?><?= e($k['full_name']) ?></a><?php endforeach; ?></div>
    <?php endif; ?>
  </div>
  <?php if (!$class): ?>
    <div class="card"><p class="muted"><?= e(t('Siswa belum terdaftar di kelas pada tahun ajaran aktif.')) ?></p></div>
  <?php else: ?>
  <?php if ($st2): ?>
  <div class="g-stats">
    <div class="stat"><span class="k"><span class="dot t-ok"><?= icon('check', 14) ?></span><?= e(t('Hadir semester ini')) ?></span><span class="v"><?= e(number_format((float) $st2['pct'], 1, ',', '.')) ?>%</span><span class="s"><?= e(t(':n dari :e hari efektif', ['n' => $st2['H'] + $st2['T'], 'e' => $semEffective])) ?></span></div>
    <div class="stat"><span class="k"><span class="dot t-warn"><?= icon('clock', 14) ?></span><?= e(t('Terlambat')) ?></span><span class="v"><?= (int) $st2['T'] ?></span><span class="s"><?= e(t('hari')) ?></span></div>
    <div class="stat"><span class="k"><span class="dot t-navy"><?= icon('file', 14) ?></span><?= e(t('Sakit / Izin / Dispensasi')) ?></span><span class="v"><?= (int) $st2['S'] ?> / <?= (int) $st2['I'] ?> / <?= (int) $st2['D'] ?></span><span class="s"><?= e(t('hari')) ?></span></div>
    <div class="stat"><span class="k"><span class="dot t-bad"><?= icon('x', 14) ?></span><?= e(t('Alpa')) ?></span><span class="v"><?= (int) $st2['A'] ?></span><span class="s"><?= e(t('hari')) ?></span></div>
  </div>
  <?php endif; ?>
  <div class="card">
    <div class="card-h">
      <h3><?= e(month_id($month)) ?></h3>
      <div class="row">
        <a class="btn btn-ghost sm" href="<?= e(url('portal/siswa/' . $st['id'], ['bulan' => $prev])) ?>" aria-label="<?= e(t('Bulan sebelumnya')) ?>"><?= icon('back', 16) ?></a>
        <?php if ($next): ?><a class="btn btn-ghost sm" href="<?= e(url('portal/siswa/' . $st['id'], ['bulan' => $next])) ?>" aria-label="<?= e(t('Bulan berikutnya')) ?>"><?= icon('chev', 16) ?></a><?php endif; ?>
      </div>
    </div>
    <?php if ($t): ?><p class="small muted"><?= e(t('Bulan ini: Hadir :h · Terlambat :t · Sakit :s · Izin :i · Dispensasi :dd · Alpa :a', ['h' => $t['H'], 't' => $t['T'], 's' => $t['S'], 'i' => $t['I'], 'dd' => $t['D'], 'a' => $t['A']])) ?></p><?php endif; ?>
    <?php if (!$d || !$d['days']): ?><p class="muted small"><?= e(t('Belum ada data kehadiran pada bulan ini.')) ?></p><?php else: ?>
    <div class="tblw"><table class="tbl">
      <thead><tr><th><?= e(t('Tanggal')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Jam scan')) ?></th><th><?= e(t('Keterangan')) ?></th></tr></thead>
      <tbody><?php foreach (array_reverse($d['days']) as $r): ?>
        <tr><td class="small nowrap"><?= e(date_id($r['date'], true)) ?></td>
          <td><?= $r['code'] ? status_badge($r['code']) : '<span class="muted small">' . e(t('belum tercatat')) . '</span>' ?><?= $r['late'] ? ' <span class="muted small">' . e(t(':n menit', ['n' => $r['late']])) . '</span>' : '' ?></td>
          <td class="mono small"><?= e($r['first_scan'] ?? '—') ?></td>
          <td class="small"><?= e($r['note']) ?><?= $r['jam_alpa'] ? ' <span class="bdg bdg-bad">' . e(t(':n jam pelajaran tidak hadir', ['n' => $r['jam_alpa']])) . '</span>' : '' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="row wrap" style="justify-content:space-between">
    <a class="btn btn-outline sm" href="<?= e(url('portal', ['tambah' => 1])) ?>"><?= icon('plus', 16) ?><?= e(t('Tambah anak lain')) ?></a>
    <span class="small muted"><?= e(t('Ada yang keliru? Hubungi wali kelas.')) ?></span>
  </div>
</div>
