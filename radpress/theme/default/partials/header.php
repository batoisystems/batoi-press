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
?>
<header class="bp-header">
    <nav class="bp-nav" aria-label="Main navigation">
        <a class="bp-brand" href="<?php echo bp_attr(bp_url('/')); ?>"><?php echo bp_esc((string)($site['name'] ?? 'Batoi Press')); ?></a>
        <div class="bp-links">
            <?php foreach ($menuItems as $item): ?>
            <a href="<?php echo bp_attr(bp_url((string)$item['url'])); ?>"><?php echo bp_esc((string)$item['label']); ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
</header>
