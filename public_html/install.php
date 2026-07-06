<?php
declare(strict_types=1);

require dirname(__DIR__) . '/radpress/autoload.php';
require dirname(__DIR__) . '/radpress/helpers/url.php';

use Batoi\Press\Security\Password;

$root = dirname(__DIR__);
$configDir = $root . '/radpress/config';
$lock = $configDir . '/installed.lock';

header('Content-Type: text/html; charset=UTF-8');

function bp_install_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bp_install_layout(string $title, string $body): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . bp_install_esc($title) . ' | Batoi Press</title><link rel="stylesheet" href="' . bp_install_esc(bp_url('/assets/uif/uif.css')) . '"><link rel="stylesheet" href="' . bp_install_esc(bp_url('/assets/css/style.css')) . '"><script src="' . bp_install_esc(bp_url('/assets/uif/uif.iife.js')) . '" defer></script><script src="' . bp_install_esc(bp_url('/assets/uif/uif.js')) . '" defer></script></head><body class="bp-install-body"><main class="bp-installer-shell">' . $body . '</main></body></html>';
}

function bp_install_write_json(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
}

function bp_install_ensure_dirs(string $root): void
{
    foreach ([
        'radpress/content/media',
        'radpress/data/backups',
        'radpress/data/cache',
        'radpress/data/export',
        'radpress/data/log',
        'radpress/data/sessions',
        'radpress/data/tmp',
        'radpress/data/versions',
        'public_html/assets/site',
    ] as $dir) {
        $path = $root . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}

function bp_install_has_owner(string $configDir): bool
{
    $usersFile = $configDir . '/users.json';
    $decoded = is_file($usersFile) ? json_decode((string)file_get_contents($usersFile), true) : [];
    $users = is_array($decoded) && is_array($decoded['users'] ?? null) ? $decoded['users'] : [];

    foreach ($users as $user) {
        if (is_array($user) && ($user['role'] ?? '') === 'owner' && ($user['username'] ?? '') !== '') {
            return true;
        }
    }

    return false;
}

$hasOwner = bp_install_has_owner($configDir);
$hasLock = is_file($lock);

if ($hasLock && $hasOwner) {
    bp_install_layout('Installation Already Complete', '<section class="bp-installer-panel bp-uif-surface"><div class="bp-section-kicker">Setup complete</div><h1>Installation Already Complete</h1><p>Batoi Press is installed and the browser setup is locked by <code>radpress/config/installed.lock</code>.</p><p>Use the admin login for normal access. Only remove the lock when intentionally rebuilding the installation from server access.</p><p class="bp-actions"><a class="bp-button bp-button-secondary" href="' . bp_install_esc(bp_url('/')) . '">View site</a><a class="bp-button" href="' . bp_install_esc(bp_url('/admin/login')) . '">Admin login</a></p></section>');
    exit;
}

$checks = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Config writable' => is_writable($configDir),
    'Content writable' => is_writable($root . '/radpress/content'),
    'Data writable' => is_writable($root . '/radpress/data'),
    'Theme readable' => is_readable($root . '/radpress/theme'),
];

