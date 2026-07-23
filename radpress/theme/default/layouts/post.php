<?php
declare(strict_types=1);
?>
<?php $requestedPostLayout = (string)($post['layout'] ?? 'full'); $postLayout = in_array($requestedPostLayout, ['full', 'sidebar-right', 'sidebar-left'], true) ? $requestedPostLayout : 'full'; ?>
<div class="bp-post-shell bp-post-layout-<?php echo bp_attr($postLayout); ?>">
<article class="bp-article bp-prose bp-post">
    <header class="bp-post-header"><p class="bp-eyebrow">Article</p><h1><?php echo bp_esc((string)($post['title'] ?? 'Untitled')); ?></h1><?php if (!empty($post['subtitle'])): ?><p class="bp-post-subtitle"><?php echo bp_esc((string)$post['subtitle']); ?></p><?php endif; ?><p class="bp-meta"><?php echo bp_esc(bp_date((string)($post['published_at'] ?? ''))); ?><?php echo !empty($post['author']) ? ' · ' . bp_esc((string)$post['author']) : ''; ?></p></header>
    <?php if (!empty($post['featured_image'])): $featuredImage = (string)$post['featured_image']; ?><figure class="bp-post-featured"><img src="<?php echo bp_attr(preg_match('#^https?://#i', $featuredImage) === 1 ? $featuredImage : bp_url($featuredImage)); ?>" alt="<?php echo bp_attr((string)($post['featured_image_alt'] ?? '')); ?>"></figure><?php endif; ?>
    <div class="bp-post-content"><?php echo $post['body'] ?? ''; ?></div>
    <?php if (is_array($previousPost ?? null) || is_array($nextPost ?? null)): ?>
    <nav class="bp-post-navigation" aria-label="Article navigation">
        <?php if (is_array($previousPost ?? null)): ?><a class="bp-button bp-button-secondary" rel="prev" href="<?php echo bp_attr(bp_url('/blog/' . rawurlencode((string)($previousPost['slug'] ?? '')))); ?>"><span>Previous Article</span><strong><?php echo bp_esc((string)($previousPost['title'] ?? 'Untitled')); ?></strong></a><?php endif; ?>
        <?php if (is_array($nextPost ?? null)): ?><a class="bp-button bp-button-secondary bp-post-navigation-next" rel="next" href="<?php echo bp_attr(bp_url('/blog/' . rawurlencode((string)($nextPost['slug'] ?? '')))); ?>"><span>Next Article</span><strong><?php echo bp_esc((string)($nextPost['title'] ?? 'Untitled')); ?></strong></a><?php endif; ?>
    </nav>
    <?php endif; ?>
</article>
<?php if ($postLayout !== 'full'): ?><aside class="bp-post-sidebar" aria-label="Post sidebar">
    <?php foreach ((array)($widgets ?? []) as $widget): ?>
        <?php if (($widget['type'] ?? '') === 'recent_posts'): ?>
        <section class="bp-sidebar-widget"><h2>Recent posts</h2><ol><?php foreach (array_slice(array_values(array_filter((array)($recentPosts ?? []), static fn (array $recent): bool => ($recent['slug'] ?? '') !== ($post['slug'] ?? ''))), 0, 5) as $recent): ?><li><a href="<?php echo bp_attr(bp_url('/blog/' . rawurlencode((string)($recent['slug'] ?? '')))); ?>"><?php echo bp_esc((string)($recent['title'] ?? 'Untitled')); ?></a></li><?php endforeach; ?></ol></section>
        <?php else: ?>
        <section class="bp-sidebar-widget"><h2><?php echo bp_esc((string)($widget['title'] ?? '')); ?></h2><div><?php echo $widget['body'] ?? ''; ?></div></section>
        <?php endif; ?>
    <?php endforeach; ?>
</aside><?php endif; ?>
</div>
