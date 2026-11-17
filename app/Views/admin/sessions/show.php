<?php
use App\Core\View;
use App\Models\Session;
View::script('js/live.js');
$tone = ['open' => 'ok', 'closed' => 'mute', 'cancelled' => 'warn'];
$total = (int) $s['student_count'];
$present = $counts['H'] + $counts['T'];
?>
<div class="pagehead">
  <div><h2><?= e($s['class_name']) ?> · <?= e($s['kind'] === 'jadwal' ? ($s['subject_name'] ?? '') : t(Session::KINDS[$s['kind']])) ?><?= $s['note'] && $s['kind'] === 'tambahan' ? ' · ' . e($s['note']) : '' ?></h2>
    <p><?= e(date_id($s['session_date'], true)) ?> · <?= e(substr($s['starts_at'], 11, 5)) ?>–<?= e(substr($s['ends_at'], 11, 5)) ?><?= $s['period_start'] ? ' · ' . e(t('Jam :a–:b', ['a' => $s['period_start'], 'b' => $s['period_end']])) : '' ?><?= $s['teacher_name'] ? ' · ' . e($s['teacher_name']) : '' ?><?= $s['room_code'] ? ' · ' . e($s['room_code']) : '' ?> · <?= e(t('Terlambat setelah :t', ['t' => substr($s['late_after'], 11, 5)])) ?></p></div>
  <div class="row">
    <span class="bdg bdg-<?= $tone[$s['status']] ?> lg" id="live-status" data-status="<?= e($s['status']) ?>"><i></i><?= e(t(Session::STATUSES[$s['status']])) ?></span>
    <?php if ($canManage && $s['status'] === 'open'): ?>
      <?= post_button('admin/sesi/' . $s['id'] . '/tutup', 'Tutup sesi', [], 'btn btn-primary sm', 'Tutup sesi sekarang? Siswa yang belum scan akan ditandai Alpa.', 'lock') ?>
      <?= post_button('admin/sesi/' . $s['id'] . '/batal', 'Jam kosong', [], 'btn btn-outline sm', 'Tandai sesi ini jam kosong? Tidak ada siswa yang dihitung Alpa.', 'x') ?>
    <?php elseif ($canManage): ?>
      <?= post_button('admin/sesi/' . $s['id'] . '/buka', 'Buka kembali', [], 'btn btn-outline sm', 'Buka kembali sesi ini? Tanda Alpa otomatis dihapus.', 'refresh') ?>
    <?php endif; ?>
  </div>
</div>
<div class="g-stats" id="live-counts" data-live="<?= e(url('admin/sesi/' . $s['id'] . '/live')) ?>" data-poll="<?= $s['status'] === 'open' ? 5000 : 0 ?>">
  <div class="stat"><span class="k"><span class="dot t-ok"><?= icon('check', 14) ?></span><?= e(t('Hadir')) ?></span><span class="v" data-c="present"><?= $present ?></span><span class="s"><?= e(t('dari :n siswa', ['n' => $total])) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-warn"><?= icon('clock', 14) ?></span><?= e(t('Terlambat')) ?></span><span class="v" data-c="T"><?= $counts['T'] ?></span><span class="s"><?= e(t('termasuk dalam hadir')) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-navy"><?= icon('user', 14) ?></span><?= e(t('Belum scan')) ?></span><span class="v" data-c="missing"><?= max(0, $total - $present - $counts['A'] - $counts['I'] - $counts['S'] - $counts['D']) ?></span><span class="s"><?= e(t('menjadi Alpa saat sesi ditutup')) ?></span></div>
  <div class="stat"><span class="k"><span class="dot t-bad"><?= icon('x', 14) ?></span><?= e(t('Alpa')) ?></span><span class="v" data-c="A"><?= $counts['A'] ?></span><span class="s"><?= e(t('Izin/Sakit: :n', ['n' => $counts['I'] + $counts['S'] + $counts['D']])) ?></span></div>
