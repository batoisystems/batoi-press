<?php
declare(strict_types=1);
// Dry-run by default. Each applied record uses revisions, snapshots and recovery.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/autoload.php';
use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Content\ProductRepository;
use Batoi\Press\Core\{Config, FileStore, HtmlContent};
$root = dirname(__DIR__, 3); $apply = in_array('--apply',$argv,true);
foreach ($argv as $index => $arg) if ($arg === '--root' && isset($argv[$index + 1])) $root = $argv[$index + 1];
$failures = 0;
try {
    $config = Config::load($root); $products = new ProductRepository($config->paths(),new FileStore(),new HtmlContent());
    foreach ($products->all() as $product) {
        if (($product['schema'] ?? null) === 2) continue;
        try {
            $input = $product + ['original_slug'=>$product['slug'],'expected_revision'=>ContentRevision::for($product)];
            $products->prepareSave($input,'product-migration');
            if ($apply) $products->save($input,'product-migration');
            echo json_encode(['slug'=>$product['slug'],'status'=>$apply ? 'migrated' : 'ready'],JSON_THROW_ON_ERROR) . "\n";
        } catch (Throwable $error) { $failures++; echo json_encode(['slug'=>$product['slug'],'status'=>'needs_review','message'=>$error->getMessage()],JSON_THROW_ON_ERROR) . "\n"; }
    }
    echo $apply ? "Applied compatible records. Prior metadata/body snapshots remain in data/versions/products/.\n" : "Dry-run only; no product content was changed. Use --apply after reviewing the report and backing up.\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage() . "\n"); exit(1); }
exit($failures === 0 ? 0 : 1);
