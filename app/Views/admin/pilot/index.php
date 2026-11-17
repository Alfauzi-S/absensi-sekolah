<?php
use App\Models\ScanMetric;
use App\Services\Pilot;
$ms = static fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', '.') . ' ms';
$verdict = static fn (?bool $ok) => $ok === null ? '<span class="bdg bdg-mute">' . e(t('Data belum cukup')) . '</span>' : ($ok ? '<span class="bdg bdg-ok">' . e(t('Lulus')) . '</span>' : '<span class="bdg bdg-bad">' . e(t('Belum lulus')) . '</span>');
?>
<div class="pagehead">
  <div><h2><?= e(t('Pilot dan Go-live')) ?></h2><p><?= e(t('Pilot 2 kelas selama 1 minggu: kecepatan scan nyata (p95 ≤ :t ms dengan scanner USB), akurasi dibanding absen manual guru 3 hari pertama, dan masukan guru.', ['t' => ScanMetric::TARGET_USB])) ?></p></div>
  <div class="row wrap">
    <?php if ($classes): ?><a class="btn btn-primary sm" href="<?= e(url('admin/pilot', ['format' => 'xlsx'])) ?>"><?= icon('download', 16) ?><?= e(t('Laporan pilot (Excel)')) ?></a><?php endif; ?>
    <a class="btn btn-outline sm" href="<?= e(url('admin/kesehatan')) ?>"><?= icon('power', 16) ?><?= e(t('Kesehatan Sistem')) ?></a>
  </div>
</div>
<?php if ($canEdit): ?>
<form class="card" method="post" action="<?= e(url('admin/pilot')) ?>"><?= csrf_field() ?>
  <div class="card-h"><h3><?= e(t('Kelas dan tanggal pilot')) ?></h3></div>
  <div class="g-form two">
    <div class="fld"><label><?= e(t('Kelas pilot (2 kelas disarankan, maks. 4)')) ?></label>
      <select class="inp" name="classes[]" multiple size="6"><?php foreach ($allClasses as $c): ?><option value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $cfg['classes'], true) ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
      <p class="hint"><?= e(t('Tahan Ctrl (Windows) atau Cmd (Mac) untuk memilih lebih dari satu.')) ?></p></div>
    <div class="stack">
      <div class="fld"><label for="from"><?= e(t('Mulai')) ?></label><input class="inp" type="date" id="from" name="from" value="<?= e($cfg['from']) ?>"></div>
      <div class="fld"><label for="to"><?= e(t('Selesai')) ?></label><input class="inp" type="date" id="to" name="to" value="<?= e($cfg['to']) ?>"></div>
      <div><button class="btn btn-primary" type="submit"><?= e(t('Simpan')) ?></button></div>
    </div>
  </div>
</form>
<?php endif; ?>
<?php if (!$classes): ?>
  <div class="card"><p class="muted"><?= e(t('Belum ada kelas pilot. Pilih kelas dan tanggal di atas.')) ?></p></div>
