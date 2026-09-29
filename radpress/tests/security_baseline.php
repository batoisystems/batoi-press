<?php
declare(strict_types=1);

use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\RateLimiter;
use Batoi\Press\Security\Session;
use Batoi\Press\Security\SecurityHeaders;
use Batoi\Press\Security\UploadGuard;

require dirname(__DIR__) . '/autoload.php';

$root = dirname(__DIR__, 2);

assertSame('radpress/config/installed.lock', (string)(securityConfig($root)['installer_lock'] ?? ''), 'installer lock path should be configured');
assertTrue(str_contains((string)file_get_contents($root . '/public_html/install.php'), 'installed.lock'), 'installer should check installed.lock');

foreach ([
    'radpress/.htaccess',
    'radpress/app/.htaccess',
    'radpress/config/.htaccess',
    'radpress/content/.htaccess',
    'radpress/data/.htaccess',
] as $path) {
    $contents = (string)file_get_contents($root . '/' . $path);
    assertTrue(str_contains($contents, 'Require all denied'), "{$path} should deny direct access");
}

$publicHtaccess = (string)file_get_contents($root . '/public_html/.htaccess');
assertTrue(str_contains($publicHtaccess, 'Options -Indexes'), 'public_html should disable directory indexes');
assertTrue(str_contains($publicHtaccess, '<FilesMatch "\\.(php|phtml|phar)$">'), 'public_html should deny arbitrary PHP entrypoints');
foreach (['index.php', 'admin.php', 'install.php'] as $entrypoint) {
    assertTrue(str_contains($publicHtaccess, '<Files "' . $entrypoint . '">'), "{$entrypoint} should be an explicit public entrypoint");
}

$uploads = (array)(securityConfig($root)['uploads'] ?? []);
$guard = new UploadGuard((array)($uploads['allowed_extensions'] ?? []), (int)($uploads['max_bytes'] ?? 5242880));
assertSame(null, $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 128, 'name' => 'asset.png']), 'allowed image upload should pass');
assertSame(null, $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 128, 'name' => 'theme.css']), 'allowed stylesheet upload should pass');
assertSame(null, $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 128, 'name' => 'theme.js']), 'allowed script upload should pass');
assertSame(null, $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 128, 'name' => 'module.mjs']), 'allowed module upload should pass');
assertSame(null, $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 128, 'name' => 'video.mp4']), 'allowed video upload should pass');
assertSame('File type is not allowed.', $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 128, 'name' => 'shell.php']), 'PHP upload should be rejected');
assertSame('File size is not allowed.', $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => 0, 'name' => 'asset.png']), 'empty upload should be rejected');
$safeName = $guard->safeName('My Unsafe File.PNG');
assertTrue((bool)preg_match('/^my-unsafe-file-[a-f0-9]{8}\.png$/', $safeName), 'safe upload name should be normalized');
$validPng = tempnam(sys_get_temp_dir(), 'bp-png-');
$fakePng = tempnam(sys_get_temp_dir(), 'bp-fake-');
file_put_contents($validPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
file_put_contents($fakePng, '<?php echo "not an image";');
try {
    assertSame(null, $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => filesize($validPng), 'name' => 'pixel.png', 'tmp_name' => $validPng]), 'valid image signature should pass');
    assertSame('Uploaded file content is not allowed.', $guard->validate(['error' => UPLOAD_ERR_OK, 'size' => filesize($fakePng), 'name' => 'payload.png', 'tmp_name' => $fakePng]), 'executable content disguised as an image should be rejected');
} finally {
    unlink($validPng);
    unlink($fakePng);
}

$headerConfig = Config::load($root);
$scriptResponse = Response::html('<script>untrusted()</script><script>approved()</script>')->withInlineScript('approved()');
$scriptResponse = SecurityHeaders::apply($scriptResponse, new Request('GET', '/', [], [], []), $headerConfig);
$scriptPolicy = (string)($scriptResponse->headers()['Content-Security-Policy-Report-Only'] ?? '');
assertTrue(str_contains($scriptPolicy, base64_encode(hash('sha256', 'approved()', true))), 'explicitly registered inline scripts must be allowed');
assertTrue(!str_contains($scriptPolicy, base64_encode(hash('sha256', 'untrusted()', true))), 'arbitrary response scripts must not automatically receive CSP authorization');
$secured = SecurityHeaders::apply(Response::html('ok'), new Request('GET', '/', [], [], ['HTTPS' => 'on']), $headerConfig);
assertSame('nosniff', (string)($secured->headers()['X-Content-Type-Options'] ?? ''), 'responses should prevent MIME sniffing');
assertSame('DENY', (string)($secured->headers()['X-Frame-Options'] ?? ''), 'responses should deny framing');
assertTrue(isset($secured->headers()['Content-Security-Policy-Report-Only']), 'default CSP rollout should be report-only');
assertTrue(str_starts_with((string)($secured->headers()['Strict-Transport-Security'] ?? ''), 'max-age='), 'HTTPS responses should carry HSTS');
assertTrue(!str_contains($secured->headers()['Content-Security-Policy-Report-Only'], 'upgrade-insecure-requests'), 'default report-only CSP must omit the enforcement-only upgrade directive');

