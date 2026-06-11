<?php
declare(strict_types=1);

$seoTitle = $page['seo_title'] ?? $post['seo_title'] ?? '';
$pageTitle = $seoTitle !== '' ? $seoTitle : (isset($title) && $title !== '' ? $title . ' | ' . ($site['name'] ?? 'Batoi Press') : ($site['name'] ?? 'Batoi Press'));
$description = $page['seo_description'] ?? $post['seo_description'] ?? $site['tagline'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo bp_esc((string)$pageTitle); ?></title>
    <meta name="description" content="<?php echo bp_attr((string)$description); ?>">
    <link rel="canonical" href="<?php echo bp_attr((string)($site['base_url'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/')); ?>">
    <link rel="stylesheet" href="<?php echo bp_attr(bp_url('/assets/uif/uif.css')); ?>">
    <link rel="stylesheet" href="<?php echo bp_attr(bp_url('/assets/css/style.css')); ?>">
    <script src="<?php echo bp_attr(bp_url('/assets/js/app.js')); ?>" defer></script>
    <script src="<?php echo bp_attr(bp_url('/assets/uif/uif.js')); ?>" defer></script>
</head>
<body class="bp-public-body">
<div class="bp-shell">
    <header class="bp-header">
        <nav class="bp-nav" aria-label="Main navigation">
            <a class="bp-brand" href="<?php echo bp_attr(bp_url('/')); ?>"><?php echo bp_esc((string)($site['name'] ?? 'Batoi Press')); ?></a>
            <div class="bp-links">
                <a href="<?php echo bp_attr(bp_url('/')); ?>">Home</a>
                <a href="<?php echo bp_attr(bp_url('/about')); ?>">About</a>
                <a href="<?php echo bp_attr(bp_url('/blog')); ?>">Blog</a>
                <a href="<?php echo bp_attr(bp_url('/admin')); ?>">Admin</a>
            </div>
        </nav>
    </header>
    <main class="bp-main">
        <?php echo $content; ?>
    </main>
    <footer class="bp-footer bp-main">
        <p><?php echo bp_esc((string)($site['tagline'] ?? '')); ?></p>
    </footer>
</div>
</body>
</html>