<?php endif; ?>
<div class="g-2">
<?php foreach ($classes as $c): $sp = $speed[(int) $c['id']]; $a = $acc[(int) $c['id']]; $tot = $sp['total']['total']; ?>
  <div class="card" data-pilot-class="<?= (int) $c['id'] ?>">
    <div class="card-h"><h3><?= e($c['name']) ?></h3><span class="sub"><?= e(date_id($from) . ' – ' . date_id($to)) ?></span></div>
    <h4 class="sec-t"><?= e(t('Kecepatan nyata (scanner USB)')) ?> <?= $verdict(Pilot::speedPass($sp['total'])) ?></h4>
    <div class="kv">
      <div><span class="muted"><?= e(t('Scan terukur')) ?></span><b><?= (int) $tot['n'] ?></b></div>
      <div><span class="muted"><?= e(t('Kode → hasil tampil, p50 / p95')) ?></span><b><?= e($ms($tot['p50'])) ?> / <?= e($ms($tot['p95'])) ?></b></div>
      <div><span class="muted"><?= e(t('Tanda pertama di layar, p95')) ?></span><b><?= e($ms($sp['total']['feedback']['p95'])) ?></b></div>
    </div>
    <?php if ($sp['days']): ?><div class="tblw"><table class="tbl"><thead><tr><th><?= e(t('Tanggal')) ?></th><th class="r"><?= e(t('Scan')) ?></th><th class="r">p95</th></tr></thead><tbody>
      <?php foreach ($sp['days'] as $d => $m): ?><tr><td class="small"><?= e(date_id($d, true)) ?></td><td class="r"><?= (int) $m['total']['n'] ?></td><td class="r mono<?= $m['total']['p95'] !== null && $m['total']['p95'] > ScanMetric::TARGET_USB ? ' bad-t' : '' ?>"><?= e($ms($m['total']['p95'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <h4 class="sec-t"><?= e(t('Akurasi vs absen manual guru')) ?> <?= $verdict($a['pass']) ?></h4>
    <p class="small muted"><?= e(t(':d hari dibandingkan · :r siswa-hari · cocok :m · selisih :s (belum dijelaskan :u)', ['d' => count($a['days']), 'r' => $a['rows'], 'm' => $a['match'], 's' => $a['diff'], 'u' => $a['unexplained']])) ?></p>
    <div class="row wrap">
      <?php foreach ($a['days'] as $d => $x): ?><a class="btn btn-<?= $x['unexplained'] ? 'outline' : 'ghost' ?> sm" href="<?= e(url('admin/pilot/banding/' . $c['id'], ['tanggal' => $d])) ?>"><?= e(date_id($d)) ?><?= $x['diff'] ? ' · ' . e(t(':n selisih', ['n' => $x['diff']])) : ' ✓' ?></a><?php endforeach; ?>
      <a class="btn btn-primary sm" href="<?= e(url('admin/pilot/banding/' . $c['id'], ['tanggal' => today()])) ?>"><?= icon('edit', 14) ?><?= e(t('Isi absen manual hari ini')) ?></a>
    </div>
  </div>
<?php endforeach; ?>
</div>
<div class="card">
  <div class="card-h"><h3><?= e(t('Masukan guru dan staf')) ?></h3><span class="sub"><?= e(t(':n baru', ['n' => count(array_filter($feedback, static fn ($f) => $f['status'] === 'new'))])) ?></span></div>
  <?php if (!$feedback): ?><p class="muted small"><?= e(t('Belum ada masukan. Semua pengguna bisa mengirim masukan dari menu Kirim Masukan.')) ?></p><?php else: ?>
  <div class="list"><?php foreach ($feedback as $f): ?>
    <div class="fb<?= $f['status'] === 'done' ? ' muted' : '' ?>">
      <div class="small"><b><?= e((string) $f['user_name']) ?></b> · <?= e(\App\Core\Auth::roleLabel($f['role'])) ?> · <?= e(t(Pilot::TOPICS[$f['topic']] ?? $f['topic'])) ?><?= $f['rating'] ? ' · ' . str_repeat('★', (int) $f['rating']) : '' ?> · <span class="mono"><?= e(substr($f['created_at'], 0, 16)) ?></span></div>
      <div><?= nl2br(e($f['message'])) ?></div>
      <?php if ($f['status'] === 'done'): ?><div class="small"><?= e(t('Selesai')) ?><?= $f['handled_note'] ? ': ' . e($f['handled_note']) : '' ?></div>
      <?php elseif ($canEdit): ?><form method="post" action="<?= e(url('admin/masukan/' . $f['id'] . '/selesai')) ?>" class="row"><?= csrf_field() ?><input class="inp sm" name="note" maxlength="500" placeholder="<?= e(t('Tindak lanjut (opsional)')) ?>"><button class="btn btn-ghost sm" type="submit"><?= icon('check', 14) ?><?= e(t('Tandai selesai')) ?></button></form><?php endif; ?>
    </div>
  <?php endforeach; ?></div><?php endif; ?>
</div>