$headerRoot = sys_get_temp_dir() . '/batoi-press-security-headers-' . bin2hex(random_bytes(4));
mkdir($headerRoot . '/radpress/config', 0775, true);
try {
    file_put_contents($headerRoot . '/radpress/config/paths.json', json_encode(['config' => 'radpress/config']));
    foreach (['report-only', 'enforce', 'off', 'unknown'] as $mode) {
        file_put_contents($headerRoot . '/radpress/config/security.json', json_encode(['headers' => ['csp_mode' => $mode]]));
        $modeConfig = Config::load($headerRoot);
        foreach ([['HTTPS' => 'on'], ['HTTP_X_FORWARDED_PROTO' => 'https'], []] as $server) {
            $isHttps = $server !== [];
            $routePolicy = "sandbox; default-src 'none'";
            $response = SecurityHeaders::apply(
                Response::html('ok')->withHeader('Content-Security-Policy', $routePolicy),
                new Request('GET', '/', [], [], $server),
                $modeConfig
            );
            $headers = $response->headers();
            $enforcedPolicy = $headers['Content-Security-Policy'];
            $reportPolicy = $headers['Content-Security-Policy-Report-Only'] ?? '';
            assertSame($isHttps, isset($headers['Strict-Transport-Security']), 'HSTS should depend on HTTPS, not CSP mode');
            assertTrue(!str_contains($reportPolicy, 'upgrade-insecure-requests'), 'report-only policies must not generate the upgrade directive');
            assertSame($mode === 'enforce' && $isHttps, str_contains($enforcedPolicy, 'upgrade-insecure-requests'), 'generated upgrade directive requires both enforcement and HTTPS');
            if ($mode === 'enforce') {
                assertTrue(str_starts_with($enforcedPolicy, $routePolicy . ', '), 'enforcement should intersect with the existing route policy');
                assertSame('', $reportPolicy, 'enforcement must not also emit a report-only policy');
            } else {
                assertSame($routePolicy, $enforcedPolicy, 'report-only and off modes must preserve route enforcement');
                assertSame($mode !== 'off', $reportPolicy !== '', 'unknown CSP modes should retain the report-only fallback');
            }
        }
    }
    $customPolicy = "default-src 'self'; upgrade-insecure-requests";
    file_put_contents($headerRoot . '/radpress/config/security.json', json_encode(['headers' => ['csp_mode' => 'enforce', 'content_security_policy' => $customPolicy]]));
    $customResponse = SecurityHeaders::apply(Response::html('ok'), new Request('GET', '/', [], [], ['HTTPS' => 'on']), Config::load($headerRoot));
    assertSame($customPolicy, $customResponse->headers()['Content-Security-Policy'], 'explicit custom policies must remain unchanged');
} finally {
    removeTree($headerRoot);
}

$sessionRoot = sys_get_temp_dir() . '/batoi-press-security-baseline-' . bin2hex(random_bytes(4));
mkdir($sessionRoot . '/sessions', 0775, true);
try {
    $session = new Session('batoi_press_security_' . bin2hex(random_bytes(3)), $sessionRoot . '/sessions', 60, 300);
    $csrf = new Csrf($session);
    $token = $csrf->token();
    assertTrue(strlen($token) === 64, 'CSRF token should be 32 random bytes encoded as hex');
    assertTrue($csrf->validate($token), 'CSRF should validate current token');
    assertTrue(!$csrf->validate('invalid-token'), 'CSRF should reject invalid token');
    $session->set('auth_user', 'owner');
    $_SESSION['_bp_created_at'] = time() - 301;
    $_SESSION['_bp_last_seen_at'] = time();
    assertSame(null, $session->get('auth_user'), 'absolute session lifetime should clear authenticated state');
    assertSame('absolute', $session->pull('_bp_expired_reason'), 'session expiry should retain a safe login notice reason');
    session_write_close();

    $paths = new Paths($sessionRoot, [
        'public_root' => 'public_html',
        'app' => 'radpress/app',
        'config' => 'radpress/config',
        'content' => 'radpress/content',
        'data' => 'radpress/data',
        'theme' => 'radpress/theme',
    ]);
    mkdir($sessionRoot . '/radpress/data/tmp', 0775, true);
    $limiter = new RateLimiter($paths, 2, 300);
    assertTrue(!$limiter->tooManyAttempts('login:test'), 'rate limiter should start clear');
    $limiter->hit('login:test');
    $limiter->hit('login:test');
    assertTrue($limiter->tooManyAttempts('login:test'), 'rate limiter should block after configured attempts');
    $limiter->clear('login:test');
    assertTrue(!$limiter->tooManyAttempts('login:test'), 'rate limiter clear should remove attempts');
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    removeTree($sessionRoot);
}

assertTrue(AdminAccess::canAccess(['role' => 'editor'], '/admin/pages/save', 'POST'), 'editors should keep publishing route access');
assertTrue(!AdminAccess::canAccess(['role' => 'viewer'], '/admin/pages', 'GET'), 'viewers should not access publishing routes');
assertTrue(AdminAccess::canManagePost(['username' => 'alice', 'role' => 'author'], ['author' => 'alice']), 'authors should manage own posts');
assertTrue(!AdminAccess::canManagePost(['username' => 'alice', 'role' => 'author'], ['author' => 'bob']), 'authors should not manage other users posts');

echo "Security baseline checks passed\n";

function securityConfig(string $root): array
{
    $decoded = json_decode((string)file_get_contents($root . '/radpress/config/security.json'), true);
    return is_array($decoded) ? $decoded : [];
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function removeTree(string $path): void
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
