<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
use Batoi\Press\Application\{ContentMutationException, ThemeDrafts};
use Batoi\Press\Core\{AuditLog, Config, FileStore, HtmlContent, Request, UifThemeKit};
use Batoi\Press\Security\{AccessTokenRepository, MachineAccessPolicy, Csrf, Session};
use Batoi\Press\Content\{PageRepository, PostRepository};
use Batoi\Press\Mcp\McpController;
use Batoi\Press\Api\ApiController;
use Batoi\Press\Admin\ThemeKitController;

$root = sys_get_temp_dir() . '/press-theme-drafts-' . bin2hex(random_bytes(5)); $files = new FileStore();
$files->writeJson($root . '/radpress/config/paths.json',['config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data']);
$files->writeJson($root . '/radpress/config/site.json',['name'=>'Draft test','base_url'=>'https://press.example.test','theme'=>'existing']);
$users = ['users'=>[['username'=>'owner','role'=>'owner'],['username'=>'admin','role'=>'admin'],['username'=>'editor','role'=>'editor']]];
$files->writeJson($root . '/radpress/config/users.json',$users);
$config = Config::load($root); $service = new ThemeDrafts($config);
$assert = static function (bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call,int $status) use ($assert): void {
    try { $call(); } catch (ContentMutationException $error) { $assert($error->httpStatus() === $status,'Unexpected theme draft error: ' . $error->getMessage()); return; }
    throw new RuntimeException('Unsafe theme draft operation was accepted.');
};
try {
    $tokens = new AccessTokenRepository($config->paths()); $policy = new MachineAccessPolicy($config->paths());
    $issued = $tokens->issue('Theme creator',['theme:read','theme:write'],'owner',new DateTimeImmutable('+1 hour'));
    $access = $policy->resolve($tokens->authenticate($issued['token']));
    $read = $tokens->issue('Theme read',['theme:read'],'owner',new DateTimeImmutable('+1 hour'));
    $other = $tokens->issue('Other creator',['theme:read','theme:write'],'owner',new DateTimeImmutable('+1 hour'));
    $admin = $tokens->issue('Admin not owner',['site:read','theme:read','theme:write'],'owner',new DateTimeImmutable('+1 hour'),'admin');
    $assert(!in_array('theme:write',$policy->resolve($tokens->authenticate($admin['token']))['scopes'],true),'Theme machine authority must remain owner-only.');
    $definition = UifThemeKit::starter();
    $reject(fn() => (new ThemeDrafts($config,new UifThemeKit($root . '/missing-source')))->validate($definition),503);
    $valid = $service->validate($definition); $assert($valid['valid'] && $valid['file_count'] > 10 && !$valid['active'],'Theme validation must compile safe definitions without activation.');
    $siteBefore = $files->read($root . '/radpress/config/site.json');
    $draft = $service->create($definition,$access,'theme-create-0001');
    $assert($draft['state'] === 'pending_review' && !$draft['active'] && str_contains($draft['review_url'],$draft['id']),'Drafts must return an honest owner handoff.');
    $assert($files->read($root . '/radpress/config/site.json') === $siteBefore && !is_dir($root . '/radpress/theme/consultancy'),'A machine draft must never install or change the active theme.');
    $assert($service->create($definition,$access,'theme-create-0001')['idempotent_replay'],'Retrying a draft must not create duplicates.');
    $changed = $definition; $changed['name'] = 'Different';
    $reject(fn() => $service->create($changed,$access,'theme-create-0001'),409);
    $reject(fn() => $service->get($draft['id'],$policy->resolve($tokens->authenticate($other['token']))),404);
    $record = $service->get($draft['id'],$access);
    $reject(fn() => $service->forDownload($draft['id'],'sha256:' . str_repeat('0',64)),409);
    $assert($service->forDownload($draft['id'],$record['approval_hash']) === $valid['definition'],'Owner download must use the reviewed definition.');
    $draftPath = $root . '/radpress/data/integrations/theme-drafts/' . $draft['id'] . '.json';
    $assert(!str_contains(file_get_contents($draftPath),'Consultancy'),'Private draft definitions must be encrypted at rest.');

    $pages = new PageRepository($config->paths(),$files,new HtmlContent()); $posts = new PostRepository($config->paths(),$files,new HtmlContent());
    $mcp = new McpController($config,$pages,$posts); $api = new ApiController($config,$pages,$posts);
    $rpc = static function (string $method,array $params,string $token) use ($mcp): array {
        $request = new Request('POST','/mcp',[],[],['HTTP_AUTHORIZATION'=>'Bearer ' . $token,'CONTENT_TYPE'=>'application/json','REMOTE_ADDR'=>'127.0.0.1'],json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params]));
        return json_decode($mcp->handle($request)->content(),true,64,JSON_THROW_ON_ERROR);
    };
    $tools = $rpc('tools/list',[],$issued['token']);
    $names = array_column($tools['result']['tools'],'name');
    foreach (['theme_kit_get','theme_validate','theme_draft_create','theme_draft_get'] as $name) $assert(in_array($name,$names,true),'Theme tool missing: ' . $name);
    $readNames = array_column($rpc('tools/list',[],$read['token'])['result']['tools'],'name');
    $assert(!in_array('theme_draft_create',$readNames,true),'Read credentials must not discover write tools.');
    $assert(isset($rpc('tools/call',['name'=>'theme_draft_create','arguments'=>['definition'=>$definition,'idempotency_key'=>'blocked-0001']],$read['token'])['error']),'Guessing a write tool must not bypass scopes.');
    $kit = $rpc('tools/call',['name'=>'theme_kit_get','arguments'=>[]],$issued['token']);
    $assert(($kit['result']['structuredContent']['kit'] ?? '') === '1.0.0','MCP must expose portable schema/starter data.');
    $bad = $definition; $bad['custom_php'] = '<?php echo 123;';
    $assert(isset($rpc('tools/call',['name'=>'theme_validate','arguments'=>['definition'=>$bad]],$issued['token'])['error']),'MCP must reject executable fields.');
    $created = $rpc('tools/call',['name'=>'theme_draft_create','arguments'=>['definition'=>$definition,'idempotency_key'=>'mcp-theme-0001']],$issued['token']);
    $assert(($created['result']['structuredContent']['state'] ?? '') === 'pending_review','MCP draft creation must preserve review state.');
    $apiResult = $api->handle(new Request('POST','/api/v2/theme-drafts',[],[],['HTTP_AUTHORIZATION'=>'Bearer ' . $issued['token'],'HTTP_IDEMPOTENCY_KEY'=>'api-theme-0001','CONTENT_TYPE'=>'application/json','REMOTE_ADDR'=>'127.0.0.1'],json_encode(['definition'=>$definition])));
    $assert($apiResult->status() === 202,'The JSON API must support the same draft contract.');
    $apiDenied = $api->handle(new Request('POST','/api/v2/theme-drafts',[],[],['HTTP_AUTHORIZATION'=>'Bearer ' . $read['token'],'CONTENT_TYPE'=>'application/json','REMOTE_ADDR'=>'127.0.0.1'],json_encode(['definition'=>$definition])));
    $assert($apiDenied->status() === 403,'API writes must require explicit theme write scope.');

    $session = new Session('theme_draft_test',$root . '/sessions'); $csrf = new Csrf($session);
    $controller = new ThemeKitController($config,$csrf,new AuditLog($config->paths(),$files),$users['users'][0]);
    $review = $controller->handle(new Request('GET','/admin/themes/uif-kit',['draft'=>$draft['id']],[],[]));
    $assert($review->status() === 200 && str_contains($review->content(),' readonly') && str_contains($review->content(),'Review AI theme draft'),'Owner review must render an immutable definition.');
    $assert($controller->handle(new Request('POST','/admin/themes/uif-kit',[],['draft'=>$draft['id'],'approval_hash'=>$record['approval_hash']],[]))->status() === 400,'Draft download requires CSRF.');
    $download = $controller->handle(new Request('POST','/admin/themes/uif-kit',[],['draft'=>$draft['id'],'approval_hash'=>$record['approval_hash'],'definition'=>json_encode($bad),'csrf_token'=>$csrf->token()],[]));
    $assert($download->status() === 200 && ($download->headers()['Content-Type'] ?? '') === 'application/zip','Download must bind to the immutable draft and ignore tampered definition fields.');
    ob_start(); $download->send(); $zipBytes = ob_get_clean();
    $files->write($root . '/download.zip',$zipBytes);
    $assert((new \Batoi\Press\Core\ThemeCompatibilityInspector())->inspect($root . '/download.zip','download.zip')['installable'],'The response must stream a complete installable ZIP after temporary-file cleanup.');

    $source = $root . '/source';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/theme/default',FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $files->write($source . '/' . substr($file->getPathname(),strlen(dirname(__DIR__) . '/theme/default/')),file_get_contents($file->getPathname()));
    $bound = new ThemeDrafts($config,new UifThemeKit($source));
    $boundDraft = $bound->create($definition,$access,'source-bound-0001'); $boundRecord = $bound->get($boundDraft['id']);
    file_put_contents($source . '/assets/css/theme.css',"\n/* changed */",FILE_APPEND);
    $reject(fn() => $bound->forDownload($boundDraft['id'],$boundRecord['approval_hash']),409);
    $tokens->revoke($access['id']);
    $assert($service->get($draft['id'])['state'] === 'connection_revoked','Owner review must show revocation.');
    $reject(fn() => $service->forDownload($draft['id'],$record['approval_hash']),409);
    $assert($files->read($root . '/radpress/config/site.json') === $siteBefore,'All review and failure paths must preserve the active site.');
    echo "Theme draft MCP/API, owner review and source-binding checks passed\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($root);
}
