<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\UploadGuard;

final class MediaController
{
    public function __construct(
        private readonly Config $config,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function index(): Response
    {
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'type' => trim((string)($_GET['type'] ?? '')),
            'sort' => trim((string)($_GET['sort'] ?? 'newest')),
        ];
        $body = AdminLayout::pageHeader(
            'Media',
            'Upload and reuse site assets for pages, posts, and theme content.'
        );
        $uploadLimit = (int)(($this->config->security()['uploads']['max_bytes'] ?? 5242880) / 1048576);
        $extensions = (array)($this->config->security()['uploads']['allowed_extensions'] ?? []);
        $body .= '<div class="bp-admin-grid"><section class="bp-admin-section"><header><div><h2>Upload asset</h2><p>Supported file types are controlled by the installation security policy.</p></div></header><form method="post" action="/admin/media/upload" enctype="multipart/form-data" class="bp-form bp-compact-form">';
        $body .= $this->csrf->field();
        $body .= '<label>File <input type="file" name="media" required><span class="bp-field-help">Maximum upload size: ' . $this->e((string)$uploadLimit) . ' MB.</span></label>';
        $body .= AdminLayout::submitButton('Upload File', 'upload') . '</form></section>';
        $body .= AdminLayout::section('Upload policy', $this->uploadPolicy($extensions), 'Files are validated before they are stored and exposed under the public media route.');

        $files = $this->files();
        $filteredFiles = $this->filterFiles($files, $filters);
        if ($files === []) {
            $body .= '<section class="bp-empty-state"><h2>No media files</h2><p>Upload images and documents here, then copy their public URL or image HTML into page and post content.</p></section>';
        } elseif ($filteredFiles === []) {
            $body .= $this->filterForm($filters);
            $body .= '<section class="bp-empty-state"><h2>No media files match these filters</h2><p>Adjust the search, type, or sort controls to review other files.</p>' . AdminLayout::buttonLink('Reset Filters', '/admin/media', 'back') . '</section>';
        } else {
            $body .= $this->filterForm($filters);
            $body .= '<section class="bp-admin-section bp-admin-section-wide"><header><div><h2>Files</h2><p>Copy URLs and snippets for use in content HTML.</p></div></header><div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>File</th><th>Type</th><th>Size</th><th>Modified</th><th>URL</th><th>HTML</th></tr></thead><tbody>';
            foreach ($filteredFiles as $file) {
                $name = basename($file);
                $url = '/media/' . rawurlencode($name);
                $snippet = $this->isImage($name) ? '<img src="' . $url . '" alt="">' : '';
                $body .= '<tr><td><strong>' . $this->e($name) . '</strong><small>' . $this->e($this->kind($name)) . '</small></td><td>' . $this->e(strtoupper(pathinfo($name, PATHINFO_EXTENSION) ?: 'FILE')) . '</td><td>' . $this->e($this->size($file)) . '</td><td>' . $this->e($this->modified($file)) . '</td><td><input class="bp-code-input" readonly value="' . $this->e($url) . '"></td><td><input class="bp-code-input" readonly value="' . $this->e($snippet) . '"></td></tr>';
            }
            $body .= '</tbody></table></div></section>';
        }
        $body .= '</div>';
        return Response::html($this->layout('Media', $body));
    }

