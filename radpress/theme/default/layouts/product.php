<?php
declare(strict_types=1);
?>
<article class="bp-page bp-commerce-page bp-product-page"><section class="bp-product-detail">
    <?php if (!empty($product['image'])): ?><div class="bp-product-gallery-main"><img src="<?php echo bp_attr(preg_match('#^https?://#i', (string)$product['image']) ? (string)$product['image'] : bp_url((string)$product['image'])); ?>" alt="<?php echo bp_attr((string)($product['image_alt'] ?? '')); ?>"></div><?php endif; ?>
    <div class="bp-product-summary"><p class="bp-eyebrow"><?php echo bp_esc((string)($product['category'] ?? 'Product')); ?></p><h1><?php echo bp_esc((string)($product['title'] ?? 'Untitled')); ?></h1><p class="bp-price"><?php echo bp_esc((string)($product['currency'] ?? 'USD') . ' ' . (string)($product['price'] ?? '0.00')); ?></p><div class="bp-prose"><?php echo $product['body'] ?? ''; ?></div><p><strong>Availability:</strong> <?php echo (int)($product['inventory'] ?? 0) > 0 ? (int)$product['inventory'] . ' in stock' : 'Out of stock'; ?></p></div>
</section></article>
