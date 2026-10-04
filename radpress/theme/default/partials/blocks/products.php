<?php
declare(strict_types=1);
/** $title, $block, published/filtered $items (with url), and $escape. */
?>
<section class="bp-content-block"><?php if ($title !== ''): ?><h2><?php echo $escape($title); ?></h2><?php endif; ?><div class="bp-product-grid">
<?php foreach ($items as $item): ?>
<article class="bp-product-card"><h3><a href="<?php echo $escape($item['url']); ?>"><?php echo $escape((string)($item['title'] ?? 'Untitled')); ?></a></h3><p class="bp-price"><?php echo $escape((string)($item['currency'] ?? 'USD') . ' ' . (string)($item['price'] ?? '0.00')); ?></p></article>
<?php endforeach; ?>
</div></section>
