<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\Config;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;

final class AuditController
{
    private readonly AuditLog $audit;

    public function __construct(
        private readonly Config $config,
        private readonly Csrf $csrf,
        private readonly array $user = []
    )
    {
        $this->audit = new AuditLog($this->config->paths(), new FileStore());
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $page = max(1, (int)$request->input('page', '1'));
        $perPage = max(10, min(100, (int)$request->input('per_page', '25')));
        $result = $this->audit->search($filters, $page, $perPage);
        $entries = $result['entries'];
        $summary = $this->audit->summary($this->audit->filtered($filters));
        $facets = $this->audit->facets();
        $body = AdminLayout::pageHeader(
            'Audit Log',
            'Review authenticated admin activity, security-sensitive actions, and operational changes recorded by this installation.'
        );

        $body .= '<dl class="bp-admin-stats bp-admin-stats-compact">';
        $body .= AdminLayout::statCard('Matching events', (string)(int)$result['total'], 'Filtered audit entries.');
        $body .= AdminLayout::statCard('Actors', (string)(int)$summary['actors'], 'Unique users in the current result set.');
        $body .= AdminLayout::statCard('Non-success outcomes', (string)(int)$summary['failures'], 'Failed, blocked, or invalid attempts.');
        $body .= AdminLayout::statCard('Retention minimum', AuditLog::MIN_RETENTION_DAYS . ' days', 'Cleanup cannot remove newer events.');
        $body .= '</dl>';

        $body .= $this->filtersForm($filters, $facets, $perPage);
        $body .= $this->toolsPanel($filters);

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
        $body .= '</tbody></table></div>' . $this->pagination($result, $filters) . '</section>';

        return Response::html(AdminLayout::render('Audit Log', $body));
    }

    public function export(Request $request): Response
    {
        $filters = $this->filters($request);
        $format = $request->input('format', 'csv') === 'jsonl' ? 'jsonl' : 'csv';
        $export = $this->audit->export($filters, $format);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'audit.exported', $format, (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'success', [
            'format' => $format,
            'filters' => json_encode($filters, JSON_UNESCAPED_SLASHES),
        ]);

        return new Response((string)$export['body'], 200, [
            'Content-Type' => (string)$export['type'],
            'Content-Disposition' => 'attachment; filename="' . (string)$export['name'] . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function cleanup(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return $this->message('Audit Log', 'Security token expired.', true, 400);
        }

        $retentionDays = max(0, (int)$request->input('retention_days', (string)AuditLog::MIN_RETENTION_DAYS));
        $result = $this->audit->cleanup($retentionDays);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), ($result['ok'] ?? false) ? 'audit.cleaned' : 'audit.cleanup_failed', (string)$retentionDays . ' days', (string)($_SERVER['REMOTE_ADDR'] ?? ''), ($result['ok'] ?? false) ? 'success' : 'blocked', [
            'removed' => (string)($result['removed'] ?? 0),
            'kept' => (string)($result['kept'] ?? 0),
            'error' => (string)($result['error'] ?? ''),
        ]);

        if (!($result['ok'] ?? false)) {
            return $this->message('Audit Log', (string)($result['error'] ?? 'Cleanup failed.'), true, 400);
        }

        return $this->message('Audit Log', 'Audit cleanup complete. Removed ' . (int)$result['removed'] . ' events older than ' . $retentionDays . ' days.');
    }

