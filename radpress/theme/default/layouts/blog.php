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
                    <?php if (!empty($item['featured_image'])): $featuredImage = (string)$item['featured_image']; ?><a class="bp-post-card-media" href="/blog/<?php echo bp_attr((string)$item['slug']); ?>"><img src="<?php echo bp_attr(preg_match('#^https?://#i', $featuredImage) === 1 ? $featuredImage : bp_url($featuredImage)); ?>" alt=""></a><?php endif; ?>
                    <p class="bp-meta"><?php echo bp_esc(bp_date((string)($item['published_at'] ?? ''))); ?></p>
                    <h2><a href="/blog/<?php echo bp_attr((string)$item['slug']); ?>"><?php echo bp_esc((string)$item['title']); ?></a></h2>
                    <p><?php echo bp_esc((string)($item['seo_description'] ?? '')); ?></p>
                    <a class="bp-text-link" href="/blog/<?php echo bp_attr((string)$item['slug']); ?>">Read article <span aria-hidden="true">&rarr;</span></a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