</div>
<div class="card">
  <div class="card-h"><h3><?= e(t('Daftar siswa')) ?></h3><span class="sub" id="live-updated"><?= $s['status'] === 'open' ? e(t('Diperbarui otomatis tiap 5 detik')) : '' ?></span></div>
  <div class="tblw"><table class="tbl" id="live-roster">
    <thead><tr><th>#</th><th><?= e(t('NIS')) ?></th><th><?= e(t('Nama')) ?></th><th><?= e(t('Status')) ?></th><th><?= e(t('Jam scan')) ?></th><th><?= e(t('Cara')) ?></th><?php if ($canManage && $s['status'] !== 'cancelled'): ?><th class="r"></th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($roster as $i => $r): ?>
      <tr data-sid="<?= (int) $r['id'] ?>" data-st="<?= e((string) $r['status']) ?>"><td class="num muted"><?= $i + 1 ?></td><td class="mono"><?= e($r['nis']) ?></td><td><a class="nm" href="<?= e(url('admin/siswa/' . $r['id'])) ?>"><?= e($r['full_name']) ?></a></td>
        <td data-f="status"><?= $r['status'] ? status_badge($r['status']) . ($r['status'] === 'T' ? ' <span class="muted small">' . e(t(':n menit', ['n' => $r['late_minutes']])) . '</span>' : '') : '<span class="bdg bdg-mute">' . e(t('Belum')) . '</span>' ?></td>
        <td class="mono" data-f="at"><?= $r['scanned_at'] ? e(substr($r['scanned_at'], 11, 5)) : '—' ?></td>
        <td class="small muted" data-f="method"><?= $r['method'] ? e(t(\App\Models\Attendance::METHODS[$r['method']])) : '' ?><?= $r['corrected'] ? ' <span class="bdg bdg-navy" title="' . e((string) $r['note']) . '">' . e(t('Dikoreksi')) . '</span>' : ($r['leave_id'] ? ' <span class="bdg bdg-info">' . e(t('Surat')) . '</span>' : '') ?></td>
        <?php if ($canManage && $s['status'] !== 'cancelled'): ?><td class="r nowrap" data-f="act"><?php if (!$r['status'] && $s['status'] === 'open'): ?><span class="js-manual"><?= post_button('admin/sesi/' . $s['id'] . '/manual', 'Tandai hadir', ['student_id' => $r['id']], 'btn btn-ghost sm', '', 'check') ?></span><?php endif; ?>
          <button type="button" class="btn btn-ghost sm" data-open="dlg-fix" data-fix="<?= (int) $r['id'] ?>" data-name="<?= e($r['full_name']) ?>" data-st="<?= e((string) $r['status']) ?>" data-corrected="<?= $r['corrected'] ? 1 : 0 ?>"><?= icon('edit', 14) ?><?= e(t('Koreksi')) ?></button></td><?php endif; ?>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php if ($history): ?>
<div class="card">
  <div class="card-h"><h3><?= e(t('Riwayat perubahan status')) ?></h3><span class="sub"><?= e(t(':n perubahan', ['n' => count($history)])) ?></span></div>
  <div class="tblw"><table class="tbl">
    <thead><tr><th><?= e(t('Waktu')) ?></th><th><?= e(t('Siswa')) ?></th><th><?= e(t('Perubahan')) ?></th><th><?= e(t('Sumber')) ?></th><th><?= e(t('Alasan')) ?></th><th><?= e(t('Oleh')) ?></th></tr></thead>
    <tbody><?php foreach ($history as $h): ?>
      <tr><td class="mono small"><?= e(substr($h['created_at'], 0, 16)) ?></td><td><?= e($h['full_name']) ?></td>
        <td><?= $h['old_status'] ? status_badge($h['old_status']) : '<span class="bdg bdg-mute">' . e(t('Belum')) . '</span>' ?> → <?= $h['new_status'] ? status_badge($h['new_status']) : '<span class="bdg bdg-mute">' . e(t('Belum')) . '</span>' ?></td>
        <td class="small"><?= e(t(\App\Models\StatusHistory::SOURCES[$h['source']] ?? $h['source'])) ?></td><td class="small"><?= e((string) $h['reason']) ?></td><td class="small muted"><?= e($h['actor_name'] ?: t('Sistem')) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>
<?php if ($canManage && $s['status'] !== 'cancelled'): ?>
<div class="ov" id="dlg-fix" hidden role="dialog" aria-modal="true" aria-labelledby="dlg-fix-t">
  <form class="sheet" method="post" action="<?= e(url('admin/sesi/' . $s['id'] . '/koreksi')) ?>" novalidate><?= csrf_field() ?>
    <input type="hidden" name="student_id" value="">
    <div class="grab"></div>
    <div class="sh-h"><div><h3 id="dlg-fix-t"><?= e(t('Koreksi status')) ?></h3><p><b data-fix-name></b> · <?= e(t('Koreksi mengalahkan hasil scan dan surat izin, dan tercatat di riwayat beserta alasannya.')) ?></p></div><button type="button" class="iconbtn plain" data-close aria-label="<?= e(t('Tutup')) ?>"><?= icon('x') ?></button></div>
    <div class="fld"><label><?= e(t('Status baru')) ?></label><select class="inp" name="status" required>
      <?php foreach (['H' => 'Hadir', 'T' => 'Terlambat', 'S' => 'Sakit', 'I' => 'Izin', 'D' => 'Dispensasi', 'A' => 'Alpa'] as $k => $v): ?><option value="<?= $k ?>"><?= e(t($v)) ?></option><?php endforeach; ?>
      <option value="reset"><?= e(t('Hapus koreksi (kembali ke scan/surat)')) ?></option>
    </select></div>
    <div class="fld"><label><?= e(t('Alasan')) ?></label><input class="inp" name="reason" maxlength="200" required placeholder="<?= e(t('Contoh: kartu tertinggal, sudah hadir sejak jam pertama')) ?>"></div>
    <div class="acts"><button type="button" class="btn btn-outline" data-close><?= e(t('Batal')) ?></button><button class="btn btn-primary" type="submit"><?= e(t('Simpan koreksi')) ?></button></div>
  </form>
</div>
<?php endif; ?>
