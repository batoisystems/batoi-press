<?php
declare(strict_types=1);
/** $title, $block, published/filtered $items (with url), and $escape. */
?>
<section class="bp-content-block"><?php if ($title !== ''): ?><h2><?php echo $escape($title); ?></h2><?php endif; ?><div class="bp-post-grid">
<?php foreach ($items as $item): ?>
<article class="bp-post-card">
<?php if (!empty($block['show_image']) && $item['image_url'] !== ''): ?>
<a class="bp-post-card-media" href="<?php echo $escape($item['url']); ?>"><img loading="lazy" src="<?php echo $escape($item['image_url']); ?>" alt="<?php echo $escape((string)($item['featured_image_alt'] ?? '')); ?>"></a>
<?php endif; ?>
<?php if (!empty($block['show_date']) && !empty($item['published_at'])): ?>
<p class="bp-meta"><time datetime="<?php echo $escape((string)$item['published_at']); ?>"><?php echo $escape(function_exists('bp_date') ? bp_date((string)$item['published_at']) : substr((string)$item['published_at'], 0, 10)); ?></time></p>
<?php endif; ?>
<h3><a href="<?php echo $escape($item['url']); ?>"><?php echo $escape((string)($item['title'] ?? 'Untitled')); ?></a></h3><p><?php echo $escape((string)($item['seo_description'] ?? '')); ?></p>
<?php if (!empty($block['show_read_more'])): ?><a class="bp-text-link" href="<?php echo $escape($item['url']); ?>">Read more <span aria-hidden="true">&rarr;</span></a><?php endif; ?>
</article>
<?php endforeach; ?>
</div></section>
