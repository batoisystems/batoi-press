<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\Config;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Response;

final class AuditController
{
    private readonly AuditLog $audit;

    public function __construct(private readonly Config $config)
    {
        $this->audit = new AuditLog($this->config->paths(), new FileStore());
    }

    public function index(): Response
    {
        $entries = $this->audit->entries(200);
        $summary = $this->audit->summary($entries);
        $body = AdminLayout::pageHeader(
            'Audit Log',
            'Review authenticated admin activity, security-sensitive actions, and operational changes recorded by this installation.'
        );

        $body .= '<dl class="bp-admin-stats bp-admin-stats-compact">';
        $body .= AdminLayout::statCard('Recent events', (string)(int)$summary['events'], 'Showing the latest 200 audit entries.');
        $body .= AdminLayout::statCard('Actors', (string)(int)$summary['actors'], 'Unique users in the current audit view.');
        $body .= AdminLayout::statCard('Non-success outcomes', (string)(int)$summary['failures'], 'Failed, blocked, or invalid attempts.');
        $body .= AdminLayout::statCard('Top action', (string)$summary['top_action'], 'Most frequent action in this view.');
        $body .= '</dl>';

        $body .= AdminLayout::section(
            'Audit coverage',
            '<ul class="bp-check-list"><li>Authenticated admin page views, form actions, and downloads are recorded.</li><li>Semantic changes such as content saves, uploads, settings changes, cache clears, exports, updates, and theme edits are recorded with outcomes.</li><li>Secrets such as passwords and CSRF tokens are excluded from audit details.</li></ul>',
            'Use the log to investigate who did what, when it happened, and which route or object was involved.'
        );

        if ($entries === []) {
            $body .= '<section class="bp-empty-state"><h2>No audit events</h2><p>Administrative actions will appear here after users update content, settings, media, users, or release packages.</p></section>';
            return Response::html(AdminLayout::render('Audit Log', $body));
        }

        $body .= '<section class="bp-admin-section"><header><div><h2>Recent activity</h2><p>Newest events appear first. Route-level events provide full coverage; semantic events provide business context.</p></div></header><div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Outcome</th><th>Target</th><th>Details</th><th>IP</th></tr></thead><tbody>';
        foreach ($entries as $entry) {
            $body .= '<tr><td>' . $this->e($this->formatDate((string)($entry['time'] ?? ''))) . '</td><td><strong>' . $this->e((string)($entry['user'] ?? 'system')) . '</strong><small>Actor</small></td><td><code>' . $this->e((string)($entry['action'] ?? '')) . '</code></td><td>' . $this->outcomeBadge((string)($entry['outcome'] ?? 'success')) . '</td><td>' . $this->e((string)($entry['target'] ?? '')) . '</td><td>' . $this->details((array)($entry['details'] ?? [])) . '</td><td>' . $this->e((string)($entry['ip'] ?? '')) . '</td></tr>';
        }
        $body .= '</tbody></table></div></section>';

        return Response::html(AdminLayout::render('Audit Log', $body));
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

    private function outcomeBadge(string $outcome): string
    {
        $class = in_array($outcome, ['success', 'seen'], true) ? 'is-published' : 'is-draft';
        return '<span class="bp-status-badge ' . $class . '">' . $this->e(ucfirst($outcome)) . '</span>';
    }

    private function details(array $details): string
    {
        if ($details === []) {
            return '<span class="bp-muted">-</span>';
        }

        $items = [];
        foreach ($details as $key => $value) {
            if ($value === '') {
                continue;
            }
            $items[] = '<span><strong>' . $this->e((string)$key) . ':</strong> ' . $this->e((string)$value) . '</span>';
        }

        return $items === [] ? '<span class="bp-muted">-</span>' : '<div class="bp-audit-details">' . implode('', $items) . '</div>';
    }
}
