<?php
declare(strict_types=1);

use Batoi\Press\Admin\WidgetController;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Session;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';

$root = sys_get_temp_dir() . '/batoi-press-widgets-' . bin2hex(random_bytes(4));
try {
    mkdir($root . '/radpress/config', 0775, true);
    mkdir($root . '/radpress/content', 0775, true);
    mkdir($root . '/radpress/data', 0775, true);
    file_put_contents($root . '/radpress/config/paths.json', json_encode([
        'config' => 'radpress/config',
        'content' => 'radpress/content',
        'data' => 'radpress/data',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

    $config = Config::load($root);
    $files = new FileStore();
    $session = new Session('batoi_press_widget_test', $config->paths()->dataPath('sessions'));
    $csrf = new Csrf($session);
    $controller = new WidgetController($config, $files, $csrf, new AuditLog($config->paths(), $files), ['username' => 'editor', 'role' => 'editor']);
    $response = $controller->save(new Request('POST', '/admin/widgets/save', [], [
        'csrf_token' => $csrf->token(),
        'widget_title' => ['Second', 'First', 'Unused'],
        'widget_body' => ['<p>Second body</p>', '<p onclick="alert(1)">First body</p>', ''],
    ], ['REMOTE_ADDR' => '127.0.0.1']));

    assertWidgets($response->status() === 302, 'widget save should redirect after success');
    $saved = $files->readJson($config->paths()->contentPath('widgets/sidebar.json'))['widgets'] ?? [];
    assertWidgets(array_column($saved, 'title') === ['Second', 'First'], 'widget save should preserve submitted order and omit unused rows');
    assertWidgets(!str_contains((string)($saved[1]['body'] ?? ''), 'onclick='), 'widget HTML should be sanitized before persistence');
    assertWidgets(str_contains($controller->edit()->content(), 'Second body'), 'saved widgets should load back into the editor');

    echo "Widget checks passed\n";
} finally {
    removeWidgetFixture($root);
}

function assertWidgets(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeWidgetFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($path);
}
