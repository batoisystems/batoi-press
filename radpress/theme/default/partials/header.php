<?php
declare(strict_types=1);

use Batoi\Press\Content\MenuRepository;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\BrandAssetManager;

$pressRoot = dirname(__DIR__, 4);
$menu = (new MenuRepository(Config::load($pressRoot)->paths(), new FileStore()))->load();
$menuItems = array_values(array_filter((array)($menu['items'] ?? []), static function (mixed $item): bool {
    if (!is_array($item) || ($item['enabled'] ?? true) !== true) {
        return false;
    }
    $type = (string)($item['type'] ?? 'link');
    return $type === 'separator' || trim((string)($item['label'] ?? '')) !== '';
}));
if ($menuItems === []) {
    $menuItems = [
        ['id' => 'mi_fallback_home', 'type' => 'link', 'label' => 'Home', 'url' => '/', 'parent_id' => null, 'presentation' => 'link', 'enabled' => true],
        ['id' => 'mi_fallback_about', 'type' => 'link', 'label' => 'About', 'url' => '/about', 'parent_id' => null, 'presentation' => 'link', 'enabled' => true],
        ['id' => 'mi_fallback_blog', 'type' => 'link', 'label' => 'Blog', 'url' => '/blog', 'parent_id' => null, 'presentation' => 'link', 'enabled' => true],
    ];
}

$menuById = [];
$menuChildren = ['' => []];
foreach ($menuItems as $item) {
    $id = (string)($item['id'] ?? '');
    if ($id !== '') {
        $menuById[$id] = $item;
    }
}
foreach ($menuItems as $item) {
    $id = (string)($item['id'] ?? '');
    $parentId = (string)($item['parent_id'] ?? '');
    if ($parentId === $id || ($parentId !== '' && !isset($menuById[$parentId]))) {
        $parentId = '';
    }
    $menuChildren[$parentId][] = $item;
}

$branchIsCurrent = function (string $id, array $trail = []) use (&$branchIsCurrent, $menuById, $menuChildren): bool {
    if ($id === '' || isset($trail[$id]) || !isset($menuById[$id])) {
        return false;
    }
    $trail[$id] = true;
    $item = $menuById[$id];
    $url = (string)($item['url'] ?? '');
    if ($url !== '' && bp_is_current_url($url)) {
        return true;
    }
    foreach ($menuChildren[$id] ?? [] as $child) {
        if ($branchIsCurrent((string)($child['id'] ?? ''), $trail)) {
            return true;
        }
    }
    return false;
};