    public function upload(): Response
    {
        if (!$this->csrf->validate((string)($_POST['csrf_token'] ?? ''))) {
            return Response::html($this->layout('Media', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $uploadConfig = $this->config->security()['uploads'] ?? [];
        $guard = new UploadGuard((array)($uploadConfig['allowed_extensions'] ?? []), (int)($uploadConfig['max_bytes'] ?? 5242880));
        $file = $_FILES['media'] ?? [];
        $error = is_array($file) ? $guard->validate($file) : 'Upload failed.';
        if ($error !== null) {
            return Response::html($this->layout('Media', '<p class="bp-error">' . $this->e($error) . '</p><p><a href="/admin/media">Back to media</a></p>'), 400);
        }

        $name = $guard->safeName((string)$file['name']);
        $mediaDir = $this->config->paths()->contentPath('media');
        if (!$this->ensureMediaDirectory($mediaDir)) {
            return Response::html($this->layout('Media', '<p class="bp-error">Unable to prepare media storage.</p>'), 500);
        }

        $target = $mediaDir . '/' . $name;
        if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
            return Response::html($this->layout('Media', '<p class="bp-error">Unable to save upload.</p>'), 500);
        }

        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'media.uploaded', $name, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/media');
    }

    private function files(): array
    {
        $files = glob($this->config->paths()->contentPath('media/*')) ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        usort($files, static fn (string $a, string $b): int => strcasecmp(basename($a), basename($b)));
        return $files;
    }

    private function filterFiles(array $files, array $filters): array
    {
        $q = strtolower((string)($filters['q'] ?? ''));
        $type = (string)($filters['type'] ?? '');
        $filtered = array_values(array_filter($files, function (string $file) use ($q, $type): bool {
            $name = basename($file);
            if ($type === 'images' && !$this->isImage($name)) {
                return false;
            }
            if ($type === 'documents' && $this->isImage($name)) {
                return false;
            }
            if ($q === '') {
                return true;
            }

            return str_contains(strtolower($name . ' ' . pathinfo($name, PATHINFO_EXTENSION) . ' ' . $this->kind($name)), $q);
        }));

        return $this->sortFiles($filtered, (string)($filters['sort'] ?? 'newest'));
    }

    private function sortFiles(array $files, string $sort): array
    {
        usort($files, static function (string $a, string $b) use ($sort): int {
            if ($sort === 'name') {
                return strcasecmp(basename($a), basename($b));
            }
            if ($sort === 'size') {
                $comparison = ((int)filesize($b)) <=> ((int)filesize($a));
                return $comparison !== 0 ? $comparison : strcasecmp(basename($a), basename($b));
            }

            $comparison = ((int)filemtime($b)) <=> ((int)filemtime($a));
            return $comparison !== 0 ? $comparison : strcasecmp(basename($a), basename($b));
        });

        return $files;
    }

    private function filterForm(array $filters): string
    {
        $type = (string)($filters['type'] ?? '');
        $sort = (string)($filters['sort'] ?? 'newest');
        $html = '<form method="get" action="/admin/media" class="bp-filter-form"><div class="bp-filter-field bp-filter-field-search"><label for="bp-media-filter-q">Search</label><input id="bp-media-filter-q" type="search" name="q" value="' . $this->e((string)($filters['q'] ?? '')) . '" placeholder="Filename or extension"></div>';
        $html .= '<div class="bp-filter-field"><label for="bp-media-filter-type">Type</label><select id="bp-media-filter-type" name="type"><option value="">All files</option>';
        foreach (['images' => 'Images', 'documents' => 'Documents'] as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($type === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        $html .= '</select></div><div class="bp-filter-field"><label for="bp-media-filter-sort">Sort</label><select id="bp-media-filter-sort" name="sort">';
        foreach (['newest' => 'Newest first', 'name' => 'Name A-Z', 'size' => 'Largest first'] as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($sort === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        return $html . '</select></div><div class="bp-filter-actions">' . AdminLayout::submitButton('Apply Filters', 'check') . AdminLayout::buttonLink('Reset', '/admin/media', 'back', true) . '</div></form>';
    }

    private function ensureMediaDirectory(string $dir): bool
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return is_writable($dir);
    }

    private function layout(string $title, string $body): string
    {
        return AdminLayout::render($title, $body);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isImage(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    private function kind(string $name): string
    {
        return $this->isImage($name) ? 'Image asset' : 'Document asset';
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

    private function modified(string $file): string
    {
        $time = is_file($file) ? filemtime($file) : false;
        return $time ? date('M j, Y H:i', $time) : 'Unknown';
    }

    private function uploadPolicy(array $extensions): string
    {
        $allowed = array_values(array_filter(array_map('strval', $extensions)));
        $allowedText = $allowed === [] ? 'Configured by installation policy' : implode(', ', array_map(static fn (string $extension): string => '.' . ltrim($extension, '.'), $allowed));

        return '<ul class="bp-admin-checklist">'
            . '<li>' . AdminLayout::icon('check') . '<span>Allowed extensions: ' . $this->e($allowedText) . '.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Uploaded filenames are normalized before storage.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Files are saved in <code>radpress/content/media</code> and served from <code>/media/{file}</code>.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Every successful upload is recorded in the audit log.</span></li>'
            . '</ul>';
    }
}
