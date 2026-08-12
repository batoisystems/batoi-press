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

echo "Settings localization checks passed\n";

function assertSettings(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
