<?php
declare(strict_types=1);

use Batoi\Press\Application\{ContentMutationService, ContentRevision, IdempotencyStore};
use Batoi\Press\Content\{PageRepository, PostRepository};
use Batoi\Press\Core\{Config, FileStore, HtmlContent, AuditLog, SocialMetadata, Appearance, Request};
use Batoi\Press\Admin\{PageController, PostController, UpdateController};
use Batoi\Press\Security\{Session, Csrf, AdminAccess};

require dirname(__DIR__) . '/autoload.php';
require_once dirname(__DIR__) . '/helpers/url.php';
require_once dirname(__DIR__) . '/helpers/esc.php';
$root = sys_get_temp_dir() . '/press-backlog-' . bin2hex(random_bytes(5));
$files = new FileStore();
function backlogCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function backlogDenied(callable $action): void { try { $action(); } catch (RuntimeException) { return; } throw new RuntimeException('Expected refusal.'); }
try {
    foreach (['config', 'content/pages', 'content/posts', 'data/sessions', 'data/log', 'data/tmp'] as $dir) mkdir($root . '/radpress/' . $dir, 0700, true);
    $files->writeJson($root . '/radpress/config/paths.json', ['config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data','theme'=>'radpress/theme','public_root'=>'public_html']);
    $files->writeJson($root . '/radpress/config/site.json', ['name'=>'Fixture', 'base_url'=>'https://example.test/sub', 'homepage'=>'home']);
    $files->writeJson($root . '/radpress/config/update.json', ['current_version'=>'3.0.0', 'require_signed_packages'=>true]);
    $config = Config::load($root);
    $pages = new PageRepository($config->paths(), $files, new HtmlContent());
    $posts = new PostRepository($config->paths(), $files, new HtmlContent());
    $audit = new AuditLog($config->paths(), $files);
    $service = new ContentMutationService($config, $pages, $posts, $audit, new IdempotencyStore($config->paths()));
    $owner = ['username'=>'owner','role'=>'owner'];
    $session = new Session('backlog_' . bin2hex(random_bytes(4)), $config->paths()->dataPath('sessions'));
    $csrf = new Csrf($session);
    $pageUi = new PageController($config, $pages, $posts, $csrf, $audit, $owner);
    $postUi = new PostController($config, $pages, $posts, $csrf, $audit, $owner);
    $input = ['title'=>'Example','slug'=>'example','status'=>'published','body'=>'<p>Keep this</p>', 'og_type'=>'article','og_title'=>'Sharing "title"','og_site_name'=>'Fixture Social','og_description'=>'Social description','og_url'=>'https://example.test/example','og_image'=>'https://example.test/image.png','twitter_card'=>'summary_large_image','twitter_title'=>'Twitter title','twitter_description'=>'Twitter description','twitter_image'=>'https://example.test/twitter.png'];
    $pages->save($input, 'owner');
    $record = $pages->findBySlug('example');
    foreach (SocialMetadata::FIELDS as $field) backlogCheck($record[$field] === $input[$field], 'Social field must persist: ' . $field);
    $service->saveFromAdmin('page', ['original_slug'=>'example','slug'=>'example','title'=>'Changed','status'=>'published'], ContentRevision::for($record), 'owner');
    $record = $pages->findBySlug('example');
    backlogCheck($record['og_title'] === $input['og_title'], 'Ordinary edits preserve social metadata.');
    foreach (SocialMetadata::FIELDS as $field) backlogCheck(str_contains($pageUi->edit('example')->content(), 'name="' . $field . '"'), 'SEO editor field missing.');
    foreach (['javascript:alert(1)', 'https://user:secret@example.test/image', '//example.test/a', 'https://example.test/%0aevil'] as $url) backlogDenied(fn()=>SocialMetadata::normalize(['og_image'=>$url]));
    backlogDenied(fn()=>SocialMetadata::normalize(['twitter_card'=>'player']));
    $tags = SocialMetadata::render($record + [], ['name'=>'Fixture'], 'Fallback', 'Description', 'https://example.test/', false);
    backlogCheck(str_contains($tags, 'Sharing &quot;title&quot;') && str_contains($tags, 'name="twitter:card"'), 'Social output must escape attributes and include Twitter.');
    backlogCheck(SocialMetadata::canonical('https://example.test/sub', '/sub/example?tracking=1') === 'https://example.test/sub/example', 'Subdirectory must not duplicate in canonical URL.');
    backlogCheck(str_contains(Appearance::footerIcon('uif:rss-feed'), 'data-uif-icon="rss-feed"'), 'UIF icon token renders.');
    backlogCheck(!str_contains(Appearance::footerIcon('<img onerror=alert(1)>'), '<img'), 'Footer markup must remain text.');
    backlogCheck(str_contains(Appearance::footerIcon('↗'), '↗'), 'Unicode symbols remain supported.');
    $rev = ContentRevision::for($record);
    backlogDenied(fn()=> $service->trashFromAdmin('page','example','stale',$owner));
    backlogDenied(fn()=> $service->trashFromAdmin('page','example',$rev,['username'=>'reader','role'=>'viewer']));
    $service->trashFromAdmin('page','example',$rev,$owner);
    $trashed = $pages->findBySlug('example');
    backlogCheck($trashed['status'] === 'trashed' && $pages->allPublished() === [], 'Trashed pages must not remain public.');
    backlogCheck($trashed['body'] === $record['body'] && $trashed['og_title'] === $record['og_title'], 'Trash retains body and metadata.');
    backlogCheck(!str_contains($pageUi->index()->content(), '>Changed</strong>'), 'Default list excludes Trash.');
    $_GET['status'] = 'trashed';
    backlogCheck(str_contains($pageUi->index()->content(), 'Restore draft'), 'Trash filter exposes restoration.');
    $_GET = [];
    backlogDenied(fn()=> $service->saveFromAdmin('page', ['original_slug'=>'example','slug'=>'example','title'=>'Bypass','status'=>'published'], ContentRevision::for($trashed), 'owner'));
    $badCsrf = $pageUi->trash(new Request('POST','/admin/pages/trash',[],['slug'=>'example','decision'=>'restore'],[]));
    backlogCheck($badCsrf->status() === 400, 'Trash requires CSRF.');
    $response = $pageUi->trash(new Request('POST','/admin/pages/trash',[],['csrf_token'=>$csrf->token(),'slug'=>'example','decision'=>'restore','expected_revision'=>ContentRevision::for($trashed)],[]));
    backlogCheck($response->status() === 302 && $pages->findBySlug('example')['status'] === 'draft', 'Restore returns content privately to draft.');
    $pages->save(['title'=>'Home','slug'=>'home','body'=>'Home','status'=>'published'], 'owner');
    backlogDenied(fn()=> $service->trashFromAdmin('page','home',ContentRevision::for($pages->findBySlug('home')),$owner));
    $pages->save(['title'=>'Child','slug'=>'child','parent_slug'=>'example','body'=>'Child'], 'owner');
    backlogDenied(fn()=> $service->trashFromAdmin('page','example',ContentRevision::for($pages->findBySlug('example')),$owner));
    $posts->save(['title'=>'Post','slug'=>'post','body'=>'Post','status'=>'published','tags'=>'one,two','og_title'=>'Post social'], 'alice');
    $post = $posts->findBySlug('post');
    backlogDenied(fn()=> $service->trashFromAdmin('post','post',ContentRevision::for($post),['username'=>'bob','role'=>'author']));
    $service->trashFromAdmin('post','post',ContentRevision::for($post),['username'=>'alice','role'=>'author']);
    backlogCheck($posts->allPublished() === [] && $posts->findBySlug('post')['tags'] === ['one','two'], 'Post deletion retains tags privately.');
    backlogCheck(AdminAccess::canAccess(['role'=>'author'],'/admin/posts/trash','POST') && !AdminAccess::canAccess(['role'=>'viewer'],'/admin/posts/trash','POST'), 'Route permissions remain scoped.');
    foreach (SocialMetadata::FIELDS as $field) backlogCheck(str_contains($postUi->edit()->content(), 'name="' . $field . '"'), 'Post SEO field missing.');
    $updates = new UpdateController($config, $csrf, $audit, $owner);
    backlogCheck(str_contains($updates->index()->content(), 'Optional, but recommended'), 'Checksum remains optional.');
    require_once dirname(__DIR__) . '/helpers/date.php';
    $site = ['name'=>'Fixture'];
    $posts = [['slug'=>'one','title'=>'One','published_at'=>'2026-09-28']];
    $pageCount = 2; $pageNumber = 1;
    ob_start(); require dirname(__DIR__) . '/theme/default/layouts/blog.php'; $archive = ob_get_clean();
    backlogCheck(str_contains($archive, 'data-bp-load-more') && str_contains($archive, 'rel="next"') && str_contains($archive, '>Load More</a>'), 'Default archive retains a non-JavaScript Load More link.');
    $site['posts_load_more'] = false;
    ob_start(); require dirname(__DIR__) . '/theme/default/layouts/blog.php'; $archive = ob_get_clean();
    backlogCheck(!str_contains($archive, 'data-bp-load-more') && str_contains($archive, '>Next</a>'), 'Explicit pagination-only preference remains supported.');
    $session->destroy();
    echo "Testing-team backlog checks passed\n";
} finally {
    $_GET = [];
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($root);
    }
}
