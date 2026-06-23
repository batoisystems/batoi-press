<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Cache;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;

final class CacheController
{
    public function __construct(
        private readonly Cache $cache,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function index(): Response
    {
        $status = $this->cache->status();
        $files = (int)($status['files'] ?? 0);
        $writable = (bool)($status['writable'] ?? false);
        $path = (string)($status['path'] ?? '');

        $body = AdminLayout::pageHeader(
            'Cache',
            'Review generated runtime cache files and clear them after template, theme, or configuration changes.',
            AdminLayout::buttonLink('Back to Dashboard', '/admin', 'back', true)
        );

        $body .= '<dl class="bp-admin-stats bp-admin-stats-compact">';
        $body .= AdminLayout::statCard('Cache files', (string)$files, $files === 1 ? 'One generated file is stored.' : $files . ' generated files are stored.');
        $body .= AdminLayout::statCard('Directory status', $writable ? 'Writable' : 'Not writable', $writable ? 'Cache can be cleared from the admin console.' : 'Fix server permissions before clearing cache.');
        $body .= AdminLayout::statCard('Storage path', 'radpress/data/cache', $path !== '' ? $path : 'Cache path is unavailable.');
        $body .= '</dl>';

        $action = '<p>Clearing cache removes generated runtime files only. Content, media, users, settings, themes, and audit logs are not deleted.</p>';
        $action .= '<form method="post" action="/admin/cache/clear" class="bp-inline-form">' . $this->csrf->field() . AdminLayout::submitButton('Clear Cache', 'refresh', $writable ? '' : 'disabled aria-disabled="true"') . '</form>';
        if (!$writable) {
            $action .= '<p class="bp-field-help">The cache directory is not writable by PHP. Update server permissions for <code>radpress/data/cache</code>.</p>';
        } elseif ($files === 0) {
            $action .= '<p class="bp-field-help">There are no cache files to remove right now.</p>';
        }
        $body .= AdminLayout::section('Cache maintenance', $action, 'Use this when published output looks stale or after editing templates and theme files.');

        $guidance = '<ul class="bp-check-list"><li>Clear cache after changing theme templates, public header/footer, or shared layout files.</li><li>Clear cache after changing configuration that affects rendered URLs, navigation, or metadata.</li><li>If the directory is not writable, the site can still run, but generated cache files may not refresh from the admin console.</li></ul>';
        $body .= AdminLayout::section('Operational notes', $guidance, 'Cache clearing is a low-risk maintenance action, but server permissions must be healthy.');

        return Response::html($this->layout('Cache', $body));
    }

    public function clear(string $token): Response
    {
        if (!$this->csrf->validate($token)) {
            $this->audit->record((string)($this->user['username'] ?? 'admin'), 'cache.clear_failed', 'csrf', (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'blocked');
            return Response::html($this->layout('Cache', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $removed = $this->cache->clear();
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'cache.cleared', (string)$removed, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/cache');
    }

    private function layout(string $title, string $body): string
    {
        return AdminLayout::render($title, $body);
    }
}
