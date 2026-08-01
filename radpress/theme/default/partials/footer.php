<?php
declare(strict_types=1);

use Batoi\Press\Content\MenuRepository;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;

$footerMenu = (new MenuRepository(Config::load(dirname(__DIR__, 4))->paths(), new FileStore()))->load('footer');
$footerItems = array_values(array_filter((array)($footerMenu['items'] ?? []), static fn (mixed $item): bool => is_array($item) && ($item['enabled'] ?? true) === true));
if ($footerItems === []) {
    $footerItems = [
        ['id' => 'mi_footer_home', 'type' => 'link', 'label' => 'Home', 'url' => '/', 'parent_id' => null, 'enabled' => true],
        ['id' => 'mi_footer_blog', 'type' => 'link', 'label' => 'Blog', 'url' => '/blog', 'parent_id' => null, 'enabled' => true],
        ['id' => 'mi_footer_sitemap', 'type' => 'link', 'label' => 'Sitemap', 'url' => '/sitemap.xml', 'parent_id' => null, 'enabled' => true],
        ['id' => 'mi_footer_rss', 'type' => 'link', 'label' => 'RSS', 'url' => '/feed.xml', 'parent_id' => null, 'enabled' => true],
    ];
}
$footerIds = [];
$footerChildren = ['' => []];
foreach ($footerItems as $item) {
    $id = (string)($item['id'] ?? '');
    if ($id !== '') $footerIds[$id] = true;
}
foreach ($footerItems as $item) {
    $parent = (string)($item['parent_id'] ?? '');
    if ($parent !== '' && !isset($footerIds[$parent])) $parent = '';
    $footerChildren[$parent][] = $item;
}
$renderFooterMenu = function (string $parent = '', int $depth = 0, array $trail = []) use (&$renderFooterMenu, $footerChildren): void {
    if ($depth >= MenuRepository::MAX_DEPTH || empty($footerChildren[$parent])) return;
    echo '<ul' . ($depth === 0 ? ' class="bp-footer-menu"' : '') . '>';
    foreach ($footerChildren[$parent] as $item) {
        $id = (string)($item['id'] ?? '');
        if ($id === '' || isset($trail[$id])) continue;
        $type = (string)($item['type'] ?? 'link');
        if ($type === 'separator') continue;
        $label = (string)($item['label'] ?? 'Untitled');
        $url = (string)($item['url'] ?? '');
        echo '<li>';
        if ($type === 'heading' || $url === '') {
            echo '<span class="bp-footer-menu-heading">' . bp_esc($label) . '</span>';
        } else {
            $target = (string)($item['target'] ?? '_self') === '_blank' ? '_blank' : '_self';
            echo '<a href="' . bp_attr(bp_url($url)) . '"' . ($target === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . bp_esc($label) . '</a>';
        }
        $next = $trail;
        $next[$id] = true;
        $renderFooterMenu($id, $depth + 1, $next);
        echo '</li>';
    }
    echo '</ul>';
};
?>
<footer class="bp-footer">
    <div class="bp-footer-inner">
        <div class="bp-footer-brand"><strong><?php echo bp_esc((string)($site['name'] ?? 'Batoi Press')); ?></strong><p><?php echo bp_esc((string)($site['tagline'] ?? '')); ?></p></div>
        <nav class="bp-footer-links" aria-label="Footer navigation"><?php $renderFooterMenu(); ?></nav>
        <p class="bp-footer-meta">&copy; <?php echo date('Y'); ?> <?php echo bp_esc((string)($site['name'] ?? 'Batoi Press')); ?></p>
    </div>
</footer>
