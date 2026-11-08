<?php
declare(strict_types=1);

// Developer tool only. Never expose this file through the public web root.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/autoload.php';
use Batoi\Press\Core\{FileStore, Paths, PluginManager};

try {
    $command = $argv[1] ?? 'help';
    if ($command === 'create') {
        $id = PluginManager::id($argv[2] ?? ''); $dir = $argv[3] ?? '';
        if ($dir === '' || file_exists($dir)) throw new RuntimeException('Choose a new plugin directory. Existing content is never replaced.');
        if (!mkdir($dir,0700,true)) throw new RuntimeException('Unable to create plugin directory.');
        $entry = <<<'ENTRY'
<?php
declare(strict_types=1);
use Batoi\Press\Core\PluginContext;
return static function (PluginContext $context): void {
    $label = (string)($context->settings()['label'] ?? 'Welcome');
    $context->registerBlock('notice', static fn(array $settings): string => '<p class="bp-notice">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>');
};
ENTRY;
        file_put_contents($dir . '/entry.php',$entry . "\n");
        $manifest = ['schema'=>1,'id'=>$id,'name'=>ucwords(str_replace('-',' ',$id)),'version'=>'1.0.0','api'=>PluginManager::API,'kind'=>'native','entrypoint'=>'entry.php','compatibility'=>['php'=>['min'=>'8.3.0','max_exclusive'=>'9.0.0'],'press'=>['min'=>'4.0.0','max_exclusive'=>'6.0.0']],'requirements'=>[],'capabilities'=>['blocks.render'],'settings_schema'=>['label'=>['label'=>'Notice text','type'=>'string','required'=>true,'default'=>'Welcome']],'files'=>['entry.php'=>hash_file('sha256',$dir . '/entry.php')]];
        (new FileStore())->writeJson($dir . '/plugin.json',$manifest);
        echo "Starter created. Sign and validate it before installation. Block: {$id}:notice\n";
    } elseif ($command === 'keygen') {
        if (!function_exists('sodium_crypto_sign_keypair')) throw new RuntimeException('Key generation requires Sodium.');
        $path = $argv[2] ?? '';
        if ($path === '' || file_exists($path)) throw new RuntimeException('Choose a new private-key file outside the plugin/website directory.');
        $pair = sodium_crypto_sign_keypair();
        $handle = fopen($path,'x');
        if ($handle === false) throw new RuntimeException('Unable to create private key.');
        chmod($path,0600); fwrite($handle,base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n"); fclose($handle);
        echo 'Public key: ' . base64_encode(sodium_crypto_sign_publickey($pair)) . "\nPrivate key saved with mode 0600; keep it outside packages and source control.\n";
    } elseif (in_array($command,['sign','validate','package'],true)) {
        $dir = $argv[2] ?? ''; $manifestPath = $dir . '/plugin.json';
        if (!is_file($manifestPath) || is_link($dir)) throw new RuntimeException('Select a plugin directory containing plugin.json.');
        $manifest = json_decode((string)file_get_contents($manifestPath),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) throw new RuntimeException('Invalid plugin manifest.');
        if ($command === 'sign') {
            $keyPath = $argv[3] ?? ''; $keyId = $argv[4] ?? '';
            $secret = is_file($keyPath) ? base64_decode(trim((string)file_get_contents($keyPath)),true) : false;
            if (!function_exists('sodium_crypto_sign_detached') || !is_string($secret) || strlen($secret) !== 64 || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$keyId)) throw new RuntimeException('Provide a base64 Ed25519 private-key file and a key ID.');
            $real = realpath($dir); $keyReal = realpath($keyPath);
            if ($real !== false && $keyReal !== false && str_starts_with($keyReal,$real . DIRECTORY_SEPARATOR)) throw new RuntimeException('Private keys must remain outside plugin directories.');
            unset($manifest['trust']); $payload = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isLink()) throw new RuntimeException('Symlinks cannot be packaged.');
                if (!$file->isFile()) continue;
                $relative = str_replace(DIRECTORY_SEPARATOR,'/',substr($file->getPathname(),strlen(rtrim($dir,DIRECTORY_SEPARATOR)) + 1));
                if ($relative === 'plugin.json') continue;
                if (preg_match('/(?:private|secret|\.key$|\.pem$|\.env)/i',$relative)) throw new RuntimeException('Remove private-key/secret files from the package.');
                $payload[$relative] = hash_file('sha256',$file->getPathname());
            }
            ksort($payload); $manifest['files'] = $payload;
            $manifest['trust'] = ['key_id'=>$keyId,'signature'=>base64_encode(sodium_crypto_sign_detached(json_encode($manifest,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),$secret))];
            (new FileStore())->writeJson($manifestPath,$manifest);
            echo 'Signed. Public key: ' . base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)) . "\n";
        } else {
            $public = $argv[$command === 'package' ? 4 : 3] ?? '';
            $decoded = base64_decode($public,true);
            if (!is_string($decoded) || strlen($decoded) !== 32) throw new RuntimeException('Supply the publisher base64 public key for independent verification.');
            $tmp = sys_get_temp_dir() . '/press-plugin-validation-' . bin2hex(random_bytes(8));
            $files = new FileStore(); $files->writeJson($tmp . '/config/plugin-keys.json',['keys'=>[(string)($manifest['trust']['key_id'] ?? '')=>$public]]);
            try {
                $plugin = (new PluginManager(new Paths($tmp,['config'=>'config','data'=>'data','app'=>'app'])))->inspectDirectory($dir);
                foreach (array_keys($plugin['files']) as $file) if (str_ends_with($file,'.php')) token_get_all((string)file_get_contents($dir . '/' . $file),TOKEN_PARSE);
                if ($command === 'package') {
                    if (!class_exists(ZipArchive::class)) throw new RuntimeException('Packaging requires PHP ZIP.');
                    $path = $argv[3] ?? ''; if ($path === '' || file_exists($path)) throw new RuntimeException('Choose a new output ZIP; existing files are never replaced.');
                    $zip = new ZipArchive(); if ($zip->open($path,ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Unable to create package.');
                    $entries = array_merge(['plugin.json'],array_keys($plugin['files'])); sort($entries);
                    foreach ($entries as $entry) { if (!$zip->addFile($dir . '/' . $entry,$entry)) throw new RuntimeException('Unable to package plugin.'); $zip->setMtimeName($entry,946684800); }
                    if (!$zip->close()) throw new RuntimeException('Unable to finish plugin ZIP.');
                    echo 'Package created: ' . $path . "\n";
                } else echo "Signature, inventory and manifest validated without executing plugin code.\n";
            } finally {
                if (is_dir($tmp)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); rmdir($tmp); }
            }
        }
    } else echo "Usage:\n  php plugin-tool.php create ID NEW-DIRECTORY\n  php plugin-tool.php keygen PRIVATE-KEY-FILE\n  php plugin-tool.php sign DIRECTORY PRIVATE-KEY-FILE KEY-ID\n  php plugin-tool.php validate DIRECTORY BASE64-PUBLIC-KEY\n  php plugin-tool.php package DIRECTORY NEW-ZIP BASE64-PUBLIC-KEY\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage() . "\n"); exit(1); }
