<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\Config;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Update\BackupManager;
use Batoi\Press\Update\RollbackManager;
use Batoi\Press\Update\UpdateRunner;
use Batoi\Press\Update\VersionChecker;

final class UpdateController
{
    public function __construct(
        private readonly Config $config,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    )
    {
    }

    public function index(?array $result = null): Response
    {
        $update = $this->config->update();
        $current = htmlspecialchars((string)($update['current_version'] ?? '0.1.0'));
        $manifest = htmlspecialchars((string)($update['stable_manifest_url'] ?? 'https://batoi.com/pub/press/latest.json'));

        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Updates | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin">';
        $body .= '<h1>Updates</h1>';
        $body .= '<p>Current version: <strong>' . $current . '</strong></p>';
        $body .= '<p>Stable manifest: <code>' . $manifest . '</code></p>';
        if ($result !== null) {
            if ($result['ok'] ?? false) {
                $body .= '<div class="bp-notice"><strong>Latest version:</strong> ' . htmlspecialchars((string)$result['latest_version'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '. ';
                $body .= ($result['update_available'] ?? false) ? 'Update available.' : 'This installation is current.';
                $body .= '</div>';
            } else {
                $body .= '<p class="bp-error">' . htmlspecialchars((string)($result['error'] ?? 'Update check failed.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            }
        }
        $body .= '<p>Updates are installed from verified staged packages. Batoi Press creates a backup before replacing live files.</p>';
        $body .= '<form method="post" action="/admin/updates/check" class="bp-inline-form">' . $this->csrf->field() . '<button type="submit">Check for Updates</button></form>';
        $body .= '<form method="post" action="/admin/updates/backup" class="bp-inline-form">' . $this->csrf->field() . '<button type="submit">Create Backup</button></form>';
        $body .= '<h2>Stage Update Package</h2><form method="post" action="/admin/updates/stage" enctype="multipart/form-data" class="bp-form">' . $this->csrf->field();
        $body .= '<label>Package ZIP <input type="file" name="package" accept=".zip" required></label>';
        $body .= '<label>SHA-256 Checksum (optional) <input type="text" name="sha256"></label>';
        $body .= '<button type="submit">Verify and Stage</button></form>';
        $body .= $this->stageList();
        $body .= $this->backupList();
        $body .= '<form method="post" action="/admin/logout" class="bp-inline-form">' . $this->csrf->field() . '<button type="submit">Log Out</button></form>';
        $body .= '<p><a href="/admin">Back to admin</a></p>';
        $body .= '</main></body></html>';

        return Response::html($body);
    }

    public function check(string $token): Response
    {
        if (!$this->csrf->validate($token)) {
            return Response::html('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Updates | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin"><p class="bp-error">Security token expired.</p><p><a href="/admin/updates">Back</a></p></main></body></html>', 400);
        }

        $update = $this->config->update();
        $current = (string)($update['current_version'] ?? '0.1.0');
        $manifestUrl = (string)($update['stable_manifest_url'] ?? 'https://batoi.com/pub/press/latest.json');
        $result = (new VersionChecker($manifestUrl))->check($current);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), ($result['ok'] ?? false) ? 'update.checked' : 'update.failed', $manifestUrl, (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return $this->index($result);
    }

    public function backup(string $token): Response
    {
        if (!$this->csrf->validate($token)) {
            return $this->message('Updates', 'Security token expired.', true, 400);
        }

        $result = (new BackupManager($this->config->paths()))->create();
        $this->audit->record((string)($this->user['username'] ?? 'admin'), ($result['ok'] ?? false) ? 'update.backup_created' : 'update.backup_failed', (string)($result['path'] ?? $result['error'] ?? ''), (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return $this->message('Backup', ($result['ok'] ?? false) ? 'Backup created: ' . (string)$result['path'] : (string)($result['error'] ?? 'Backup failed.'), !($result['ok'] ?? false), ($result['ok'] ?? false) ? 200 : 500);
    }

    public function stage(string $token, array $files, string $sha256): Response
    {
        if (!$this->csrf->validate($token)) {
            return $this->message('Updates', 'Security token expired.', true, 400);
        }

        $file = $files['package'] ?? [];
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->message('Stage Update', 'Package upload failed.', true, 400);
        }

        $target = $this->config->paths()->dataPath('tmp/update-package-' . date('Ymd-His') . '.zip');
        if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
            return $this->message('Stage Update', 'Unable to save uploaded package.', true, 500);
        }

        $result = (new UpdateRunner($this->config->paths()))->stage($target, trim($sha256));
        $this->audit->record((string)($this->user['username'] ?? 'admin'), ($result['ok'] ?? false) ? 'update.staged' : 'update.failed', (string)($result['stage_dir'] ?? $result['error'] ?? ''), (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return $this->message('Stage Update', ($result['ok'] ?? false) ? 'Package verified and staged: ' . (string)$result['stage_dir'] : (string)($result['error'] ?? 'Staging failed.'), !($result['ok'] ?? false), ($result['ok'] ?? false) ? 200 : 400);
    }

    public function rollback(string $token, string $backup): Response
    {
        if (!$this->csrf->validate($token)) {
            return $this->message('Rollback', 'Security token expired.', true, 400);
        }

        $backup = basename($backup);
        $path = $this->config->paths()->dataPath('backups/' . $backup);
        $result = (new RollbackManager($this->config->paths()))->restore($path);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), ($result['ok'] ?? false) ? 'update.rollback' : 'update.rollback_failed', $backup, (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return $this->message('Rollback', ($result['ok'] ?? false) ? 'Rollback restored from backup.' : (string)($result['error'] ?? 'Rollback failed.'), !($result['ok'] ?? false), ($result['ok'] ?? false) ? 200 : 500);
    }

    public function apply(string $token, string $stage): Response
    {
        if (!$this->csrf->validate($token)) {
            return $this->message('Apply Update', 'Security token expired.', true, 400);
        }

        $stage = basename($stage);
        $path = $this->config->paths()->dataPath('tmp/' . $stage);
        $result = (new UpdateRunner($this->config->paths()))->apply($path);
        $target = (string)($result['version'] ?? $stage);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), ($result['ok'] ?? false) ? 'update.applied' : 'update.apply_failed', $target, (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        if ($result['ok'] ?? false) {
            $message = 'Update applied. Installed files: ' . (string)($result['installed_files'] ?? 0) . '. Cache files cleared: ' . (string)($result['cache_cleared'] ?? 0) . '. Backup: ' . (string)($result['backup'] ?? '');
            return $this->message('Apply Update', $message);
        }

        $message = (string)($result['error'] ?? 'Update apply failed.');
        if (array_key_exists('rolled_back', $result)) {
            $message .= ($result['rolled_back'] ?? false) ? ' Automatic rollback completed.' : ' Automatic rollback failed: ' . (string)($result['rollback_error'] ?? 'unknown error');
        }

        return $this->message('Apply Update', $message, true, 500);
    }

    private function backupList(): string
    {
        $files = glob($this->config->paths()->dataPath('backups/*.zip')) ?: [];
        if ($files === []) {
            return '<h2>Backups</h2><p>No backups created yet.</p>';
        }

        $html = '<h2>Backups</h2><table class="bp-table"><thead><tr><th>File</th><th>Size</th><th></th></tr></thead><tbody>';
        foreach ($files as $file) {
            $name = basename($file);
            $html .= '<tr><td><code>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></td><td>' . number_format((int)filesize($file)) . ' bytes</td><td><form method="post" action="/admin/updates/rollback" class="bp-inline-form">' . $this->csrf->field() . '<input type="hidden" name="backup" value="' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><button type="submit">Restore</button></form></td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function stageList(): string
    {
        $stages = (new UpdateRunner($this->config->paths()))->stagedPackages();
        if ($stages === []) {
            return '<h2>Staged Packages</h2><p>No packages staged yet.</p>';
        }

        $html = '<h2>Staged Packages</h2><table class="bp-table"><thead><tr><th>Directory</th><th></th></tr></thead><tbody>';
        foreach ($stages as $stage) {
            $name = basename($stage);
            $html .= '<tr><td><code>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></td><td><form method="post" action="/admin/updates/apply" class="bp-inline-form">' . $this->csrf->field() . '<input type="hidden" name="stage" value="' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><button type="submit">Apply</button></form></td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function message(string $title, string $message, bool $error = false, int $status = 200): Response
    {
        $class = $error ? 'bp-error' : 'bp-notice';
        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin"><p class="' . $class . '">' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><p><a href="/admin/updates">Back to updates</a></p></main></body></html>';
        return Response::html($body, $status);
    }
}
