<?php
declare(strict_types=1);

use Batoi\Press\Core\{FileStore, Paths, Theme};
use Batoi\Press\Update\{ReleaseSignature, RollbackManager, UpdateRunner};

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
$workspace = sys_get_temp_dir() . '/press-upgrade-compatibility-' . bin2hex(random_bytes(5));
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$keys = ['fixture'=>base64_encode(sodium_crypto_sign_publickey($keypair))];
$store = new FileStore();

function upgradeCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function upgradeTheme(string $marker = 'original'): array
{
    $files = ['theme.json'=>json_encode(['slug'=>'default','name'=>'Bundled theme','version'=>'1.0.0','author'=>'Fixture','assets'=>['styles'=>[['file'=>'css/theme.css']],'scripts'=>[['file'=>'js/theme.js']]]]), 'assets/css/theme.css'=>'body { color: ' . ($marker === 'original' ? '#123456' : '#abcdef') . '; }', 'assets/js/theme.js'=>'window.fixtureTheme = ' . json_encode($marker) . ';'];
    foreach (['base','page','post','blog','archive','404'] as $layout) $files['layouts/'.$layout.'.php'] = $layout === 'base' ? '<html><body><?php echo $content; ?></body></html>' : '<main data-theme="'.$marker.'"><?php echo $page["body"] ?? ""; ?></main>';
    return $files;
}
function upgradeFixture(string $name, string $url = 'https://press.example.test/public_html'): array
{
    global $workspace, $store, $keys;
    $root = $workspace . '/' . $name;
    $config = ['public_root'=>'public_html','app'=>'radpress/app','config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data','theme'=>'radpress/theme'];
    $paths = new Paths($root, $config);
    foreach (['public_html','radpress/app','radpress/content','radpress/data/cache','radpress/data/log','radpress/data/sessions','radpress/data/tmp','radpress/data/backups','radpress/theme/default'] as $dir) mkdir($root.'/'.$dir, 0755, true);
    $store->writeJson($paths->configPath('paths.json'), $config);
    $store->writeJson($paths->configPath('site.json'), ['theme'=>'default','base_url'=>$url,'name'=>'Custom business','homepage'=>'home','palette'=>['primary'=>'#123456'],'custom_setting'=>'preserve me']);
    $store->writeJson($paths->configPath('security.json'), ['session_name'=>'fixture']);
    $store->writeJson($paths->configPath('update.json'), ['current_version'=>'1.0.0','require_signed_packages'=>true,'release_public_keys'=>$keys]);
    foreach (upgradeTheme() as $file=>$body) $store->write($paths->themePath('default/'.$file), $body);
    foreach (['public_html/index.php','public_html/admin.php','radpress/autoload.php'] as $file) $store->write($root.'/'.$file, '<?php // fixture');
    $store->write($paths->contentPath('pages/home/body.html'), '<h1>Preserved content</h1>');
    $store->write($root.'/README.md', 'original runtime');
    return [$root,$paths,new UpdateRunner($paths)];
}
function upgradePackage(string $root, string $version, array $themes, array $extra = []): string
{
    global $keys, $secret;
    $files = ['README.md'=>'runtime '.$version,'radpress/config/update.json'=>json_encode(['current_version'=>$version,'require_signed_packages'=>true,'release_public_keys'=>$keys])];
    foreach ($themes as $slug=>$theme) foreach ($theme as $path=>$body) $files['radpress/theme/'.$slug.'/'.$path] = $body;
    $files = array_replace($files, $extra);
    $entries = [];foreach ($files as $path=>$body) $entries[] = ['path'=>$path,'sha256'=>hash('sha256',$body)];
    $manifest = ReleaseSignature::sign(['name'=>'Fixture Press','version'=>$version,'files'=>$entries], $secret, 'fixture', 'package-manifest');
    $path = $root.'/package-'.bin2hex(random_bytes(4)).'.zip';$zip = new ZipArchive();$zip->open($path, ZipArchive::CREATE);
    foreach ($files as $name=>$body) $zip->addFromString($name, $body);
    $zip->addFromString('release.json', json_encode($manifest));$zip->close();
    return $path;
}
function upgradeApply(UpdateRunner $runner, string $package, array $server): array
{
    $stage = $runner->stage($package);upgradeCheck($stage['ok'] ?? false, 'Signed compatibility fixture failed to stage: '.($stage['error']??''));
    return $runner->apply($stage['stage_dir'], $server);
}
function upgradeBaseline(string $root, Paths $paths, bool $rootManifest = false): void
{
    global $store, $secret;
    $files = [];foreach (upgradeTheme() as $path=>$body) $files[] = ['path'=>'radpress/theme/default/'.$path,'sha256'=>hash('sha256',$body)];
    $manifest = ReleaseSignature::sign(['version'=>'1.0.0','files'=>$files],$secret,'fixture','package-manifest');
    $store->writeJson($rootManifest ? $root.'/release.json' : $paths->dataPath('updates/installed-release.json'), $manifest);
}

