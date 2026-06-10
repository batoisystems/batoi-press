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
        $body = '<h1>Cache</h1><dl class="bp-stats">';
        $body .= '<div><dt>Cache files</dt><dd>' . (int)$status['files'] . '</dd></div>';
        $body .= '<div><dt>Writable</dt><dd>' . ((bool)$status['writable'] ? 'Yes' : 'No') . '</dd></div>';
        $body .= '</dl><form method="post" action="/admin/cache/clear" class="bp-inline-form">' . $this->csrf->field() . '<button type="submit">Clear Cache</button></form><p><a href="/admin">Back to admin</a></p>';
        return Response::html($this->layout('Cache', $body));
    }

    public function clear(string $token): Response
    {
        if (!$this->csrf->validate($token)) {
            return Response::html($this->layout('Cache', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $removed = $this->cache->clear();
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'cache.cleared', (string)$removed, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/cache');
    }

    private function layout(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin">' . $body . '</main></body></html>';
    }
}

