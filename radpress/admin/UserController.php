<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\Password;

final class UserController
{
    public function __construct(
        private readonly Config $config,
        private readonly FileStore $files,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function index(): Response
    {
        $body = '<h1>Users</h1><p><a href="/admin/users/new">Create User</a> · <a href="/admin">Admin</a></p><table class="bp-table"><thead><tr><th>Username</th><th>Email</th><th>Role</th></tr></thead><tbody>';
        foreach ($this->users() as $user) {
            $body .= '<tr><td>' . $this->e((string)($user['username'] ?? '')) . '</td><td>' . $this->e((string)($user['email'] ?? '')) . '</td><td>' . $this->e((string)($user['role'] ?? '')) . '</td></tr>';
        }
        $body .= '</tbody></table>';
        return Response::html($this->layout('Users', $body));
    }

    public function create(): Response
    {
        $body = '<h1>Create User</h1><form method="post" action="/admin/users/save" class="bp-form">';
        $body .= $this->csrf->field();
        $body .= '<label>Username <input type="text" name="username" required></label>';
        $body .= '<label>Email <input type="email" name="email"></label>';
        $body .= '<label>Role <select name="role"><option value="admin">Admin</option><option value="editor">Editor</option><option value="author">Author</option><option value="viewer">Viewer</option></select></label>';
        $body .= '<label>Password <input type="password" name="password" required minlength="10"></label>';
        $body .= '<button type="submit">Save User</button></form><p><a href="/admin/users">Back to users</a></p>';
        return Response::html($this->layout('Create User', $body));
    }

    public function save(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Users', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $username = $request->input('username');
        $password = $request->input('password');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $username) || strlen($password) < 10) {
            return Response::html($this->layout('Users', '<p class="bp-error">Invalid username or password length.</p>'), 400);
        }

        $config = ['users' => $this->users()];
        foreach ($config['users'] as $existing) {
            if (($existing['username'] ?? '') === $username) {
                return Response::html($this->layout('Users', '<p class="bp-error">Username already exists.</p>'), 400);
            }
        }

        $role = in_array($request->input('role'), ['admin', 'editor', 'author', 'viewer'], true) ? $request->input('role') : 'viewer';
        $config['users'][] = [
            'username' => $username,
            'email' => $request->input('email'),
            'role' => $role,
            'password_hash' => Password::hash($password),
            'created_at' => date(DATE_ATOM),
        ];
        $this->files->writeJson($this->config->paths()->configPath('users.json'), $config);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'user.created', $username, (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/users');
    }

    private function users(): array
    {
        $path = $this->config->paths()->configPath('users.json');
        if (!is_file($path)) {
            return [];
        }
        $config = $this->files->readJson($path);
        return isset($config['users']) && is_array($config['users']) ? $config['users'] : [];
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
