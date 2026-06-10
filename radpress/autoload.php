<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Batoi\\Press\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    $top = array_shift($parts);

    $map = [
        'Admin' => 'admin',
        'Content' => 'core/content',
        'Core' => 'core',
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
