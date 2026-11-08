<?php
declare(strict_types=1);

use Batoi\Press\Content\PageRepository;
use Batoi\Press\Core\{FileStore, HtmlContent, Paths, Theme};

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/press-presentation-' . bin2hex(random_bytes(5));
$files = new FileStore();
$paths = new Paths($root, ['content'=>'radpress/content', 'config'=>'radpress/config', 'data'=>'radpress/data', 'theme'=>'radpress/theme']);
$html = new HtmlContent();
$legacy = <<<'HTML'
<section class="custom-hero" style="background:url(./assets/images/hero.png) no-repeat; background-size:cover; background-position:center;position:relative;unsupported-property:discard">
  <div style="z-index:2;position:relative"><h1>Legacy headline</h1><span data-uif-icon="user-round" data-uif-icon-title="About &amp; team" data-uif-icon-size="32" data-uif-icon-class="custom-icon" data-uif-icon-hidden="false"></span></div>
  <div class="uif-carousel" data-uif="carousel" data-uif-items-per-slide="1" data-uif-step="1" tabindex="0">
    <article class="uif-carousel-slide" data-uif-role="slide" data-uif-state="active"><p>First slide</p></article>
    <article class="uif-carousel-slide" data-uif-role="slide" data-uif-state="inactive"><p>Second slide</p></article>
    <div class="uif-carousel-controls"><button data-uif-action="previous">Previous</button><button data-uif-action="next">Next</button></div>
    <div class="uif-carousel-indicators"><button data-uif-slide-to="0" aria-label="Slide 1"></button><button data-uif-slide-to="1" aria-label="Slide 2"></button></div>
    <p data-uif-role="status" aria-live="polite"></p>
  </div>
</section>
<section class="custom-faqs">
  <div class="component-accordion" data-uif="accordion"><button data-uif-role="trigger">First question</button><div data-uif-role="panel">First answer</div></div>
  <div class="uif-accordion" data-uif="accordion" data-uif-state="expanded"><button class="uif-accordion-trigger" data-uif-role="trigger">Second question</button><div class="uif-accordion-panel" data-uif-role="panel">Second answer</div></div>
</section>
HTML;

function presentationCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function presentationContract(string $markup, bool $recovered = false): void
{
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $xpath = new DOMXPath($dom);
    foreach (['//*[@data-uif="carousel"]'=>1, '//*[@data-uif-role="slide"]'=>2, '//button[@data-uif-slide-to="0"]'=>1, '//button[@data-uif-slide-to="1"]'=>1, '//button[@data-uif-action="previous"]'=>1, '//button[@data-uif-action="next"]'=>1, '//*[@data-uif="accordion"]'=>2, '//button[@data-uif-role="trigger"]'=>2, '//div[@data-uif-role="panel"]'=>2] as $query => $count) {
        presentationCheck($xpath->query($query)->length === $count, 'Presentation contract lost: ' . $query);
    }
    presentationCheck($xpath->query('//div[@data-uif-role="panel"][@hidden]')->length === ($recovered ? 2 : 1), 'FAQ initial visibility changed.');
    if (!$recovered) {
        presentationCheck($xpath->query('//*[@data-uif="carousel"][@data-uif-items-per-slide="1"][@data-uif-step="1"]')->length === 1, 'Carousel settings lost.');
        presentationCheck($xpath->query('//*[@data-uif-role="status"]')->length === 1, 'Carousel status role lost.');
        presentationCheck($xpath->query('//article[@data-uif-state="inactive"][@hidden]')->length === 1, 'Inactive carousel slide should start hidden.');
        presentationCheck($xpath->query('//span[@data-uif-icon="user-round"][@data-uif-icon-size="32"][@data-uif-icon-class="custom-icon"][@data-uif-icon-hidden="false"]')->length === 1, 'Icon metadata lost.');
    }
    presentationCheck(str_contains($markup, 'background:url(./assets/images/hero.png) no-repeat') && str_contains($markup, 'background-size:cover') && str_contains($markup, 'background-position:center') && str_contains($markup, 'position:relative') && str_contains($markup, 'z-index:2'), 'Legacy background or stacking styles lost.');
    presentationCheck(!str_contains($markup, 'unsupported-property'), 'Unsupported CSS must remain filtered.');
}

