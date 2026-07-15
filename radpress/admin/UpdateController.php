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
use Batoi\Press\Security\UploadGuard;

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
        $current = (string)($update['current_version'] ?? '0.1.0');
        $channel = (string)($update['channel'] ?? 'stable');
        $manifest = (string)($update['stable_manifest_url'] ?? 'https://www.batoi.com/pub/press/latest.json');

        $body = AdminLayout::pageHeader(
            'Updates',
            'Check, stage, back up, and apply Batoi Press releases with rollback protection.',
            '<form method="post" action="/admin/updates/check" class="bp-inline-form">' . $this->csrf->field() . AdminLayout::submitButton('Check for Updates', 'refresh') . '</form>'
        );

        $cards = '<dl class="bp-admin-stats bp-admin-stats-compact">';
        $cards .= AdminLayout::statCard('Current version', $current, 'Release channel: ' . $channel);
        $cards .= AdminLayout::statCard('Stable manifest', 'latest.json', $manifest);
        if ($result !== null) {
            if ($result['ok'] ?? false) {
                $cards .= AdminLayout::statCard('Latest version', (string)$result['latest_version'], ($result['update_available'] ?? false) ? 'Update available.' : 'This installation is current.');
            } else {
                $cards .= AdminLayout::statCard('Latest version', 'Check failed', (string)($result['error'] ?? 'Update check failed.'));
            }
        }
        $cards .= '</dl>';
        $body .= $cards;
        $body .= AdminLayout::section('Update workflow', $this->updateWorkflow(), 'Recommended sequence for release maintenance.');

        $body .= '<div class="bp-admin-grid"><section class="bp-admin-section"><header><div><h2>Create backup</h2><p>Create a minimal update backup before staging or applying a package.</p></div></header><form method="post" action="/admin/updates/backup" class="bp-inline-form">' . $this->csrf->field() . AdminLayout::submitButton('Create Backup', 'download') . '</form></section>';
        $body .= '<section class="bp-admin-section"><header><div><h2>Stage package</h2><p>Upload a release ZIP and verify its archive safety before applying it.</p></div></header><form method="post" action="/admin/updates/stage" enctype="multipart/form-data" class="bp-form bp-compact-form">' . $this->csrf->field();
        $body .= '<label>Package ZIP <input type="file" name="package" accept=".zip" required></label>';
        $body .= '<label>SHA-256 Checksum <input type="text" name="sha256"><span class="bp-field-help">Optional, but recommended when applying a package downloaded outside the built-in release flow.</span></label>';
        $body .= AdminLayout::submitButton('Verify and Stage', 'upload') . '</form></section></div>';
        $body .= $this->stageList();
        $body .= $this->backupList();

        return Response::html(AdminLayout::render('Updates', $body));
    }

    public function check(string $token): Response
    {
        if (!$this->csrf->validate($token)) {
            return Response::html(AdminLayout::render('Updates', '<p class="bp-error">Security token expired.</p><p>' . AdminLayout::buttonLink('Back', '/admin/updates', 'back', true) . '</p>'), 400);
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
        $error = is_array($file) ? (new UploadGuard(['zip'], 50 * 1024 * 1024))->validate($file) : 'Package upload failed.';
        if ($error !== null) {
            return $this->message('Stage Update', $error, true, 400);
        }

        $target = $this->config->paths()->dataPath('tmp/update-package-' . date('Ymd-His') . '.zip');
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            return $this->message('Stage Update', 'Unable to prepare the update staging directory.', true, 500);
        }
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
        if (!$this->isStageName($stage)) {
            return $this->message('Apply Update', 'Select a staged package from the Updates screen before applying.', true, 400);
        }

        $path = $this->config->paths()->dataPath('tmp/' . $stage);
        if (!is_dir($path)) {
            return $this->message('Apply Update', 'The selected staged package is no longer available. Stage the package again.', true, 404);
        }

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

    private function isStageName(string $stage): bool
    {
        return preg_match('/^update-stage-\d{8}-\d{6}$/', $stage) === 1;
    }

    private function backupList(): string
    {
        $files = glob($this->config->paths()->dataPath('backups/*.zip')) ?: [];
        if ($files === []) {
            return AdminLayout::section('Rollback backups', '<p class="bp-muted">No backups created yet.</p>', 'Rollback restore points created before update operations.');
        }

        rsort($files);
        $html = '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>File</th><th>Size</th><th>Modified</th><th>Restore</th></tr></thead><tbody>';
        foreach ($files as $file) {
            $name = basename($file);
            $html .= '<tr><td><code>' . $this->e($name) . '</code></td><td>' . $this->e($this->size($file)) . '</td><td>' . $this->e($this->modified($file)) . '</td><td><form method="post" action="/admin/updates/rollback" class="bp-inline-form bp-danger-action">' . $this->csrf->field() . '<input type="hidden" name="backup" value="' . $this->e($name) . '">' . AdminLayout::submitButton('Restore Backup', 'refresh') . '</form></td></tr>';
        }

        return '<section class="bp-admin-section bp-danger-zone"><header><div><h2>Rollback backups</h2><p>Restore only when an update has failed and you understand this replaces live files.</p></div></header>' . $html . '</tbody></table></div></section>';
    }

    private function updateWorkflow(): string
    {
        return '<div class="bp-workflow-grid">'
            . $this->workflowStep('1', 'Check', 'Compare the installed version with the stable release manifest.', 'complete')
            . $this->workflowStep('2', 'Back up', 'Create a rollback point before staging or applying files.', 'current')
            . $this->workflowStep('3', 'Stage', 'Upload and verify a release ZIP before it can be applied.', 'idle')
            . $this->workflowStep('4', 'Apply', 'Apply only after staging succeeds and rollback expectations are clear.', 'idle')
            . '</div>';
    }

    private function workflowStep(string $number, string $title, string $description, string $state): string
    {
        return '<div class="bp-workflow-step is-' . $this->e($state) . '"><span>' . $this->e($number) . '</span><div><strong>' . $this->e($title) . '</strong><p>' . $this->e($description) . '</p></div></div>';
    }

    private function stageList(): string
    {
        $stages = (new UpdateRunner($this->config->paths()))->stagedPackages();
        if ($stages === []) {
            return AdminLayout::section('Staged packages', '<p class="bp-muted">No packages staged yet.</p>', 'Verified packages waiting for manual apply.');
        }

        $html = '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>Directory</th><th>Staged</th><th>Apply</th></tr></thead><tbody>';
        foreach ($stages as $stage) {
            $name = basename($stage);
            $html .= '<tr><td><code>' . $this->e($name) . '</code></td><td>' . $this->e($this->modified($stage)) . '</td><td><form method="post" action="/admin/updates/apply" class="bp-inline-form">' . $this->csrf->field() . '<input type="hidden" name="stage" value="' . $this->e($name) . '">' . AdminLayout::submitButton('Apply Package', 'check') . '</form></td></tr>';
        }

        return AdminLayout::section('Staged packages', $html . '</tbody></table></div>', 'Applying a package creates a backup, enables maintenance mode, runs health checks, and rolls back on failure.');
    }

    private function message(string $title, string $message, bool $error = false, int $status = 200): Response
    {
        $class = $error ? 'bp-error' : 'bp-notice';
        $body = '<p class="' . $class . '">' . $this->e($message) . '</p><p>' . AdminLayout::buttonLink('Back to updates', '/admin/updates', 'back', true) . '</p>';
        return Response::html(AdminLayout::render($title, $body), $status);
    }

    private function size(string $file): string
    {
        $bytes = is_file($file) ? (int)filesize($file) : 0;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    private function modified(string $path): string
    {
        $time = file_exists($path) ? filemtime($path) : false;
        return $time ? date('M j, Y H:i', $time) : 'Unknown';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
