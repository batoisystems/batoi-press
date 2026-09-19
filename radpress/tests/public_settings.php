<?php
declare(strict_types=1);

use Batoi\Press\Content\PublicSettingsRepository;
use Batoi\Press\Content\MenuConflictException;
use Batoi\Press\Core\Appearance;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;

require dirname(__DIR__) . '/autoload.php';
$root = sys_get_temp_dir() . '/press-public-settings-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
try {
    $paths = new Paths($root, ['config' => 'radpress/config', 'theme' => 'radpress/theme', 'data' => 'radpress/data']);
    $files = new FileStore();
    $manifest = ['schema' => 1, 'slug' => 'default', 'name' => 'Test theme', 'version' => '1.0.0', 'author' => 'Test', 'supports' => []];
    $files->writeJson($paths->themePath('default/theme.json'), $manifest);
    $original = ['name' => 'Original', 'base_url' => 'https://example.test', 'theme' => 'default', 'operator_secret' => 'NEVER_EXPOSE', 'custom_option' => ['keep' => true], 'homepage' => 'home'];
    $files->writeJson($paths->configPath('site.json'), $original);
    $repository = new PublicSettingsRepository($paths);
    $initial = $repository->load();
    checkSettings(!str_contains(json_encode($initial), 'NEVER_EXPOSE') && !isset($initial['settings']['base_url']), 'read projection never exposes raw configuration');
    checkSettings($initial['capabilities']['appearance_tokens'] && $initial['capabilities']['footer_layout'], 'bundled theme reports implemented appearance surfaces');
    $changes = ['name' => 'Reviewed site', 'palette_dark' => ['body_bg' => '#abcdef'], 'footer_bottom_columns' => 3, 'footer_icon_links' => 'Docs | https://example.test/docs | ↗', 'timezone' => 'UTC'];
    $prepared = $repository->prepareSave($changes, $initial['revision']);
    checkSettings($files->readJson($paths->configPath('site.json')) === $original && !str_contains(json_encode($prepared), 'NEVER_EXPOSE'), 'preparation does not write or place secrets in previews');
    checkSettings($prepared['after']['palette_dark']['body_bg'] === '#ABCDEF' && count($prepared['after']['palette_dark']) === 10, 'partial palette changes merge validated shared tokens');
    foreach ([['base_url' => 'https://evil.example'], ['operator_secret' => 'x'], ['theme' => 'other'], ['custom_js' => 'alert(1)'], ['font_stylesheet_url' => 'https://example.test/style.css'], ['palette_light' => ['unknown' => '#123456']], ['palette_dark' => ['body_bg' => 'red;display:none']], ['show_theme_toggle' => 'true'], ['footer_top_columns' => 5], ['timezone' => 'Bad/Timezone'], ['font_family' => 'Arial; color:red'], ['footer_icon_links' => 'Bad | https://user:secret@example.test/ | X'], ['name' => []]] as $invalid) {
        try { $repository->prepareSave($invalid, $initial['revision']); throw new LogicException('Invalid public settings accepted'); }
        catch (InvalidArgumentException $exception) { checkSettings($exception->getMessage() !== '', 'invalid settings have a clear validation error'); }
    }
    $operation = 'proposal_' . bin2hex(random_bytes(16));
    $repository->save($prepared['after'], $initial['revision'], $operation);
    $saved = $files->readJson($paths->configPath('site.json'));
    checkSettings($saved['operator_secret'] === 'NEVER_EXPOSE' && $saved['custom_option'] === ['keep' => true] && $saved['base_url'] === $original['base_url'], 'approved public changes preserve unknown and non-public keys');
    checkSettings(str_contains(Appearance::css($saved), '--bp-body-bg:#ABCDEF'), 'saved palette uses the shared public CSS renderer');
    try { $repository->save(['name' => 'Stale'], $initial['revision'], 'proposal_' . bin2hex(random_bytes(16))); throw new LogicException('Stale settings accepted'); }
    catch (MenuConflictException $exception) { checkSettings($repository->load()['settings']['name'] === 'Reviewed site', 'stale writes do not overwrite settings'); }
    $files->writeJson($paths->themePath('custom/theme.json'), array_replace($manifest, ['slug' => 'custom']));
    $saved['theme'] = 'custom';
    $files->writeJson($paths->configPath('site.json'), $saved);
    $custom = $repository->load();
    checkSettings(!$custom['capabilities']['appearance_tokens'] && !$custom['capabilities']['custom_theme_verified'], 'undeclared custom-theme support is not promised');
    try { $repository->prepareSave(['appearance_mode' => 'dark'], $custom['revision']); throw new LogicException('Unsupported theme accepted'); }
    catch (InvalidArgumentException $exception) { checkSettings(str_contains($exception->getMessage(), 'theme'), 'unsupported appearance changes are rejected'); }
    checkSettings($repository->prepareSave(['tagline' => 'Public text'], $custom['revision'])['after']['tagline'] === 'Public text', 'core public fields remain available for custom themes');
    $files->writeJson($paths->themePath('custom/theme.json'), array_replace($manifest, ['slug' => 'custom', 'supports' => ['appearance_tokens', 'footer_layout']]));
    checkSettings($repository->load()['capabilities']['appearance_tokens'] && !$repository->load()['capabilities']['custom_theme_verified'], 'declared support is distinguished from runtime certification');
    echo "Public settings checks passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    rmdir($root);
}
function checkSettings(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