$errors = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $errors[] = $label . ' check failed.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors === []) {
    $siteName = trim((string)($_POST['site_name'] ?? ''));
    $baseUrl = trim((string)($_POST['base_url'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($siteName === '') {
        $errors[] = 'Site name is required.';
    }
    if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'A valid base URL is required.';
    }
    if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $username)) {
        $errors[] = 'Username must be 3-64 characters and use letters, numbers, dot, underscore, or dash.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is invalid.';
    }
    if (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    }

    if ($errors === []) {
        bp_install_ensure_dirs($root);

        bp_install_write_json($configDir . '/site.json', [
            'name' => $siteName,
            'tagline' => 'A secure flat-file CMS and publishing engine aligned with Batoi RAD.',
            'base_url' => rtrim($baseUrl, '/'),
            'theme' => 'default',
            'locale' => 'en',
            'timezone' => date_default_timezone_get() ?: 'UTC',
        ]);

        bp_install_write_json($configDir . '/users.json', [
            'users' => [[
                'username' => $username,
                'email' => $email,
                'role' => 'owner',
                'password_hash' => Password::hash($password),
                'created_at' => date(DATE_ATOM),
            ]],
        ]);

        $securityFile = $configDir . '/security.json';
        $security = is_file($securityFile) ? json_decode((string)file_get_contents($securityFile), true) : [];
        $security = is_array($security) ? $security : [];
        $security['security_key'] = bin2hex(random_bytes(32));
        $security['installer_lock'] = 'radpress/config/installed.lock';
        bp_install_write_json($securityFile, $security);

        file_put_contents($lock, 'installed ' . date(DATE_ATOM) . "\n", LOCK_EX);

        bp_install_layout('Installation Complete', '<section class="bp-installer-panel bp-uif-surface"><div class="bp-section-kicker">Ready</div><h1>Installation Complete</h1><p>The installer is now locked by <code>radpress/config/installed.lock</code>.</p><p class="bp-actions"><a class="bp-button" href="' . bp_install_esc(bp_url('/admin/login')) . '">Log in to admin</a><a class="bp-button bp-button-secondary" href="' . bp_install_esc(bp_url('/')) . '">View site</a></p></section>');
        exit;
    }
}

$body = '<section class="bp-installer-hero"><div><div class="bp-section-kicker">Batoi Press</div><h1>Install Batoi Press</h1><p>Configure a secure flat-file publishing site and create the first owner account.</p></div><div class="bp-installer-badge">PHP ' . bp_install_esc(PHP_VERSION) . '</div></section>';
if ($errors !== []) {
    $body .= '<div class="bp-error"><strong>Review required:</strong><ul>';
    foreach ($errors as $error) {
        $body .= '<li>' . bp_install_esc($error) . '</li>';
    }
    $body .= '</ul></div>';
}
if ($hasLock && !$hasOwner) {
    $body .= '<div class="bp-notice"><strong>Setup lock will be refreshed:</strong> <code>radpress/config/installed.lock</code> exists, but no owner account was found. Complete the form below to repair setup and create the first owner.</div>';
}

$body .= '<dl class="bp-stats bp-checks">';
foreach ($checks as $label => $ok) {
    $body .= '<div class="' . ($ok ? 'is-ok' : 'is-error') . '"><dt>' . bp_install_esc($label) . '</dt><dd>' . ($ok ? 'Ready' : 'Needs attention') . '</dd></div>';
}
$body .= '</dl>';

if ($errors === [] || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $detectedBase = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost') . bp_base_path();
    $siteValue = bp_install_esc(trim((string)($_POST['site_name'] ?? 'Batoi Press')));
    $baseValue = bp_install_esc(trim((string)($_POST['base_url'] ?? $detectedBase)));
    $usernameValue = bp_install_esc(trim((string)($_POST['username'] ?? '')));
    $emailValue = bp_install_esc(trim((string)($_POST['email'] ?? '')));
    $body .= '<form method="post" class="bp-form bp-installer-form bp-uif-surface">';
    $body .= '<div class="bp-form-section"><div><h2>Site</h2><p>Name the site and confirm the public URL used for links and feeds.</p></div><div class="bp-form-grid"><label>Site Name <input type="text" name="site_name" value="' . $siteValue . '" required></label><label>Base URL <input type="url" name="base_url" value="' . $baseValue . '" required></label></div></div>';
    $body .= '<div class="bp-form-section"><div><h2>Owner</h2><p>Create the first owner account. Use a strong password of at least 10 characters.</p></div><div class="bp-form-grid"><label>Owner Username <input type="text" name="username" value="' . $usernameValue . '" autocomplete="username" required></label><label>Owner Email <input type="email" name="email" value="' . $emailValue . '" autocomplete="email"></label><label class="bp-field-wide">Owner Password <input type="password" name="password" autocomplete="new-password" required minlength="10"></label></div></div>';
    $body .= '<div class="bp-form-actions"><button type="submit">Install Batoi Press</button></div>';
    $body .= '</form>';
}

bp_install_layout('Install', $body);
