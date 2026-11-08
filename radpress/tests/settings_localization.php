<?php
declare(strict_types=1);

use Batoi\Press\Admin\SettingsController;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Session;

require dirname(__DIR__) . '/autoload.php';
require_once dirname(__DIR__) . '/helpers/url.php';

$root = dirname(__DIR__, 2);
$config = Config::load($root);
$files = new FileStore();
$controller = new SettingsController(
    $config,
    $files,
    new Csrf(new Session('batoi_press_settings_localization_test', $config->paths()->dataPath('sessions'))),
    new AuditLog($config->paths(), $files),
    ['username' => 'admin', 'role' => 'owner']
);

$timezoneSelect = new ReflectionMethod($controller, 'timezoneSelect');
$html = (string)$timezoneSelect->invoke($controller, 'Europe/Berlin');
$available = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);

assertSettings(str_contains($html, '<select name="timezone" required>'), 'Timezone should render as a required select.');
assertSettings(substr_count($html, '<option value=') === count($available), 'Timezone select should contain every PHP timezone identifier.');
assertSettings(str_contains($html, '<optgroup label="Europe">'), 'Timezone identifiers should be grouped by region.');
assertSettings(str_contains($html, '<option value="Europe/Berlin" selected>Europe/Berlin</option>'), 'Configured timezone should remain selected.');
assertSettings(str_contains($html, '<option value="America/New_York">America/New York</option>'), 'Timezone values should remain canonical while labels are readable.');

$supported = new ReflectionMethod($controller, 'isSupportedTimezone');
assertSettings($supported->invoke($controller, 'UTC') === true, 'UTC should be accepted.');
assertSettings($supported->invoke($controller, 'Not/A_Timezone') === false, 'Forged timezone values should be rejected.');

