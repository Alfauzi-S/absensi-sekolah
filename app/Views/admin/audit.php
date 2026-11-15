<?php use App\Core\Auth; use App\Core\View; use App\Models\AuditLog; ?>
<div class="pagehead"><div><h2><?= e(t('Audit Log')) ?></h2><p><?= e(t('Seluruh tindakan penting: login, perubahan data, dan akses yang ditolak.')) ?></p></div></div>
<form method="get" action="<?= e(url('admin/audit')) ?>" class="filters">
  <div class="search inp-ic"><?= icon('search', 18) ?><input class="inp" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Cari username, objek, keterangan…')) ?>" aria-label="<?= e(t('Cari')) ?>"></div>
  <div class="frow">
    <select class="inp" name="jenis" aria-label="<?= e(t('Jenis tindakan')) ?>" data-autosubmit-field>
      <?php foreach (AuditLog::GROUPS as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($group, $k) ?>><?= e(t($label)) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"><?= e(t('Terapkan')) ?></button>
  </div>
</form>
<?php if (!$items): ?>
  <div class="card"><div class="empty"><span class="eic"><?= icon('file', 26) ?></span><h3><?= e(t('Belum ada catatan')) ?></h3><p><?= e(t('Tindakan penting akan tercatat di sini.')) ?></p></div></div>
<?php else: ?>
  <div class="tblw">
    <table class="tbl">
      <thead><tr><th><?= e(t('Waktu')) ?></th><th><?= e(t('Pengguna')) ?></th><th><?= e(t('Tindakan')) ?></th><th><?= e(t('Kelas')) ?></th><th><?= e(t('Objek')) ?></th><th><?= e(t('Hasil')) ?></th><th><?= e(t('Keterangan')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($items as $l): $meta = json_decode((string) $l['meta'], true) ?: []; ?>
        <tr>
          <td class="mono"><?= e(datetime_id($l['created_at'])) ?></td>
          <td><?= $l['username'] ? '@' . e($l['username']) : '—' ?><br><span class="small muted"><?= e($l['role'] ? Auth::roleLabel($l['role']) : t('Sistem')) ?></span></td>
          <td style="white-space:normal"><?= e(AuditLog::label($l['action'])) ?></td>
          <td><?= e($l['class_name'] ?? ($l['class_id'] ? '#' . $l['class_id'] : '—')) ?></td>
          <td class="mono small"><?= e(trim(($l['object_type'] ?? '') . ' ' . ($l['object_id'] ?? ''))) ?: '—' ?></td>
          <td><span class="bdg <?= $l['result'] === 'ok' ? 'bdg-ok' : 'bdg-bad' ?>"><?= e(t($l['result'] === 'ok' ? 'Berhasil' : ($l['result'] === 'denied' ? 'Ditolak' : 'Gagal'))) ?></span></td>
          <td class="small" style="white-space:normal;max-width:320px"><?php foreach ($meta as $k => $v): ?><span class="mono"><?= e($k) ?></span>: <?= e(is_array($v) ? implode(', ', array_map(static fn ($x) => is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : (string) $x, $v)) : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v)) ?><br><?php endforeach; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?= View::partial('pager', ['page' => $page, 'pages' => $pages, 'total' => $total, 'path' => 'admin/audit', 'query' => ['q' => $q, 'jenis' => $group]]) ?>
