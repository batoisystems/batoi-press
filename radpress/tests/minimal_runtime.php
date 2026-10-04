<?php
declare(strict_types=1);

use Batoi\Press\Core\{Config,FileStore,HtmlContent,PluginManager,RuntimeCapabilities,PageBlockRenderer};
use Batoi\Press\Content\{PageRepository,PostRepository,ProductRepository,FormRepository};
use Batoi\Press\Application\{ContentRevision,FormDeliveryQueue};
use Batoi\Press\Security\{Auth,Password,Session};
require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__).'/helpers/url.php';
require dirname(__DIR__).'/helpers/date.php';
$root=sys_get_temp_dir().'/press-minimal-'.bin2hex(random_bytes(5));$files=new FileStore();
function checkMinimal(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
try {
    $files->writeJson($root.'/radpress/config/paths.json',['config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data']);
    $config=Config::load($root);$paths=$config->paths();$html=new HtmlContent();
    $pages=new PageRepository($paths,$files,$html);
    $pages->save(['slug'=>'welcome','title'=>'Welcome 秘密','body'=>'<p>Hello website.</p>','status'=>'published'],'fixture-owner');
    checkMinimal(count($pages->allPublished())===1 && $pages->findBySlug('welcome')['title']==='Welcome 秘密','Basic publishing depended on optional adapters or damaged text.');
    $renderer=new PageBlockRenderer($paths,new PostRepository($paths,$files,$html),new ProductRepository($paths,$files,$html));
    checkMinimal(str_contains($renderer->render([['type'=>'html','body'=>$pages->findBySlug('welcome')['body']]]),'Hello website.'),'Basic rendering failed.');
    $files->writeJson($paths->configPath('users.json'),['users'=>[['username'=>'owner','role'=>'owner','password_hash'=>Password::hash('synthetic-fixture-password')]]]);
    $session=new Session('press_minimal_runtime',$paths->dataPath('sessions'));$auth=new Auth($paths,$session,$files);
    checkMinimal($auth->attempt('owner','synthetic-fixture-password') && ($auth->user()['role']??'')==='owner' && session_status()===PHP_SESSION_ACTIVE,'Local login depended on optional cryptography or remote identity.');
    $plugins=new PluginManager($paths);$plugins->toggle('forms',true);
    $repo=new FormRepository($paths);$form=$repo->save(['id'=>'simple','title'=>'Simple','enabled'=>true,'action'=>'store','fields'=>[['id'=>'message','label'=>'Message','type'=>'text']]],ContentRevision::for([]));
    (new FormDeliveryQueue($config))->assertReady($form);$repo->retain($form,'simple-one',['message'=>'Hello']);
    checkMinimal(count($repo->submissions('simple'))===1,'Store-only forms required transport or encryption.');
    checkMinimal(!array_filter(get_included_files(),static fn(string $file):bool=>str_contains($file,'/vendor/')),'Core publishing loaded Composer dependencies.');
    foreach(RuntimeCapabilities::rows() as $row)if($row['level']==='core')checkMinimal($row['available'],'A core capability is unavailable.');
    if(!RuntimeCapabilities::available('encryption')) {
        $blocked=false;$form['action']='email';try{(new FormDeliveryQueue($config))->assertReady($form);}catch(RuntimeException){$blocked=true;}checkMinimal($blocked,'Encrypted delivery failed open.');
    }
    if(!RuntimeCapabilities::available('http')) {
        $blocked=false;try{$plugins->toggle('webhooks',true);}catch(RuntimeException){$blocked=true;}checkMinimal($blocked,'Remote adapter activated without transport.');
    }
    echo 'Minimal publishing/login/store-only checks passed; optional capabilities: '.json_encode(array_intersect_key(array_column(RuntimeCapabilities::rows(),'available','id'),array_flip(['http','encryption','signatures','images','mbstring'])))."\n";
}finally {
    session_write_close();if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$entry)$entry->isDir()?rmdir((string)$entry):unlink((string)$entry);rmdir($root);}
}