    private function filters(Request $request): array
    {
        return [
            'q' => $request->input('q'),
            'actor' => $request->input('actor'),
            'action' => $request->input('action'),
            'outcome' => $request->input('outcome'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ];
    }

    private function filtersForm(array $filters, array $facets, int $perPage): string
    {
        $actionOptions = '<option value="">Any action</option>';
        foreach (($facets['actions'] ?? []) as $action) {
            $selected = $filters['action'] === $action ? ' selected' : '';
            $actionOptions .= '<option value="' . $this->e((string)$action) . '"' . $selected . '>' . $this->e((string)$action) . '</option>';
        }
        $outcomeOptions = '<option value="">Any outcome</option>';
        foreach (($facets['outcomes'] ?? []) as $outcome) {
            $selected = $filters['outcome'] === $outcome ? ' selected' : '';
            $outcomeOptions .= '<option value="' . $this->e((string)$outcome) . '"' . $selected . '>' . $this->e((string)$outcome) . '</option>';
        }

        $perPageOptions = '';
        foreach ([25, 50, 100] as $option) {
            $perPageOptions .= '<option value="' . $option . '"' . ($perPage === $option ? ' selected' : '') . '>' . $option . '</option>';
        }

        $form = '<form method="get" action="/admin/audit" class="bp-form bp-audit-filter-form"><div class="bp-form-grid">';
        $form .= '<label>Search <input type="search" name="q" value="' . $this->e((string)$filters['q']) . '" placeholder="Action, target, IP, detail"></label>';
        $form .= '<label>Actor <input type="search" name="actor" value="' . $this->e((string)$filters['actor']) . '" placeholder="Username"></label>';
        $form .= '<label>Action <select name="action">' . $actionOptions . '</select></label>';
        $form .= '<label>Outcome <select name="outcome">' . $outcomeOptions . '</select></label>';
        $form .= '<label>From <input type="date" name="from" value="' . $this->e((string)$filters['from']) . '"></label>';
        $form .= '<label>To <input type="date" name="to" value="' . $this->e((string)$filters['to']) . '"></label>';
        $form .= '<label>Per page <select name="per_page">' . $perPageOptions . '</select></label>';
        $form .= '</div><div class="bp-form-actions">' . AdminLayout::buttonLink('Reset', '/admin/audit', 'back', true) . AdminLayout::submitButton('Apply Filters', 'check') . '</div></form>';

        return AdminLayout::section('Search and filters', $form, 'Filter by actor, action, outcome, date range, text, or request details.');
    }

    private function toolsPanel(array $filters): string
    {
        $query = $this->query($filters);
        $csv = '/admin/audit/export?format=csv' . ($query !== '' ? '&' . $query : '');
        $jsonl = '/admin/audit/export?format=jsonl' . ($query !== '' ? '&' . $query : '');
        $html = '<div class="bp-audit-tools">';
        $html .= '<div><h3>Export logs</h3><p>Download the current filtered result set for offline review or compliance storage.</p><p>' . AdminLayout::buttonLink('Export CSV', $csv, 'download', true) . AdminLayout::buttonLink('Export JSONL', $jsonl, 'download', true) . '</p></div>';
        $html .= '<div><h3>Cleanup old logs</h3><p>Remove entries older than the retention period. The minimum retention is ' . AuditLog::MIN_RETENTION_DAYS . ' days.</p><form method="post" action="/admin/audit/cleanup" class="bp-inline-form">' . $this->csrf->field() . '<label>Retention days <input type="number" name="retention_days" min="' . AuditLog::MIN_RETENTION_DAYS . '" value="' . AuditLog::MIN_RETENTION_DAYS . '"></label>' . AdminLayout::submitButton('Cleanup Logs', 'refresh', 'class="bp-button bp-button-secondary"') . '</form></div>';
        return AdminLayout::section('Log management', $html . '</div>', 'Export filtered logs or remove old events while preserving the required retention window.');
    }

    private function pagination(array $result, array $filters): string
    {
        $page = (int)$result['page'];
        $pages = (int)$result['pages'];
        $total = (int)$result['total'];
        $perPage = (int)$result['per_page'];
        $start = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $end = min($total, $page * $perPage);
        $base = '/admin/audit?' . $this->query($filters + ['per_page' => (string)$perPage]);
        $prev = $page > 1 ? AdminLayout::buttonLink('Previous', $base . '&page=' . ($page - 1), 'back', true) : '';
        $next = $page < $pages ? AdminLayout::buttonLink('Next', $base . '&page=' . ($page + 1), 'site', true) : '';

        return '<div class="bp-pagination"><span>Showing ' . $start . '-' . $end . ' of ' . $total . ' events. Page ' . $page . ' of ' . $pages . '.</span><div>' . $prev . $next . '</div></div>';
    }

    private function query(array $params): string
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null) {
                $clean[$key] = (string)$value;
            }
        }
        return http_build_query($clean);
    }

    private function message(string $title, string $message, bool $error = false, int $status = 200): Response
    {
        $body = AdminLayout::pageHeader($title, $error ? 'Review the message below and return to the audit log.' : 'Request completed.');
        $body .= '<p class="' . ($error ? 'bp-error' : 'bp-notice') . '">' . $this->e($message) . '</p><p>' . AdminLayout::buttonLink('Back to Audit Log', '/admin/audit', 'back', true) . '</p>';
        return Response::html(AdminLayout::render($title, $body), $status);
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
