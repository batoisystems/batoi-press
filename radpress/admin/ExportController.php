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
        $status = $this->exporter->status();
        $ready = (bool)($status['zip_available'] ?? false) && (bool)($status['export_writable'] ?? false) && (bool)($status['tmp_writable'] ?? false);

        $body = AdminLayout::pageHeader(
            'Static Export',
            'Generate a portable ZIP package of published site output for static hosting, backup, or review.',
            AdminLayout::buttonLink('Back to Dashboard', '/admin', 'back', true)
        );

        $body .= '<dl class="bp-admin-stats bp-admin-stats-compact">';
        $body .= AdminLayout::statCard('Published pages', (string)(int)($status['published_pages'] ?? 0), 'Pages included in the static package.');
        $body .= AdminLayout::statCard('Published posts', (string)(int)($status['published_posts'] ?? 0), 'Blog posts included in the static package.');
        $body .= AdminLayout::statCard('ZIP support', (bool)($status['zip_available'] ?? false) ? 'Available' : 'Unavailable', (bool)($status['zip_available'] ?? false) ? 'PHP ZipArchive is enabled.' : 'Enable PHP ZipArchive to generate packages.');
        $body .= AdminLayout::statCard('Export storage', (bool)($status['export_writable'] ?? false) ? 'Writable' : 'Not writable', (string)($status['export_path'] ?? 'radpress/data/export'));
        $body .= '</dl>';

        $action = '<p>The export includes published pages, published posts, the blog listing, uploaded media files, sitemap.xml, and feed.xml. Draft content, admin screens, users, sessions, backups, and audit logs are not included.</p>';
        $action .= '<form method="post" action="/admin/export-static/run" class="bp-inline-form">' . $this->csrf->field() . AdminLayout::submitButton('Generate Export', 'download', $ready ? '' : 'disabled aria-disabled="true"') . '</form>';
        if (!$ready) {
            $action .= '<p class="bp-field-help">Resolve ZIP support or directory permissions before generating an export.</p>';
        }
        $body .= AdminLayout::section('Generate package', $action, 'Create a new timestamped ZIP in the export storage directory.');

        $contents = '<ul class="bp-check-list"><li>Published pages are written as static HTML files.</li><li>Published posts are written under the blog path with a generated blog index.</li><li>Uploaded media files are copied into the package under <code>media/</code>.</li><li>Sitemap and RSS feed files use the configured Base URL from Settings.</li></ul>';
        $body .= AdminLayout::section('Package contents', $contents, 'Use this package for static deployment workflows after reviewing the generated files.');

        $body .= $this->recentExports($status['exports'] ?? []);
        return Response::html($this->layout('Static Export', $body));
    }

    public function run(string $token): Response
    {
        if (!$this->csrf->validate($token)) {
            $this->audit->record((string)($this->user['username'] ?? 'admin'), 'export.failed', 'csrf', (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'blocked');
            return $this->message('Static Export', 'Security token expired.', true, 400);
        }

        try {
            $result = $this->exporter->export();
        } catch (\Throwable $exception) {
            $this->audit->record((string)($this->user['username'] ?? 'admin'), 'export.failed', 'exception', (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'failed');
            return $this->message('Static Export', 'Export failed: ' . $exception->getMessage(), true, 500);
        }
        if (!($result['ok'] ?? false)) {
            return $this->message('Static Export', (string)($result['error'] ?? 'Export failed.'), true, 500);
        }

        $path = (string)$result['path'];
        $name = (string)($result['name'] ?? basename($path));
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'export.static', basename($path), (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        $body = AdminLayout::pageHeader(
            'Export Complete',
            'The static package was generated and is ready to download.',
            AdminLayout::buttonLink('Back to Static Export', '/admin/export-static', 'back', true)
        );
        $body .= '<dl class="bp-admin-stats bp-admin-stats-compact">';
        $body .= AdminLayout::statCard('Package', $name, 'Generated static site ZIP.');
        $body .= AdminLayout::statCard('Size', $this->size((int)($result['size'] ?? 0)), 'Approximate archive size.');
        $body .= AdminLayout::statCard('Storage path', 'radpress/data/export', $path);
        $body .= '</dl>';
        $body .= AdminLayout::section(
            'Package verification',
            $this->verificationSummary((array)($result['verification'] ?? [])),
            'Review this status before handing the static package to a hosting target.'
        );
        $body .= AdminLayout::section(
            'Next step',
            '<p>Download the ZIP and deploy it to your static hosting target after verifying the generated pages.</p><p>' . AdminLayout::buttonLink('Download ZIP', '/admin/export-static/download/' . rawurlencode($name), 'download') . '</p>',
            'The package stays on the server until removed manually from the export directory.'
        );
        return Response::html($this->layout('Export Complete', $body));
    }

    public function download(string $name): Response
    {
        $file = $this->exporter->exportFile($name);
        if ($file === null) {
            $this->audit->record((string)($this->user['username'] ?? 'admin'), 'export.download_failed', $name, (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'failed');
            return $this->message('Static Export', 'Export package not found.', true, 404);
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            $this->audit->record((string)($this->user['username'] ?? 'admin'), 'export.download_failed', $name, (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'failed');
            return $this->message('Static Export', 'Unable to read export package.', true, 500);
        }

        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'export.downloaded', $name, (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'success', [
            'size' => (string)filesize($file),
        ]);
        return new Response($contents, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . basename($file) . '"',
            'Content-Length' => (string)filesize($file),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function layout(string $title, string $body): string
    {
        return AdminLayout::render($title, $body);
    }

    private function recentExports(array $exports): string
    {
        if ($exports === []) {
            return AdminLayout::section('Recent exports', '<p>No static export packages have been generated yet.</p>', 'Generated ZIP files will appear here after export.');
        }

        $html = '<div class="bp-table-wrap"><table class="bp-table"><thead><tr><th>Package</th><th>Size</th><th>Modified</th><th>Action</th></tr></thead><tbody>';
        foreach ($exports as $export) {
            $name = (string)($export['name'] ?? '');
            $html .= '<tr><td><code>' . $this->e($name) . '</code></td><td>' . $this->e($this->size((int)($export['size'] ?? 0))) . '</td><td>' . $this->e($this->date((int)($export['modified'] ?? 0))) . '</td><td>' . AdminLayout::buttonLink('Download', '/admin/export-static/download/' . rawurlencode($name), 'download', true) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        return AdminLayout::section('Recent exports', $html, 'Download the most recent generated packages from this installation.');
    }

    private function verificationSummary(array $verification): string
    {
        $ok = (bool)($verification['ok'] ?? false);
        $html = '<p>' . ($ok ? '<span class="bp-status-badge is-published">Passed</span>' : '<span class="bp-status-badge is-draft">Review needed</span>') . ' ';
        $html .= $this->e((string)($verification['entries'] ?? 0)) . ' archive entries checked against ' . $this->e((string)($verification['expected_entries'] ?? 0)) . ' expected entries.</p>';

        $errors = array_values(array_filter(array_map('strval', (array)($verification['errors'] ?? []))));
        if ($errors !== []) {
            $html .= '<h3>Errors</h3><ul class="bp-check-list">';
            foreach ($errors as $error) {
                $html .= '<li>' . $this->e($error) . '</li>';
            }
            $html .= '</ul>';
        }

        $warnings = array_values(array_filter(array_map('strval', (array)($verification['warnings'] ?? []))));
        if ($warnings !== []) {
            $html .= '<h3>Warnings</h3><ul class="bp-check-list">';
            foreach ($warnings as $warning) {
                $html .= '<li>' . $this->e($warning) . '</li>';
            }
            $html .= '</ul>';
        }

        if ($errors === [] && $warnings === []) {
            $html .= '<p class="bp-field-help">The package contains required static output and no unsafe archive paths were detected.</p>';
        }

        return $html;
    }

    private function message(string $title, string $message, bool $error = false, int $status = 200): Response
    {
        $class = $error ? 'bp-error' : 'bp-notice';
        $body = AdminLayout::pageHeader($title, $error ? 'Review the message below and return to Static Export.' : 'Request completed.');
        $body .= '<p class="' . $class . '">' . $this->e($message) . '</p><p>' . AdminLayout::buttonLink('Back to Static Export', '/admin/export-static', 'back', true) . '</p>';
        return Response::html($this->layout($title, $body), $status);
    }

    private function size(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    private function date(int $timestamp): string
    {
        return $timestamp > 0 ? date('M j, Y H:i', $timestamp) : 'Unknown';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
