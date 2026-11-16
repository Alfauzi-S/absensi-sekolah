<!doctype html>
<html lang="<?= e(lang()) ?>">
<head>
<?= \App\Core\View::partial('head', ['title' => $title ?? '']) ?>
<meta name="robots" content="noindex">
</head>
<body class="kiosk-body">
<?= $content ?>
<?php foreach (\App\Core\View::scripts() as $s): ?><script src="<?= e(asset($s)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
