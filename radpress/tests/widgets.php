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
        'widget_type' => ['html', 'recent_posts', 'tag_cloud', 'image_gallery', 'html'],
        'widget_target' => ['right_sidebar', 'all_sidebars', 'left_sidebar', 'all_sidebars', 'all_sidebars'],
        'widget_title' => ['Second', 'Recent posts', 'Topics', 'Gallery', 'Unused'],
        'widget_body' => ['<p>Second body</p>', '', '', '<img src="/media/example.jpg" alt="Example">', ''],
    ], ['REMOTE_ADDR' => '127.0.0.1']));

    assertWidgets($response->status() === 302, 'widget save should redirect after success');
    $saved = $files->readJson($config->paths()->contentPath('widgets/sidebar.json'))['widgets'] ?? [];
    assertWidgets(array_column($saved, 'title') === ['Second', 'Recent posts', 'Topics', 'Gallery'], 'widget save should preserve custom and built-in widget order while omitting unused rows');
    assertWidgets(($saved[1]['type'] ?? '') === 'recent_posts', 'recent posts should persist as a sortable built-in widget');
    assertWidgets(($saved[0]['target'] ?? '') === 'right_sidebar' && ($saved[2]['target'] ?? '') === 'left_sidebar', 'widget target sections should persist');
    assertWidgets(($saved[2]['type'] ?? '') === 'tag_cloud' && ($saved[3]['type'] ?? '') === 'image_gallery', 'additional built-in widget types should persist');
    assertWidgets(str_contains($controller->edit()->content(), 'Second body'), 'saved widgets should load back into the editor');
    assertWidgets(str_contains($controller->edit()->content(), 'Built-in widget'), 'recent posts should load as a visible sortable row');
    $posts = new \Batoi\Press\Content\PostRepository($config->paths(), $files, new \Batoi\Press\Core\HtmlContent());
    $posts->save(['title'=>'Widget article','slug'=>'widget-article','status'=>'published','tags'=>'Testing','body'=>'Article'], 'owner');
    $renderer = new \Batoi\Press\Core\PageBlockRenderer($config->paths(), $posts, new \Batoi\Press\Content\ProductRepository($config->paths(), $files, new \Batoi\Press\Core\HtmlContent()));
    $blocks = $renderer->render([['type'=>'widget','widget'=>'Recent posts'], ['type'=>'widget','widget'=>'Topics']]);
    assertWidgets(str_contains($blocks, 'Widget article') && str_contains($blocks, '/blog/widget-article') && str_contains($blocks, 'Testing'), 'page widget blocks must render dynamic recent posts and tag counts');

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
