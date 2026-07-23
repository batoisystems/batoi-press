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
        'item_label' => ['Company', 'Team', 'Leadership'],
        'item_url' => ['/company', '/company/team', '/company/team/leadership'],
        'item_parent' => ['', '/company', '/company/team'],
    ], ['REMOTE_ADDR' => '127.0.0.1']));
    assertNavigation($response->status() === 302, 'menu hierarchy save should redirect');
    $items = $files->readJson($config->paths()->contentPath('menus/main.json'))['items'] ?? [];
    assertNavigation(($items[1]['parent'] ?? '') === '/company', 'submenu parent URLs should persist');
    assertNavigation(($items[2]['parent'] ?? '') === '/company/team', 'multi-level submenu parent URLs should persist');
    assertNavigation(str_contains($controller->edit()->content(), 'Parent URL'), 'menu editor should expose hierarchy controls');

    $normalize = new ReflectionMethod($controller, 'normalizeHierarchy');
    $cycle = $normalize->invoke($controller, [
        ['label' => 'One', 'url' => '/one', 'parent' => '/two'],
        ['label' => 'Two', 'url' => '/two', 'parent' => '/one'],
    ]);
    assertNavigation(($cycle[0]['parent'] ?? '') === '' || ($cycle[1]['parent'] ?? '') === '', 'circular submenu relationships should be broken on save');

    $headerSource = (string)file_get_contents(dirname(__DIR__) . '/theme/default/partials/header.php');
    assertNavigation(str_contains($headerSource, 'bp-submenu') && str_contains($headerSource, 'bp_is_current_url'), 'default theme should render nested menus with active-route classes');

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
