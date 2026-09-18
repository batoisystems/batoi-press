<?php
declare(strict_types=1);

use Batoi\Press\Admin\ImportController;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Session;

require dirname(__DIR__) . '/autoload.php';

$root = sys_get_temp_dir() . '/batoi-press-xml-import-' . bin2hex(random_bytes(4));
try {
    foreach (['public_html','radpress/config','radpress/content/pages','radpress/content/posts','radpress/content/assets','radpress/data'] as $dir) mkdir($root.'/'.$dir,0775,true);
    file_put_contents($root.'/radpress/config/paths.json',json_encode(['public_root'=>'public_html','config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data','theme'=>'radpress/theme']));
    $config=Config::load($root); $files=new FileStore(); $html=new HtmlContent();
    $pages=new PageRepository($config->paths(),$files,$html); $posts=new PostRepository($config->paths(),$files,$html);
    $controller=new ImportController($config,$pages,$posts,new Csrf(new Session('bp_import_test',$config->paths()->dataPath('sessions'))),new AuditLog($config->paths(),$files),['username'=>'owner','role'=>'owner']);
    $method=new ReflectionMethod($controller,'import');
    $xml='<?xml version="1.0"?><batoi-press><pages><page><title>Imported Page</title><slug>imported-page</slug><status>draft</status><body><![CDATA[<p>Page body</p>]]></body></page></pages><posts><post><title>Imported Post</title><slug>imported-post</slug><status>draft</status><category>News</category><body><![CDATA[<p>Post body</p>]]></body></post></posts></batoi-press>';
    $counts=$method->invoke($controller,$xml);
    assertImport(($counts['pages']??0)===1 && ($counts['posts']??0)===1,'XML import should create page and post content');
    assertImport(($pages->findBySlug('imported-page')['title']??'')==='Imported Page','imported pages should persist through the repository');
    $second=$method->invoke($controller,$xml);
    assertImport(($second['skipped']??0)===2,'XML import should skip existing slugs without overwriting content');
    $unsafeRejected=false;
    try{$method->invoke($controller,'<!DOCTYPE x [<!ENTITY file SYSTEM "file:///etc/passwd">]><batoi-press/>');}catch(RuntimeException $exception){$unsafeRejected=str_contains($exception->getMessage(),'not allowed');}
    assertImport($unsafeRejected,'XML import should reject document type and entity declarations');
    $hierarchy = '<batoi-press><pages><page><title>Child</title><slug>child</slug><parent_slug>parent</parent_slug><body>Child</body></page><page><title>Parent</title><slug>parent</slug><body>Parent</body></page><page><title>Orphan</title><slug>orphan</slug><parent_slug>missing</parent_slug><body>Orphan</body></page></pages></batoi-press>';
    $ordered = $method->invoke($controller, $hierarchy);
    assertImport($ordered['pages'] === 2 && $ordered['skipped'] === 1 && $pages->publicPath('child') === '/parent/child', 'imports must resolve children listed before parents and skip unresolved parents');
    $mediaXml = '<batoi-press><media><file name="guide.txt" source="/old/guide.txt" encoding="base64">' . base64_encode('A plain text guide') . '</file></media><pages><page><title>Media import</title><slug>media-import</slug><body><![CDATA[<a href="/old/guide.txt">Guide</a>]]></body></page></pages></batoi-press>';
    $mediaCounts = $method->invoke($controller, $mediaXml);
    $mediaBody = $pages->findBySlug('media-import')['body'];
    assertImport($mediaCounts['media'] === 1 && str_contains($mediaBody, '/assets/documents/') && !str_contains($mediaBody, '/old/guide.txt'), 'embedded media references should point to the imported asset');
    $unsupported = false;
    try { $method->invoke($controller, '<urlset><url><loc>https://example.com/</loc></url></urlset>'); } catch (RuntimeException $e) { $unsupported = str_contains($e->getMessage(), 'Unsupported XML format'); }
    assertImport($unsupported, 'unsupported XML should explain the format requirement');
    echo "XML import checks passed\n";
} finally { removeImport($root); }
function assertImport(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function removeImport(string $path):void{if(!is_dir($path))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$item)$item->isDir()?rmdir((string)$item):unlink((string)$item);rmdir($path);}
