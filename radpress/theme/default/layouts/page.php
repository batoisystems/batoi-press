<?php
declare(strict_types=1);
$hasGrid = array_filter((array)($page['blocks'] ?? []), static fn($block): bool => is_array($block) && ($block['type'] ?? '') === 'grid') !== [];
?>
<article class="bp-article bp-prose<?php echo $hasGrid ? ' bp-page-grid' : ''; ?>">
    <?php echo $page['body'] ?? ''; ?>
</article>
<?php require __DIR__ . '/../partials/latest-posts.php'; ?>
