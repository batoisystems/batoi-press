<?php
declare(strict_types=1);

use Batoi\Press\Content\MenuConflictException;
use Batoi\Press\Content\MenuRepository;
use Batoi\Press\Content\MenuValidationException;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-menu-repository-' . bin2hex(random_bytes(5));
foreach (['radpress/content/menus', 'radpress/data/versions/menus'] as $directory) {
    mkdir($root . '/' . $directory, 0775, true);
}

try {
    $paths = new Paths($root, ['content' => 'radpress/content', 'data' => 'radpress/data']);
    $files = new FileStore();
    $repository = new MenuRepository($paths, $files);
    $legacyPath = $paths->contentPath('menus/main.json');
    $files->writeJson($legacyPath, ['items' => [
        ['label' => 'Company', 'url' => '/company'],
        ['label' => 'Team', 'url' => '/company/team', 'parent' => '/company'],
    ]]);

    $legacy = $repository->load();
    assertMenu((int)$legacy['schema_version'] === 2, 'legacy menus should normalize to schema version 2');
    assertMenu(str_starts_with((string)$legacy['items'][0]['id'], 'mi_'), 'legacy items should receive stable migration IDs');
    assertMenu($legacy['items'][1]['parent_id'] === $legacy['items'][0]['id'], 'legacy parent URLs should migrate to parent IDs');
    assertMenu($legacy['items'][0]['presentation'] === 'dropdown', 'legacy parents should migrate to dropdown presentation');

    $parentId = (string)$legacy['items'][0]['id'];
    $childId = (string)$legacy['items'][1]['id'];
    $originalBytes = $files->read($legacyPath);
    $preview = $repository->prepareSave(['name' => 'Preview only', 'items' => $legacy['items']], 'owner', 0);
    assertMenu($preview['name'] === 'Preview only' && $preview['revision'] === 1, 'preview uses the shared save normalization');
    assertMenu($files->read($legacyPath) === $originalBytes && (glob($paths->dataPath('versions/menus/main/*.json')) ?: []) === [], 'preview changes neither the live menu nor snapshots');
    assertThrowsMenu(static fn () => $repository->prepareSave(['items' => []], 'owner', 1), MenuConflictException::class, 'preview rejects stale revisions');
    $saved = $repository->save([
        'id' => 'menu_main',
        'name' => 'Primary navigation',
        'location' => 'primary',
        'items' => [
            ['id' => $parentId, 'type' => 'page', 'label' => 'Our company', 'url' => '/about-us', 'presentation' => 'mega', 'enabled' => true],
            ['id' => $childId, 'type' => 'page', 'label' => 'Team', 'url' => '/company/team', 'parent_id' => $parentId, 'description' => 'Meet the team', 'column' => 2, 'enabled' => true],
            ['id' => 'mi_resources', 'type' => 'heading', 'label' => 'Resources', 'parent_id' => $parentId, 'column' => 1, 'enabled' => true],
        ],
    ], 'owner', 0);

    assertMenu((int)$saved['revision'] === 1, 'first structured save should increment the revision');
    assertMenu($saved['items'][1]['parent_id'] === $parentId, 'child should remain attached by stable ID after parent URL change');
    assertMenu($saved['items'][1]['parent'] === '/about-us', 'legacy parent URL should be retained as a compatibility field');
    assertMenu($saved['items'][0]['presentation'] === 'mega', 'top-level mega-menu presentation should persist');
    assertMenu($saved['items'][2]['url'] === '', 'heading items should not retain a destination URL');
    assertMenu(count(glob($paths->dataPath('versions/menus/main/*.json')) ?: []) === 1, 'saving should snapshot the previous menu document');

    assertThrowsMenu(
        static fn () => $repository->save(['items' => $saved['items']], 'owner', 0),
        MenuConflictException::class,
        'stale revisions should fail with a conflict'
    );
    assertThrowsMenu(
        static fn () => $repository->save(['items' => [
            ['id' => 'mi_cycle_one', 'type' => 'link', 'label' => 'One', 'url' => '/one', 'parent_id' => 'mi_cycle_two', 'enabled' => true],
            ['id' => 'mi_cycle_two', 'type' => 'link', 'label' => 'Two', 'url' => '/two', 'parent_id' => 'mi_cycle_one', 'enabled' => true],
        ]], 'owner', 1),
        MenuValidationException::class,
        'cycles should fail validation'
    );
    assertThrowsMenu(
        static fn () => $repository->save(['items' => [[
            'id' => 'mi_unsafe_url', 'type' => 'link', 'label' => 'Unsafe', 'url' => 'javascript:alert(1)', 'enabled' => true,
        ]]], 'owner', 1),
        MenuValidationException::class,
        'unsafe URL schemes should fail validation'
    );
    assertThrowsMenu(
        static fn () => $repository->save(['items' => [[
            'id' => 'mi_backslash_url', 'type' => 'link', 'label' => 'Unsafe path', 'url' => '/safe\\evil', 'enabled' => true,
        ]]], 'owner', 1),
        MenuValidationException::class,
        'ambiguous internal paths should fail validation'
    );

    $unicode = $repository->save(['items' => [[
        'id' => 'mi_unicode',
        'type' => 'link',
        'label' => str_repeat('ଆ', 50),
        'url' => '/unicode',
        'enabled' => true,
    ]]], 'owner', 1);
    assertMenu(json_encode($unicode) !== false, 'bounded multilingual labels should remain valid UTF-8');

    echo "Menu repository checks passed\n";
} finally {
    removeMenuFixture($root);
}

function assertMenu(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertThrowsMenu(callable $callback, string $class, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return;
        }
        throw new RuntimeException($message . ' threw ' . $exception::class . ' instead of ' . $class);
    }
    throw new RuntimeException($message . ' did not throw ' . $class);
}

function removeMenuFixture(string $path): void
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
