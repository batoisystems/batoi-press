<?php
declare(strict_types=1);
?>
<section class="bp-page bp-commerce-page bp-shop-page">
    <header class="bp-commerce-toolbar"><div><p class="bp-eyebrow">Catalogue</p><h1>Shop</h1><p>Browse available products.</p></div></header>
    <?php if (empty($products)): ?><p>No published products yet.</p><?php else: ?><div class="bp-product-grid">
    <?php foreach ($products as $item): $url = bp_url('/product/' . rawurlencode((string)($item['slug'] ?? ''))); ?><article class="bp-product-card">
        <?php if (!empty($item['image'])): ?><a class="bp-product-card-media" href="<?php echo bp_attr($url); ?>"><img src="<?php echo bp_attr(preg_match('#^https?://#i', (string)$item['image']) ? (string)$item['image'] : bp_url((string)$item['image'])); ?>" alt="<?php echo bp_attr((string)($item['image_alt'] ?? '')); ?>"></a><?php endif; ?>
        <h2><a href="<?php echo bp_attr($url); ?>"><?php echo bp_esc((string)($item['title'] ?? 'Untitled')); ?></a></h2><p class="bp-price"><?php echo bp_esc((string)($item['currency'] ?? 'USD') . ' ' . (string)($item['price'] ?? '0.00')); ?></p>
    </article><?php endforeach; ?></div><?php endif; ?>
</section>
