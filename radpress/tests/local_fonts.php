<?php
declare(strict_types=1);

use Batoi\Press\Core\{LocalFontManager, Paths, FileStore, Config, AuditLog, Request, Theme};
use Batoi\Press\Admin\SettingsController;
use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Security\{Csrf, Session};

require dirname(__DIR__) . '/autoload.php';
require_once dirname(__DIR__) . '/helpers/url.php';
$root = sys_get_temp_dir() . '/press-fonts-' . bin2hex(random_bytes(5));
$files = new FileStore();
function fontCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function fontDenied(callable $action): void { try { $action(); } catch (RuntimeException) { return; } throw new LogicException('Invalid font was accepted.'); }
try {
    $files->writeJson($root . '/radpress/config/paths.json', ['config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data','theme'=>'radpress/theme','public_root'=>'public_html']);
    $config = Config::load($root);
    $paths = $config->paths();
    $manager = new LocalFontManager($paths);
    $store = new ReflectionMethod($manager, 'store');
    // These are bounded structural fixtures, not renderable glyph fonts.
    $ttf = pack('Nnnnn', 0x10000, 1, 16, 0, 0) . pack('a4NNN', 'head', 0, 28, 4) . str_repeat("\0", 4);
    $otf = 'OTTO' . substr($ttf, 4);
    $woff = pack('a4NNnnNnnNNNNN', 'wOFF', 0x10000, 68, 1, 0, 32, 1, 0, 0, 0, 0, 0, 0) . pack('a4NNNN', 'head', 64, 4, 4, 0) . str_repeat("\0", 4);
    $woff2 = pack('a4NNnnNNnnNNNNN', 'wOF2', 0x10000, 52, 1, 0, 32, 2, 1, 0, 0, 0, 0, 0, 0) . "\1\4\0\0";
    $urls = [];
    foreach (['ttf'=>$ttf, 'otf'=>$otf, 'woff'=>$woff, 'woff2'=>$woff2] as $extension=>$data) {
        $path = $root . '/fixture.' . $extension;
        $files->write($path, $data);
        $url = $store->invoke($manager, $path, 'Original name.' . $extension, strlen($data), false);
        $urls[$extension] = $url;
        fontCheck($manager->resolve($url) !== null && file_get_contents($manager->resolve($url)) === $data, 'Font bytes must be stored without conversion.');
        $css = $manager->css(['local_font'=>$url]);
        fontCheck(str_contains($css, '@font-face') && str_contains($css, 'font-display:swap') && str_contains($css, $url), 'Font stylesheet uses the validated local asset.');
    }
    $binary = substr_replace($woff2, pack('N', 55), 8, 4);
    $binary = substr_replace($binary, pack('N', 5), 20, 4);
    $binary = substr($binary, 0, 50) . "<?=\0\0";
    $files->write($root . '/binary.woff2', $binary);
    fontCheck($store->invoke($manager, $root . '/binary.woff2', 'binary.woff2', strlen($binary), false) !== '', 'Compressed binary must not be rejected for coincidental source-code delimiters.');
    fontCheck(count(array_unique($urls)) === 4 && $manager->saveUpload([]) === null, 'Uploads get independent names and omitted uploads preserve state.');
    fontDenied(fn()=> $store->invoke($manager, $root . '/fixture.ttf', 'bad.php', strlen($ttf), false));
    fontDenied(fn()=> $store->invoke($manager, $root . '/fixture.ttf', 'bad.woff2', strlen($ttf), false));
    fontDenied(fn()=> $store->invoke($manager, $root . '/fixture.ttf', 'bad.ttf', strlen($ttf)+1, false));
    fontDenied(fn()=> $manager->saveUpload(['name'=>'fake.ttf','tmp_name'=>$root . '/fixture.ttf','size'=>strlen($ttf),'error'=>UPLOAD_ERR_OK]));
    fontDenied(fn()=> $manager->saveUpload(['error'=>UPLOAD_ERR_INI_SIZE]));
    foreach ([substr($ttf,0,12), substr_replace($ttf, pack('N',99999),20,4), $ttf . '<?php echo "not a font";', str_repeat('x', LocalFontManager::MAX_BYTES+1)] as $bad) {
        $path = $root . '/bad.ttf'; $files->write($path, $bad);
        fontDenied(fn()=> $store->invoke($manager, $path, 'bad.ttf', strlen($bad), false));
    }
    $files->write($root . '/bad.woff', substr_replace($woff, pack('N',69),8,4));
    fontDenied(fn()=> $store->invoke($manager,$root . '/bad.woff','bad.woff',strlen($woff),false));
    $files->write($root . '/bad.woff2', substr_replace($woff2, pack('N',33554433),16,4));
    fontDenied(fn()=> $store->invoke($manager,$root . '/bad.woff2','bad.woff2',strlen($woff2),false));
    symlink($root . '/fixture.ttf', $root . '/linked.ttf');
    fontDenied(fn()=> $store->invoke($manager,$root . '/linked.ttf','linked.ttf',strlen($ttf),false));
    foreach (['https://evil.test/font.woff2', '/assets/fonts/site/../../secret.ttf', '/assets/fonts/site/font-deadbeef.woff2', $urls['ttf'] . '");}</style><script>bad</script>'] as $badUrl) fontCheck($manager->css(['local_font'=>$badUrl]) === '', 'Untrusted font URLs cannot enter CSS.');

    $site = ['name'=>'Font fixture','tagline'=>'Fixture','base_url'=>'https://example.test','locale'=>'en','timezone'=>'UTC','local_font'=>$urls['ttf'],'font_family'=>'Fallback','font_stylesheet_url'=>'https://example.test/fallback.css'];
    $files->writeJson($paths->configPath('site.json'), $site);
    $files->writeJson($paths->themePath('preview/theme.json'), ['name'=>'Preview','slug'=>'preview','version'=>'1.0.0','author'=>'Fixture','assets'=>['styles'=>[],'scripts'=>[]]]);
    $files->write($paths->themePath('preview/layouts/page.php'), '<?php echo htmlspecialchars($site["font_family"], ENT_QUOTES); ?>');
    $files->write($paths->themePath('preview/layouts/base.php'), '<html><head></head><body><?php echo $content; ?></body></html>');
    $rendered = (new Theme($paths, $site + ['theme'=>'preview']))->render('page')->content();
    fontCheck(str_contains($rendered, 'data-bp-local-font') && str_contains($rendered, LocalFontManager::FAMILY) && !str_contains($rendered,'fallback.css'), 'Public rendering registers the local face and applies its site family.');
    $config = Config::load($root);
    $session = new Session('press_fonts', $paths->dataPath('sessions'));
    $csrf = new Csrf($session);
    $controller = new SettingsController($config,$files,$csrf,new AuditLog($paths,$files),['username'=>'fixture','role'=>'owner']);
    $form = $controller->edit()->content();
    fontCheck(str_contains($form,'name="local_font"') && str_contains($form,'name="remove_local_font"'), 'Settings exposes local upload and reversible disable.');
    $input = ['csrf_token'=>$csrf->token(),'expected_revision'=>ContentRevision::for($site),'name'=>'Font fixture','tagline'=>'Fixture','base_url'=>'https://example.test','locale'=>'en','timezone'=>'UTC','remove_local_font'=>'1'];
    $before = file_get_contents($paths->configPath('site.json'));
    $_FILES['local_font'] = ['error'=>UPLOAD_ERR_INI_SIZE];
    fontCheck($controller->save(new Request('POST','/admin/settings/save',[],$input,[]))->status() === 400 && file_get_contents($paths->configPath('site.json')) === $before, 'Failed upload must preserve active font settings.');
    $_FILES = [];
    $input['csrf_token'] = 'invalid';
    fontCheck($controller->save(new Request('POST','/admin/settings/save',[],$input,[]))->status() === 400, 'Font changes require CSRF.');
    $input['csrf_token'] = $csrf->token();
    fontCheck($controller->save(new Request('POST','/admin/settings/save',[],$input,[]))->status() === 302, 'Local font can be disabled through normal Settings.');
    fontCheck(!isset(Config::load($root)->site()['local_font']) && $manager->resolve($urls['ttf']) !== null, 'Disabling retains the previous font file.');
    $manager->discardUpload($urls['otf']);
    fontCheck($manager->resolve($urls['otf']) === null && $manager->resolve($urls['ttf']) !== null, 'Failed-commit cleanup removes only its new upload.');
    echo "Local font checks passed\n";
} finally {
    $_FILES = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($root);
    }
}