$renderMenu = function (string $parent = '', int $depth = 0, array $trail = [], string $parentPresentation = 'link') use (&$renderMenu, $menuChildren, $branchIsCurrent): void {
    if ($depth >= MenuRepository::MAX_DEPTH || empty($menuChildren[$parent])) {
        return;
    }
    $mega = $depth === 1 && $parentPresentation === 'mega';
    $listClass = $depth === 0 ? 'bp-menu-list' : 'bp-submenu' . ($mega ? ' bp-mega-menu' : '');
    $columns = 1;
    if ($mega) {
        foreach ($menuChildren[$parent] as $child) {
            $columns = max($columns, min(MenuRepository::MAX_MEGA_COLUMNS, (int)($child['column'] ?? 1)));
        }
    }
    echo '<ul class="' . bp_attr($listClass) . '"' . ($mega ? ' style="--bp-mega-columns:' . $columns . '"' : '') . '>';
    foreach ($menuChildren[$parent] as $item) {
        $id = (string)($item['id'] ?? '');
        if ($id === '' || isset($trail[$id])) {
            continue;
        }
        $type = (string)($item['type'] ?? 'link');
        if ($type === 'separator') {
            echo '<li class="bp-menu-separator" role="separator"></li>';
            continue;
        }
        $url = (string)($item['url'] ?? '');
        $children = !empty($menuChildren[$id]);
        $current = $url !== '' && bp_is_current_url($url);
        $currentBranch = $branchIsCurrent($id);
        $presentation = $depth === 0 && in_array(($item['presentation'] ?? 'link'), ['dropdown', 'mega'], true)
            ? (string)$item['presentation']
            : 'link';
        $submenuId = 'bp-submenu-' . preg_replace('/[^A-Za-z0-9_-]/', '', $id);
        $column = max(1, min(MenuRepository::MAX_MEGA_COLUMNS, (int)($item['column'] ?? 1)));
        $classes = 'bp-menu-item bp-menu-type-' . preg_replace('/[^a-z_-]/', '', $type)
            . ($children ? ' has-children' : '')
            . ($currentBranch && !$current ? ' is-current-ancestor' : '')
            . ($presentation !== 'link' ? ' is-' . $presentation : '')
            . ($mega ? ' bp-mega-column-' . $column : '');
        echo '<li class="' . bp_attr($classes) . '" data-bp-menu-item>';
        if ($type === 'heading' || $url === '') {
            echo '<div class="bp-menu-item-control"><span class="bp-menu-heading">' . bp_esc((string)($item['label'] ?? 'Untitled')) . '</span>';
            if ($children) {
                echo '<button class="bp-submenu-toggle" type="button" aria-expanded="false" aria-controls="' . bp_attr($submenuId) . '" aria-label="Show submenu for ' . bp_attr((string)($item['label'] ?? 'item')) . '"><span aria-hidden="true"></span></button>';
            }
            echo '</div>';
        } else {
            $target = (string)($item['target'] ?? '_self') === '_blank' ? '_blank' : '_self';
            echo '<div class="bp-menu-item-control"><a class="' . ($current ? 'is-active' : '') . '"' . ($current ? ' aria-current="page"' : '') . ' href="' . bp_attr(bp_url($url)) . '"' . ($target === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . bp_esc((string)($item['label'] ?? 'Untitled')) . '</a>';
            if ($children) {
                echo '<button class="bp-submenu-toggle" type="button" aria-expanded="false" aria-controls="' . bp_attr($submenuId) . '" aria-label="Show submenu for ' . bp_attr((string)($item['label'] ?? 'item')) . '"><span aria-hidden="true"></span></button>';
            }
            echo '</div>';
        }
        $description = trim((string)($item['description'] ?? ''));
        if ($description !== '') {
            echo '<small class="bp-menu-description">' . bp_esc($description) . '</small>';
        }
        if ($children) {
            $nextTrail = $trail;
            $nextTrail[$id] = true;
            echo '<div class="bp-submenu-panel" id="' . bp_attr($submenuId) . '" data-bp-submenu-panel>';
            $renderMenu($id, $depth + 1, $nextTrail, $presentation);
            echo '</div>';
        }
        echo '</li>';
    }
    echo '</ul>';
};
?>
<header class="bp-header">
    <nav class="bp-nav" aria-label="Main navigation" data-bp-primary-navigation>
        <?php
        $brandDisplay = (string)($branding['display'] ?? 'text');
        $brandName = (string)($branding['site_name'] ?? ($site['name'] ?? 'Batoi Press'));
        $brandLogo = (string)($branding['logo_url'] ?? '');
        $darkBrandLogo = (new BrandAssetManager(Config::load($pressRoot)->paths()))->resolveUrl((string)($site['brand_logo_dark'] ?? ''));
        ?>
        <a class="bp-brand bp-brand-mode-<?php echo bp_attr($brandDisplay); ?>" href="<?php echo bp_attr(bp_url('/')); ?>" aria-label="<?php echo bp_attr($brandName); ?>">
            <?php if ($brandLogo !== '' && in_array($brandDisplay, ['logo', 'logo_with_text'], true)): ?>
            <img class="bp-brand-logo bp-brand-logo-default" src="<?php echo bp_attr(bp_url($brandLogo)); ?>" alt="<?php echo bp_attr((string)($branding['logo_alt'] ?? $brandName)); ?>"><?php if ($darkBrandLogo !== null): ?><img class="bp-brand-logo bp-brand-logo-dark" src="<?php echo bp_attr(bp_url($darkBrandLogo)); ?>" alt="<?php echo bp_attr((string)($branding['logo_alt'] ?? $brandName)); ?>"><?php endif; ?>
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
