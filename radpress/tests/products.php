<?php
declare(strict_types=1);

use Batoi\Press\Content\ProductRepository;
use Batoi\Press\Core\App;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Core\Paths;
use Batoi\Press\Core\Request;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/esc.php';
require dirname(__DIR__) . '/helpers/url.php';
require dirname(__DIR__) . '/helpers/date.php';

$sourceRoot = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/batoi-press-products-' . bin2hex(random_bytes(4));
try {
    foreach (['public_html', 'radpress/config', 'radpress/content/products', 'radpress/content/pages', 'radpress/content/posts', 'radpress/data', 'radpress/theme'] as $dir) mkdir($root . '/' . $dir, 0775, true);
    file_put_contents($root . '/radpress/config/paths.json', json_encode(['public_root'=>'public_html','config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data','theme'=>'radpress/theme']));
    file_put_contents($root . '/radpress/config/site.json', json_encode(['name'=>'Store','theme'=>'default']));
    copyTreeProducts($sourceRoot . '/radpress/theme/default', $root . '/radpress/theme/default');
    $paths = new Paths($root, ['public_root'=>'public_html','config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data','theme'=>'radpress/theme']);
    $products = new ProductRepository($paths, new FileStore(), new HtmlContent());
    $saved = $products->save(['title'=>'Digital Guide','slug'=>'','sku'=>'GUIDE-1','status'=>'published','price'=>'49.5','currency'=>'usd','inventory'=>'7','body'=>'<p onclick="bad()">Useful guide</p>'], 'owner');
    assertProduct(($saved['slug'] ?? '') === 'digital-guide', 'product slugs should derive from titles');
    $duplicateRejected = false;
    try { $products->save(['title'=>'Replacement','slug'=>'digital-guide','price'=>'1','body'=>'Replacement'], 'owner'); }
    catch (RuntimeException) { $duplicateRejected = true; }
    assertProduct($duplicateRejected && $products->findBySlug('digital-guide')['title'] === 'Digital Guide', 'creating a duplicate product must preserve the existing product');
    assertProduct(($saved['price'] ?? '') === '49.50' && ($saved['currency'] ?? '') === 'USD', 'product prices and currency should normalize');
    assertProduct(!str_contains((string)($products->findBySlug('digital-guide')['body'] ?? ''), 'onclick='), 'product descriptions should be sanitized');
    $shop = (new App($root))->handle(new Request('GET', '/shop', [], [], []));
    assertProduct($shop->status() === 200 && str_contains($shop->content(), 'Digital Guide'), 'published products should render in the public shop');
    $detail = (new App($root))->handle(new Request('GET', '/product/digital-guide', [], [], []));
    assertProduct($detail->status() === 200 && str_contains($detail->content(), 'USD 49.50'), 'published product detail routes should render commerce metadata');
    echo "Product checks passed\n";
} finally { removeProducts($root); }

function assertProduct(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function copyTreeProducts(string $source, string $target): void { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) { $dest=$target . '/' . substr((string)$item, strlen($source)+1); if ($item->isDir()) { if(!is_dir($dest)) mkdir($dest,0775,true); } else { if(!is_dir(dirname($dest))) mkdir(dirname($dest),0775,true); copy((string)$item,$dest); } } }
function removeProducts(string $path): void { if(!is_dir($path)) return; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir()?rmdir((string)$item):unlink((string)$item); rmdir($path); }
