<?php
declare(strict_types=1);
?>
<section class="bp-listing-page">
    <header class="bp-page-heading"><p class="bp-eyebrow">Insights</p><h1>Blog</h1><p>News, ideas, and practical guidance from <?php echo bp_esc((string)($site['name'] ?? 'our team')); ?>.</p></header>
    <?php if (empty($posts)): ?>
        <p>No published posts yet.</p>
    <?php else: ?>
        <div class="bp-post-grid">
            <?php foreach ($posts as $item): ?>
                <article class="bp-post-card">
                    <?php $postUrl = (string)(($postUrls ?? [])[(string)($item['slug'] ?? '')] ?? ('/blog/' . rawurlencode((string)($item['slug'] ?? '')))); ?>
                    <?php if (!empty($item['featured_image'])): $featuredImage = (string)$item['featured_image']; ?><a class="bp-post-card-media" href="<?php echo bp_attr(bp_url($postUrl)); ?>"><img src="<?php echo bp_attr(preg_match('#^https?://#i', $featuredImage) === 1 ? $featuredImage : bp_url($featuredImage)); ?>" alt="<?php echo bp_attr((string)($item['featured_image_alt'] ?? '')); ?>"></a><?php endif; ?>
                    <p class="bp-meta"><?php echo bp_esc(bp_date((string)($item['published_at'] ?? ''))); ?></p>
                    <h2><a href="<?php echo bp_attr(bp_url($postUrl)); ?>"><?php echo bp_esc((string)$item['title']); ?></a></h2>
                    <p><?php echo bp_esc((string)($item['seo_description'] ?? '')); ?></p>
                    <a class="bp-text-link" href="<?php echo bp_attr(bp_url($postUrl)); ?>">Read article <span aria-hidden="true">&rarr;</span></a>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (($pageCount ?? 1) > 1): ?>
        <nav class="bp-pagination" aria-label="Blog pages">
            <?php if (($pageNumber ?? 1) > 1): ?><a class="bp-button bp-button-secondary" rel="prev" href="<?php echo bp_attr(bp_url('/blog') . '?page=' . ((int)$pageNumber - 1)); ?>">Previous</a><?php endif; ?>
            <span>Page <?php echo (int)($pageNumber ?? 1); ?> of <?php echo (int)$pageCount; ?></span>
            <?php if (($pageNumber ?? 1) < $pageCount): ?><a class="bp-button bp-button-secondary" rel="next" href="<?php echo bp_attr(bp_url('/blog') . '?page=' . ((int)$pageNumber + 1)); ?>">Next</a><?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
