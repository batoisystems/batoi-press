<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Batoi\Press\Core\{UifThemeKit, ThemeCompatibilityInspector};
$kit = new UifThemeKit(); $root = sys_get_temp_dir() . '/press-uif-kit-' . bin2hex(random_bytes(5)); mkdir($root,0700);
try {
    foreach (['corporate','editorial','shop'] as $preset) {
        $definition = UifThemeKit::starter(); $definition['preset'] = $preset;
        $one = $root . '/' . $preset . '-1.zip'; $two = $root . '/' . $preset . '-2.zip';
        $kit->writeZip($definition,$one); $kit->writeZip($definition,$two);
        if (hash_file('sha256',$one) !== hash_file('sha256',$two)) throw new RuntimeException('Theme packaging is not reproducible.');
        $report = (new ThemeCompatibilityInspector())->inspect($one,'fixture.zip');
        if (!$report['installable']) throw new RuntimeException('Generated theme failed ordinary package inspection: ' . json_encode($report['checks']));
        $zip = new ZipArchive(); $zip->open($one);
        for ($i = 0; $i < $zip->numFiles; $i++) if (str_ends_with($zip->getNameIndex($i),'.php')) token_get_all($zip->getFromIndex($i),TOKEN_PARSE);
        $zip->close();
    }
    foreach ([['unknown'=>'x'],['tokens'=>['body_text'=>'url(https://example.test)']],['sections'=>[['type'=>'button','text'=>'Go','url'=>'//evil.test']]],['sections'=>[['type'=>'php','text'=>'eval()']]],['width'=>10000],['name'=>'sk_live_' . str_repeat('a',20)]] as $change) {
        $rejected = false; try { $kit->validate(array_replace(UifThemeKit::starter(),$change)); } catch (RuntimeException) { $rejected = true; }
        if (!$rejected) throw new RuntimeException('Unsafe or unsupported theme input was accepted.');
    }
    $definition = UifThemeKit::starter(); $definition['sections'] = [['type'=>'text','text'=>'<?php echo "unsafe"; ?><img src=x onerror=alert(1)>']];
    $source = $kit->files($definition)['layouts/landing.php'];
    if (str_contains($source,'<img src=x') || !str_contains($source,'&lt;?php')) throw new RuntimeException('Theme text was treated as executable markup.');
    echo "UIF Theme Kit checks passed\n";
} finally { foreach (glob($root . '/*') as $path) unlink($path); rmdir($root); }
