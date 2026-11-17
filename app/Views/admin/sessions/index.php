<?php
use App\Models\Session;
$tone = ['open' => 'ok', 'closed' => 'mute', 'cancelled' => 'warn'];
$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$next = date('Y-m-d', strtotime($date . ' +1 day'));
$isToday = $date === today();
?>
<div class="pagehead">
  <div><h2><?= e(t('Sesi Absensi')) ?></h2><p><?= e(t('Sesi dibuat otomatis saat scanner menerima scan pertama sesuai jadwal ruangan. Setelah jam berakhir sesi ditutup dan siswa yang belum scan menjadi Alpa.')) ?></p></div>
  <div class="row">
    <a class="btn btn-outline sm" href="<?= e(url('admin/sesi', ['tanggal' => $prev])) ?>" aria-label="<?= e(t('Hari sebelumnya')) ?>"><?= icon('back', 16) ?></a>
    <form method="get" action="<?= e(url('admin/sesi')) ?>" class="inline"><input class="inp" type="date" name="tanggal" value="<?= e($date) ?>" style="min-height:38px;padding:6px 10px;width:auto" onchange="this.form.submit()"></form>
    <a class="btn btn-outline sm" href="<?= e(url('admin/sesi', ['tanggal' => $next])) ?>" aria-label="<?= e(t('Hari berikutnya')) ?>"><?= icon('chev', 16) ?></a>
    <?php if ($canCreate): ?><button type="button" class="btn btn-primary sm" data-open="dlg-extra"><?= icon('plus', 16) ?><?= e(t('Sesi tambahan')) ?></button><?php endif; ?>
  </div>
</div>
<?php if ($isHoliday): ?><div class="banner warn"><?= icon('sun') ?><div class="grow"><b><?= e(t('Hari libur')) ?></b><?= e(t('Scanner tidak membuat sesi pada hari libur.')) ?></div></div><?php endif; ?>
<div class="card">
  <div class="card-h"><h3><?= e(date_id($date, true)) ?><?= $isToday ? ' <span class="bdg bdg-info">' . e(t('Hari ini')) . '</span>' : '' ?></h3><span class="sub"><?= e(t(':n sesi', ['n' => count($sessions)])) ?></span></div>
  <?php if (!$sessions): ?><p class="muted small"><?= e(t('Belum ada sesi pada tanggal ini.')) ?></p>
  <?php else: ?><div class="tblw"><table class="tbl">
    <thead><tr><th><?= e(t('Jam')) ?></th><th><?= e(t('Kelas')) ?></th><th><?= e(t('Sesi')) ?></th><th><?= e(t('Guru')) ?></th><th><?= e(t('Ruangan')) ?></th><th class="r"><?= e(t('Hadir')) ?></th><th class="r"><?= e(t('Terlambat')) ?></th><th class="r"><?= e(t('Alpa')) ?></th><th><?= e(t('Status')) ?></th></tr></thead>
    <tbody><?php foreach ($sessions as $s): ?>
      <tr>
        <td class="mono"><?= e(substr($s['starts_at'], 11, 5)) ?>–<?= e(substr($s['ends_at'], 11, 5)) ?></td>
        <td><a class="nm" href="<?= e(url('admin/sesi/' . $s['id'])) ?>"><?= e($s['class_name']) ?></a></td>
        <td><?= e($s['kind'] === 'jadwal' ? ($s['subject_name'] ?? '') : t(Session::KINDS[$s['kind']])) ?><?= $s['period_start'] ? ' <span class="muted small">' . e(t('Jam :a–:b', ['a' => $s['period_start'], 'b' => $s['period_end']])) . '</span>' : '' ?><?= $s['note'] && $s['kind'] === 'tambahan' ? ' <span class="muted small">· ' . e($s['note']) . '</span>' : '' ?></td>
        <td class="small"><?= e($s['teacher_name'] ?: '—') ?></td>
        <td class="mono small"><?= e($s['room_code'] ?: '—') ?></td>
        <td class="r num"><?= (int) $s['present_count'] ?>/<?= (int) $s['student_count'] ?></td>
        <td class="r num"><?= (int) $s['late_count'] ?></td>
        <td class="r num"><?= (int) $s['absent_count'] ?></td>
        <td><span class="bdg bdg-<?= $tone[$s['status']] ?>"><i></i><?= e(t(Session::STATUSES[$s['status']])) ?></span></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</div>
<?php if ($canCreate): ?>
<div class="ov" id="dlg-extra" hidden role="dialog" aria-modal="true" aria-labelledby="dlg-extra-t">
  <form class="sheet" method="post" action="<?= e(url('admin/sesi/baru')) ?>" novalidate><?= csrf_field() ?>
    <div class="grab"></div>
    <div class="sh-h"><div><h3 id="dlg-extra-t"><?= e(t('Sesi tambahan')) ?></h3><p><?= e(t('Untuk kegiatan di luar jadwal (ekstrakurikuler, pengganti jam kosong). Scanner di ruangan yang dipilih akan menerima scan kelas ini pada jam tersebut.')) ?></p></div><button type="button" class="iconbtn plain" data-close aria-label="<?= e(t('Tutup')) ?>"><?= icon('x') ?></button></div>
    <div class="fld"><label><?= e(t('Kelas')) ?></label><select class="inp" name="class_id" required><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?><?= $c['room_code'] ? ' · ' . e($c['room_code']) : '' ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label><?= e(t('Ruangan scanner')) ?></label><select class="inp" name="room_id"><option value=""><?= e(t('Ruang kelas (bawaan)')) ?></option><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['code'] . ' · ' . $r['name']) ?></option><?php endforeach; ?></select></div>
    <div class="g-2">
      <div class="fld"><label><?= e(t('Tanggal')) ?></label><input class="inp" type="date" name="date" value="<?= e($date) ?>" required></div>
      <div class="fld"><label><?= e(t('Keterangan')) ?></label><input class="inp" name="note" maxlength="200" placeholder="<?= e(t('Pramuka, pengganti MTK…')) ?>"></div>
      <div class="fld"><label><?= e(t('Mulai')) ?></label><input class="inp" type="time" name="start" value="<?= e(date('H:i')) ?>" required></div>
      <div class="fld"><label><?= e(t('Selesai')) ?></label><input class="inp" type="time" name="end" value="<?= e(date('H:i', time() + 3600)) ?>" required></div>
    </div>
    <div class="acts"><button type="button" class="btn btn-outline" data-close><?= e(t('Batal')) ?></button><button class="btn btn-primary" type="submit"><?= e(t('Buat sesi')) ?></button></div>
  </form>
</div>
<?php endif; ?>
