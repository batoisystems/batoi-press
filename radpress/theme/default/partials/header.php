<?php
declare(strict_types=1);

$menuPath = dirname(__DIR__, 3) . '/content/menus/main.json';
$menuItems = [];
if (is_file($menuPath)) {
    $menuData = json_decode((string)file_get_contents($menuPath), true);
    if (is_array($menuData)) {
        $menuItems = array_values(array_filter((array)($menuData['items'] ?? []), static function (mixed $item): bool {
            return is_array($item) && trim((string)($item['label'] ?? '')) !== '' && trim((string)($item['url'] ?? '')) !== '';
        }));
    }
}
if ($menuItems === []) {
    $menuItems = [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'About', 'url' => '/about'],
        ['label' => 'Blog', 'url' => '/blog'],
    ];
}

$menuUrls = [];
foreach ($menuItems as $item) {
    $menuUrls[(string)$item['url']] = true;
}
$menuChildren = ['' => []];
foreach ($menuItems as $item) {
    $parent = trim((string)($item['parent'] ?? ''));
    if ($parent === (string)$item['url'] || !isset($menuUrls[$parent])) {
        $parent = '';
    }
    $menuChildren[$parent][] = $item;
}
$renderMenu = function (string $parent = '', int $depth = 0, array $trail = []) use (&$renderMenu, $menuChildren): void {
    if ($depth > 8 || empty($menuChildren[$parent])) {
        return;
    }
    $listClass = $depth === 0 ? 'bp-menu-list' : 'bp-submenu';
    echo '<ul class="' . bp_attr($listClass) . '">';
    foreach ($menuChildren[$parent] as $item) {
        $url = (string)($item['url'] ?? '');
        if ($url === '' || isset($trail[$url])) {
            continue;
        }
        $children = !empty($menuChildren[$url]);
        $current = bp_is_current_url($url);
        echo '<li class="bp-menu-item' . ($children ? ' has-children' : '') . '">';
        echo '<a class="' . ($current ? 'is-active' : '') . '"' . ($current ? ' aria-current="page"' : '') . ' href="' . bp_attr(bp_url($url)) . '">' . bp_esc((string)($item['label'] ?? 'Untitled')) . '</a>';
        if ($children) {
            $nextTrail = $trail;
            $nextTrail[$url] = true;
            $renderMenu($url, $depth + 1, $nextTrail);
        }
        echo '</li>';
    }
    echo '</ul>';
};
?>
<header class="bp-header">
    <nav class="bp-nav" aria-label="Main navigation">
        <?php
        $brandDisplay = (string)($branding['display'] ?? 'text');
        $brandName = (string)($branding['site_name'] ?? ($site['name'] ?? 'Batoi Press'));
        $brandLogo = (string)($branding['logo_url'] ?? '');
        ?>
        <a class="bp-brand bp-brand-mode-<?php echo bp_attr($brandDisplay); ?>" href="<?php echo bp_attr(bp_url('/')); ?>" aria-label="<?php echo bp_attr($brandName); ?>">
            <?php if ($brandLogo !== '' && in_array($brandDisplay, ['logo', 'logo_with_text'], true)): ?>
            <img class="bp-brand-logo" src="<?php echo bp_attr(bp_url($brandLogo)); ?>" alt="<?php echo bp_attr((string)($branding['logo_alt'] ?? $brandName)); ?>">
            <?php endif; ?>
            <?php if ($brandDisplay !== 'logo'): ?>
            <span class="bp-brand-name"><?php echo bp_esc($brandName); ?></span>
            <?php endif; ?>
        </a>
        <button class="bp-nav-toggle" type="button" aria-expanded="false" aria-controls="bp-primary-links">
            <span class="bp-nav-toggle-icon" aria-hidden="true"></span>
            <span>Menu</span>
        </button>
        <div class="bp-links" id="bp-primary-links"><?php $renderMenu(); ?></div>
    </nav>
</header>
