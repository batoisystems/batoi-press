<?php
declare(strict_types=1);
/** $title, sanitized $body, $block and $escape. */
?>
<section class="bp-content-block bp-content-gallery"><?php if ($title !== ''): ?><h2><?php echo $escape($title); ?></h2><?php endif; ?><?php echo $body; ?></section>
