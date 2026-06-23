<?php
declare(strict_types=1);

$seoTitle = $page['seo_title'] ?? $post['seo_title'] ?? '';
$pageTitle = $seoTitle !== '' ? $seoTitle : (isset($title) && $title !== '' ? $title . ' | ' . ($site['name'] ?? 'Batoi Press') : ($site['name'] ?? 'Batoi Press'));
$description = $page['seo_description'] ?? $post['seo_description'] ?? $site['tagline'] ?? '';
$favicon = (string)($site['favicon'] ?? '');
$faviconFile = $favicon !== '' ? dirname(__DIR__, 4) . '/public_html/' . ltrim($favicon, '/') : '';
$faviconHref = $faviconFile !== '' && is_file($faviconFile) ? bp_url($favicon) . '?v=' . filemtime($faviconFile) : '';
$faviconTypes = [
    'ico' => 'image/x-icon',
    'jpeg' => 'image/jpeg',
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
];
$faviconType = $faviconHref !== '' ? ($faviconTypes[strtolower((string)pathinfo($faviconFile, PATHINFO_EXTENSION))] ?? 'image/x-icon') : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo bp_esc((string)$pageTitle); ?></title>
    <meta name="description" content="<?php echo bp_attr((string)$description); ?>">
    <link rel="canonical" href="<?php echo bp_attr((string)($site['base_url'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/')); ?>">
    <?php if ($faviconHref !== ''): ?>
    <link rel="icon" type="<?php echo bp_attr($faviconType); ?>" href="<?php echo bp_attr($faviconHref); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo bp_attr(bp_url('/assets/uif/uif.css')); ?>">
    <link rel="stylesheet" href="<?php echo bp_attr(bp_url('/assets/css/style.css')); ?>">
    <script src="<?php echo bp_attr(bp_url('/assets/uif/uif.iife.js')); ?>" defer></script>
    <script src="<?php echo bp_attr(bp_url('/assets/js/app.js')); ?>" defer></script>
    <script src="<?php echo bp_attr(bp_url('/assets/uif/uif.js')); ?>" defer></script>
</head>
<body class="bp-public-body">
<div class="bp-shell">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="bp-main">
        <?php echo $content; ?>
    </main>
    <?php require __DIR__ . '/../partials/footer.php'; ?>
</div>
</body>
</html>
