<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
use Batoi\Press\Application\{ContentMutationException, ContentRevision, EditorRecovery};
use Batoi\Press\Content\{PageRepository, PostRepository};
use Batoi\Press\Core\{Config, FileStore, HtmlContent, Request, AuditLog};
use Batoi\Press\Security\{Session, Csrf};
use Batoi\Press\Admin\{EditorRecoveryController, PageController, MediaController};

$root = sys_get_temp_dir() . '/press-editor-recovery-' . bin2hex(random_bytes(5));
$files = new FileStore();
$files->writeJson($root . '/radpress/config/paths.json',['config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data']);
$config = Config::load($root); $html = new HtmlContent();
$pages = new PageRepository($config->paths(),$files,$html); $posts = new PostRepository($config->paths(),$files,$html);
$owner = ['username'=>'owner','role'=>'owner','created_at'=>'2026-01-01'];
$service = new EditorRecovery($config,$owner);
$assert = static function (bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call,int $status) use ($assert): void {
    try { $call(); } catch (ContentMutationException $error) { $assert($error->httpStatus() === $status,'Unexpected recovery error: ' . $error->getMessage()); return; }
    throw new RuntimeException('Unsafe recovery operation was accepted.');
};
try {
    $pages->save(['title'=>'Live source','slug'=>'home','status'=>'published','body'=>'<p>Public original</p>'],'owner');
    $source = $pages->findBySlug('home'); $base = ContentRevision::for($source); $branch = str_repeat('a',32);
    $fields = ['title'=>'Private working title','slug'=>'renamed','body'=>'<p>Private working body</p>','seo_title'=>'Working search title'];
    $saved = $service->save('page','home',$branch,$base,'',$fields);
    $assert($pages->findBySlug('home') === $source && $pages->findBySlug('renamed') === null,'Autosave must not publish, rename or alter source files.');
    $assert($service->forEditor('page','home',$branch) === $fields,'Recovery must retain content and metadata.');
    $assert($service->save('page','home',$branch,$base,'',$fields) === $saved,'Retrying identical recovery must be idempotent.');
    $reject(fn() => $service->save('page','home',$branch,$base,'',['title'=>'Stale request']),409);
    $second = $service->save('page','home',str_repeat('b',32),$base,'',['body'=>'Second tab']);
    $assert(count($service->listing('page','home')) === 2,'Two tabs must keep independent copies.');
    $other = new EditorRecovery($config,['username'=>'editor','role'=>'editor']);
    $reject(fn() => $other->load($branch),404);
    $assert($other->listing('page','home') === [],'Other users must not see recovery summaries.');
    $reject(fn() => (new EditorRecovery($config,array_replace($owner,['role'=>'viewer'])))->load($branch),403);
    $assert(!str_contains(file_get_contents((glob($root . '/radpress/data/editor-recovery/*/' . $branch . '.json'))[0]),'Private working'),'Recovery body and metadata must be encrypted at rest.');
    $reject(fn() => $service->save('page','home','../invalid',$base,'',[]),422);
    $reject(fn() => $service->save('page','home',str_repeat('c',32),$base,'',['status'=>'published']),422);
    $reject(fn() => $service->save('page','home',str_repeat('c',32),$base,'',['custom_js'=>'alert(1)']),422);
    $reject(fn() => $service->save('page','home',str_repeat('c',32),$base,'',['block_type'=>'html']),422);
    $reject(fn() => $service->save('page','home',str_repeat('c',32),$base,'',['body'=>str_repeat('x',1100001)]),422);
    $changed = $source; $changed['title'] = 'Concurrent edit'; $pages->save($changed,'owner');
    $assert(!$service->load($branch)['matches_source'],'A concurrent source change must be visible when comparing.');
    $reject(fn() => $service->forEditor('page','home',$branch),409);
    $service->save('page','home',$branch,$base,$saved['revision'],['body'=>'Keep typing after concurrent edit']);
    $assert($service->load($branch)['fields']['body'] === 'Keep typing after concurrent edit','A source conflict must still allow private preservation.');
    $assert($pages->findBySlug('home')['title'] === 'Concurrent edit','Private preservation must not overwrite the newer source.');

    $protected = '<section class="layout"><h2>Heading</h2><p>Copy <strong>bold</strong></p><table><tr><td>Keep table</td></tr></table></section>';
    $segments = $html->editableTextSegments($protected); $text = [];
    foreach ($segments as $segment) $text[$segment['id']] = $segment['value'] === 'Heading' ? '' : '<New & safe>';
    $hydrated = EditorRecovery::values(['body'=>$protected,'status'=>'published'],['body_edit_mode'=>'text_only','body_source_hash'=>hash('sha256',$protected),'body_text'=>$text],'page');
    $assert(str_contains($hydrated['body'],'<h2></h2>') && str_contains($hydrated['body'],'&lt;New &amp; safe&gt;'),'Protected recovery must preserve blank and escaped replacements.');
    $assert(str_contains($hydrated['body'],'<table><tr><td>Keep table</td></tr></table>'),'Protected recovery must retain structural markup.');
    $blocks = EditorRecovery::values(null,['block_type'=>['html','posts'],'block_body'=>['<p>Block</p>',''],'block_title'=>['','News'],'block_limit'=>['6','8']],'page');
    $assert($blocks['body'] === '<p>Block</p>' && $blocks['blocks'][1]['limit'] === '8','Structured blocks must rehydrate without losing settings.');
    $posts->save(['title'=>'Author post','slug'=>'author-post','body'=>'Original','status'=>'draft'],'author');
    $author = new EditorRecovery($config,['username'=>'author','role'=>'author']);
    $assert($author->source('post','author-post')['author'] === 'author','Authors must be able to recover their own posts.');
    $reject(fn() => $author->source('page','home'),403);
    $reject(fn() => (new EditorRecovery($config,['username'=>'other','role'=>'author']))->source('post','author-post'),404);

    $session = new Session('press_recovery_test',$root . '/sessions'); $csrf = new Csrf($session); $token = $csrf->token();
    $audit = new AuditLog($config->paths(),$files); $controller = new EditorRecoveryController($config,$csrf,$audit,$owner);
    $body = json_encode(['type'=>'page','identifier'=>'','branch'=>str_repeat('d',32),'base_revision'=>'','recovery_revision'=>'','fields'=>['title'=>'New private page']]);
    $assert($controller->handle(new Request('POST','/admin/editor-recovery/save',[],[],[],$body))->status() === 400,'Autosave must require CSRF.');
    $assert($controller->handle(new Request('POST','/admin/editor-recovery/save',[],[],['HTTP_X_CSRF_TOKEN'=>$token],$body))->status() === 200,'A valid autosave request must succeed.');
    $_GET = ['recovery'=>$branch];
    $assert((new PageController($config,$pages,$posts,$csrf,$audit,$owner))->edit('home')->status() === 409,'The editor must reject opening a stale copy rather than rebasing it silently.');
    $_GET = [];
    $imageDir = $root . '/radpress/content/assets/images'; mkdir($imageDir,0700,true);
    file_put_contents($imageDir . '/dot.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jrAoAAAAASUVORK5CYII='));
    file_put_contents($imageDir . '/not-an-image.png','<script>unsafe</script>');
    file_put_contents($imageDir . '/vector.svg','<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    $picker = (new MediaController($config,$csrf,$audit,$owner))->picker(new Request('GET','/admin/media/picker',[],[],[]));
    $list = json_decode($picker->content(),true);
    $assert($picker->status() === 200 && count($list['items']) === 1 && $list['items'][0]['width'] === 1,'Picker must return valid raster images only.');
    $assert(!str_contains($picker->content(),$root) && !str_contains($picker->content(),'"path"'),'Picker must never expose local filesystem paths.');
    $assert((new MediaController($config,$csrf,$audit,['username'=>'author','role'=>'author']))->picker(new Request('GET','/admin/media/picker',[],[],[]))->status() === 403,'Picker must preserve media permissions.');
    echo "Editor recovery and media picker checks passed\n";
} finally {
    $_GET = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($root);
}
