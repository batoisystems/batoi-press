<?php
declare(strict_types=1);

use Batoi\Press\Core\{FileStore,Paths,PluginState,PluginContext};
use Batoi\Press\Application\ContentRevision;
require dirname(__DIR__) . '/autoload.php';
$root=sys_get_temp_dir().'/press-plugin-state-'.bin2hex(random_bytes(6));
$paths=new Paths($root,['data'=>'radpress/data','content'=>'radpress/content']);$files=new FileStore();
function checkPluginState(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function rejectPluginState(callable $run,string $message):void {try{$run();}catch(RuntimeException){return;}throw new RuntimeException($message);}
try {
    $plugin=['id'=>'sample','data_schema'=>1,'capabilities'=>['state.write','events.listen'],'settings_schema'=>[
        'label'=>['label'=>'Label','type'=>'string','required'=>true,'default'=>'Default'],
        'limit'=>['label'=>'Limit','type'=>'integer','min'=>1,'max'=>10,'default'=>3],
        'active'=>['label'=>'Active','type'=>'boolean','default'=>false],
        'mode'=>['label'=>'Mode','type'=>'enum','choices'=>['quiet','verbose'],'default'=>'quiet'],
    ]];
    PluginState::validateManifest($plugin);$state=new PluginState($paths);
    $config=$state->configuration($plugin);checkPluginState($config['values']['limit']===3,'Defaults were not applied.');
    $state->saveSettings($plugin,['label'=>'Changed','limit'=>'7','active'=>'1','mode'=>'verbose'],$config['revision']);
    checkPluginState($state->configuration($plugin)['values']===['label'=>'Changed','limit'=>7,'active'=>true,'mode'=>'verbose'],'Typed settings failed.');
    rejectPluginState(fn()=>$state->saveSettings($plugin,[],$config['revision']),'Stale configuration was accepted.');
    $current=$state->configuration($plugin);
    foreach([['limit'=>'11'],['mode'=>'arbitrary'],['active'=>['malformed']],['unknown'=>'x']] as $bad)rejectPluginState(fn()=>$state->saveSettings($plugin,$bad,$current['revision']),'Invalid setting was accepted.');
    $bus=new PluginContext();$scoped=$bus->scope($paths,$plugin);
    $scoped->replaceData(['old'=>'preserved','counter'=>1],$scoped->data()['revision']);
    $stale=$scoped->data()['revision'];$scoped->replaceData(['old'=>'preserved','counter'=>2],$stale);
    rejectPluginState(fn()=>$scoped->replaceData(['counter'=>99],$stale),'Stale plugin data overwrote a newer write.');
    $unprivileged=$bus->scope($paths,['id'=>'other','capabilities'=>[]]);
    rejectPluginState(fn()=>$unprivileged->replaceData([],''),'Undeclared state write succeeded.');
    rejectPluginState(fn()=>$scoped->emit('form.accepted',['id'=>'fake','form_id'=>'enquiry']),'Plugin forged a core event.');
    $received=[];$scoped->on('form.accepted',static function(array $event)use(&$received):void{$received=$event;});
    $bus->emit('form.accepted',['id'=>'committed','form_id'=>'enquiry','visitor'=>'secret']);
    checkPluginState($received===['id'=>'committed','form_id'=>'enquiry'],'Events exposed undeclared metadata.');
    $migrated=$plugin+['migrations'=>[['from'=>1,'to'=>2,'renames'=>['old'=>'new'],'defaults'=>['enabled'=>false]]]];$migrated['data_schema']=2;
    PluginState::validateManifest($migrated);$state->migrate($migrated);
    $after=$state->read('sample');checkPluginState($after['schema']===2 && $after['data']===['counter'=>2,'new'=>'preserved','enabled'=>false] && $after['settings']['label']==='Changed','Migration corrupted plugin state.');
    rejectPluginState(fn()=>$scoped->data(),'Old plugin context read a newer data schema.');
    rejectPluginState(fn()=>$scoped->replaceData(['counter'=>99],ContentRevision::for($after)),'Old plugin context wrote a newer data schema.');
    $history=glob($paths->dataPath('plugins/sample/history/*.json'));
    checkPluginState(count($history)===1 && $files->readJson($history[0])['before']['data']['old']==='preserved','Migration snapshot missing.');
    $state->migrate($migrated);checkPluginState(count(glob($paths->dataPath('plugins/sample/history/*.json')))===1,'Migration ran twice.');
    rejectPluginState(fn()=>$state->assertRollback($plugin),'Older schema rollback discarded migrated data.');
    $broken=$migrated;$broken['data_schema']=3;
    rejectPluginState(fn()=>$state->migrate($broken),'Missing migration path succeeded.');
    checkPluginState($state->read('sample')===$after,'Failed migration changed data.');
    $conflict=$migrated;$conflict['data_schema']=3;$conflict['migrations'][]=['from'=>2,'to'=>3,'renames'=>['counter'=>'new']];
    rejectPluginState(fn()=>$state->migrate($conflict),'Conflicting migration overwrote data.');
    $invalid=$plugin;$invalid['migrations']=[['from'=>1,'to'=>2,'execute'=>'unsafe.php']];$invalid['data_schema']=2;
    rejectPluginState(fn()=>PluginState::validateManifest($invalid),'Executable migration was accepted.');
    $path=$paths->dataPath('encoding.json');$files->writeJson($path,['value'=>'original']);
    rejectPluginState(fn()=>$files->writeJson($path,['value'=>"\xff"]),'Invalid JSON encoding was written.');
    checkPluginState($files->readJson($path)['value']==='original','Failed JSON encoding lost existing content.');
    $pages=new \Batoi\Press\Content\PageRepository($paths,$files,new \Batoi\Press\Core\HtmlContent());
    $pages->save(['slug'=>'public','title'=>'Public page','status'=>'published','body'=>'<p>Body excluded from API.</p>'],'fixture');
    $pages->save(['slug'=>'private','title'=>'Draft secret','status'=>'draft','body'=>'Private draft'],'fixture');
    $reader=$bus->scope($paths,['id'=>'reader','capabilities'=>['content.read']]);$published=$reader->publishedPages();
    checkPluginState(count($published)===1 && $published[0]['title']==='Public page' && !isset($published[0]['body']),'Content service exposed drafts or raw bodies.');
    rejectPluginState(fn()=>$unprivileged->publishedPages(),'Undeclared content read succeeded.');
    $extensions=$bus->scope($paths,['id'=>'extension','capabilities'=>['blocks.render','admin.routes','content.propose','media.read','audit.write']]);
    $extensions->registerBlock('summary',static fn(array $settings):string=>'<p>Safe block</p><script>alert(1)</script><img src=x onerror=alert(1)>');
    $rendered=$bus->renderBlock('extension:summary',[]);
    checkPluginState(str_contains($rendered,'Safe block') && !str_contains($rendered,'script') && !str_contains($rendered,'onerror'),'Plugin block bypassed rich HTML sanitization.');
    rejectPluginState(fn()=>$unprivileged->registerBlock('bad',fn()=>''),'Undeclared block registration succeeded.');
    rejectPluginState(fn()=>$extensions->registerBlock('summary',fn()=>''),'Duplicate block registration succeeded.');
    $anonymous=new \Batoi\Press\Core\Request('POST','/extension',[],[],[]);
    rejectPluginState(fn()=>$extensions->proposeContent($anonymous,'page','public',[],ContentRevision::for($pages->findBySlug('public')),'anonymous'),'Plugin proposals accepted an unauthenticated request.');
    rejectPluginState(fn()=>$extensions->media($anonymous),'Plugin media accepted an unauthenticated request.');
    rejectPluginState(fn()=>$extensions->audit($anonymous,'changed','record'),'Plugin audit accepted an unauthenticated request.');
    $files->writeJson($root.'/radpress/config/paths.json',['data'=>'radpress/data','content'=>'radpress/content','config'=>'radpress/config']);
    $files->writeJson($root.'/radpress/config/site.json',['base_url'=>'https://fixture.example']);
    $files->writeJson($root.'/radpress/config/users.json',['users'=>[['username'=>'owner','role'=>'owner']]]);
    $tokens=new \Batoi\Press\Security\AccessTokenRepository($paths);
    $credential=$tokens->issue('SDK fixture',['content:write','media:read','audit:read'],'owner',new DateTimeImmutable('+1 hour'));
    $authenticated=new \Batoi\Press\Core\Request('POST','/extension',[],[],['HTTP_AUTHORIZATION'=>'Bearer '.$credential['token'],'REMOTE_ADDR'=>'192.0.2.9']);
    $before=$pages->findBySlug('public');
    $proposal=$extensions->proposeContent($authenticated,'page','public',['title'=>'Proposed title'],ContentRevision::for($before),'plugin-proposal-fixture');
    checkPluginState($proposal['state']==='pending' && $pages->findBySlug('public')===$before,'SDK proposal bypassed human approval.');
    rejectPluginState(fn()=>$extensions->proposeContent($authenticated,'page','public',[],ContentRevision::for($before),'plugin-publish-fixture','publish'),'SDK proposal bypassed publish scope.');
    checkPluginState(is_array($extensions->media($authenticated)),'Authenticated SDK media read failed.');
    $extensions->audit($authenticated,'observed','public');
    checkPluginState(str_contains($files->read($paths->dataPath('log/audit.jsonl')),'plugin.extension.observed'),'Authenticated SDK audit was not namespaced.');
    $audit=new \Batoi\Press\Core\AuditLog($paths,$files);
    foreach(['=1+1'," \t+1",'-1','@SUM(1)'] as $username)$audit->record($username,'auth.login.failed','login');
    $csv=$audit->export([])['body'];
    foreach(['=1+1'," \t+1",'-1','@SUM(1)'] as $username)checkPluginState(str_contains($csv,"'" . $username),'Audit CSV formula was not neutralized.');
    checkPluginState(str_contains($audit->export([],'jsonl')['body'],'=1+1'),'CSV hardening altered the original audit representation.');
    echo "Plugin settings, scoped services, migrations and write preservation checks passed\n";
}finally {
    if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$entry)$entry->isDir()?rmdir((string)$entry):unlink((string)$entry);rmdir($root);}
}
