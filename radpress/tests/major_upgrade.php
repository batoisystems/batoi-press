<?php
declare(strict_types=1);

use Batoi\Press\Core\{Config,FileStore,Paths,PluginManager,RuntimeCapabilities,HtmlContent,Request};
use Batoi\Press\Content\FormRepository;
use Batoi\Press\Application\{ContentRevision,FormDeliveryQueue,WebhookTransport};
use Batoi\Press\Security\{Auth,Password,Session,SessionRegistry,RateLimiter,MfaRepository,Totp};

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
$root = sys_get_temp_dir() . '/press-major-' . bin2hex(random_bytes(5));
$files = new FileStore();
$files->writeJson($root . '/radpress/config/paths.json', ['config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data','theme'=>'radpress/theme','public_root'=>'public_html']);
$paths = Config::load($root)->paths();
function checkMajor(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    $receiver=require __DIR__.'/fixtures/webhook-receiver.php';
    $secret=str_repeat('synthetic-',4);$timestamp=(string)time();
    $body=json_encode(['id'=>'fixture-delivery','event'=>'form.submitted','data'=>['form_id'=>'enquiry','fields'=>['email'=>'fixture@example.test']]],JSON_THROW_ON_ERROR);
    $signature='sha256='.hash_hmac('sha256',$timestamp.'.'.$body,$secret);$receipt=$paths->dataPath('receiver-fixture.json');
    checkMajor($receiver($body,$timestamp,$signature,'fixture-delivery',$secret,$receipt,time()),'Receiver did not accept a valid signed event.');
    checkMajor(!$receiver($body,$timestamp,$signature,'fixture-delivery',$secret,$receipt,time()),'Receiver repeated an accepted operation.');
    foreach([[$body.' ', $timestamp,$signature],[$body,(string)(time()-301),'sha256='.hash_hmac('sha256',(string)(time()-301).'.'.$body,$secret)]] as [$invalidBody,$invalidTime,$invalidSignature]){
        $rejected=false;try{$receiver($invalidBody,$invalidTime,$invalidSignature,'fixture-delivery',$secret,$receipt,time());}catch(RuntimeException){$rejected=true;}checkMajor($rejected,'Receiver accepted tampering or an expired signature.');
    }
    checkMajor(!array_filter(get_included_files(), static fn(string $file): bool => str_contains($file, '/vendor/')), 'Core boot loaded optional Composer dependencies.');
    $caps = array_fill_keys(array_keys(RuntimeCapabilities::definitions()), false);
    foreach (['php','json','session','hash','filter','password'] as $id) $caps[$id]=true;
    checkMajor(!array_filter(RuntimeCapabilities::rows($caps), static fn(array $row): bool => $row['status']==='Blocking'), 'Optional features blocked minimal installation.');
    $html = new HtmlContent();
    foreach (['<a href=javascript:alert(1)>x</a>','<a href="java&#x09;script:alert(1)">x</a>','<img src=x onerror=alert(1)>','<iframe srcdoc="<script>alert(1)</script>" src="https://example.com"></iframe>','<svg><a xlink:href="javascript:alert(1)">x</a></svg>','<div style="background:url(javascript:alert(1))">x</div>','<input formaction="data:text/html,evil" autofocus onfocus=alert(1)>'] as $source) {
        $clean = $html->sanitize($source);
        checkMajor(!preg_match('/javascript:|onerror=|onfocus=|srcdoc=|data:text|xlink:/i', $clean), 'Active HTML survived sanitization.');
    }
    $plugins = new PluginManager($paths);
    checkMajor(!$plugins->enabled('forms'), 'Forms must be default-off.');
    $plugins->toggle('forms',true);
    $repo = new FormRepository($paths);
    $input = ['id'=>'enquiry','title'=>'Enquiry','enabled'=>true,'action'=>'store','fields'=>[['id'=>'email','label'=>'Email','type'=>'email','required'=>true],['id'=>'message','label'=>'Message','type'=>'textarea','max_length'=>20,'sensitive'=>true],['id'=>'consent','label'=>'I agree','type'=>'consent']]];
    $form=$repo->save($input,ContentRevision::for([]));
    checkMajor(count($repo->validate($form,['email'=>'bad','consent'=>'evil'])['errors'])===2,'Invalid fields were accepted.');
    $result=$repo->validate($form,['email'=>'person@example.com','message'=>'private','consent'=>'1','forged'=>'extra']);
    checkMajor($result['errors']===[] && !isset($result['values']['forged']),'Field allowlist failed.');
    $repo->retain($form,'submission-one',$result['values']);$repo->retain($form,'submission-one',$result['values']);
    checkMajor(count($repo->submissions('enquiry'))===1 && $repo->submissions('enquiry')[0]['values']['message']==='[redacted]','Idempotency/redaction failed.');
    $rejected=false;try{$repo->save($input,ContentRevision::for([]));}catch(RuntimeException){$rejected=true;}
    checkMajor($rejected,'Stale form replaced current revision.');
    $config=Config::load($root);$controller=new Batoi\Press\Application\FormController($config);
    $get=$controller->handle('enquiry',new Request('GET','/forms/enquiry',[],[],[]));
    checkMajor($get->status()===200 && $get->headers()['Cache-Control']==='private, no-store','Public form GET failed.');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$get->content(),$csrf);
    preg_match('/name="submission_id" value="([a-f0-9]+)"/',$get->content(),$nonce);
    $post=['csrf_token'=>$csrf[1],'submission_id'=>$nonce[1],'fields'=>['email'=>'person@example.com','message'=>'<b>private</b>','consent'=>'1']];
    $sent=$controller->handle('enquiry',new Request('POST','/forms/enquiry',[],$post,['REMOTE_ADDR'=>'192.0.2.1']));
    checkMajor($sent->status()===302,'Valid public form was rejected.');
    checkMajor($controller->handle('enquiry',new Request('POST','/forms/enquiry',[],$post,['REMOTE_ADDR'=>'192.0.2.1']))->status()===400,'Duplicate nonce was accepted.');
    $deliveryCount=0;$queue=new FormDeliveryQueue($config,static function(string $id,array $payload) use (&$deliveryCount):void { $deliveryCount++; });
    $queue->enqueue('delivery-one',$form,$result['values']);$queue->enqueue('delivery-one',$form,$result['values']);
    checkMajor(!str_contains($files->read($paths->dataPath('forms/deliveries.json')),'person@example.com'),'Queue retained plaintext.');
    checkMajor($queue->process() && !$queue->process() && $deliveryCount===1 && $queue->status()[0]['status']==='sent','Queue duplicate/lease processing failed.');
    $queue->enqueue('crashed-fifth-attempt',$form,$result['values']);
    $files->mutateJson($paths->dataPath('forms/deliveries.json'),static function(array $state):array {
        $job=&$state['jobs']['crashed-fifth-attempt'];
        $job['status']='processing';$job['attempts']=5;$job['lease']='expired-worker';$job['lease_until']=time()-1;
        return $state;
    });
    checkMajor(!$queue->process() && $deliveryCount===1,'Expired fifth attempt triggered a sixth delivery.');
    $exhausted=$files->readJson($paths->dataPath('forms/deliveries.json'))['jobs']['crashed-fifth-attempt'];
    checkMajor($exhausted['status']==='failed' && $exhausted['attempts']===5 && !isset($exhausted['payload']) && !isset($exhausted['lease']),'Exhausted worker retained delivery secrets or lease.');
    $deliveryForm = $form; $deliveryForm['action']='email';
    $queue->enqueue('atomic-submission',$deliveryForm,$result['values'],true);
    $queue->enqueue('atomic-submission',$deliveryForm,$result['values'],true);
    $atomic = $files->readJson($paths->dataPath('forms/deliveries.json'));
    checkMajor(isset($atomic['jobs']['atomic-submission']) && count($atomic['submissions'])===1 && $atomic['submissions'][0]['values']['message']==='[redacted]','Atomic acceptance did not persist one redacted submission with its job.');
    checkMajor(count($repo->submissions('enquiry'))===3,'Queued records must merge with retained legacy records.');
    $changedValues=$result['values'];$changedValues['email']='other@example.com';$conflict=false;
    try{$queue->enqueue('atomic-submission',$deliveryForm,$changedValues,true);}catch(RuntimeException){$conflict=true;}
    checkMajor($conflict,'Delivery identity accepted changed visitor data.');
    $files->mutateJson($paths->dataPath('forms/deliveries.json'),static function(array $state):array {
        for($i=count($state['jobs']);$i<1000;$i++)$state['jobs']['capacity-'.$i]=['expires_at'=>time()+3600];
        return $state;
    });
    $beforeFull=$files->read($paths->dataPath('forms/deliveries.json'));$full=false;
    try{$queue->enqueue('unaccepted',$deliveryForm,$result['values'],true);}catch(RuntimeException){$full=true;}
    checkMajor($full && $files->read($paths->dataPath('forms/deliveries.json'))===$beforeFull,'Full queue changed retained data or accepted an orphan submission.');
    foreach (['https://example.com/a','http://example.com/a','https://user:password@example.com/a','https://example.com:8443/a'] as $i=>$url) {
        $transport=new WebhookTransport(static fn()=>['127.0.0.1']);$blocked=false;try{$transport->destination($url);}catch(RuntimeException){$blocked=true;}checkMajor($blocked,'Unsafe destination accepted.');
    }
    $transport=new WebhookTransport(static fn()=>['8.8.8.8']);checkMajor($transport->destination('https://example.com/a')['host']==='example.com','Public pinned destination rejected.');
    $files->writeJson($paths->configPath('users.json'),['users'=>[['username'=>'owner','role'=>'owner','password_hash'=>Password::hash('test-password-2026')]]]);
    $session=new Session('press_major_test',$paths->dataPath('sessions'));$auth=new Auth($paths,$session,$files);
    checkMajor($auth->attempt('owner','test-password-2026'),'Actual login failed.');
    $registry=new SessionRegistry($paths);$sid=$session->id();
    checkMajor(count($registry->allFor('owner'))===1,'Actual login did not enter inventory.');
    checkMajor($registry->revoke(hash('sha256',$sid),'owner'),'Actual session could not be revoked.');
    session_write_close();session_id($sid);checkMajor($auth->user()===null,'Revoked session remained authenticated.');session_write_close();
    $limiter=new RateLimiter($paths,2,300);checkMajor($limiter->consume('test') && $limiter->consume('test') && !$limiter->consume('test'),'Atomic quota failed.');
    $files->write($paths->dataPath('tmp/rate/'.hash('sha256','broken').'.json'),'{');checkMajor(!$limiter->consume('broken') && $limiter->tooManyAttempts('broken'),'Corrupt quota failed open.');
    if (function_exists('pcntl_fork')) {
        $mfa=new MfaRepository($paths);$mfa->enable('owner',Totp::generateSecret(),['1234-ABCD','ABCD-5678']);
        $children=[];
        for($i=0;$i<8;$i++) { $pid=pcntl_fork(); if($pid===0) { while(!is_file($root.'/go')) usleep(1000); $rate=(new RateLimiter($paths,3,300))->consume('parallel'); $recovery=(new MfaRepository($paths))->verify('owner','1234-ABCD'); file_put_contents($root.'/worker-'.$i,json_encode([$rate,$recovery]));exit(0); } $children[]=$pid; }
        touch($root.'/go');foreach($children as $pid)pcntl_waitpid($pid,$status);
        $allowed=0;$recovered=0;for($i=0;$i<8;$i++){[$r,$m]=json_decode(file_get_contents($root.'/worker-'.$i),true);$allowed+=(int)$r;$recovered+=(int)($m==='recovery');}
        checkMajor($allowed===3 && $recovered===1,'Concurrent quota/recovery transaction failed.');
        checkMajor($mfa->verify('owner','ABCD-5678')==='recovery','Race removed unrelated recovery code.');
    }
    $files->write($paths->dataPath('plugins/disabled.lock'),'recovery');checkMajor(!$plugins->enabled('forms'),'Recovery mode did not disable plugins.');
    echo "Major upgrade form, queue, portability and security transaction checks passed\n";
} finally {
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if(is_dir($root)) { foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry) $entry->isDir()?rmdir((string)$entry):unlink((string)$entry);rmdir($root); }
}
