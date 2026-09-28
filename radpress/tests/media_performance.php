<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
use Batoi\Press\Core\{Paths, FileStore, MediaPerformance, ImageVariants, HtmlContent, Config};
use Batoi\Press\Content\PageRepository;
function mediaPerfCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$root = sys_get_temp_dir() . '/press-media-perf-' . bin2hex(random_bytes(5));
$files = new FileStore();
try {
    $files->writeJson($root . '/radpress/config/paths.json', ['config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data','theme'=>'radpress/theme','public_root'=>'public_html']);
    $config = Config::load($root);
    $paths = $config->paths();
    $imagePath = $paths->contentPath('assets/images/hero.png');
    $files->write($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII='));
    if (function_exists('imagecreatetruecolor')) { $im=imagecreatetruecolor(1200, 600); imagepng($im, $imagePath); imagedestroy($im); }
    $original = hash_file('sha256', $imagePath);
    $media = new MediaPerformance($paths);
    $settings = $media->normalize(['hero_image'=>'/assets/images/hero.png', 'hero_preload'=>'1', 'hero_priority'=>'high','lazy_images'=>'1']);
    mediaPerfCheck(str_contains($media->head($settings), 'rel="preload"') && $media->head([]) === '', 'preload is page scoped');
    foreach (['https://example.com/hero.png','//example.com/x.png','/assets/../../secret.png','javascript:alert(1)'] as $url) {
        try { $media->normalize(['hero_image'=>$url]); throw new LogicException('unsafe hero accepted'); } catch (RuntimeException $e) { if ($e instanceof LogicException) throw $e; }
    }
    $markup = $media->markup('<img src="/assets/images/hero.png" loading="lazy" alt="Hero"><img src="/assets/images/hero.png" alt="Repeated hero">', $settings);
    mediaPerfCheck(substr_count($markup,'loading="eager"') === 2 && str_contains($markup, 'fetchpriority="high"') && str_contains($markup, 'width="'), 'hero eager priority and dimensions');
    $later = $media->markup('<img src="/assets/images/hero.png"><img src="/assets/images/hero.png">', ['lazy_images'=>true]);
    mediaPerfCheck(substr_count($later, 'loading="lazy"') === 1, 'lazy loading excludes first image');
    $variants = (new ImageVariants($paths))->generate('/assets/images/hero.png');
    mediaPerfCheck(hash_file('sha256', $imagePath) === $original, 'original preserved');
    if (function_exists('imagewebp')) {
        mediaPerfCheck(count($variants) >= 1 && str_contains($media->markup('<img src="/assets/images/hero.png">', $settings), 'srcset='), 'WebP generated and responsive');
        mediaPerfCheck(str_contains($media->head($settings), 'imagesrcset='), 'preload selects same responsive representation');
    }
    mediaPerfCheck((new ImageVariants($paths))->generate('/assets/missing.png') === [], 'unsupported input graceful');
    $serverBefore = $_SERVER;
    try {
        $_SERVER['DOCUMENT_ROOT'] = $root;
        $_SERVER['SCRIPT_FILENAME'] = $root . '/nested/public_html/index.php';
        $localized = $media->normalize(['hero_image'=>'/nested/public_html/assets/images/hero.png','hero_preload'=>true]);
        mediaPerfCheck($localized['hero_image'] === '/assets/images/hero.png', 'subdirectory hero URL canonicalized');
        mediaPerfCheck(str_contains($media->head($localized), '/nested/public_html/assets/'), 'preload localized for live installation');
        mediaPerfCheck(!str_contains($media->head($localized, false), '/nested/public_html/'), 'static export preload and srcset avoid installation prefix');
    } finally { $_SERVER = $serverBefore; }

    $html = new HtmlContent();
    $source = '<link rel="preload" href="/secret"><script>secret()</script><video src="/media/a.mp4" onplay="evil()"><track kind="captions" src="/media/c.vtt" label="English"><p>Video transcript</p></video>';
    $clean = $html->sanitize($source);
    mediaPerfCheck(!str_contains($clean, '<link') && !str_contains($clean, '<script') && !str_contains($clean, 'onplay'), 'head and active markup remain blocked');
    mediaPerfCheck(str_contains($clean, 'preload="none"') && str_contains($clean, 'controls') && str_contains($clean, 'kind="captions"') && str_contains($clean, 'Video transcript'), 'video controls captions fallback and default preload');
    mediaPerfCheck(!str_contains($html->sanitize('<video src="jav&#97;script:alert(1)"></video>'), 'javascript'), 'encoded active video URL rejected');
    mediaPerfCheck(count($html->removedMarkup($source)) >= 2 && !str_contains(implode(' ', $html->removedMarkup($source)), '/secret'), 'safe sanitization report');
    $pages = new PageRepository($paths, $files, $html);
    $page = $pages->save(['title'=>'Performance','slug'=>'performance','body'=>'<p>Test</p>'] + $settings, 'owner');
    mediaPerfCheck($pages->findBySlug('performance')['hero_image'] === '/assets/images/hero.png', 'metadata round trip');
    $pages->save(['title'=>'Performance','slug'=>'performance','original_slug'=>'performance','body'=>'<p>Changed</p>'], 'owner');
    mediaPerfCheck($pages->findBySlug('performance')['hero_preload'] === true, 'old callers retain media metadata');
    $csrf = new \Batoi\Press\Security\Csrf(new \Batoi\Press\Security\Session('press_media_performance_test', $paths->dataPath('sessions')));
    $controller = new \Batoi\Press\Admin\PageController($config, $pages, new \Batoi\Press\Content\PostRepository($paths, $files, $html), $csrf, new \Batoi\Press\Core\AuditLog($paths,$files), ['username'=>'owner','role'=>'owner']);
    $input = ['csrf_token'=>$csrf->token(), 'title'=>'Admin media', 'slug'=>'admin-media', 'body'=>'<link rel="preload" href="/assets/images/hero.png"><p>Copy</p>'] + $settings;
    $response = $controller->save(new \Batoi\Press\Core\Request('POST','/admin/pages/save',[],$input,[]));
    mediaPerfCheck($response->status() === 200 && str_contains($response->content(),'Removed unsupported link'), 'admin save reports removed preload markup');
    mediaPerfCheck($pages->findBySlug('admin-media')['hero_preload'] === true, 'admin mutation service retains structured media settings');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    rmdir($root);
}
echo "Media performance checks passed\n";
