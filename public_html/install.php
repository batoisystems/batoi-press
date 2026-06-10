<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$lock = $root . '/radpress/config/installed.lock';

header('Content-Type: text/html; charset=UTF-8');

if (is_file($lock)) {
    http_response_code(403);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Installer Disabled</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin"><h1>Installer Disabled</h1><p>Batoi Press is already installed. Remove <code>radpress/config/installed.lock</code> manually on the server to re-enable setup.</p><p><a href="/">View site</a></p></main></body></html>';
    exit;
}

$checks = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Config writable' => is_writable($root . '/radpress/config'),
    'Content writable' => is_writable($root . '/radpress/content'),
    'Data writable' => is_writable($root . '/radpress/data'),
    'Theme readable' => is_readable($root . '/radpress/theme'),
];

echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Install Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin"><h1>Install Batoi Press</h1><p>This Phase 1 installer performs environment checks. Account creation and write-through setup are scheduled for the next phase.</p><dl class="bp-stats">';
foreach ($checks as $label => $ok) {
    echo '<div><dt>' . htmlspecialchars($label) . '</dt><dd>' . ($ok ? 'OK' : 'Needs attention') . '</dd></div>';
}
echo '</dl></main></body></html>';
