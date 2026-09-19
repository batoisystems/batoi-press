<?php
declare(strict_types=1);

use Batoi\Press\Application\MachineMediaInput;
use Batoi\Press\Core\AssetManager;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Router;
use Batoi\Press\Core\Theme;
use Batoi\Press\Core\Request;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/press-machine-media-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
try {
    $text = ['name' => 'Read me.md', 'content_base64' => base64_encode("# Documentation\nPlain text.\n"), 'metadata' => ['title' => ' Guide ', 'alt' => '', 'caption' => "Line one\nLine two"]];
    $prepared = MachineMediaInput::prepare($text);
    mediaCheck($prepared['metadata']['title'] === 'Guide' && $prepared['metadata']['alt'] === '', 'metadata is bounded plain text and permits decorative alt');
    mediaCheck($prepared['size'] === strlen($prepared['bytes']) && $prepared['sha256'] === hash('sha256', $prepared['bytes']), 'prepared size/hash describe exact bytes');
    $png = ['name' => 'pixel.png', 'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aE9sAAAAASUVORK5CYII='];
    mediaCheck(MachineMediaInput::prepare($png)['dimensions'] === ['width' => 1, 'height' => 1], 'images require matching MIME and bounded dimensions');
    $oversizedImage = base64_decode($png['content_base64']);
    $oversizedImage = substr_replace($oversizedImage, pack('NN', 8192, 8192), 16, 8);
    mediaReject(fn () => MachineMediaInput::prepare(array_replace($png, ['content_base64' => base64_encode($oversizedImage)])), 'image pixel count is independently bounded');
    foreach ([
        array_replace($text, ['name' => '../notes.md']),
        array_replace($text, ['name' => 'notes\\file.md']),
        array_replace($text, ['name' => '.hidden.md']),
        array_replace($text, ['name' => 'payload.php']),
        array_replace($text, ['name' => 'vector.svg']),
        array_replace($text, ['name' => 'style.css']),
        array_replace($text, ['name' => 'document.pdf']),
        array_replace($text, ['url' => 'https://example.test/file']),
        array_replace($text, ['content_base64' => "eA==\n"]),
        array_replace($text, ['content_base64' => 'eB==']),
        array_replace($text, ['content_base64' => '%%%']),
        array_replace($text, ['content_base64' => '']),
        array_replace($text, ['content_base64' => base64_encode(str_repeat('x', MachineMediaInput::MAX_BYTES + 1))]),
        array_replace($text, ['content_base64' => base64_encode(str_repeat('x', 5000) . '<?php echo 1;')]),
        array_replace($text, ['content_base64' => base64_encode("\xFF\xFE")]),
        array_replace($text, ['content_base64' => base64_encode("plain\0binary")]),
        array_replace($png, ['content_base64' => base64_encode('not an image')]),
        array_replace($png, ['name' => 'pixel.jpg']),
        array_replace($text, ['metadata' => ['path' => '/tmp/private']]),
        array_replace($text, ['metadata' => null]),
        array_replace($text, ['metadata' => ['alt' => ['bad']]]),
        array_replace($text, ['metadata' => ['title' => str_repeat('x', 201)]]),
        array_replace($text, ['metadata' => ['caption' => "bad\0text"]]),
    ] as $invalid) {
        mediaReject(fn () => MachineMediaInput::prepare($invalid), 'unsafe or unsupported upload rejected');
    }
    mediaReject(fn () => MachineMediaInput::prepare($png, ['allowed_extensions' => ['txt']]), 'site extension restrictions retained');
    mediaReject(fn () => MachineMediaInput::prepare($text, ['max_bytes' => 4]), 'smaller site byte limit retained');
    mediaCheck(MachineMediaInput::metadata(['caption' => '<b>Untrusted text</b>'])['caption'] === '<b>Untrusted text</b>', 'metadata remains data; rendering must escape it');

    $paths = new Paths($root, ['content' => 'content', 'data' => 'data']);
    mkdir($root . '/outside', 0700);
    file_put_contents($root . '/outside/private.txt', 'private');
    mkdir($root . '/content/assets', 0700, true);
    mkdir($root . '/content/media', 0700);
    $assets = new AssetManager($paths);
    $target = $assets->prepareTarget('documents/2026/09/safe.txt');
    file_put_contents($target, 'safe');
    file_put_contents(dirname($target) . '/.bp-previous-test', 'previous');
    file_put_contents($root . '/content/media/.hidden.txt', 'hidden');
    symlink($root . '/outside', $root . '/content/assets/linked');
    symlink($root . '/outside/private.txt', $root . '/content/media/linked.txt');
    symlink($target, dirname($target) . '/alias.txt');
    mediaCheck(count($assets->all()) === 1, 'listing excludes hidden replacement files and typed/legacy symlinks');
    mediaCheck($assets->resolveAsset('linked/private.txt') === null && $assets->resolveAsset('documents/2026/09/alias.txt') === null, 'external and internal asset aliases are refused');
    mediaCheck($assets->resolveAsset('documents/2026/09/.bp-previous-test') === null, 'hidden replacement bytes cannot resolve publicly');
    mediaCheck($assets->find('media', 'linked.txt') === null && !$assets->delete('media', 'linked.txt'), 'legacy symlink cannot be read, edited or deleted as an asset');
    mediaReject(fn () => $assets->prepareTarget('linked/new.txt'), 'uploads cannot write through directory symlinks');
    mediaReject(fn () => $assets->prepareTarget('documents/2026/09/alias.txt'), 'uploads cannot overwrite through leaf symlinks');
    mediaReject(fn () => $assets->prepareTarget('.hidden/new.txt'), 'hidden directories are not public asset targets');
    mediaCheck(!is_file($root . '/outside/new.txt') && file_get_contents($root . '/outside/private.txt') === 'private', 'rejected target preparation leaves external bytes intact');
    $files = new FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'content', 'data' => 'data', 'theme' => 'theme']);
    $files->writeJson($root . '/radpress/config/site.json', ['name' => 'Media fixture', 'theme' => 'default']);
    $files->write($root . '/theme/default/layouts/404.php', 'Not found');
    $files->write($root . '/theme/default/layouts/base.php', '<?php echo $content;');
    $config = Config::load($root);
    $html = new HtmlContent();
    $router = new Router(new Theme($config->paths(), $config->site()), new PageRepository($config->paths(), $files, $html), new PostRepository($config->paths(), $files, $html), $config);
    foreach (['/media/linked.txt', '/media/.hidden.txt', '/assets/linked/private.txt', '/assets/documents/2026/09/.bp-previous-test'] as $url) {
        $response = $router->dispatch(new Request('GET', $url, [], [], []));
        mediaCheck($response->status() === 404 && !str_contains($response->content(), 'private'), 'public routes refuse hidden and linked bytes');
    }
    mediaCheck($router->dispatch(new Request('GET', '/assets/documents/2026/09/safe.txt', [], [], []))->content() === 'safe', 'ordinary public asset delivery remains compatible');
    rename($root . '/content/assets', $root . '/content/saved-assets');
    symlink($root . '/outside', $root . '/content/assets');
    mediaCheck($assets->all() === [] && $assets->resolveAsset('private.txt') === null, 'linked asset roots are not listed or served');
    mediaReject(fn () => $assets->prepareTarget('new.txt'), 'linked asset root cannot receive uploads');
    echo "Machine media preparation checks passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isLink() || !$item->isDir() ? unlink((string)$item) : rmdir((string)$item);
    rmdir($root);
}

function mediaCheck(bool $ok, string $message): void { if (!$ok) throw new LogicException($message); }
function mediaReject(callable $callback, string $message): void
{
    try { $callback(); } catch (InvalidArgumentException|RuntimeException) { return; }
    throw new LogicException($message);
}