try {
    $clean = $html->sanitize($legacy);
    presentationContract($clean);
    presentationCheck($html->sanitize($clean) === $clean, 'Repeated sanitization must preserve presentation.');
    presentationCheck(str_contains($clean, 'data-uif-icon-title="About &amp; team"'), 'Icon titles must be escaped.');
    $stripped = preg_replace('/\sdata-[a-z-]+="[^"]*"/', '', $legacy);
    presentationContract($html->sanitize((string)$stripped), true);
    $attributeOnly = $html->sanitize('<section data-uif="carousel" data-uif-items-per-slide="3" data-uif-step="2"><div data-uif-role="slide">Slide</div><button data-uif-slide-to="0">One</button></section><article data-uif="accordion"><button data-uif-role="trigger">Question</button><section data-uif-role="panel">Answer</section></article>');
    presentationCheck(str_contains($attributeOnly, 'data-uif="carousel"') && str_contains($attributeOnly, 'data-uif="accordion"') && str_contains($attributeOnly, 'data-uif-role="slide"'), 'Native attributes must also work without legacy classes.');
    presentationCheck(str_contains($attributeOnly, 'data-uif-items-per-slide="3"') && str_contains($attributeOnly, 'data-uif-step="2"'), 'Non-default carousel settings lost.');
    $wrapped = $html->sanitize('<div class="accordion-item" data-uif="accordion"><div class="accordion-header"><button data-uif-role="trigger">Wrapped question</button></div><div class="accordion-collapse" data-uif-role="panel">Wrapped answer</div></div>');
    $wrappedDom = new DOMDocument();
    @$wrappedDom->loadHTML($wrapped);
    $wrappedXpath = new DOMXPath($wrappedDom);
    presentationCheck($wrappedXpath->query('//div[@class="accordion-header"][@hidden or @data-uif-role]')->length === 0, 'Accordion header wrapper must stay visible and must not become a panel.');
    presentationCheck($wrappedXpath->query('//button[@data-uif-role="trigger"]')->length === 1 && $wrappedXpath->query('//div[@data-uif-role="panel"][@hidden]')->length === 1, 'Wrapped accordion trigger and panel lost.');

    $hostile = '<div class="uif-carousel" data-uif="ajax" data-uif-url="/admin/users" data-uif-options="evil" data-uif-step="NaN" data-uif-items-per-slide="999999"><article class="uif-carousel-slide" onclick="alert(1)" data-uif-state="delete">Slide</article><button data-uif-action="delete" data-uif-slide-to="Infinity">Bad</button><script>alert(1)</script></div><div class="component-accordion" data-uif="route"><button data-uif-role="trigger" data-uif-action="submit">Question</button><div data-uif-role="panel" data-uif-method="POST">Answer</div></div><span data-uif-icon="../evil.svg" data-uif-icon-title="Bad"></span><i data-uif-icon="mail" data-uif-icon-size="Infinity" data-uif-icon-class="evil()" data-uif-icon-hidden="other" data-uif-icon-mounted="true"></i><button data-uif-action="next" data-uif-slide-to="0">Outside</button><span data-uif-role="panel">Outside</span>';
    $safe = $html->sanitize($hostile);
    presentationCheck(!preg_match('/onclick=|<script|data-uif-(?:url|options|step|items-per-slide|method|action|slide-to)=|data-uif="(?:ajax|route)"|data-uif-icon-(?:title|size|class|hidden|mounted)=|\.\.\/evil|data-uif-state="delete"/', $safe), 'Presentation support admitted active or invalid attributes.');
    presentationCheck(!str_contains($safe, '<span data-uif-role='), 'Roles outside a supported widget must stay filtered.');
    foreach (['background:url(javascript:alert(1))', 'background-image:url(data:text/html,evil)', 'background:url(//example.test/track)', 'background:url(https://example.test/a),url(javascript:evil)', 'color:expression(alert(1))', 'background:u\\72l(/evil)', 'background:/*x*/url(/evil)', 'position:fixed;z-index:999999'] as $style) {
        $safe = $html->sanitize('<div style="' . htmlspecialchars($style, ENT_QUOTES) . '">Safe text</div>');
        presentationCheck(!str_contains($safe, 'style='), 'Unsafe CSS admitted: ' . $style);
    }
    presentationCheck(str_contains($html->sanitize('<div style="background:url(/hero.png);unknown:drop;color:red">Safe</div>'), 'background:url(/hero.png);color:red'), 'One unsupported declaration must not erase safe neighboring styles.');

    $files->writeJson($paths->contentPath('pages/legacy/meta.json'), ['id'=>'legacy', 'slug'=>'legacy', 'title'=>'Legacy', 'status'=>'published', 'template'=>'landing']);
    $files->write($paths->contentPath('pages/legacy/body.html'), $legacy);
    $pages = new PageRepository($paths, $files, $html);
    $page = $pages->findBySlug('legacy');
    presentationContract($page['body']);
    presentationCheck($files->read($paths->contentPath('pages/legacy/body.html')) === $legacy, 'Rendering must not rewrite stored content.');
    foreach (['first-custom-theme', 'second-custom-theme'] as $theme) {
        $files->writeJson($paths->themePath($theme . '/theme.json'), ['name'=>$theme, 'slug'=>$theme, 'version'=>'1.0.0', 'assets'=>['styles'=>[], 'scripts'=>[]]]);
        $files->write($paths->themePath($theme . '/layouts/landing.php'), '<?php echo $page["body"]; ?>');
        $files->write($paths->themePath($theme . '/layouts/base.php'), '<html><head></head><body><?php echo $content; ?></body></html>');
        $rendered = (new Theme($paths, ['theme'=>$theme]))->render('landing', ['page'=>$page]);
        presentationCheck($rendered->status() === 200, 'Custom theme failed to render.');
        presentationContract($rendered->content());
    }
    $replacements = [];
    foreach ($html->editableTextSegments($page['body']) as $segment) if ($segment['value'] === 'Legacy headline') $replacements[$segment['id']] = 'Updated headline';
    $edited = $html->replaceEditableText($page['body'], $replacements);
    $pages->save(['original_slug'=>'legacy', 'slug'=>'legacy', 'title'=>'Legacy', 'status'=>'published', 'body'=>$edited], 'fixture-owner');
    $saved = $pages->findBySlug('legacy');
    presentationCheck(str_contains($saved['body'], 'Updated headline'), 'Protected text edit did not save.');
    presentationContract($saved['body']);
    $pages->save(['original_slug'=>'legacy', 'slug'=>'legacy', 'title'=>'Legacy', 'status'=>'published', 'blocks'=>[['type'=>'html', 'body'=>$saved['body']]]], 'fixture-owner');
    presentationContract($pages->findBySlug('legacy')['body']);
    echo "Theme presentation compatibility checks passed\n";
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($root);
    }
}
