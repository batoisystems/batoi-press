<?php
declare(strict_types=1);
?>
<?php $requestedPostLayout = (string)($post['layout'] ?? 'full'); $postLayout = in_array($requestedPostLayout, ['full', 'sidebar-right', 'sidebar-left'], true) ? $requestedPostLayout : 'full'; ?>
<div class="bp-post-shell bp-post-layout-<?php echo bp_attr($postLayout); ?>">
<article class="bp-article bp-prose bp-post">
    <header class="bp-post-header"><p class="bp-eyebrow">Article</p><h1><?php echo bp_esc((string)($post['title'] ?? 'Untitled')); ?></h1><p class="bp-meta"><?php echo bp_esc(bp_date((string)($post['published_at'] ?? ''))); ?><?php echo !empty($post['author']) ? ' · ' . bp_esc((string)$post['author']) : ''; ?></p></header>
    <?php if (!empty($post['featured_image'])): $featuredImage = (string)$post['featured_image']; ?><figure class="bp-post-featured"><img src="<?php echo bp_attr(preg_match('#^https?://#i', $featuredImage) === 1 ? $featuredImage : bp_url($featuredImage)); ?>" alt=""></figure><?php endif; ?>
    <div class="bp-post-content"><?php echo $post['body'] ?? ''; ?></div>
</article>
<?php if ($postLayout !== 'full'): ?><aside class="bp-post-sidebar" aria-label="Post sidebar">
    <?php foreach ((array)($widgets ?? []) as $widget): ?><section class="bp-sidebar-widget"><h2><?php echo bp_esc((string)($widget['title'] ?? '')); ?></h2><div><?php echo $widget['body'] ?? ''; ?></div></section><?php endforeach; ?>
    <section class="bp-sidebar-widget"><h2>Recent posts</h2><ol><?php foreach (array_slice((array)($recentPosts ?? []), 0, 5) as $recent): ?><li><a href="/blog/<?php echo bp_attr((string)($recent['slug'] ?? '')); ?>"><?php echo bp_esc((string)($recent['title'] ?? 'Untitled')); ?></a></li><?php endforeach; ?></ol></section>
</aside><?php endif; ?>
</div>
