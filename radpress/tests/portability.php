<?php
declare(strict_types=1);

use Batoi\Press\Core\Paths;
use Batoi\Press\Security\SecretStore;
use Batoi\Press\Update\VersionChecker;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
$root = sys_get_temp_dir() . '/press-portability-' . bin2hex(random_bytes(5));
try {
    $store = new SecretStore(new Paths($root, ['data'=>'radpress/data']));
    foreach (['api-key-123', '秘密', ''] as $value) {
        $encoded = $store->encrypt($value);
        if ($store->decrypt($encoded) !== $value) throw new RuntimeException('Secret round trip failed.');
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') && !str_starts_with($encoded, 'v2.')) throw new RuntimeException('Expected authenticated OpenSSL fallback.');
        $payload = base64_decode(strtr(substr($encoded,3), '-_', '+/'), true);
        $payload[0] = chr(ord($payload[0]) ^ 1);
        $rejected = false;
        try { $store->decrypt(substr($encoded,0,3) . base64_encode($payload)); } catch (RuntimeException) { $rejected = true; }
        if (!$rejected) throw new RuntimeException('Modified ciphertext was accepted.');
    }
    $manifest = ['version'=>'9.0.0','trust'=>['signature_required'=>true,'signature_algorithm'=>'Ed25519','signed_payload'=>'release-index','key_id'=>'test','signature'=>base64_encode(str_repeat('s',64))]];
    $check = (new VersionChecker('https://example.invalid/latest.json', static fn()=>json_encode($manifest), ['test'=>base64_encode(str_repeat('k',32))], true))->check('2.2.0');
    if ($check['ok'] !== false) throw new RuntimeException('Invalid signature accepted.');
    if (!function_exists('sodium_crypto_sign_verify_detached') && !str_contains($check['error'], 'Enable extension=sodium')) throw new RuntimeException('Missing Sodium must produce actionable guidance.');
    $files = new \Batoi\Press\Core\FileStore();
    $files->writeJson($root . '/radpress/config/paths.json', ['config'=>'radpress/config','content'=>'radpress/content','data'=>'radpress/data','theme'=>'radpress/theme','public_root'=>'public_html']);
    $config = \Batoi\Press\Core\Config::load($root);
    $csrf = new \Batoi\Press\Security\Csrf(new \Batoi\Press\Security\Session('press_portability_test',$config->paths()->dataPath('sessions')));
    $settings = new \Batoi\Press\Admin\SettingsController($config,$files,$csrf,new \Batoi\Press\Core\AuditLog($config->paths(),$files),['username'=>'owner','role'=>'owner']);
    $input = ['csrf_token'=>$csrf->token(),'name'=>'Test','timezone'=>'UTC','mail_provider'=>'mailgun','mailgun_domain'=>'example.com','mailgun_api_key'=>'fake-mail-key','recaptcha_site_key'=>'fake-site-key','recaptcha_secret_key'=>'fake-secret-key','show_theme_toggle'=>'1','posts_load_more'=>'1','palette_dark_body_bg'=>'#123456','footer_top_columns'=>'3'];
    $saved = $settings->save(new \Batoi\Press\Core\Request('POST','/admin/settings/save',[],$input,[]));
    if ($saved->status() !== 302) throw new RuntimeException('Settings failed to save synthetic keys.');
    $reloaded = \Batoi\Press\Core\Config::load($root);
    if ($store->decrypt($reloaded->integrations()['mailgun_api_key']) !== 'fake-mail-key' || $store->decrypt($reloaded->integrations()['recaptcha_secret_key']) !== 'fake-secret-key') throw new RuntimeException('Settings did not persist encrypted keys.');
    if (!$reloaded->site()['show_theme_toggle'] || !$reloaded->site()['posts_load_more'] || $reloaded->site()['palette_dark']['body_bg'] !== '#123456' || $reloaded->site()['footer_top_columns'] !== 3) throw new RuntimeException('Appearance settings did not persist.');
    $updates = new \Batoi\Press\Admin\UpdateController($config, $csrf, new \Batoi\Press\Core\AuditLog($config->paths(), $files), ['username'=>'owner','role'=>'owner']);
    $olderHtml = $updates->index(['ok'=>true, 'latest_version'=>'2.1.1', 'update_available'=>false, 'manifest_behind'=>true])->content();
    if (!str_contains($olderHtml, 'stable manifest is older') || str_contains($olderHtml, 'matches the stable manifest')) throw new RuntimeException('An older manifest must not be presented as an up-to-date installation.');
    $equalHtml = $updates->index(['ok'=>true, 'latest_version'=>'2.3.0', 'update_available'=>false, 'manifest_behind'=>false])->content();
    if (!str_contains($equalHtml, 'matches the stable manifest')) throw new RuntimeException('Equal version status should be explicit.');
    echo "Portability and secret authentication checks passed\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) $entry->isDir() ? rmdir((string)$entry) : unlink((string)$entry);
        rmdir($root);
    }
}
