<?php
declare(strict_types=1);

if (empty($latestPosts) || !is_array($latestPosts)) {
    return;
}
?>
<section class="bp-latest-posts" aria-labelledby="bp-latest-posts-title">
    <header class="bp-latest-posts-header">
        <div><p class="bp-eyebrow">From the blog</p><h2 id="bp-latest-posts-title">Latest articles</h2></div>
        <a class="bp-text-link" href="<?php echo bp_attr(bp_url('/blog')); ?>">View all articles <span aria-hidden="true">&rarr;</span></a>
    </header>
    <div class="bp-post-grid">
        <?php foreach ($latestPosts as $item): ?>
            <?php $postUrl = bp_url('/blog/' . rawurlencode((string)($item['slug'] ?? ''))); ?>
            <article class="bp-post-card">
                <?php if (!empty($item['featured_image'])): $featuredImage = (string)$item['featured_image']; ?>
                    <a class="bp-post-card-media" href="<?php echo bp_attr($postUrl); ?>"><img src="<?php echo bp_attr(preg_match('#^https?://#i', $featuredImage) === 1 ? $featuredImage : bp_url($featuredImage)); ?>" alt="<?php echo bp_attr((string)($item['featured_image_alt'] ?? '')); ?>"></a>
                <?php endif; ?>
                <p class="bp-meta"><?php echo bp_esc(bp_date((string)($item['published_at'] ?? ''))); ?></p>
                <h2><a href="<?php echo bp_attr($postUrl); ?>"><?php echo bp_esc((string)($item['title'] ?? 'Untitled')); ?></a></h2>
                <?php if (!empty($item['seo_description'])): ?><p><?php echo bp_esc((string)$item['seo_description']); ?></p><?php endif; ?>
                <a class="bp-text-link" href="<?php echo bp_attr($postUrl); ?>">Read article <span aria-hidden="true">&rarr;</span></a>
            </article>
        <?php endforeach; ?>
    </div>
</section>
