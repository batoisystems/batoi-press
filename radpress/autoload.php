<?php
declare(strict_types=1);

// Optional integration libraries must not participate in core boot.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Firebase\\JWT\\')) return;
    $composerAutoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($composerAutoload)) {
        static $loader = null;
        $loader ??= require $composerAutoload;
        $loader->loadClass($class);
    }
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'Batoi\\Press\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    $top = array_shift($parts);

    $map = [
        'Aif' => 'aif',
        'Admin' => 'admin',
        'Api' => 'api',
        'Application' => 'application',
        'Content' => 'core/content',
        'Core' => 'core',
        'Mcp' => 'mcp',
        'Security' => 'security',
        'Update' => 'updates',
    ];

    if (!isset($map[$top])) {
        return;
    }

    $file = __DIR__ . '/' . $map[$top] . ($parts ? '/' . implode('/', $parts) : '') . '.php';

    if (is_file($file)) {
        require $file;
    }
});
