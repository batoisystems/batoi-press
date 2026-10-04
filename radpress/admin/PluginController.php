<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\PluginManager;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\SecretStore;
use Batoi\Press\Application\WebhookTransport;

final class PluginController
{
    public function __construct(private readonly Config $config, private readonly Csrf $csrf, private readonly AuditLog $audit, private readonly array $user) {}

    public function handle(Request $request): Response
    {
        if (AdminAccess::role($this->user) !== 'owner') return Response::html(AdminLayout::message('Plugins', 'Only the installation owner can manage plugins.', true), 403);
        $plugins = new PluginManager($this->config->paths());
        $message = ''; $status = 200;
        if ($request->method === 'POST') {
            if (!$this->csrf->validate($request->input('csrf_token'))) return Response::html(AdminLayout::message('Plugins', 'Security token expired.', true), 400);
            try {
                $action = $request->input('action');
                if (in_array($action, ['enable','disable'], true)) $plugins->toggle($request->input('plugin'), $action === 'enable');
                elseif ($action === 'install') {
                    $upload = $_FILES['package'] ?? [];
                    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) throw new \RuntimeException('Select a valid plugin ZIP.');
                    $plugins->install((string)$upload['tmp_name']);
                } elseif ($action === 'configure') {
                    $input = $request->post['settings'] ?? [];
                    if (!is_array($input)) throw new \RuntimeException('Invalid plugin settings.');
                    $plugins->configure($request->input('plugin'), $input, $request->input('expected_revision'));
                } elseif ($action === 'webhook') $this->saveWebhook($request);
                elseif ($action === 'rollback') $plugins->rollback($request->input('plugin'));
                elseif ($action === 'uninstall') {
                    if ($request->input('confirm') !== $request->input('plugin')) throw new \RuntimeException('Enter the plugin ID to confirm removal. Its data will be retained.');
                    $plugins->uninstall($request->input('plugin'));
                } else throw new \RuntimeException('Unsupported plugin action.');
                $this->audit->record((string)$this->user['username'], 'plugins.' . $action, $request->input('plugin', $request->input('id', 'package')));
                return Response::redirect('/admin/plugins');
            } catch (\RuntimeException $error) { $message = $error->getMessage(); $status = str_contains($message, 'changed') ? 409 : 422; }
        }
        $html = AdminLayout::pageHeader('Plugins', 'Add optional forms and integrations. Only enable PHP packages from developers you trust.');
        if ($message !== '') $html .= '<p class="bp-error" role="alert">' . AdminLayout::e($message) . '</p>';
        if ($plugins->recovery()) $html .= '<p class="bp-notice">Recovery mode is active. Optional plugins are disabled. Remove the private plugins/disabled.lock file through server access after correcting the failing package.</p>';
        $rows = '<div class="bp-table-wrap"><table class="bp-table"><thead><tr><th scope="col">Plugin</th><th scope="col">Access and requirements</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead><tbody>';
        foreach ($plugins->all() as $plugin) {
            $id = AdminLayout::e($plugin['id']);
            $compatibility = '';
            foreach ($plugin['compatibility'] ?? [] as $runtime => $range) $compatibility .= '<p>' . AdminLayout::e(strtoupper($runtime) . ': >= ' . $range['min'] . ' and < ' . $range['max_exclusive']) . '</p>';
            $rows .= '<tr><td><strong>' . AdminLayout::e($plugin['name']) . '</strong><p>' . AdminLayout::e($plugin['description']) . '</p><small>' . $id . ' · ' . AdminLayout::e($plugin['version']) . ' · ' . AdminLayout::e($plugin['kind']) . '</small></td><td>' . AdminLayout::e(implode(', ', $plugin['capabilities'])) . $compatibility . '<p>Missing: ' . AdminLayout::e(implode(', ', $plugin['missing']) ?: 'None') . '</p></td><td><span class="bp-status-label">' . ($plugin['kind'] === 'invalid' ? 'Needs attention' : ($plugin['enabled'] ? 'Enabled' : 'Disabled')) . '</span></td><td><form method="post">' . $this->csrf->field() . '<input type="hidden" name="plugin" value="' . $id . '"><button class="bp-button" type="submit" name="action" value="' . ($plugin['enabled'] || $plugin['kind'] === 'invalid' ? 'disable' : 'enable') . '">' . ($plugin['enabled'] || $plugin['kind'] === 'invalid' ? 'Disable' : 'Enable') . '</button>';
            if ($plugin['kind'] !== 'bundled') $rows .= '<button class="bp-button bp-button-secondary" type="submit" name="action" value="rollback">Restore prior version</button><details><summary>Uninstall package</summary><p>Data is retained. Enter ' . $id . ' to confirm.</p><input name="confirm" aria-label="Confirm plugin ID"><button name="action" value="uninstall">Uninstall</button></details>';
            $rows .= '</form>';
            if (($plugin['settings_schema'] ?? []) !== []) $rows .= '<a class="bp-button bp-button-secondary" href="/admin/plugins?configure=' . $id . '">Configure</a>';
            if ($plugin['enabled'] && in_array('admin.routes',$plugin['capabilities'],true)) $rows .= AdminLayout::buttonLink('Open extension','/admin/extensions/' . $plugin['id'] . '/index','view',true);
            $rows .= '</td></tr>';
        }
        $html .= AdminLayout::section('Installed plugins', $rows . '</tbody></table></div>');
        if ($request->input('configure') !== '') {
            try {
                $id = PluginManager::id($request->input('configure'));
                $plugin = $plugins->all()[$id] ?? null;
                if ($plugin === null || ($plugin['settings_schema'] ?? []) === []) throw new \RuntimeException('This package has no configuration form.');
                $state = $plugins->configuration($id);
                $form = '<form method="post" class="bp-form">' . $this->csrf->field() . '<input type="hidden" name="action" value="configure"><input type="hidden" name="plugin" value="' . AdminLayout::e($id) . '"><input type="hidden" name="expected_revision" value="' . AdminLayout::e($state['revision']) . '"><p>These are non-secret settings. Use an integration connection for credentials. Data schema: ' . (int)$state['schema'] . '.</p>';
                foreach ($plugin['settings_schema'] as $key => $field) {
                    $name = 'settings[' . $key . ']'; $value = $state['values'][$key] ?? $field['default'] ?? '';
                    $form .= '<label>' . AdminLayout::e($field['label']);
                    if ($field['type'] === 'boolean' || $field['type'] === 'enum') {
                        $choices = $field['type'] === 'boolean' ? ['0'=>'No','1'=>'Yes'] : array_combine($field['choices'], $field['choices']);
                        $form .= '<select name="' . $name . '">';
                        foreach ($choices as $option => $label) $form .= '<option value="' . AdminLayout::e((string)$option) . '"' . ((string)$value === (string)$option ? ' selected' : '') . '>' . AdminLayout::e($label) . '</option>';
                        $form .= '</select>';
                    } else {
                        $attributes = $field['type'] === 'integer' ? ' type="number" min="' . (int)($field['min'] ?? -2147483647) . '" max="' . (int)($field['max'] ?? 2147483647) . '"' : ' type="text" maxlength="2000"';
                        $form .= '<input name="' . $name . '"' . $attributes . ' value="' . AdminLayout::e((string)$value) . '"' . (!empty($field['required']) ? ' required' : '') . '>';
                    }
                    $form .= '</label>';
                }
                $html .= AdminLayout::section('Configure ' . $plugin['name'], $form . '<button type="submit">Save settings</button></form>');
            } catch (\RuntimeException $error) { $html .= '<p class="bp-error" role="alert">' . AdminLayout::e($error->getMessage()) . '</p>'; $status = 422; }
        }
        $html .= AdminLayout::section('Install or upgrade', '<form method="post" enctype="multipart/form-data" class="bp-form">' . $this->csrf->field() . '<input type="hidden" name="action" value="install"><label>Signed plugin ZIP<input type="file" name="package" accept=".zip" required></label><p class="bp-field-help">Packages must be signed by a key in the private plugin-keys.json trust store. Installation leaves the plugin disabled for review. Native PHP has application privileges; declarations are not a sandbox.</p><button type="submit">Inspect and install</button></form>');
        $html .= AdminLayout::section('Webhook connection', '<form method="post" class="bp-form">' . $this->csrf->field() . '<input type="hidden" name="action" value="webhook"><label>Connection ID<input name="id" pattern="[a-z][a-z0-9-]{0,63}" required></label><label>HTTPS destination<input type="url" name="url" required></label><label>Signing secret<input type="password" name="secret" minlength="32" autocomplete="new-password"><span class="bp-field-help">At least 32 characters; leave blank to preserve an existing secret. Values are encrypted and never displayed.</span></label><label><input type="checkbox" name="enabled" value="1"> Enable connection</label><button type="submit">Save connection</button></form>');
        return Response::html(AdminLayout::render('Plugins', $html), $status);
    }

    private function saveWebhook(Request $request): void
    {
        if (!(new PluginManager($this->config->paths()))->enabled('webhooks')) throw new \RuntimeException('Enable Webhook delivery first.');
        $id = PluginManager::id($request->input('id'));
        $url = $request->input('url');
        (new WebhookTransport())->destination($url);
        $secret = $request->input('secret');
        if ($secret !== '' && (strlen($secret) < 32 || strlen($secret) > 512)) throw new \RuntimeException('Use a signing secret of 32–512 bytes.');
        $encrypted = $secret !== '' ? (new SecretStore($this->config->paths()))->encrypt($secret) : '';
        (new FileStore())->mutateJson($this->config->paths()->dataPath('plugins/webhooks/connections.json'), static function (array $data) use ($id, $url, $encrypted, $request): array {
            $old = $data['connections'][$id] ?? [];
            $cipher = $encrypted !== '' ? $encrypted : ($old['secret'] ?? '');
            if ($cipher === '') throw new \RuntimeException('A signing secret is required.');
            $data['connections'][$id] = ['url' => $url, 'secret' => $cipher, 'enabled' => $request->input('enabled') === '1'];
            return $data;
        });
    }
}