try {
    // Legacy unknown provenance: isolate the complete active design, then apply the stock release.
    [$root,$paths,$runner] = upgradeFixture('legacy');
    $beforeSite = $store->readJson($paths->configPath('site.json'));
    $oldSiteBytes = $store->read($paths->configPath('site.json'));
    $original = upgradeTheme();
    $server = ['DOCUMENT_ROOT'=>$paths->publicPath(),'REQUEST_URI'=>'/admin/updates/apply','HTTP_HOST'=>'press.example.test'];
    $first = upgradeApply($runner, upgradePackage($root,'1.1.0',['default'=>upgradeTheme('new')]), $server);
    upgradeCheck($first['ok'] ?? false, 'Legacy migration failed: '.($first['error']??''));
    $site = $store->readJson($paths->configPath('site.json'));$expected = $beforeSite;$expected['theme']='default-preserved';$expected['base_url']='https://press.example.test';
    upgradeCheck($site === $expected, 'Only active theme and erroneous URL path should change.');
    foreach ($original as $path=>$body) if ($path !== 'theme.json') upgradeCheck($store->read($paths->themePath('default-preserved/'.$path)) === $body, 'Custom theme file changed: '.$path);
    $oldManifest = json_decode($original['theme.json'],true);$copyManifest = $store->readJson($paths->themePath('default-preserved/theme.json'));unset($oldManifest['slug'],$oldManifest['name'],$copyManifest['slug'],$copyManifest['name']);
    upgradeCheck($oldManifest === $copyManifest, 'Theme contract metadata must survive isolation.');
    upgradeCheck(str_contains($store->read($paths->themePath('default/layouts/page.php')), 'data-theme="new"'), 'Bundled theme did not receive its update.');
    upgradeCheck($store->read($paths->contentPath('pages/home/body.html')) === '<h1>Preserved content</h1>', 'Upgrade rewrote content.');
    $rendered = (new Theme($paths, $site))->render('page',['page'=>['body'=>'<h1>Preserved content</h1>']]);
    upgradeCheck($rendered->status() === 200 && str_contains($rendered->content(),'data-theme="original"'), 'Preserved theme is not the rendered active design.');
    $second = upgradeApply($runner, upgradePackage($root,'1.2.0',['default'=>upgradeTheme('next')]), $server);
    upgradeCheck(($second['ok'] ?? false) && $second['compatibility']['themes'] === [], 'Repeat upgrades should update known stock files without cloning again.');
    upgradeCheck(count(glob($paths->themePath('default-preserved*'))) === 1 && $store->readJson($paths->configPath('site.json')) === $expected, 'Repeat upgrade changed the isolated design or URL.');
    upgradeCheck($first['backup'] !== $second['backup'] && is_file($first['backup']), 'Rapid upgrades must not overwrite a rollback backup.');
    upgradeCheck((new RollbackManager($paths))->restore($second['backup'])['ok'], 'Second upgrade rollback failed.');
    upgradeCheck($store->readJson($paths->dataPath('updates/installed-release.json'))['version'] === '1.1.0', 'Rollback must restore the previous release baseline.');
    upgradeCheck((new RollbackManager($paths))->restore($first['backup'])['ok'], 'Legacy rollback failed.');
    upgradeCheck($store->read($paths->configPath('site.json')) === $oldSiteBytes && $store->read($paths->themePath('default/layouts/page.php')) === $original['layouts/page.php'], 'Rollback must restore original theme activation, template, and base URL.');

    // Authenticated stock history permits normal theme updates; local additions/deletions do not.
    foreach (['stock','edited','added','deleted','tampered-history','wrong-version','identical'] as $case) {
        [$root,$paths,$runner] = upgradeFixture($case, 'https://press.example.test');
        if ($case !== 'identical') upgradeBaseline($root,$paths,$case === 'stock');
        if ($case === 'edited') $store->write($paths->themePath('default/assets/css/theme.css'), 'custom colors');
        if ($case === 'added') $store->write($paths->themePath('default/local.txt'), 'local addition');
        if ($case === 'deleted') unlink($paths->themePath('default/layouts/archive.php'));
        if ($case === 'tampered-history' || $case === 'wrong-version') {
            $history = $store->readJson($paths->dataPath('updates/installed-release.json'));
            $history['version'] = '0.9.0';
            if ($case === 'wrong-version') $history = ReleaseSignature::sign($history,$secret,'fixture','package-manifest');
            $store->writeJson($paths->dataPath('updates/installed-release.json'), $history);
        }
        $incoming = $case === 'identical' ? upgradeTheme() : upgradeTheme('new');
        $result = upgradeApply($runner,upgradePackage($root,'1.1.0',['default'=>$incoming]),['DOCUMENT_ROOT'=>$paths->publicPath()]);
        upgradeCheck($result['ok'] ?? false, 'History migration failed: '.$case);
        $preserve = !in_array($case,['stock','identical'],true);
        upgradeCheck(($store->readJson($paths->configPath('site.json'))['theme'] === 'default-preserved') === $preserve, 'Incorrect stock/custom classification: '.$case);
        if ($case === 'deleted') upgradeCheck(!file_exists($paths->themePath('default-preserved/layouts/archive.php')), 'Isolation must preserve intentional deletions.');
        if ($case === 'added') upgradeCheck($store->read($paths->themePath('default-preserved/local.txt')) === 'local addition', 'Isolation lost a local addition.');
    }

    [$root,$paths,$runner] = upgradeFixture('collisions');
    $store->write($paths->themePath('default-preserved'), 'occupied file');
    $store->write($paths->themePath('default-preserved-2/local.txt'), 'occupied directory');
    $result = upgradeApply($runner,upgradePackage($root,'1.1.0',['default'=>upgradeTheme('new'),'default-preserved-3'=>upgradeTheme('other')]),['DOCUMENT_ROOT'=>$paths->publicPath()]);
    upgradeCheck(($result['ok']??false) && $store->readJson($paths->configPath('site.json'))['theme']==='default-preserved-4', 'Preserved names must avoid installed and incoming themes.');
    upgradeCheck($store->read($paths->themePath('default-preserved'))==='occupied file' && $store->read($paths->themePath('default-preserved-2/local.txt'))==='occupied directory', 'Name collision overwrote existing data.');

    [$root,$paths,$runner] = upgradeFixture('fixed-paths');
    $fixed = '<link rel="stylesheet" href="/theme-assets/default/css/theme.css">';
    $store->write($paths->themePath('default/layouts/page.php'),$fixed);
    $themeBefore = $store->readJson($paths->themePath('default/theme.json'));
    $result = upgradeApply($runner,upgradePackage($root,'1.1.0',['default'=>upgradeTheme('new') + ['new.txt'=>'new packaged file']]),['DOCUMENT_ROOT'=>$paths->publicPath()]);
    upgradeCheck(($result['ok']??false) && $result['compatibility']['themes'][0]['mode']==='in_place', 'Fixed theme references must stay in place.');
    upgradeCheck($store->read($paths->themePath('default/layouts/page.php'))===$fixed && !file_exists($paths->themePath('default/new.txt')) && $store->readJson($paths->themePath('default/theme.json'))===$themeBefore, 'In-place protection must preserve the entire directory.');

    [$root,$paths,$runner] = upgradeFixture('inactive','https://press.example.test');
    foreach (upgradeTheme('independent') as $path=>$body) $store->write($paths->themePath('independent/'.$path), $body);
    $site = $store->readJson($paths->configPath('site.json'));$site['theme']='independent';$store->writeJson($paths->configPath('site.json'),$site);
    $siteBytes = $store->read($paths->configPath('site.json'));
    $result = upgradeApply($runner,upgradePackage($root,'1.1.0',['default'=>upgradeTheme('new')]),['DOCUMENT_ROOT'=>$paths->publicPath()]);
    upgradeCheck(($result['ok']??false) && is_dir($paths->themePath('default-preserved')) && $store->read($paths->configPath('site.json'))===$siteBytes, 'Preserving an inactive theme must not switch the active theme or rewrite settings.');

    [$root,$paths] = upgradeFixture('separate-theme-store');
    $configuration = $store->readJson($paths->configPath('paths.json'));$configuration['theme']='radpress/custom-themes';$store->writeJson($paths->configPath('paths.json'),$configuration);
    $paths = new Paths($root,$configuration);
    foreach (upgradeTheme('external') as $path=>$body) $store->write($paths->themePath('default/'.$path),$body);
    $result = upgradeApply(new UpdateRunner($paths),upgradePackage($root,'1.1.0',['default'=>upgradeTheme('new')]),['DOCUMENT_ROOT'=>$paths->publicPath()]);
    upgradeCheck(($result['ok']??false) && str_contains($store->read($paths->themePath('default/layouts/page.php')),'data-theme="external"') && str_contains($store->read($root.'/radpress/theme/default/layouts/page.php'),'data-theme="original"'), 'A separately configured theme store must stay untouched without installing unused theme replacements.');

    // Migration failures roll back site configuration, core files, baseline, and new theme copies.
    foreach (['health-failure','record-failure'] as $case) {
        [$root,$paths,$runner] = upgradeFixture($case);
        upgradeBaseline($root,$paths);
        $store->write($paths->themePath('default/assets/css/theme.css'),'customized');
        $siteBytes = $store->read($paths->configPath('site.json'));$history = $store->read($paths->dataPath('updates/installed-release.json'));
        $extra = $case==='health-failure' ? ['radpress/config/update.json'=>'{broken'] : [];
        if ($case==='record-failure') { unlink($paths->dataPath('updates/installed-release.json'));rmdir($paths->dataPath('updates'));$store->write($paths->dataPath('updates'),'occupied storage'); }
        $result = upgradeApply($runner,upgradePackage($root,'1.1.0',['default'=>upgradeTheme('new')],$extra),['DOCUMENT_ROOT'=>$paths->publicPath()]);
        upgradeCheck(!($result['ok']??true) && ($result['rolled_back']??false), 'Failed compatibility migration must roll back: '.$case);
        upgradeCheck($store->read($paths->configPath('site.json'))===$siteBytes && $store->read($root.'/README.md')==='original runtime' && $store->read($paths->themePath('default/assets/css/theme.css'))==='customized', 'Rollback failed to restore pre-upgrade bytes.');
        upgradeCheck(glob($paths->themePath('default-preserved*'))===[] && !file_exists($paths->dataPath('maintenance.json')), 'Failed upgrade left a partial theme or maintenance flag.');
        if ($case==='health-failure') upgradeCheck($store->read($paths->dataPath('updates/installed-release.json'))===$history, 'Failed update changed trusted history.');
    }

    // The filesystem mount confirms URL paths; request headers never supply a new origin.
    $urlCases = [
        ['root','https://press.example.test/public_html','public','/admin/updates/apply',[], 'https://press.example.test',false],
        ['port','http://press.example.test:8080/public_html/','public','/admin/updates/apply',[], 'http://press.example.test:8080',false],
        ['valid-root','https://press.example.test/','public','/admin/updates/apply',[], 'https://press.example.test/',false],
        ['valid-subdirectory','https://press.example.test/public_html','application','/public_html/admin/updates/apply',[], 'https://press.example.test/public_html',false],
        ['duplicate-subdirectory','https://press.example.test/public_html/public_html','application','/public_html/admin/updates/apply',[], 'https://press.example.test/public_html',false],
        ['cli','https://press.example.test/public_html','none',null,[], 'https://press.example.test/public_html',true],
        ['proxy','https://press.example.test/public_html','public','/portal/admin/updates/apply',['HTTP_X_FORWARDED_PREFIX'=>'/portal'], 'https://press.example.test/public_html',true],
        ['host','https://press.example.test/public_html','public','/admin/updates/apply',['HTTP_HOST'=>'attacker.example'], 'https://press.example.test/public_html',true],
        ['unconfirmed-prefix','https://press.example.test/public_html','public','/public_html/admin/updates/apply',[], 'https://press.example.test/public_html',true],
    ];
    foreach ($urlCases as [$name,$url,$documentRoot,$uri,$headers,$expected,$review]) {
        [$root,$paths,$runner] = upgradeFixture('url-'.$name,$url);
        $server = $headers;
        if ($documentRoot!=='none') $server['DOCUMENT_ROOT']=$documentRoot==='public' ? $paths->publicPath() : $root;
        if ($uri!==null) $server['REQUEST_URI']=$uri;
        $result = upgradeApply($runner,upgradePackage($root,'1.1.0',[]),$server);
        upgradeCheck(($result['ok']??false) && $store->readJson($paths->configPath('site.json'))['base_url']===$expected, 'Incorrect URL migration: '.$name);
        upgradeCheck(($result['compatibility']['base_url_review']??false)===$review, 'Incorrect URL review decision: '.$name);
    }

    [$root,$paths,$runner] = upgradeFixture('linked-public-directory');
    rename($paths->publicPath(),$root.'/actual-web-root');symlink($root.'/actual-web-root',$paths->publicPath());
    $result = upgradeApply($runner,upgradePackage($root,'1.1.0',[]),['DOCUMENT_ROOT'=>$root.'/actual-web-root','REQUEST_URI'=>'/admin/updates/apply']);
    upgradeCheck(($result['ok']??false) && $store->readJson($paths->configPath('site.json'))['base_url']==='https://press.example.test', 'Public directory aliases should use the confirmed physical mount without changing the configured directory.');

    [$root,$paths,$runner] = upgradeFixture('pending-site-operation');
    $before = $store->readJson($paths->configPath('site.json'));
    $pending = array_replace($before, ['name'=>'Recently committed name']);
    $operation = 'proposal_' . bin2hex(random_bytes(16));
    $documents = new Batoi\Press\Content\WebsiteDocumentStore($paths, $store, static function(string $point): void { if ($point==='written') throw new RuntimeException('Fixture interruption'); });
    try { $documents->commit('site',$pending,Batoi\Press\Application\ContentRevision::for($before),$operation); }
    catch (RuntimeException $error) { upgradeCheck($error->getMessage()==='Fixture interruption','Unexpected site transaction failure.'); }
    $result = upgradeApply($runner,upgradePackage($root,'1.1.0',[]),['DOCUMENT_ROOT'=>$paths->publicPath()]);
    $reloaded = Batoi\Press\Core\Config::load($root)->site();
    upgradeCheck(($result['ok']??false) && $reloaded['name']==='Recently committed name' && $reloaded['base_url']==='https://press.example.test', 'URL repair must reconcile a pending site transaction before changing settings.');
    upgradeCheck((new Batoi\Press\Content\WebsiteDocumentStore($paths))->receipt('site',$operation)['state']==='committed','Upgrade lost a completed website-operation receipt.');

    [$root,$paths,$runner] = upgradeFixture('browser-result');
    $config = Batoi\Press\Core\Config::load($root);
    $session = new Batoi\Press\Security\Session('press_upgrade_compatibility',$paths->dataPath('sessions'));
    $csrf = new Batoi\Press\Security\Csrf($session);$audit = new Batoi\Press\Core\AuditLog($paths,$store);
    $stage = $runner->stage(upgradePackage($root,'1.1.0',['default'=>upgradeTheme('new')]));
    upgradeCheck($stage['ok']??false,'Browser result fixture failed to stage.');
    $originalServer = $_SERVER;
    try {
        $_SERVER = array_replace($_SERVER,['DOCUMENT_ROOT'=>$paths->publicPath(),'SCRIPT_FILENAME'=>$paths->publicPath('admin.php'),'SCRIPT_NAME'=>'/admin.php','REQUEST_URI'=>'/admin/updates/apply','HTTP_HOST'=>'press.example.test']);
        $response = (new Batoi\Press\Admin\UpdateController($config,$csrf,$audit,['username'=>'owner','role'=>'owner'],$session))->apply($csrf->token(),basename($stage['stage_dir']));
    } finally { $_SERVER = $originalServer; }
    upgradeCheck($response->status()===303 && ($response->headers()['Location']??'')==='/admin/updates','Successful apply must redirect to the Updates page as a GET.');
    $updates = new Batoi\Press\Admin\UpdateController(Batoi\Press\Core\Config::load($root),$csrf,$audit,['username'=>'owner','role'=>'owner'],$session);
    $content = $updates->index()->content();
    upgradeCheck(str_contains($content,'Update applied.') && str_contains($content,'Preserved theme default as default-preserved.') && str_contains($content,'Corrected base URL to https://press.example.test.'),'Updates page must explain successful apply, automatic preservation and URL repair.');
    upgradeCheck(!str_contains($updates->index()->content(),'Update applied.'),'Refreshing Updates must not repeat the apply notice.');
    $event = $audit->entries(1)[0];
    upgradeCheck($event['action']==='update.applied' && $event['details']['themes'][0]['to']==='default-preserved' && $event['details']['base_url']['to']==='https://press.example.test','Update audit must retain migration results.');

    [$root,$paths,$runner] = upgradeFixture('linked-theme');
    $store->write($root.'/outside.txt','outside sentinel');symlink($root.'/outside.txt',$paths->themePath('default/linked.txt'));
    $result = upgradeApply($runner,upgradePackage($root,'1.1.0',['default'=>upgradeTheme('new')]),['DOCUMENT_ROOT'=>$paths->publicPath()]);
    upgradeCheck(!($result['ok']??true) && $store->read($root.'/outside.txt')==='outside sentinel' && $store->read($root.'/README.md')==='original runtime', 'Linked theme entries must fail without replacing runtime or external files.');
    upgradeCheck(!isset($result['rolled_back']) && glob($paths->dataPath('backups/*.zip'))===[], 'Linked themes must be rejected before backup traversal or replacement.');
    echo "Upgrade theme preservation, base URL migration and rollback checks passed\n";
} finally {
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    if (is_dir($workspace)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isLink() || !$entry->isDir()) unlink($entry->getPathname()); else rmdir($entry->getPathname());
        }
        rmdir($workspace);
    }
}
