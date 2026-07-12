<?php
declare(strict_types=1);
?>
<article class="bp-article bp-prose bp-post">
    <header class="bp-post-header"><p class="bp-eyebrow">Article</p><h1><?php echo bp_esc((string)($post['title'] ?? 'Untitled')); ?></h1><p class="bp-meta"><?php echo bp_esc(bp_date((string)($post['published_at'] ?? ''))); ?><?php echo !empty($post['author']) ? ' · ' . bp_esc((string)$post['author']) : ''; ?></p></header>
    <div class="bp-post-content"><?php echo $post['body'] ?? ''; ?></div>
</article>
