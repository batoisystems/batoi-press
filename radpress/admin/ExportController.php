<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Response;
use Batoi\Press\Core\StaticExporter;
use Batoi\Press\Security\Csrf;

final class ExportController
{
    public function __construct(
        private readonly StaticExporter $exporter,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function index(): Response
    {
        $body = '<h1>Static Export</h1><p>Generate a static ZIP of published pages, posts, sitemap, and feed.</p>';
        $body .= '<form method="post" action="/admin/export-static/run" class="bp-inline-form">' . $this->csrf->field() . '<button type="submit">Generate Export</button></form><p><a href="/admin">Back to admin</a></p>';
        return Response::html($this->layout('Static Export', $body));
    }

    public function run(string $token): Response
    {
        if (!$this->csrf->validate($token)) {
            return Response::html($this->layout('Static Export', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $result = $this->exporter->export();
        if (!($result['ok'] ?? false)) {
            return Response::html($this->layout('Static Export', '<p class="bp-error">' . htmlspecialchars((string)($result['error'] ?? 'Export failed.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><p><a href="/admin/export-static">Back</a></p>'), 500);
        }

        $path = (string)$result['path'];
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'export.static', basename($path), (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::html($this->layout('Static Export', '<h1>Export Complete</h1><p>Created <code>' . htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>.</p><p><a href="/admin/export-static">Back</a></p>'));
    }

    private function layout(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin">' . $body . '</main></body></html>';
    }
}

