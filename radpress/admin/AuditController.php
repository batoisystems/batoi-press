<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\Config;
use Batoi\Press\Core\Response;

final class AuditController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function index(): Response
    {
        $entries = $this->entries();
        $body = AdminLayout::pageHeader(
            'Audit Log',
            'Review recent governance events recorded by this installation.'
        );

        if ($entries === []) {
            $body .= '<section class="bp-empty-state"><h2>No audit events</h2><p>Administrative actions will appear here after users update content, settings, media, users, or release packages.</p></section>';
            return Response::html(AdminLayout::render('Audit Log', $body));
        }

        $body .= '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Target</th><th>IP</th></tr></thead><tbody>';
        foreach ($entries as $entry) {
            $body .= '<tr><td>' . $this->e($this->formatDate((string)($entry['time'] ?? ''))) . '</td><td><strong>' . $this->e((string)($entry['user'] ?? 'system')) . '</strong><small>Actor</small></td><td><code>' . $this->e((string)($entry['action'] ?? '')) . '</code></td><td>' . $this->e((string)($entry['target'] ?? '')) . '</td><td>' . $this->e((string)($entry['ip'] ?? '')) . '</td></tr>';
        }
        $body .= '</tbody></table></div>';

        return Response::html(AdminLayout::render('Audit Log', $body));
    }

    private function entries(): array
    {
        $path = $this->config->paths()->dataPath('log/audit.jsonl');
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $lines = array_slice(array_reverse($lines), 0, 100);
        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    private function formatDate(string $value): string
    {
        if ($value === '') {
            return 'Unknown';
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('M j, Y H:i', $timestamp) : $value;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
