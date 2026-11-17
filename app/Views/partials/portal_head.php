<header class="portal-top">
  <a class="row" href="<?= e(url('portal')) ?>" style="gap:10px;color:inherit;text-decoration:none"><?= logo_mark(34) ?><span><b><?= e(t('Portal Orang Tua')) ?></b><small class="muted" style="display:block"><?= e($school) ?></small></span></a>
  <?php if (!empty($kids)): ?>
  <form method="post" action="<?= e(url('portal/keluar')) ?>"><?= csrf_field() ?><button class="btn btn-ghost sm" type="submit"><?= icon('logout', 16) ?><?= e(t('Keluar')) ?></button></form>
  <?php endif; ?>
</header>
