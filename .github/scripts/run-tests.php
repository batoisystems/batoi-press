<?php
declare(strict_types=1);

// Top-level PHP files are standalone regression entrypoints. Fixtures live below
// subdirectories; do not recursively execute them as tests.
$root = dirname(__DIR__, 2);
chdir($root);
$tests = glob('radpress/tests/*.php') ?: [];
sort($tests);
$failed = [];
foreach ($tests as $test) {
    echo PHP_EOL . 'Running ' . basename($test) . PHP_EOL;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test), $status);
    if ($status !== 0) $failed[] = basename($test);
}
foreach (glob('radpress/tests/*.js') ?: [] as $test) {
    passthru('node ' . escapeshellarg($test), $status);
    if ($status !== 0) $failed[] = basename($test);
}
if ($failed !== []) {
    fwrite(STDERR, 'Failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'All regression entrypoints passed.' . PHP_EOL;
