<?php
declare(strict_types=1);

use Batoi\Press\Admin\AifController;
use Batoi\Press\Admin\AifEditorPanel;
use Batoi\Press\Aif\AifContext;
use Batoi\Press\Aif\AifManager;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Session;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';

$root = sys_get_temp_dir() . '/batoi-press-aif-integration-' . bin2hex(random_bytes(5));
foreach (['radpress/config', 'radpress/data/sessions', 'radpress/data/log'] as $directory) {
    mkdir($root . '/' . $directory, 0775, true);
}

try {
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', ['name' => 'AIF Test', 'base_url' => 'https://press.example.test']);
    $files->writeJson($root . '/radpress/config/aif.json', [
        'enabled' => true,
        'provider' => 'local',
        'workspace_required' => false,
        'features' => ['content_health' => true, 'seo_assist' => true, 'summarize' => true, 'tags' => true, 'draft_content' => true],
    ]);
    $config = Config::load($root);
    $manager = new AifManager($config->aif());
    $status = $manager->status();
    assertAif(($status['enabled'] ?? false) === true && ($status['available'] ?? false) === true, 'local Batoi AIF should be available only when explicitly enabled');
    assertAif(($status['provider'] ?? '') === 'local' && ($status['network_access'] ?? true) === false, 'local Batoi AIF should disclose that it uses no network');

    $context = [
        'content_type' => 'post',
        'title' => 'A practical publishing guide',
        'body' => '<script>secret-instruction</script><h2>Start here</h2><p>This practical publishing guide explains a careful editorial workflow. It helps teams review content before publication.</p>',
        'seo_description' => '',
    ];
    $prepared = AifContext::prepare($context);
    assertAif(!str_contains((string)($prepared['body_text'] ?? ''), 'secret-instruction'), 'AIF context should discard executable or embedded instruction blocks');
    assertAif(!isset($prepared['password']) && strlen((string)($prepared['body_text'] ?? '')) <= 50000, 'AIF context should be allowlisted and bounded');

    $seo = $manager->assist('seo_assist', $context);
    assertAif(($seo['ok'] ?? false) === true && ($seo['network_used'] ?? true) === false, 'local SEO suggestions should remain server-local');
    assertAif(isset($seo['suggestions']['seo_title'], $seo['suggestions']['seo_description']), 'SEO assistance should return reviewable field suggestions');
    $health = $manager->assist('content_health', $context);
    assertAif(isset($health['suggestions']['score'], $health['suggestions']['checks']), 'content health should return a bounded score and actionable checks');
    $tags = $manager->assist('tags', $context);
    assertAif(is_array($tags['suggestions']['tags'] ?? null), 'tag assistance should return structured terms');
    $disabledFeature = $manager->assist('translate', $context);
    assertAif(($disabledFeature['ok'] ?? true) === false, 'undeclared AIF features must remain unavailable');

    $session = new Session('batoi_press_aif_test', $config->paths()->dataPath('sessions'));
    $csrf = new Csrf($session);
    $controller = new AifController($config, $csrf, new AuditLog($config->paths(), $files), ['username' => 'editor', 'role' => 'editor']);
    $request = new Request('POST', '/admin/aif/assist', [], [
        'format' => 'json', 'csrf_token' => $csrf->token(), 'task' => 'seo_assist',
        'content_type' => 'post', 'title' => $context['title'], 'body' => $context['body'],
    ], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUEST_ID' => 'aif-test-request-01']);
    $response = $controller->assist($request);
    $payload = json_decode($response->content(), true);
    assertAif($response->status() === 200 && is_array($payload) && ($payload['review_required'] ?? false) === true, 'editor assist endpoint should return a review-required JSON suggestion');
    assertAif(($response->headers()['Cache-Control'] ?? '') === 'private, no-store', 'AIF responses should never be cached');

    $badCsrf = $controller->assist(new Request('POST', '/admin/aif/assist', [], ['format' => 'json', 'task' => 'seo_assist', 'csrf_token' => 'bad'], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json']));
    assertAif($badCsrf->status() === 400, 'AIF assist should require CSRF even for JSON editor requests');

    $panel = AifEditorPanel::render($config, 'post');
    assertAif(str_contains($panel, 'data-bp-aif-task="content_health"') && str_contains($panel, 'nothing is applied or published automatically'), 'post editor panel should expose enabled AIF actions with a human-review boundary');
    $audit = $files->read($root . '/radpress/data/log/audit.jsonl');
    assertAif(str_contains($audit, 'aif-test-request-01') && str_contains($audit, '"network_used":false'), 'AIF audit should capture safe provider and request metadata');
    assertAif(!str_contains($audit, 'practical publishing guide') && !str_contains($audit, 'secret-instruction'), 'AIF audit must not retain prompt or content values');

    echo "Batoi AIF integration checks passed\n";
} finally {
    removeAifFixture($root);
}

function assertAif(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeAifFixture(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($path);
}