$unsupportedHtml = (string)$timezoneSelect->invoke($controller, '<script>alert(1)</script>');
assertSettings(!str_contains($unsupportedHtml, '<script>'), 'Unsupported configured timezone values should be escaped.');
assertSettings(str_contains($unsupportedHtml, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Unsupported configured timezone should remain visible for correction.');

$form = new ReflectionMethod($controller, 'form');
$formHtml = (string)$form->invoke($controller, array_merge($config->site(), ['posts_per_page' => 18]), $config->editor());
assertSettings(str_contains($formHtml, 'name="posts_per_page"') && str_contains($formHtml, 'value="18"'), 'Settings should expose the configured public blog page size.');
assertSettings(str_contains($formHtml, 'name="appearance_mode"') && str_contains($formHtml, 'name="brand_primary_color"'), 'Settings should expose public light, dark, and brand color controls.');
assertSettings(str_contains($formHtml, 'name="mail_provider"') && str_contains($formHtml, 'name="analytics_measurement_id"'), 'Settings should expose mail and analytics integrations.');
$integrationFields = new ReflectionMethod($controller, 'integrationFields');
$integrationHtml = (string)$integrationFields->invoke($controller, ['google_maps_embed'=>true,'recaptcha_custom_theme'=>true,'mail_copy_to_sender'=>true]);
foreach (['google_maps_embed','recaptcha_custom_theme','mail_copy_to_sender'] as $field) {
    assertSettings(str_contains($integrationHtml, 'name="' . $field . '" value="1" checked'), 'Enabled integration declarations should remain checked.');
    assertSettings(str_contains($formHtml, 'name="' . $field . '" value="1">'), 'Absent integration declarations should default off.');
}
assertSettings(str_contains($formHtml, '/admin/import'), 'Settings should expose the XML site-content import workflow.');
foreach (['show_theme_toggle','posts_load_more','footer_top_columns','footer_bottom_columns','footer_bottom_text','footer_icon_links'] as $field) assertSettings(str_contains($formHtml, 'name="' . $field . '"'), 'Missing setting: ' . $field);
foreach (['light','dark'] as $mode) {
    foreach (\Batoi\Press\Core\Appearance::LABELS as $key=>$label) assertSettings(str_contains($formHtml,'name="palette_' . $mode . '_' . $key . '"'), 'Missing palette setting');
}
$safePalette = \Batoi\Press\Core\Appearance::css(['palette_dark'=>['body_bg'=>'</style><script>bad</script>','body_text'=>'#ABCDEF']]);
assertSettings(!str_contains($safePalette, '<') && str_contains($safePalette,'--bp-body-text:#ABCDEF'), 'Palette must validate values before rendering CSS.');
$footerLinks = \Batoi\Press\Core\Appearance::footerLinks("Good | https://example.com | ↗\nBad | javascript:alert(1) | X\nBad | //evil.test | X");
assertSettings(count($footerLinks) === 1, 'Footer links must reject executable and protocol-relative URLs.');

// Date and time display preferences never mutate original timestamps.
require_once dirname(__DIR__) . '/helpers/date.php';
use Batoi\Press\Core\DateFormat;
use Batoi\Press\Core\Request;
use Batoi\Press\Application\ContentRevision;
$displaySite = ['timezone'=>'Asia/Kolkata', 'date_format'=>'d/m/Y', 'time_format'=>'g:i A'];
assertSettings(DateFormat::display('2026-11-05T20:30:00Z', $displaySite, 'datetime') === '06/11/2026 2:00 AM', 'Display must apply the configured timezone across midnight.');
assertSettings(DateFormat::display('@0', ['timezone'=>'UTC']) === 'Jan 1, 1970', 'Unix epoch must remain a valid display date.');
assertSettings(DateFormat::display('invalid-date', $displaySite) === 'invalid-date' && bp_date(null) === '', 'Invalid and absent dates have safe fallbacks.');
assertSettings(DateFormat::display('2026-11-05T18:38:00Z', ['timezone'=>'UTC']) === 'Nov 5, 2026', 'Existing sites retain the old default date format.');
foreach (['', "H:i\n", str_repeat('Y', 81)] as $badFormat) {
    try { DateFormat::validate($badFormat); throw new LogicException('Invalid display format accepted.'); }
    catch (RuntimeException $error) { assertSettings(str_contains($error->getMessage(), 'formats'), 'Invalid format has actionable feedback.'); }
}
try { DateFormat::validate("Y\\"); throw new LogicException('Dangling format escape accepted.'); }
catch (RuntimeException $error) { assertSettings(str_contains($error->getMessage(), 'backslash'), 'Reject PHP formats that would render a NUL byte.'); }
assertSettings(DateFormat::validate("Y\\\\") === "Y\\\\", 'Paired literal backslashes remain supported.');
$fields = new ReflectionMethod($controller, 'dateFormatFields');
$customForm = $fields->invoke($controller, $displaySite + []);
assertSettings(str_contains($customForm, 'name="date_format" value="d/m/Y" checked') && str_contains($customForm, 'name="time_format" value="g:i A" checked'), 'Saved presets must be selected on reload.');
$customForm = $fields->invoke($controller, ['timezone'=>'UTC', 'date_format'=>'D, j M Y', 'time_format'=>'H:i:s']);
assertSettings(substr_count($customForm, 'value="custom" checked') === 2, 'Saved custom formats must reopen as Custom.');
assertSettings(str_contains($customForm, 'data-bp-format-preview="date"') && str_contains($customForm, 'data-bp-format-preview="time"'), 'Both controls include accessible previews.');

$fixture = sys_get_temp_dir() . '/press-date-formats-' . bin2hex(random_bytes(5));
try {
    $files->writeJson($fixture . '/radpress/config/paths.json', ['config'=>'radpress/config','data'=>'radpress/data','content'=>'radpress/content','theme'=>'radpress/theme','public_root'=>'public_html']);
    $files->writeJson($fixture . '/radpress/config/site.json', ['name'=>'Date fixture', 'base_url'=>'https://example.test', 'timezone'=>'UTC', 'locale'=>'en', 'private_option'=>'PRESERVE']);
    $fixtureConfig = Config::load($fixture);
    $fixtureSession = new Session('press_date_fixture', $fixtureConfig->paths()->dataPath('sessions'));
    $fixtureCsrf = new Csrf($fixtureSession);
    $fixtureController = new SettingsController($fixtureConfig, $files, $fixtureCsrf, new AuditLog($fixtureConfig->paths(), $files), ['username'=>'fixture','role'=>'owner']);
    $before = file_get_contents($fixture . '/radpress/config/site.json');
    $previewInput = ['csrf_token'=>$fixtureCsrf->token(), 'timezone'=>'Asia/Kolkata', 'date_format'=>'custom', 'date_format_custom'=>'d/m/Y', 'time_format'=>'g:i A'];
    $preview = $fixtureController->datePreview(new Request('POST', '/admin/settings/date-preview', [], $previewInput, []));
    $result = json_decode($preview->content(), true);
    assertSettings($preview->status() === 200 && $result['ok'] === true && preg_match('~^\d{2}/\d{2}/\d{4}$~', $result['date']), 'Authenticated preview uses the real formatter.');
    assertSettings($result['examples']['time']['g:i A'] === $result['time'], 'Preset examples must use the same timezone and instant as the live preview.');
    assertSettings(file_get_contents($fixture . '/radpress/config/site.json') === $before, 'Preview must not save preferences.');
    $invalid = $previewInput; $invalid['date_format_custom'] = str_repeat('Y',81);
    assertSettings($fixtureController->datePreview(new Request('POST','/admin/settings/date-preview',[],$invalid,[]))->status() === 422, 'Oversized preview formats are rejected.');
    $invalid = $previewInput; $invalid['csrf_token'] = 'invalid';
    assertSettings($fixtureController->datePreview(new Request('POST','/admin/settings/date-preview',[],$invalid,[]))->status() === 400, 'Preview requires CSRF.');
    $saveInput = $previewInput + ['name'=>'Date fixture','base_url'=>'https://example.test','locale'=>'en','expected_revision'=>ContentRevision::for($fixtureConfig->site())];
    $save = $fixtureController->save(new Request('POST', '/admin/settings/save', [], $saveInput, []));
    assertSettings($save->status() === 302, 'Date/time preferences save through normal Settings.');
    $savedConfig = Config::load($fixture);
    assertSettings($savedConfig->site()['date_format'] === 'd/m/Y' && $savedConfig->site()['time_format'] === 'g:i A' && $savedConfig->site()['private_option'] === 'PRESERVE', 'Formats persist while unrelated site data is preserved.');
    $stale = $fixtureController->save(new Request('POST','/admin/settings/save',[],$saveInput,[]));
    assertSettings($stale->status() === 409, 'Date preferences keep revision protection.');
    $fresh = new SettingsController($savedConfig, $files, $fixtureCsrf, new AuditLog($savedConfig->paths(), $files), ['username'=>'fixture','role'=>'owner']);
    $invalid = $saveInput; $invalid['expected_revision'] = ContentRevision::for($savedConfig->site()); $invalid['date_format_custom'] = str_repeat('Y',81);
    $before = file_get_contents($fixture . '/radpress/config/site.json');
    assertSettings($fresh->save(new Request('POST','/admin/settings/save',[],$invalid,[]))->status() === 400 && file_get_contents($fixture . '/radpress/config/site.json') === $before, 'Invalid formats cannot change stored settings.');
    $inputMethod = new ReflectionMethod($fresh, 'submittedFormats');
    assertSettings($inputMethod->invoke($fresh, new Request('POST','/',[],[],[]), $savedConfig->site()) === ['date_format'=>'d/m/Y','time_format'=>'g:i A'], 'Older clients omitting the new fields preserve existing preferences.');
    $files->writeJson($fixture . '/radpress/theme/preview/theme.json', ['slug'=>'preview','name'=>'Preview','version'=>'1.0.0','author'=>'Fixture','assets'=>['styles'=>[],'scripts'=>[]]]);
    $files->write($fixture . '/radpress/theme/preview/layouts/page.php', '<?php echo bp_date("2026-11-05T20:30:00Z") . " / " . bp_time("2026-11-05T20:30:00Z"); ?>');
    $files->write($fixture . '/radpress/theme/preview/layouts/base.php', '<?php echo $content; ?>');
    $theme = new \Batoi\Press\Core\Theme($savedConfig->paths(), array_replace($displaySite, ['theme'=>'preview']));
    $rendered = $theme->render('page')->content();
    assertSettings(str_contains($rendered, '06/11/2026 / 2:00 AM'), 'Public theme helpers use configured formats and timezone.');
    assertSettings(!isset($GLOBALS['bp_date_settings']), 'Theme rendering restores the date context.');
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (is_dir($fixture)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($fixture);
    }
}

echo "Settings localization checks passed\n";

function assertSettings(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
