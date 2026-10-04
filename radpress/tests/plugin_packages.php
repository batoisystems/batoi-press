<?php
declare(strict_types=1);

use Batoi\Press\Core\{FileStore,Paths,PluginManager,Config,AuditLog};
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
$root = sys_get_temp_dir() . '/press-plugins-' . bin2hex(random_bytes(5));
$paths = new Paths($root, ['config'=>'radpress/config','data'=>'radpress/data']);
$files = new FileStore();
function assertPackage(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    $keypair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($keypair); $private = sodium_crypto_sign_secretkey($keypair);
    $files->writeJson($paths->configPath('plugin-keys.json'), ['keys'=>['fixture'=>base64_encode($public)]]);
    $manager = new PluginManager($paths);
    $create = static function (string $version, array $extra = [], bool $tamper = false, ?array $compatibility = null, array $metadata = []) use ($root,$private): string {
        $source = '<?php return static function ($context): void { $GLOBALS["press_plugin_test"] = "' . $version . '"; $context->on("form.accepted", static function ($event): void { $GLOBALS["press_event_test"] = $event["id"]; }); };';
        $manifest = ['schema'=>1,'id'=>'sample','name'=>'Sample','version'=>$version,'api'=>PluginManager::API,'compatibility'=>$compatibility ?? ['php'=>['min'=>'8.3.0','max_exclusive'=>'9.0.0'],'press'=>['min'=>'3.0.0','max_exclusive'=>'5.0.0']],'kind'=>'native','entrypoint'=>'entry.php','requirements'=>[],'capabilities'=>['events.listen'],'files'=>['entry.php'=>hash('sha256',$source)]];
        $manifest = array_replace($manifest, $metadata);
        $signature=sodium_crypto_sign_detached(json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$private);
        $manifest['trust']=['key_id'=>'fixture','signature'=>base64_encode($signature)];
        $path=$root.'/fixture-'.$version.'-'.bin2hex(random_bytes(4)).'.zip';$zip=new ZipArchive();$zip->open($path,ZipArchive::CREATE);
        $zip->addFromString('plugin.json',json_encode($manifest));$zip->addFromString('entry.php',$source . ($tamper ? ' tampered' : ''));
        foreach($extra as $name=>$bytes)$zip->addFromString($name,$bytes);$zip->close();return $path;
    };
    $manager->install($create('0.9.0', [], false, ['php'=>['min'=>'99.0.0','max_exclusive'=>'100.0.0'],'press'=>['min'=>'3.0.0','max_exclusive'=>'5.0.0']]));
    $blocked=false;try{$manager->toggle('sample',true);}catch(RuntimeException){$blocked=true;}
    assertPackage($blocked && !$manager->enabled('sample') && $manager->all()['sample']['missing'] !== [], 'Incompatible plugin was enabled or its requirements hidden.');
    $first=$create('1.0.0');$manager->install($first);
    assertPackage(!isset($GLOBALS['press_plugin_test']) && !$manager->enabled('sample'),'Inspection executed or enabled PHP.');
    $manager->toggle('sample',true);$context=$manager->boot();assertPackage($GLOBALS['press_plugin_test']==='1.0.0','Approved plugin did not boot.');
    $context->emit('form.accepted',['id'=>'event-one','form_id'=>'enquiry','visitor'=>'private']);assertPackage($GLOBALS['press_event_test']==='event-one','Typed hook contract failed.');
    $invalid=false;try{$context->emit('delivery.completed',['id'=>'event-two','success'=>'yes']);}catch(RuntimeException){$invalid=true;}assertPackage($invalid,'Untyped delivery metadata was accepted.');
    foreach([ $create('2.0.0',[],true),$create('2.0.0',['../escape.php'=>'evil']),$create('2.0.0',['unlisted.php'=>'evil']) ] as $bad) {
        $rejected=false;try{$manager->install($bad);}catch(RuntimeException){$rejected=true;}assertPackage($rejected,'Unsafe package was installed.');
    }
    assertPackage($manager->all()['sample']['version']==='1.0.0','Failed install replaced current package.');
    $manager->install($create('2.0.0'));assertPackage(!$manager->enabled('sample'),'Upgrade retained activation without review.');
    $manager->rollback('sample');assertPackage($manager->all()['sample']['version']==='1.0.0' && !$manager->enabled('sample'),'Rollback failed or executed code.');
    $settings = ['label'=>['label'=>'Observer label','type'=>'string','required'=>true]];
    $manager->install($create('3.0.0', [], false, null, ['settings_schema'=>$settings,'data_schema'=>2,'migrations'=>[['from'=>1,'to'=>2,'defaults'=>['counter'=>0]]]]));
    $blocked=false;try{$manager->toggle('sample',true);}catch(RuntimeException){$blocked=true;}assertPackage($blocked,'Required settings did not block activation.');
    $configuration=$manager->configuration('sample');$manager->configure('sample',['label'=>'Configured observer'],$configuration['revision']);
    $manager->toggle('sample',true);$manager->boot();assertPackage($manager->configuration('sample')['schema']===2,'Activation did not run declared migration.');
    $blocked=false;try{$manager->rollback('sample');}catch(RuntimeException){$blocked=true;}assertPackage($blocked && !$manager->enabled('sample') && $manager->all()['sample']['version']==='3.0.0','Older data schema rollback was not blocked safely.');
    $files->writeJson($paths->configPath('paths.json'),['config'=>'radpress/config','data'=>'radpress/data','content'=>'radpress/content']);
    $config=Config::load($root);$session=new \Batoi\Press\Security\Session('press_plugin_ui',$paths->dataPath('sessions'));$csrf=new \Batoi\Press\Security\Csrf($session);
    $owner=new \Batoi\Press\Admin\PluginController($config,$csrf,new AuditLog($paths,$files),['username'=>'owner','role'=>'owner']);
    $request=new \Batoi\Press\Core\Request('GET','/admin/plugins',['configure'=>'sample'],[],[]);
    assertPackage(str_contains($owner->handle($request)->content(),'Configured observer'),'Owner configuration UI did not show scoped settings.');
    $admin=new \Batoi\Press\Admin\PluginController($config,$csrf,new AuditLog($paths,$files),['username'=>'admin','role'=>'admin']);assertPackage($admin->handle($request)->status()===403,'Administrator accessed owner package settings.');
    $bad=new \Batoi\Press\Core\Request('POST','/admin/plugins',[],['action'=>'configure','plugin'=>'sample','settings'=>['label'=>'Forbidden'],'csrf_token'=>'bad'],[]);assertPackage($owner->handle($bad)->status()===400,'Package settings accepted invalid CSRF.');
    session_write_close();
    $files->write($root.'/radpress/app/plugins/sample/entry.php','<?php // tampered live package');
    assertPackage($manager->all()['sample']['kind']==='invalid' && !$manager->enabled('sample'),'Invalid installed package was hidden or executable.');
    $manager->toggle('sample',false);
    $files->write($paths->dataPath('plugins/disabled.lock'),'recovery');$manager->boot();assertPackage(!$manager->enabled('sample'),'Recovery mode bypassed.');
    $manager->uninstall('sample');assertPackage(!isset($manager->all()['sample']),'Uninstall failed to archive package.');
    echo "Signed plugin inspection, lifecycle and recovery checks passed\n";
} finally {
    if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry)$entry->isDir()?rmdir((string)$entry):unlink((string)$entry);rmdir($root);}
}
