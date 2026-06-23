<?php
declare(strict_types=1);
?>
<header class="bp-header">
    <nav class="bp-nav" aria-label="Main navigation">
        <a class="bp-brand" href="<?php echo bp_attr(bp_url('/')); ?>"><?php echo bp_esc((string)($site['name'] ?? 'Batoi Press')); ?></a>
        <div class="bp-links">
            <a href="<?php echo bp_attr(bp_url('/')); ?>">Home</a>
            <a href="<?php echo bp_attr(bp_url('/about')); ?>">About</a>
            <a href="<?php echo bp_attr(bp_url('/blog')); ?>">Blog</a>
            <a href="<?php echo bp_attr(bp_url('/admin')); ?>">Admin</a>
        </div>
    </nav>
</header>
