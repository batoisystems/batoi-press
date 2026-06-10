<?php
declare(strict_types=1);
?>
<article class="bp-article">
    <p class="bp-meta"><?php echo bp_esc(bp_date((string)($post['published_at'] ?? ''))); ?></p>
    <?php echo $post['body'] ?? ''; ?>
</article>

