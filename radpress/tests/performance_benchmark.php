<?php
declare(strict_types=1);
// Local-only, disposable fixture. No HTTP requests or site writes.
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
require dirname(__DIR__) . '/helpers/esc.php';
require dirname(__DIR__) . '/helpers/date.php';
use Batoi\Press\Core\{AssetResponse, Request, App};
if (($argv[1] ?? '') === '--worker') {
    $mode = $argv[2];
    $path = $argv[3];
    $start = hrtime(true);
    $before = memory_get_usage(true);
    $usedBefore = memory_get_usage();
    $bytes = filesize($path);
    if ($mode === 'buffered') {
        $body = file_get_contents($path);
        echo $body;
    } else {
        $response = AssetResponse::make($path, new Request('GET', '/assets/fixture', [], [], []));
        $response->send();
    }
    fwrite(STDERR, json_encode(['mode'=>$mode,'bytes'=>$bytes,'elapsed_ms'=>(hrtime(true)-$start)/1e6,'peak_delta_bytes'=>memory_get_peak_usage(true)-$before,'peak_used_delta_bytes'=>memory_get_peak_usage()-$usedBefore], JSON_UNESCAPED_SLASHES) . "\n");
    exit;
}
$path = tempnam(sys_get_temp_dir(), 'press-benchmark-');
try {
    $file = fopen($path, 'wb');
    for ($i=0; $i<64; $i++) fwrite($file, str_repeat('x', 1048576));
    fclose($file);
    foreach (['buffered','streamed'] as $mode) {
        for ($run=0; $run<3; $run++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, '--worker', $mode, $path], [1=>['file','/dev/null','w'],2=>['pipe','w']], $pipes);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            echo $error;
            if (proc_close($process) !== 0) throw new RuntimeException($error);
        }
    }
    $app = new App(dirname(__DIR__, 2));
    foreach (['/','/about','/blog'] as $route) {
        $times = [];
        for ($i=0; $i<20; $i++) {
            $start = hrtime(true);
            $response = $app->handle(new Request('GET', $route, [], [], []));
            $times[] = (hrtime(true)-$start)/1e6;
        }
        sort($times);
        echo json_encode(['route'=>$route,'status'=>$response->status(),'html_bytes'=>strlen($response->content()),'median_ms'=>$times[10],'p95_ms'=>$times[18]]) . "\n";
    }
} finally { unlink($path); }
