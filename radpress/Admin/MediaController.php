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
        $body .= '<h2>Files</h2><ul class="bp-list">';
        foreach ($this->files() as $file) {
            $body .= '<li><code>' . $this->e(basename($file)) . '</code></li>';
        }
        $body .= '</ul><p><a href="/admin">Back to admin</a></p>';
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
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $this->e($title) . ' | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin">' . $body . '</main></body></html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
