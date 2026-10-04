<?php
declare(strict_types=1);

use Batoi\Press\Admin\FormController;
use Batoi\Press\Application\{ContentRevision, FormDeliveryQueue};
use Batoi\Press\Content\FormRepository;
use Batoi\Press\Core\{AuditLog, Config, FileStore, PluginManager, Request};
use Batoi\Press\Security\{Csrf, Session};

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
$root = sys_get_temp_dir() . '/press-forms-admin-' . bin2hex(random_bytes(5));
$files = new FileStore();
$files->writeJson($root . '/radpress/config/paths.json', ['config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data']);
function checkFormsAdmin(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    $config = Config::load($root); $paths = $config->paths();
    (new PluginManager($paths))->toggle('forms', true);
    $csrf = new Csrf(new Session('press_forms_admin', $paths->dataPath('sessions')));
    $audit = new AuditLog($paths, $files);
    $controller = new FormController($config, $csrf, $audit, ['username'=>'owner','role'=>'owner']);
    $post = ['csrf_token'=>$csrf->token(),'id'=>'enquiry','title'=>'Enquiry','enabled'=>'1','action'=>'email','expected_revision'=>ContentRevision::for([]),'field_id'=>['email','message'],'field_label'=>['Email','Message'],'field_type'=>['email','textarea'],'field_required'=>['1','0'],'field_length'=>['254','1000']];
    $response = $controller->handle(new Request('POST','/admin/forms',[],$post,[]));
    checkFormsAdmin($response->status() === 422 && (new FormRepository($paths))->find('enquiry') === null, 'Unconfigured mail form was published.');
    $post['enabled']='0';
    checkFormsAdmin($controller->handle(new Request('POST','/admin/forms',[],$post,[]))->status()===302, 'Draft creation required delivery capabilities.');
    $draft=(new FormRepository($paths))->find('enquiry');
    $files->writeJson($paths->configPath('integrations.json'), ['mail_provider'=>'php_mail','mail_from'=>'site@example.com','mail_to'=>'owner@example.com']);
    $config=Config::load($root);
    $controller=new FormController($config,$csrf,$audit,['username'=>'owner','role'=>'owner']);
    $post['enabled']='1';$post['expected_revision']=ContentRevision::for($draft);
    checkFormsAdmin($controller->handle(new Request('POST','/admin/forms',[],$post,[]))->status()===302,'Configured email form was not published.');
    $repo=new FormRepository($paths);$form=$repo->find('enquiry');$form['store']=true;
    for($i=0;$i<120;$i++)$repo->retain($form,'entry-'.$i,['email'=>'person@example.com','message'=>$i===1?'  =HYPERLINK("evil")':'Entry '.$i]);
    $view=$controller->handle(new Request('GET','/admin/forms',['id'=>'enquiry','page'=>'2'],[],[]));
    checkFormsAdmin(str_contains($view->content(),'Page 2 of 3') && substr_count($view->content(),'<pre>')===50 && str_contains($view->content(),'data-bp-add-form-field'), 'Submission pagination or field builder controls failed.');
    $filtered=$controller->handle(new Request('GET','/admin/forms',['id'=>'enquiry','q'=>'Entry 119'],[],[]));
    checkFormsAdmin(str_contains($filtered->content(),'1 submissions') && substr_count($filtered->content(),'<pre>')===1,'Stored-value filtering failed.');
    $csv=$controller->handle(new Request('GET','/admin/forms',['id'=>'enquiry','export'=>'csv'],[],[]));
    checkFormsAdmin($csv->status()===200 && $csv->headers()['Cache-Control']==='private, no-store' && str_contains($csv->content(),"'  =HYPERLINK"), 'CSV privacy or formula protection failed.');
    $editor=new FormController($config,$csrf,$audit,['username'=>'editor','role'=>'editor']);
    checkFormsAdmin($editor->handle(new Request('GET','/admin/forms',['id'=>'enquiry','export'=>'csv'],[],[]))->status()===403,'Editor exported private submissions.');
    $webhook=$form;$webhook['action']='webhook';$webhook['connection']='missing';
    $rejected=false;try{(new FormDeliveryQueue($config))->assertReady($webhook);}catch(RuntimeException){$rejected=true;}
    checkFormsAdmin($rejected,'Unavailable webhook passed publication preflight.');
    checkFormsAdmin(!str_contains($files->read($paths->dataPath('log/audit.jsonl')),'person@example.com'),'Export audit leaked visitor data.');
    $import=['action'=>'import_contact','id'=>'contact-copy','csrf_token'=>$csrf->token()];
    checkFormsAdmin($controller->handle(new Request('POST','/admin/forms',[],$import,[]))->status()===302,'Legacy contact draft could not be created.');
    $preview=$controller->handle(new Request('GET','/admin/forms',['preview'=>'contact-copy'],[],[]));
    checkFormsAdmin($preview->status()===200 && $preview->headers()['Cache-Control']==='private, no-store' && str_contains($preview->content(),'Draft preview. Submissions are disabled.') && str_contains($preview->content(),'type="submit" disabled'),'Draft preview was unavailable or accepted submissions.');
    checkFormsAdmin($editor->handle(new Request('GET','/admin/forms',['preview'=>'contact-copy'],[],[]))->status()===403,'Editor accessed private draft preview.');
    $copy=$repo->find('contact-copy');checkFormsAdmin(!$copy['enabled'] && !$copy['store'] && array_column($copy['fields'],'id')===['name','email','subject','message'],'Contact conversion lost fields or privacy defaults.');
    checkFormsAdmin($controller->handle(new Request('POST','/admin/forms',[],$import,[]))->status()===409,'Contact import overwrote an existing form.');
    $files->writeJson($paths->configPath('integrations.json'),['recaptcha_site_key'=>'partial']);
    $rejected=false;try{(new FormDeliveryQueue(Config::load($root)))->assertReady(['action'=>'store']);}catch(RuntimeException){$rejected=true;}checkFormsAdmin($rejected,'Contact conversion allowed incomplete CAPTCHA configuration.');
    echo "Forms publication, administration and private export checks passed\n";
} finally {
    session_write_close();
    if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry)$entry->isDir()?rmdir((string)$entry):unlink((string)$entry);rmdir($root);}
}
