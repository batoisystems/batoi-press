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
        'expected_revision' => (new \Batoi\Press\Content\WidgetRepository($config->paths()))->load()['revision'],
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
    assertWidgets(str_contains($controller->edit()->content(), 'name="expected_revision"'), 'widget forms carry a revision precondition');
    $stale = $controller->save(new Request('POST', '/admin/widgets/save', [], ['csrf_token' => $csrf->token(), 'expected_revision' => \Batoi\Press\Application\ContentRevision::for([]), 'widget_type' => ['tag_cloud'], 'widget_title' => ['Stale edit']], []));
    assertWidgets($stale->status() === 409 && $files->readJson($config->paths()->contentPath('widgets/sidebar.json'))['widgets'] === $saved, 'stale browser widget forms cannot overwrite newer content');
    $posts = new \Batoi\Press\Content\PostRepository($config->paths(), $files, new \Batoi\Press\Core\HtmlContent());
    $posts->save(['title'=>'Widget article','slug'=>'widget-article','status'=>'published','tags'=>'Testing','body'=>'Article'], 'owner');
    $renderer = new \Batoi\Press\Core\PageBlockRenderer($config->paths(), $posts, new \Batoi\Press\Content\ProductRepository($config->paths(), $files, new \Batoi\Press\Core\HtmlContent()));
    $blocks = $renderer->render([['type'=>'widget','widget'=>'Recent posts'], ['type'=>'widget','widget'=>'Topics']]);
    assertWidgets(str_contains($blocks, 'Widget article') && str_contains($blocks, '/blog/widget-article') && str_contains($blocks, 'Testing'), 'page widget blocks must render dynamic recent posts and tag counts');

    $gallery = \Batoi\Press\Core\WidgetRenderer::render(['type'=>'image_gallery','gallery_images'=>"/media/a.jpg | A & B\njavascript:alert(1) | Bad"], [], []);
    assertWidgets(substr_count($gallery, '<img ') === 1 && str_contains($gallery, 'A &amp; B'), 'gallery should escape alt text and reject executable URLs');
    $calendar = \Batoi\Press\Core\WidgetRenderer::render(['type'=>'activity_calendar'], [['title'=>'Today <event>','slug'=>'today','published_at'=>date(DATE_ATOM)]], ['today'=>'/news/today']);
    assertWidgets(str_contains($calendar, '<caption>' . date('F Y')) && substr_count($calendar, '<th scope=') === 7 && str_contains($calendar,'Today &lt;event&gt;'), 'calendar should render a real month grid with escaped dated post links');
    $signup = \Batoi\Press\Core\WidgetRenderer::render(['type'=>'subscribe','subscribe_url'=>'https://example.org/subscribe'], [], []);
    assertWidgets(str_contains($signup,'Subscribe to newsletter') && !str_contains($signup,'<form'), 'newsletter widget must link to configured signup without collecting personal data');
    $strict = \Batoi\Press\Core\WidgetRenderer::normalizeList([['type' => 'html', 'title' => 'Safe preview', 'body' => '<p>Body</p><script>alert(1)</script>']], true);
    assertWidgets($strict[0]['type'] === 'recent_posts' && $strict[1]['body'] === '<p>Body</p>', 'machine preparation shares sanitization and the existing required Recent Posts behavior');
    foreach ([
        [['type' => 'html', 'title' => 'Bad', 'body' => 'Body', 'custom_js' => 'alert(1)']],
        [['type' => 'html', 'title' => ['invalid'], 'body' => 'Body']],
        [['type' => 'unknown', 'title' => 'Bad']],
        [['type' => 'image_gallery', 'title' => 'Bad', 'gallery_images' => 'javascript:alert(1) | Bad']],
        [['type' => 'image_gallery', 'title' => 'Bad', 'gallery_images' => '/%2fevil.example/image.png | Bad']],
        [['type' => 'subscribe', 'title' => 'Bad', 'subscribe_url' => 'https://user:secret@example.test/signup']],
        [['type' => 'recent_posts'], ['type' => 'recent_posts']],
        array_fill(0, 50, ['type' => 'tag_cloud', 'title' => 'Tags']),
    ] as $invalidWidgets) {
        try { \Batoi\Press\Core\WidgetRenderer::normalizeList($invalidWidgets, true); throw new LogicException('Invalid widget preparation accepted'); }
        catch (RuntimeException $exception) { assertWidgets($exception->getMessage() !== '', 'invalid machine widgets produce a validation message'); }
    }
    assertWidgets(($files->readJson($config->paths()->contentPath('widgets/sidebar.json'))['widgets'] ?? []) === $saved, 'widget preparation never changes stored sidebar content');
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
