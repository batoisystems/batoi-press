<?php
declare(strict_types=1);

use Batoi\Press\Content\{PageRepository, PublicationState};
use Batoi\Press\Core\{FileStore, HtmlContent, Paths, Theme};

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/press-gallery-' . bin2hex(random_bytes(5));
$files = new FileStore();
$paths = new Paths($root, ['content'=>'radpress/content', 'config'=>'radpress/config', 'data'=>'radpress/data', 'theme'=>'radpress/theme']);
$html = new HtmlContent();
$legacy = <<<'HTML'
<div class="uif-lightbox" data-uif="lightbox" aria-label="Image gallery">
  <div class="uif-masonry" data-uif="masonry">
    <article class="uif-masonry-item"><a href="/assets/images/one.jpg" data-uif-role="item" data-uif-src="/assets/images/large-one.jpg" data-uif-caption="First &amp; best" data-uif-alt="First photo"><img src="/assets/images/one.jpg" alt="First photo"></a></article>
    <article class="uif-masonry-item"><a href="/assets/images/two.jpg" data-uif-role="item"><img src="/assets/images/two.jpg" alt="Second photo"></a></article>
  </div>
  <div class="uif-lightbox-dialog" data-uif-role="dialog" hidden>
    <div class="uif-lightbox-toolbar"><button data-uif-action="previous">Previous</button><button data-uif-action="close">Close</button><button data-uif-action="next">Next</button></div>
    <img class="uif-lightbox-image" data-uif-role="image" alt=""><p class="uif-lightbox-caption" data-uif-role="caption"></p>
  </div>
</div>
HTML;

function galleryCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function galleryContract(string $markup): void
{
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $xpath = new DOMXPath($dom);
    galleryCheck($xpath->query('//*[@data-uif="lightbox"]')->length === 1, 'Native lightbox root missing.');
    galleryCheck($xpath->query('//a[@data-uif-role="item"]')->length === 2, 'Linked gallery items lost their roles.');
    galleryCheck($xpath->query('//div[@data-uif-role="dialog"][@hidden]')->length === 1, 'Dialog must remain hidden before JavaScript starts.');
    galleryCheck($xpath->query('//img[@data-uif-role="image"]')->length === 1, 'Viewer image role missing.');
    galleryCheck($xpath->query('//p[@data-uif-role="caption"]')->length === 1, 'Viewer caption role missing.');
    foreach (['previous', 'close', 'next'] as $action) {
        galleryCheck($xpath->query('//button[@data-uif-action="' . $action . '"]')->length === 1, 'Viewer action missing: ' . $action);
    }
}

try {
    $clean = $html->sanitize($legacy);
    galleryContract($clean);
    galleryCheck(str_contains($clean, 'data-uif-caption="First &amp; best"'), 'Safe captions must survive.');
    galleryCheck(str_contains($clean, 'data-uif-src="/assets/images/large-one.jpg"'), 'Safe full-size image URLs must survive.');
    galleryCheck($html->sanitize($clean) === $clean, 'Repeated editor/repository sanitization must be stable.');
    $stripped = preg_replace('/\sdata-[a-z-]+="[^"]*"|\shidden/', '', $legacy);
    galleryContract($html->sanitize((string)$stripped));

    $hostile = '<div class="uif-lightbox" data-uif="ajax" data-uif-url="/admin/users" data-uif-method="POST" onclick="alert(1)"><article class="uif-masonry-item"><a href="javascript:alert(1)" data-uif-role="item" data-uif-src="javascript:alert(1)">Bad link</a></article><div class="uif-lightbox-dialog"><button data-uif-action="delete">Delete</button><input data-uif="ajax" data-uif-action="submit"></div><script>alert(1)</script></div><button data-uif-action="close">Outside</button><div data-uif="lightbox"><span data-uif-role="item">Wrong element</span></div>';
    $safe = $html->sanitize($hostile);
    galleryCheck(!preg_match('/javascript:|onclick=|<script|data-uif-(?:url|method|src)=|data-uif="ajax"|data-uif-action="(?:delete|submit|close)"|<span data-uif-role=/i', $safe), 'Gallery support admitted unrelated active attributes.');

    // Read legacy storage through the same path used by every custom theme.
    $files->writeJson($paths->contentPath('pages/gallery/meta.json'), ['id'=>'gallery', 'slug'=>'gallery', 'title'=>'Gallery', 'status'=>'published', 'template'=>'page']);
    $files->write($paths->contentPath('pages/gallery/body.html'), $legacy);
    $pages = new PageRepository($paths, $files, $html);
    $page = $pages->findBySlug('gallery');
    galleryContract($page['body']);
    galleryCheck($files->read($paths->contentPath('pages/gallery/body.html')) === $legacy, 'Upgrade reads must not rewrite stored content.');

    // A theme needs no gallery-specific PHP workaround.
    $files->writeJson($paths->themePath('legacy/theme.json'), ['name'=>'Legacy', 'slug'=>'legacy', 'version'=>'1.0.0', 'assets'=>['styles'=>[], 'scripts'=>[]]]);
    $files->write($paths->themePath('legacy/layouts/page.php'), '<?php echo $page["body"]; ?>');
    $files->write($paths->themePath('legacy/layouts/base.php'), '<html><head></head><body><?php echo $content; ?></body></html>');
    $rendered = (new Theme($paths, ['theme'=>'legacy']))->render('page', ['page'=>$page]);
    galleryCheck($rendered->status() === 200, 'Legacy custom theme failed to render.');
    galleryContract($rendered->content());

    $pages->save(['original_slug'=>'gallery', 'slug'=>'gallery', 'title'=>'Gallery', 'status'=>'published', 'blocks'=>[['type'=>'gallery', 'body'=>$legacy]]], 'fixture-owner');
    galleryContract($pages->findBySlug('gallery')['body']);
    galleryCheck(!PublicationState::isPublic(['status'=>'draft', 'body'=>$legacy]), 'Gallery compatibility must not publish drafts.');
    echo "Gallery compatibility checks passed\n";
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($root);
    }
}
