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
        $body = '<h1>Media</h1><form method="post" action="/admin/media/upload" enctype="multipart/form-data" class="bp-form">';
        $body .= $this->csrf->field();
        $body .= '<label>Upload File <input type="file" name="media" required></label>';
        $body .= '<button type="submit">Upload</button></form>';
        $body .= '<h2>Files</h2>';
        $files = $this->files();
        if ($files === []) {
            $body .= '<p>No media files uploaded yet.</p>';
        } else {
            $body .= '<table class="bp-table"><thead><tr><th>File</th><th>URL</th><th>HTML</th></tr></thead><tbody>';
            foreach ($files as $file) {
                $name = basename($file);
                $url = '/media/' . rawurlencode($name);
                $snippet = $this->isImage($name) ? '<img src="' . $url . '" alt="">' : '';
                $body .= '<tr><td><code>' . $this->e($name) . '</code></td><td><input class="bp-code-input" readonly value="' . $this->e($url) . '"></td><td><input class="bp-code-input" readonly value="' . $this->e($snippet) . '"></td></tr>';
            }
            $body .= '</tbody></table>';
        }
        $body .= '<p><a href="/admin">Back to admin</a></p>';
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
        $target = $this->config->paths()->contentPath('media/' . $name);
        if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
            return Response::html($this->layout('Media', '<p class="bp-error">Unable to save upload.</p>'), 500);
        }

        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'media.uploaded', $name, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return Response::redirect('/admin/media');
    }

    private function files(): array
    {
        return glob($this->config->paths()->contentPath('media/*')) ?: [];
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
}
