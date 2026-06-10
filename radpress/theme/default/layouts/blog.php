<?php
declare(strict_types=1);
?>
<section class="bp-article">
    <h1>Blog</h1>
    <?php if (empty($posts)): ?>
        <p>No published posts yet.</p>
    <?php else: ?>
        <ul class="bp-post-list">
            <?php foreach ($posts as $item): ?>
                <li>
                    <h2><a href="/blog/<?php echo bp_attr((string)$item['slug']); ?>"><?php echo bp_esc((string)$item['title']); ?></a></h2>
                    <p class="bp-meta"><?php echo bp_esc(bp_date((string)($item['published_at'] ?? ''))); ?></p>
                    <p><?php echo bp_esc((string)($item['seo_description'] ?? '')); ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

