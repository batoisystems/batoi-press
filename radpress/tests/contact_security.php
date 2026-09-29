<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use Batoi\Press\Application\ContactController;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;

$root = sys_get_temp_dir() . '/press-contact-security-' . bin2hex(random_bytes(6));
$files = new FileStore();
try {
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'data' => 'radpress/data', 'content' => 'radpress/content']);
    $controller = new ContactController(Config::load($root));
    $verify = new ReflectionMethod(ContactController::class, 'verifyRecaptcha');
    foreach ([
        [[], '', true],
        [['recaptcha_site_key' => 'configured'], '', false],
        [['recaptcha_secret_key' => 'invalid'], '', false],
        [['recaptcha_secret_key' => 'invalid'], 'fake-token', false],
    ] as [$config, $token, $expected]) {
        if ($verify->invoke($controller, $token, '127.0.0.1', $config) !== $expected) throw new RuntimeException('CAPTCHA configuration did not fail closed');
    }
    $request = new Request('POST', '/contact/submit', [], ['name' => 'Test', 'email' => 'test@example.invalid', 'message' => 'private enquiry marker'], ['REMOTE_ADDR' => '127.0.0.1']);
    // Mail is disabled: this exercises failure without sending an enquiry.
    $response = $controller->submit($request);
    if ($response->status() !== 503 || !str_contains($response->content(), $request->requestId) || str_contains($response->content(), 'delivery is disabled')) throw new RuntimeException('Public mail failure must be neutral and correlated');
    $log = (string)file_get_contents($root . '/radpress/data/log/audit.jsonl');
    if (!str_contains($log, $request->requestId) || str_contains($log, 'private enquiry marker') || str_contains($log, 'test@example.invalid')) throw new RuntimeException('Private audit must contain correlation, not enquiry data');
    $files->writeJson($root . '/radpress/config/integrations.json', ['recaptcha_site_key' => 'partial']);
    $response = (new ContactController(Config::load($root)))->submit($request);
    if ($response->status() !== 422) throw new RuntimeException('Partial CAPTCHA configuration reached mail delivery');
    echo "Contact security checks passed\n";
} finally {
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
        rmdir($root);
    }
}
