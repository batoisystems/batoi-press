<?php
declare(strict_types=1);

use Batoi\Press\Admin\MenuController;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Session;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';

$root = sys_get_temp_dir() . '/batoi-press-navigation-' . bin2hex(random_bytes(4));

try {
    foreach (['radpress/config', 'radpress/content/menus', 'radpress/content/pages/home', 'radpress/data/sessions', 'radpress/data/log'] as $directory) {
        mkdir($root . '/' . $directory, 0775, true);
    }
    file_put_contents($root . '/radpress/config/paths.json', json_encode([
        'config' => 'radpress/config',
        'content' => 'radpress/content',
        'data' => 'radpress/data',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    file_put_contents($root . '/radpress/config/site.json', json_encode(['homepage' => 'home'], JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/pages/home/meta.json', json_encode([
        'title' => 'Home',
        'slug' => 'home',
        'status' => 'published',
    ], JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    file_put_contents($root . '/radpress/content/pages/home/body.html', '<h1>Home</h1>', LOCK_EX);

    $config = Config::load($root);
    $files = new FileStore();
    $csrf = new Csrf(new Session('batoi_press_navigation_test', $config->paths()->dataPath('sessions')));
    $controller = new MenuController($config, $files, $csrf, new AuditLog($config->paths(), $files), ['username' => 'owner', 'role' => 'owner']);
    $response = $controller->save(new Request('POST', '/admin/menus/save', [], [
        'csrf_token' => $csrf->token(),
        'homepage' => 'home',
        'menu_id' => 'menu_main',
        'menu_revision' => '0',
        'menu_location' => 'primary',
        'item_order' => ['mi_company', 'mi_team', 'mi_leadership'],
        'menu_items' => [
            'mi_company' => ['type' => 'page', 'label' => 'Company', 'url' => '/company', 'parent_id' => '', 'presentation' => 'mega', 'column' => '1', 'target' => '_self', 'enabled' => '1'],
            'mi_team' => ['type' => 'heading', 'label' => 'Team', 'url' => '', 'parent_id' => 'mi_company', 'presentation' => 'link', 'column' => '1', 'target' => '_self', 'enabled' => '1'],
            'mi_leadership' => ['type' => 'page', 'label' => 'Leadership', 'url' => '/company/team/leadership', 'parent_id' => 'mi_team', 'presentation' => 'link', 'description' => 'Meet our leadership', 'column' => '2', 'target' => '_self', 'enabled' => '1'],
        ],
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    assertNavigation($response->status() === 302, 'menu hierarchy save should redirect');
    $stored = $files->readJson($config->paths()->contentPath('menus/main.json'));
    $items = $stored['items'] ?? [];
    assertNavigation(($stored['schema_version'] ?? 0) === 2 && ($stored['revision'] ?? 0) === 1, 'menu saves should use the revisioned schema version 2 contract');
    assertNavigation(($items[1]['parent_id'] ?? '') === 'mi_company', 'submenu stable parent IDs should persist');
    assertNavigation(($items[2]['parent_id'] ?? '') === 'mi_team', 'multi-level stable parent IDs should persist');
    assertNavigation(($items[0]['presentation'] ?? '') === 'mega', 'mega-menu presentation should persist');
    $editor = $controller->edit()->content();
    assertNavigation(str_contains($editor, 'Navigation tree') && str_contains($editor, 'Structure preview'), 'menu editor should expose professional tree and preview controls');
    assertNavigation(str_contains($editor, 'data-bp-add-menu-library') && str_contains($editor, 'Top-level display'), 'menu editor should expose an item library and presentation controls');

    $invalid = $controller->save(new Request('POST', '/admin/menus/save', [], [
        'csrf_token' => $csrf->token(),
        'homepage' => 'home',
        'menu_id' => 'menu_main',
        'menu_revision' => '1',
        'item_order' => ['mi_cycle_one', 'mi_cycle_two'],
        'menu_items' => [
            'mi_cycle_one' => ['type' => 'link', 'label' => 'One', 'url' => '/one', 'parent_id' => 'mi_cycle_two', 'enabled' => '1'],
            'mi_cycle_two' => ['type' => 'link', 'label' => 'Two', 'url' => '/two', 'parent_id' => 'mi_cycle_one', 'enabled' => '1'],
        ],
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    assertNavigation($invalid->status() === 422 && str_contains($invalid->content(), 'Menu not saved'), 'invalid circular hierarchy should return actionable editor validation');

    $headerSource = (string)file_get_contents(dirname(__DIR__) . '/theme/default/partials/header.php');
    $themeScript = (string)file_get_contents(dirname(__DIR__) . '/theme/default/assets/js/theme.js');
    assertNavigation(str_contains($headerSource, 'bp-mega-menu') && str_contains($headerSource, 'bp-submenu-toggle'), 'default theme should render mega menus and explicit submenu disclosures');
    assertNavigation(str_contains($headerSource, 'bp_is_current_url') && str_contains($headerSource, 'is-current-ancestor'), 'default theme should render active route and ancestor classes');
    assertNavigation(str_contains($themeScript, 'aria-expanded') && str_contains($themeScript, "event.key !== 'Escape'"), 'default theme should manage disclosure state and Escape behavior');

    echo "Navigation hierarchy checks passed\n";
} finally {
    removeNavigationFixture($root);
}

function assertNavigation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeNavigationFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($path);
}
