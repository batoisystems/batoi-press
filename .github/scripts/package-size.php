<?php
declare(strict_types=1);
// Size evidence only: signatures and inventory authenticity have separate checks.
function pressPackageMetrics(string $path): array
{
    if (!is_file($path) || !class_exists(ZipArchive::class)) throw new RuntimeException('Provide an existing release ZIP; PHP ZIP is required.');
    $zip = new ZipArchive(); if ($zip->open($path) !== true) throw new RuntimeException('Unable to inspect package.');
    $groups = []; $total = 0; $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i); $name = $stat['name'];
        if (isset($files[$name])) throw new RuntimeException('Duplicate package entry.');
        $files[$name] = true; $total += $stat['size'];
        $parts = explode('/',$name); $group = implode('/',array_slice($parts,0,str_starts_with($name,'public_html/assets/') ? 3 : 2));
        $groups[$group] ??= ['bytes'=>0,'compressed_bytes'=>0,'files'=>0];
        $groups[$group]['bytes'] += $stat['size']; $groups[$group]['compressed_bytes'] += $stat['comp_size']; $groups[$group]['files']++;
        if (preg_match('#^radpress/data/(?:commerce|editor-recovery|integrations|forms|plugins|security|sessions)/#',$name) && !in_array(basename($name),['.gitkeep','.htaccess'],true)) throw new RuntimeException('Package includes private runtime state.');
    }
    $zip->close(); arsort($groups);
    $pass = filesize($path) <= 1572864 && $total <= 6291456;
    return ['zip_bytes'=>filesize($path),'extracted_bytes'=>$total,'sha256'=>hash_file('sha256',$path),'files'=>count($files),'limits'=>['zip_bytes'=>1572864,'extracted_bytes'=>6291456],'pass'=>$pass,'groups'=>$groups];
}
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $metrics = pressPackageMetrics($argv[1] ?? '');
        echo json_encode($metrics,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        exit($metrics['pass'] ? 0 : 1);
    } catch (Throwable $error) { fwrite(STDERR,$error->getMessage() . "\n"); exit(1); }
}
