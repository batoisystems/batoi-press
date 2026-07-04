<?php
declare(strict_types=1);

use Batoi\Press\Core\Paths;
use Batoi\Press\Update\UpdateRunner;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-update-test-' . bin2hex(random_bytes(4));

try {
    createFixture($root);
    $paths = fixturePaths($root);
    $runner = new UpdateRunner($paths);

    file_put_contents($paths->dataPath('cache/page.html'), 'cached');

    $package = createPackage($root, 'success', [
        'README.md' => 'updated readme',
    ], [
        ['path' => 'README.md', 'sha256' => hash('sha256', 'updated readme')],
    ]);

    $stage = $runner->stage($package);
    assertTrue($stage['ok'] ?? false, 'successful package should stage');
    $applied = $runner->apply((string)$stage['stage_dir']);
    assertTrue($applied['ok'] ?? false, 'successful package should apply');
    assertSame('updated readme', file_get_contents($root . '/README.md'), 'README should be updated');
    assertTrue(!is_file($paths->dataPath('cache/page.html')), 'cache should be cleared after apply');
    assertTrue(!is_file($paths->dataPath('maintenance.json')), 'maintenance mode should be disabled after apply');

    $validUpdate = json_encode(['current_version' => '0.1.0'], JSON_PRETTY_PRINT);
    file_put_contents($paths->configPath('update.json'), $validUpdate);

    $failingPackage = createPackage($root, 'fail-health', [
        'radpress/config/update.json' => 'not json',
    ], [
        ['path' => 'radpress/config/update.json'],
    ]);

    $stage = $runner->stage($failingPackage);
    assertTrue($stage['ok'] ?? false, 'failing health package should stage');
    $failed = $runner->apply((string)$stage['stage_dir']);
    assertTrue(!($failed['ok'] ?? false), 'health failure should fail apply');
    assertTrue($failed['rolled_back'] ?? false, 'health failure should trigger rollback');
    assertSame($validUpdate, file_get_contents($paths->configPath('update.json')), 'rollback should restore update config');
    assertTrue(!is_file($paths->dataPath('maintenance.json')), 'maintenance mode should be disabled after rollback');

    $missingStage = $runner->apply($paths->dataPath('tmp/update-stage-20990101-000000'));
    assertTrue(!($missingStage['ok'] ?? false), 'missing staged package should fail apply');
    assertSame('The selected staged package is no longer available. Stage the package again.', (string)($missingStage['error'] ?? ''), 'missing staged package should return actionable error');

    $outsideStage = sys_get_temp_dir() . '/batoi-press-outside-stage-' . bin2hex(random_bytes(4));
    mkdir($outsideStage, 0775, true);
    $outside = $runner->apply($outsideStage);
    assertTrue(!($outside['ok'] ?? false), 'outside staged package should fail apply');
    assertSame('The selected staged package is outside update storage. Stage the package again from the Updates screen.', (string)($outside['error'] ?? ''), 'outside staged package should return actionable error');
    removeTree($outsideStage);

    echo "Update runner checks passed\n";
} finally {
    removeTree($root);
}

function fixturePaths(string $root): Paths
{
    return new Paths($root, [
        'public_root' => 'public_html',
        'app' => 'radpress/app',
        'config' => 'radpress/config',
        'content' => 'radpress/content',
        'data' => 'radpress/data',
        'theme' => 'radpress/theme',
    ]);
}

function createFixture(string $root): void
{
    foreach ([
        'public_html',
        'radpress/admin',
        'radpress/app',
        'radpress/config',
        'radpress/content',
        'radpress/core',
        'radpress/data/backups',
        'radpress/data/cache',
        'radpress/data/export',
        'radpress/data/log',
        'radpress/data/sessions',
        'radpress/data/tmp',
        'radpress/data/versions',
        'radpress/helpers',
        'radpress/security',
        'radpress/theme/default',
        'radpress/updates',
    ] as $dir) {
        mkdir($root . '/' . $dir, 0775, true);
    }

    file_put_contents($root . '/README.md', 'original readme');
    file_put_contents($root . '/LICENSE', 'MIT');
    file_put_contents($root . '/public_html/index.php', '<?php echo "ok";');
    file_put_contents($root . '/public_html/admin.php', '<?php echo "admin";');
    file_put_contents($root . '/radpress/autoload.php', '<?php');
    file_put_contents($root . '/radpress/config/paths.json', json_encode(['public_root' => 'public_html'], JSON_PRETTY_PRINT));
    file_put_contents($root . '/radpress/config/site.json', json_encode(['site_name' => 'Test'], JSON_PRETTY_PRINT));
    file_put_contents($root . '/radpress/config/security.json', json_encode(['session_name' => 'test'], JSON_PRETTY_PRINT));
    file_put_contents($root . '/radpress/config/update.json', json_encode(['current_version' => '0.1.0'], JSON_PRETTY_PRINT));
}

function createPackage(string $root, string $name, array $files, array $manifestFiles): string
{
    $dir = $root . '/package-' . $name;
    mkdir($dir, 0775, true);
    foreach ($files as $path => $body) {
        $target = $dir . '/' . $path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        file_put_contents($target, $body);
    }
    file_put_contents($dir . '/release.json', json_encode([
        'version' => '0.1.1-' . $name,
        'files' => $manifestFiles,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $zipPath = $root . '/' . $name . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    addZipDir($zip, $dir, $dir);
    $zip->close();

    return $zipPath;
}

function addZipDir(ZipArchive $zip, string $root, string $dir): void
{
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            addZipDir($zip, $root, $path);
            continue;
        }
        $zip->addFile($path, ltrim(substr($path, strlen($root)), '/'));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        if (is_dir($child)) {
            removeTree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}
