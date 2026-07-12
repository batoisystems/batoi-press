<?php
declare(strict_types=1);
?>
<footer class="bp-footer">
    <div class="bp-footer-inner">
        <div class="bp-footer-brand"><strong><?php echo bp_esc((string)($site['name'] ?? 'Batoi Press')); ?></strong><p><?php echo bp_esc((string)($site['tagline'] ?? '')); ?></p></div>
        <nav class="bp-footer-links" aria-label="Footer navigation"><a href="<?php echo bp_attr(bp_url('/')); ?>">Home</a><a href="<?php echo bp_attr(bp_url('/blog')); ?>">Blog</a><a href="<?php echo bp_attr(bp_url('/sitemap.xml')); ?>">Sitemap</a><a href="<?php echo bp_attr(bp_url('/feed.xml')); ?>">RSS</a></nav>
        <p class="bp-footer-meta">&copy; <?php echo date('Y'); ?> <?php echo bp_esc((string)($site['name'] ?? 'Batoi Press')); ?></p>
    </div>
</footer>
