<?php
use App\Models\Leave;
use App\Models\StatusHistory;
$t = $d['totals'];
$q = ['dari' => $from, 'sampai' => $to];
?>
<div class="pagehead">
  <div><h2><?= e($st['full_name']) ?></h2><p><?= e('NIS ' . $st['nis'] . ' · ' . $class['name'] . ' · ' . date_id($from) . ' s.d. ' . date_id($to)) ?></p></div>
  <div class="row wrap">
    <form method="get" action="<?= e(url('admin/rekap/siswa/' . $st['id'])) ?>" class="row"><input class="inp" type="date" name="dari" value="<?= e($from) ?>" style="width:auto"><input class="inp" type="date" name="sampai" value="<?= e($to) ?>" style="width:auto"><button class="btn btn-outline sm" type="submit"><?= e(t('Terapkan')) ?></button></form>
    <a class="btn btn-outline sm" href="<?= e(url('admin/rekap/siswa/' . $st['id'], $q + ['format' => 'csv'])) ?>"><?= icon('download', 16) ?>CSV</a>
    <a class="btn btn-primary sm" href="<?= e(url('admin/rekap/siswa/' . $st['id'], $q + ['format' => 'xlsx'])) ?>"><?= icon('download', 16) ?>Excel</a>
    <?php if (can('class:manage', (int) $class['id'])): ?><a class="btn btn-outline sm" href="<?= e(url('admin/izin/baru', ['kelas' => $class['id'], 'siswa' => $st['id']])) ?>"><?= icon('plus', 16) ?><?= e(t('Catat izin/sakit')) ?></a><?php endif; ?>
  </div>
</div>
<?php if ($t): ?>
<div class="g-stats">
  <div class="stat"><span class="k"><span class="dot t-ok"><?= icon('check', 14) ?></span><?= e(t('Persentase hadir')) ?></span><span class="v"><?= e(number_format((float) $t['pct'], 1, ',', '.')) ?>%</span><span class="s"><?= e(t('H :h + T :t dari :n hari efektif', ['h' => $t['H'], 't' => $t['T'], 'n' => $d['effective']])) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-warn"><?= icon('clock', 14) ?></span><?= e(t('Terlambat')) ?></span><span class="v"><?= (int) $t['T'] ?></span><span class="s"><?= e(t('hari')) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-navy"><?= icon('file', 14) ?></span><?= e(t('Sakit / Izin / Dispensasi')) ?></span><span class="v"><?= (int) $t['S'] ?> / <?= (int) $t['I'] ?> / <?= (int) $t['D'] ?></span><span class="s"><?= e(t('hari')) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-bad"><?= icon('x', 14) ?></span><?= e(t('Alpa')) ?></span><span class="v"><?= (int) $t['A'] ?></span><span class="s"><?= e(t('jam alpa saat hadir: :n', ['n' => $d['jam_alpa']])) ?></span></div>
</div>
<?php endif; ?>
<div class="g-2">
  <div class="card">
    <div class="card-h"><h3><?= e(t('Per hari')) ?></h3></div>
    <?php if (!$d['days']): ?><p class="muted small"><?= e(t('Belum ada data kehadiran pada rentang ini.')) ?></p><?php else: ?>
    <div class="tblw"><table class="tbl">
      <thead><tr><th><?= e(t('Tanggal')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Scan')) ?></th><th><?= e(t('Keterangan')) ?></th></tr></thead>
      <tbody><?php foreach (array_reverse($d['days']) as $r): ?>
        <tr><td class="small nowrap"><?= e(date_id($r['date'], true)) ?><?= $r['effective'] ? '' : ' <span class="muted small">' . e(t('(belum ditutup)')) . '</span>' ?></td>
          <td><?= $r['code'] ? status_badge($r['code']) : '—' ?><?= $r['late'] ? ' <span class="muted small">' . e(t(':n menit', ['n' => $r['late']])) . '</span>' : '' ?></td>
          <td class="mono small"><?= e($r['first_scan'] ?? '—') ?><?= $r['jam_alpa'] ? ' <span class="bdg bdg-bad">' . e(t(':n jam alpa', ['n' => $r['jam_alpa']])) . '</span>' : '' ?></td>
          <td class="small"><?= e($r['note']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </div>
  <div>
    <div class="card">
      <div class="card-h"><h3><?= e(t('Surat izin/sakit')) ?></h3></div>
      <?php if (!$leaves): ?><p class="muted small"><?= e(t('Tidak ada.')) ?></p><?php else: ?><div class="list">
        <?php foreach ($leaves as $l): ?><div class="small<?= $l['status'] === 'cancelled' ? ' muted' : '' ?>"><?= status_badge($l['kind']) ?> <b><?= e(date_id($l['date_from'])) ?><?= $l['date_to'] !== $l['date_from'] ? ' – ' . e(date_id($l['date_to'])) : '' ?></b> · <?= e($l['reason']) ?><?= $l['status'] === 'cancelled' ? ' · ' . e(t('dibatalkan')) : '' ?></div><?php endforeach; ?>
      </div><?php endif; ?>
    </div>
    <div class="card">
      <div class="card-h"><h3><?= e(t('Riwayat perubahan')) ?></h3></div>
      <?php if (!$history): ?><p class="muted small"><?= e(t('Tidak ada koreksi atau perubahan.')) ?></p><?php else: ?><div class="list">
        <?php foreach ($history as $h): ?><div class="small"><span class="mono muted"><?= e(substr($h['created_at'], 0, 16)) ?></span> · <?= e(date_id($h['session_date'])) ?> <?= e($h['kind'] === 'jadwal' ? 'J' . $h['period_start'] . ' ' . ($h['subject_name'] ?? '') : t(\App\Models\Session::KINDS[$h['kind']] ?? $h['kind'])) ?>:
          <?= $h['old_status'] ? status_badge($h['old_status']) : '—' ?> → <?= $h['new_status'] ? status_badge($h['new_status']) : '—' ?> · <?= e(t(StatusHistory::SOURCES[$h['source']] ?? $h['source'])) ?><?= $h['reason'] ? ' · ' . e($h['reason']) : '' ?> <span class="muted">(<?= e($h['actor_name'] ?: t('Sistem')) ?>)</span></div><?php endforeach; ?>
      </div><?php endif; ?>
    </div>
  </div>
</div>
