<?php use App\Services\Pilot; ?>
<div class="pagehead"><div><h2><?= e(t('Kirim Masukan')) ?></h2><p><?= e(t('Ada yang lambat, membingungkan, atau salah? Tulis di sini; masukan dibaca pengelola aplikasi selama pilot dan sesudahnya.')) ?></p></div></div>
<div class="g-2">
  <form method="post" action="<?= e(url('admin/masukan')) ?>" class="card stack"><?= csrf_field() ?>
    <div class="fld"><label for="topic"><?= e(t('Tentang')) ?></label><select class="inp" id="topic" name="topic"><?php foreach (Pilot::TOPICS as $k => $v): ?><option value="<?= e($k) ?>"><?= e(t($v)) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label><?= e(t('Seberapa mudah dipakai?')) ?></label>
      <div class="chips"><?php for ($i = 1; $i <= 5; $i++): ?><label class="chip"><input type="radio" name="rating" value="<?= $i ?>" style="margin:0"><?= str_repeat('★', $i) ?></label><?php endfor; ?></div></div>
    <div class="fld"><label for="message"><?= e(t('Masukan')) ?></label><textarea class="inp" id="message" name="message" rows="5" required minlength="5" maxlength="2000" placeholder="<?= e(t('Contoh: di kelas X-1 scanner kadang tidak bereaksi saat kartu ditempel cepat.')) ?>"></textarea></div>
    <div><button class="btn btn-primary" type="submit"><?= icon('send', 16) ?><?= e(t('Kirim')) ?></button></div>
  </form>
  <div class="card">
    <div class="card-h"><h3><?= e(t('Masukan saya')) ?></h3></div>
    <?php if (!$mine): ?><p class="muted small"><?= e(t('Belum ada.')) ?></p><?php else: ?><div class="list"><?php foreach ($mine as $f): ?>
      <div class="small"><span class="bdg <?= $f['status'] === 'done' ? 'bdg-ok' : 'bdg-info' ?>"><?= e($f['status'] === 'done' ? t('Ditindaklanjuti') : t('Terkirim')) ?></span> <span class="mono muted"><?= e(substr($f['created_at'], 0, 16)) ?></span> · <?= e($f['message']) ?><?= $f['handled_note'] ? '<div class="muted">' . e(t('Tanggapan: :n', ['n' => $f['handled_note']])) . '</div>' : '' ?></div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
</div>
