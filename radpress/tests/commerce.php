<?php
declare(strict_types=1);

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Commerce\{OrderStore, StoreSettings, StripeCheckout};
use Batoi\Press\Content\ProductRepository;
use Batoi\Press\Core\{Config, FileStore, HtmlContent, Money};
use Batoi\Press\Security\AdminAccess;

require dirname(__DIR__) . '/autoload.php';

if (($argv[1] ?? '') === '--buyer' || ($argv[1] ?? '') === '--crash') {
    $config = Config::load($argv[2]);
    $store = new OrderStore($config->paths(), new FileStore(), ($argv[1] === '--crash') ? static function (string $stage): void { if ($stage === 'file:0') exit(37); } : null);
    try { $store->reserve(['last-unit'=>1], ['email'=>'buyer@example.test'], hash('sha256', 'buyer:' . $argv[3]), substr(hash('sha256', 'checkout:' . $argv[3]), 0, 32), ['currency'=>'USD','shipping_minor'=>0,'tax_bps'=>0,'mode'=>'test']); exit(0); }
    catch (RuntimeException) { exit(9); }
}
$root = sys_get_temp_dir() . '/press-commerce-' . bin2hex(random_bytes(6));
$files = new FileStore();
try {
    $files->writeJson($root . '/radpress/config/paths.json', ['config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', ['name'=>'Commerce fixture','base_url'=>'https://store.example.test']);
    $config = Config::load($root); $paths = $config->paths();
    $products = new ProductRepository($paths, $files, new HtmlContent());
    $settings = ['currency'=>'USD','shipping_minor'=>250,'tax_bps'=>500,'mode'=>'test'];
    $product = $products->save(['title'=>'Guide','slug'=>'guide','sku'=>'GUIDE','currency'=>'USD','price'=>'19.99','inventory'=>'10','status'=>'published','body'=>'<p>Guide</p>'], 'owner');
    checkCommerce($product['price_minor'] === 1999, 'prices use exact minor units');
    checkCommerce(Money::parse('0.1','USD') + Money::parse('0.2','USD') === 30, 'decimal addition is exact');
    checkCommerce(Money::parse('10.00','JPY') === 10 && Money::format(1234,'KWD') === '1.234', 'currency precision and historic zero decimals');
    foreach ([['1.001','USD'],['1.01','JPY'],['1e3','USD'],['-1','USD'],['1','XXX']] as [$amount,$currency]) denyCommerce(fn()=>Money::parse($amount,$currency), 'invalid decimal/currency');
    $store = new OrderStore($paths);
    mkdir($paths->dataPath('backups'),0700,true);
    $backup = (new \Batoi\Press\Update\BackupManager($paths))->create();
    checkCommerce($backup['ok'], 'runtime backup fixture');
    $access = str_repeat('a',64); $key = str_repeat('b',32);
    $order = $store->reserve(['guide'=>2], ['email'=>'buyer@example.test','name'=>'Buyer','delivery'=>'shipping'], $access, $key, $settings);
    checkCommerce($order['total_minor'] === 4460 && $products->findBySlug('guide')['inventory'] === 8, 'authoritative totals, tax rounding and stock reservation');
    checkCommerce(!str_contains(file_get_contents($paths->dataPath('commerce/orders/' . $order['id'] . '.json')), 'buyer@example.test'), 'customer information is encrypted');
    checkCommerce($store->reserve(['guide'=>2], ['email'=>'buyer@example.test','name'=>'Buyer','delivery'=>'shipping'], $access, $key, $settings)['id'] === $order['id'] && $products->findBySlug('guide')['inventory'] === 8, 'duplicate checkout reserves once');
    denyCommerce(fn()=> $store->reserve(['guide'=>1], ['email'=>'buyer@example.test'], $access, $key, $settings), 'changed idempotent request');
    denyCommerce(fn()=> $products->save($product + ['original_slug'=>'guide','expected_revision'=>ContentRevision::for($product)], 'owner'), 'stale product save after reservation');
    $session = ['livemode'=>false,'id'=>'cs_test_fixture123456','url'=>'https://checkout.stripe.com/c/pay/test','currency'=>'usd','amount_total'=>$order['total_minor'],'metadata'=>['press_order'=>$order['id'],'press_site'=>$store->siteId()],'payment_status'=>'paid','status'=>'complete','payment_intent'=>'pi_fixture123456'];
    $store->attach($order['id'],$session);
    $paid = $store->applySession($order['id'],$session,'evt_fixture123456');
    $store->applySession($order['id'],$session,'evt_fixture123456');
    $store->applySession($order['id'],$session,'evt_fixture223456');
    checkCommerce($paid['payment_status'] === 'paid' && $paid['allocation'] === 'sold' && $products->findBySlug('guide')['inventory'] === 8, 'duplicate payment events never duplicate stock effects');
    checkCommerce(count(glob($paths->dataPath('commerce/outbox/*.json'))) === 1, 'one durable outbox event per paid order');
    denyCommerce(fn()=> $store->applySession($order['id'],array_replace($session,['amount_total'=>1]),'evt_wrong123456'), 'wrong amount');
    $fulfilled = $store->fulfil($order['id'],ContentRevision::for($paid));
    checkCommerce($fulfilled['fulfilment_status'] === 'fulfilled', 'revisioned fulfilment');
    denyCommerce(fn()=> $store->fulfil($order['id'],ContentRevision::for($paid)), 'duplicate/stale fulfilment');
    $refunded = array_replace($session,['payment_intent'=>['id'=>'pi_fixture123456','latest_charge'=>['amount_refunded'=>$order['total_minor']]]]);
    checkCommerce($store->applySession($order['id'],$refunded,'evt_refund123456')['payment_status'] === 'refunded' && $products->findBySlug('guide')['inventory'] === 8, 'verified refund updates do not auto-restock');
    $rollback = (new \Batoi\Press\Update\RollbackManager($paths))->restore($backup['path']);
    checkCommerce($rollback['ok'] && $rollback['preserved_commerce_products'] && $products->findBySlug('guide')['inventory'] === 8, 'runtime rollback cannot rewind inventory after orders exist');
    $products->save(['title'=>'Late payment','slug'=>'late-payment','price'=>'10','inventory'=>'1','status'=>'published'], 'owner');
    $clock = time() - 10000; $basic = ['currency'=>'USD','shipping_minor'=>0,'tax_bps'=>0,'mode'=>'test'];
    $late = $store->reserve(['late-payment'=>1],['email'=>'late@example.test'],str_repeat('1',64),str_repeat('2',32),$basic,$clock);
    $replacement = $store->reserve(['late-payment'=>1],['email'=>'new@example.test'],str_repeat('3',64),str_repeat('4',32),$basic,$clock + 2200);
    $lateSession = array_replace($session,['id'=>'cs_test_late123456','amount_total'=>$late['total_minor'],'metadata'=>['press_order'=>$late['id'],'press_site'=>$store->siteId()]]);
    $latePaid = $store->applySession($late['id'],$lateSession,'evt_late123456',$clock + 2201);
    checkCommerce($latePaid['payment_status'] === 'paid' && $latePaid['fulfilment_status'] === 'needs_attention' && $latePaid['allocation'] === 'unavailable' && $products->findBySlug('late-payment')['inventory'] === 0, 'late payment after another reservation becomes an exception without overselling');
    denyCommerce(fn()=> $store->fulfil($late['id'],ContentRevision::for($latePaid)), 'unallocated paid order cannot be fulfilled');
    $signatureBody = json_encode(['id'=>'evt_signed123456','type'=>'checkout.session.completed','livemode'=>false]);
    $secret = 'whsec_' . str_repeat('z',32); $timestamp = time();
    $header = 't=' . $timestamp . ',v1=' . hash_hmac('sha256',$timestamp . '.' . $signatureBody,$secret);
    checkCommerce(StripeCheckout::verifyEvent($signatureBody,$header,$secret)['id'] === 'evt_signed123456', 'raw-byte Stripe signature verification');
    denyCommerce(fn()=>StripeCheckout::verifyEvent($signatureBody . ' ',$header,$secret), 'modified webhook');
    denyCommerce(fn()=>StripeCheckout::verifyEvent($signatureBody,$header,$secret,$timestamp + 301), 'stale webhook');
    $called = false;
    $adapter = new StripeCheckout('dummy', static function ($method,$path,$data,$idempotency) use (&$called,$order): array { $called = $method === 'POST' && $path === '/v1/checkout/sessions' && $data['line_items'][0]['price_data']['unit_amount'] === 1999 && $idempotency === $order['id'] && !isset($data['success_url']['paid']); return ['id'=>'cs_test_fake123456']; });
    $adapter->create($order,['email'=>'buyer@example.test'],'https://store.example.test',$store->siteId());
    checkCommerce($called, 'Stripe requests use authoritative integer line items and stable idempotency');
    $products->save(['title'=>'Last unit','slug'=>'last-unit','price'=>'10','inventory'=>'1','status'=>'published'], 'owner');
    $workers = [];
    for ($i = 0; $i < 20; $i++) {
        $p = proc_open([PHP_BINARY,__FILE__,'--buyer',$root,(string)$i], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
        if (!is_resource($p)) throw new RuntimeException('Unable to start concurrent buyer.');
        $workers[] = $p;
    }
    $success = 0; foreach ($workers as $p) { $exit = proc_close($p); if ($exit === 0) $success++; else checkCommerce($exit === 9, 'concurrent buyers have an explained rejection'); }
    // Repeated identities are idempotent, so count committed orders rather than successful retries.
    $lastOrders = array_filter($store->all(),static fn(array $o): bool => $o['items'][0]['slug'] === 'last-unit');
    checkCommerce(count($lastOrders) === 1 && $products->findBySlug('last-unit')['inventory'] === 0 && $success >= 1, '20 concurrent attempts cannot oversell one unit');
    $current = $products->findBySlug('last-unit');
    $current['inventory'] = '1'; $current['original_slug'] = 'last-unit'; $current['expected_revision'] = ContentRevision::for($products->findBySlug('last-unit'));
    $products->save($current,'owner');
    $p = proc_open([PHP_BINARY,__FILE__,'--crash',$root,'crash'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
    checkCommerce(proc_close($p) === 37, 'crash after stock write');
    checkCommerce($products->findBySlug('last-unit')['inventory'] === 0, 'next ordinary product read completes a prepared commerce journal');
    $crashOrder = $store->reserve(['last-unit'=>1], ['email'=>'buyer@example.test'], hash('sha256', 'buyer:crash'),substr(hash('sha256', 'checkout:crash'), 0, 32),['currency'=>'USD','shipping_minor'=>0,'tax_bps'=>0,'mode'=>'test']);
    checkCommerce($crashOrder['items'][0]['quantity'] === 1, 'retry recovers the committed order after process termination');
    checkCommerce(!AdminAccess::canAccess(['role'=>'editor'],'/admin/orders') && !AdminAccess::canAccess(['role'=>'admin'],'/admin/commerce') && AdminAccess::canAccess(['role'=>'owner'],'/admin/commerce'), 'commerce permissions are independent of content editing');
    checkCommerce(!AdminAccess::canAccess(['role'=>'editor','_commerce_active'=>true],'/admin/products/save') && AdminAccess::canAccess(['role'=>'editor','_commerce_active'=>false],'/admin/products/save'), 'financial product changes require merchant administration while legacy catalogue editing remains available');
    checkCommerce(!array_key_exists('customer', $store->summaries()[0]) && !array_key_exists('items', $store->summaries()[0]), 'order list projections omit customer payloads and line bodies');
    checkCommerce((new StoreSettings($config))->read()['enabled'] === false,'commerce defaults off');
    $legacy = ['id'=>'prd_' . str_repeat('a',12),'slug'=>'legacy','title'=>'Legacy','sku'=>'LEGACY','price'=>'12.30','currency'=>'USD','inventory'=>3,'status'=>'draft','created_at'=>'2026-01-01T00:00:00Z','updated_at'=>'2026-01-01T00:00:00Z'];
    $files->writeJson($paths->contentPath('products/legacy/meta.json'),$legacy);
    $legacy['custom_reference'] = 'retain-this-site-value';
    $files->writeJson($paths->contentPath('products/legacy/meta.json'),$legacy);
    $files->write($paths->contentPath('products/legacy/body.html'),'<p>Legacy body</p>');
    $migration = dirname(__DIR__) . '/docs/examples/migrate-products.php';
    foreach ([false,true,true] as $apply) {
        $args = [PHP_BINARY,$migration,'--root',$root]; if ($apply) $args[] = '--apply';
        $process = proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $report = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        checkCommerce(proc_close($process) === 0, 'product migration completed: ' . $error);
        if (!$apply) checkCommerce(!isset($products->findBySlug('legacy')['schema']), 'migration dry-run preserves product content');
    }
    $migrated = $products->findBySlug('legacy');
    checkCommerce($migrated['id'] === $legacy['id'] && $migrated['price_minor'] === 1230 && $migrated['body'] === '<p>Legacy body</p>' && glob($paths->dataPath('versions/products/legacy/*/meta.json')) !== [], 'migration preserves identity/body/exact price and keeps prior snapshots');
    checkCommerce($migrated['custom_reference'] === $legacy['custom_reference'], 'migration preserves additive site/plugin metadata');
    echo "Commerce integrity checks passed\n";
} finally {
    if (is_dir($root)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $p) $p->isDir() ? rmdir((string)$p) : unlink((string)$p); rmdir($root); }
}
function checkCommerce(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
function denyCommerce(callable $work,string $message): void { try { $work(); } catch (RuntimeException) { return; } throw new RuntimeException('Expected rejection: ' . $message); }
