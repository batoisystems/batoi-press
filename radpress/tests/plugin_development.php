<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
$tool = dirname(__DIR__) . '/docs/examples/plugin-tool.php';
$root = sys_get_temp_dir() . '/press-plugin-development-' . bin2hex(random_bytes(6)); mkdir($root,0700);
function runPluginTool(array $args, bool $ok = true): string {
    global $tool;
    $process = proc_open(array_merge([PHP_BINARY,$tool],$args),[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if (($exit === 0) !== $ok) throw new RuntimeException('Unexpected plugin tool result: ' . $out . $err);
    return $out;
}
try {
    runPluginTool(['create','example-notice',$root . '/plugin']);
    $before = file_get_contents($root . '/plugin/entry.php');
    runPluginTool(['create','example-notice',$root . '/plugin'],false);
    if (file_get_contents($root . '/plugin/entry.php') !== $before) throw new RuntimeException('Generator replaced existing source.');
    $keygen = runPluginTool(['keygen',$root . '/private.key']);
    preg_match('/Public key: ([A-Za-z0-9+\/=]+)/',$keygen,$matches); $public = $matches[1] ?? '';
    if ($public === '' || (fileperms($root . '/private.key') & 0777) !== 0600) throw new RuntimeException('Key setup did not preserve private permissions.');
    runPluginTool(['sign',$root . '/plugin',$root . '/private.key','fixture-author']);
    runPluginTool(['validate',$root . '/plugin',$public]);
    runPluginTool(['package',$root . '/plugin',$root . '/plugin.zip',$public]);
    $zip = new ZipArchive(); $zip->open($root . '/plugin.zip');
    if ($zip->numFiles !== 2 || $zip->locateName('plugin.json') === false || $zip->locateName('entry.php') === false) throw new RuntimeException('Package inventory is incorrect.');
    $zip->close();
    file_put_contents($root . '/plugin/entry.php',$before . "\n// altered\n");
    runPluginTool(['validate',$root . '/plugin',$public],false);
    echo "Plugin developer workflow checks passed\n";
} finally { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); rmdir($root); }
