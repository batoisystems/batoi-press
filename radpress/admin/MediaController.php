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
        $body = AdminLayout::pageHeader(
            'Media',
            'Upload and reuse site assets for pages, posts, and theme content.'
        );
        $uploadLimit = (int)(($this->config->security()['uploads']['max_bytes'] ?? 5242880) / 1048576);
        $body .= '<div class="bp-admin-grid"><section class="bp-admin-section"><header><div><h2>Upload asset</h2><p>Supported file types are controlled by the installation security policy.</p></div></header><form method="post" action="/admin/media/upload" enctype="multipart/form-data" class="bp-form bp-compact-form">';
        $body .= $this->csrf->field();
        $body .= '<label>File <input type="file" name="media" required><span class="bp-field-help">Maximum upload size: ' . $this->e((string)$uploadLimit) . ' MB.</span></label>';
        $body .= AdminLayout::submitButton('Upload File', 'upload') . '</form></section>';

        $files = $this->files();
        if ($files === []) {
            $body .= '<section class="bp-empty-state"><h2>No media files</h2><p>Upload images and documents here, then copy their public URL or image HTML into page and post content.</p></section>';
        } else {
            $body .= '<section class="bp-admin-section bp-admin-section-wide"><header><div><h2>Files</h2><p>Copy URLs and snippets for use in content HTML.</p></div></header><div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>File</th><th>Type</th><th>Size</th><th>Modified</th><th>URL</th><th>HTML</th></tr></thead><tbody>';
            foreach ($files as $file) {
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
        usort($files, static fn (string $a, string $b): int => (int)filemtime($b) <=> (int)filemtime($a));
        return $files;
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
}
