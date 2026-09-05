<?php
declare(strict_types=1);
?>
<section class="bp-listing-page">
    <header class="bp-page-heading"><p class="bp-eyebrow">Browse</p><h1><?php echo bp_esc((string)($title ?? 'Archive')); ?></h1></header>
    <?php if (empty($posts)): ?><div class="bp-empty"><h2>No entries yet</h2><p>Published posts will appear here.</p></div><?php else: ?>
    <div class="bp-archive-list"><?php foreach ($posts as $item): ?><article><time><?php echo bp_esc(bp_date((string)($item['published_at'] ?? ''))); ?></time><h2><a href="<?php echo bp_attr(bp_url((string)(($postUrls ?? [])[(string)($item['slug'] ?? '')] ?? ('/blog/' . rawurlencode((string)($item['slug'] ?? '')))))); ?>"><?php echo bp_esc((string)$item['title']); ?></a></h2><p><?php echo bp_esc((string)($item['seo_description'] ?? '')); ?></p></article><?php endforeach; ?></div>
    <?php endif; ?>
</section>
