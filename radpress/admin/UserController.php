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
        $users = $this->users();
        $body = AdminLayout::pageHeader(
            'Users',
            'Manage installation users and role assignments.',
            '<a class="bp-button" href="/admin/users/new">Create User</a>'
        );

        if ($users === []) {
            $body .= '<section class="bp-empty-state"><h2>No users configured</h2><p>Create the first administrator account before opening this installation to editors.</p><a class="bp-button" href="/admin/users/new">Create User</a></section>';
            return Response::html($this->layout('Users', $body));
        }

        $body .= '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Created</th><th>Status</th></tr></thead><tbody>';
        foreach ($users as $user) {
            $role = (string)($user['role'] ?? 'viewer');
            $body .= '<tr><td><strong>' . $this->e((string)($user['username'] ?? '')) . '</strong><small>User account</small></td><td>' . $this->e((string)($user['email'] ?? '')) . '</td><td>' . $this->roleBadge($role) . '</td><td>' . $this->formatDate((string)($user['created_at'] ?? '')) . '</td><td><span class="bp-status-badge is-published">Active</span></td></tr>';
        }
        $body .= '</tbody></table></div>';
        return Response::html($this->layout('Users', $body));
    }

    public function create(): Response
    {
        $body = AdminLayout::pageHeader(
            'Create User',
            'Add a governed account with the minimum role required for the person.'
        );
        $body .= '<form method="post" action="/admin/users/save" class="bp-form bp-settings-form">';
        $body .= $this->csrf->field();
        $body .= '<section class="bp-editor-panel"><header><h2>Account</h2><p>Use a unique username and a reachable email address.</p></header><div class="bp-form-grid"><label>Username <input type="text" name="username" required></label><label>Email <input type="email" name="email"></label></div></section>';
        $body .= '<section class="bp-editor-panel"><header><h2>Access</h2><p>Roles prepare the installation for stricter permissions in later phases.</p></header><div class="bp-form-grid"><label>Role <select name="role"><option value="admin">Admin</option><option value="editor">Editor</option><option value="author">Author</option><option value="viewer">Viewer</option></select></label><label>Password <input type="password" name="password" required minlength="10"><span class="bp-field-help">Use at least 10 characters.</span></label></div></section>';
        $body .= '<div class="bp-form-actions"><a class="bp-button bp-button-secondary" href="/admin/users">Cancel</a><button type="submit">Save User</button></div></form>';
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
        return AdminLayout::render($title, $body);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function roleBadge(string $role): string
    {
        $safeRole = in_array($role, ['admin', 'editor', 'author', 'viewer'], true) ? $role : 'viewer';
        return '<span class="bp-role-badge is-' . $safeRole . '">' . $this->e(ucfirst($safeRole)) . '</span>';
    }

    private function formatDate(string $value): string
    {
        if ($value === '') {
            return '<span class="bp-muted">Unknown</span>';
        }

        $timestamp = strtotime($value);
        return $timestamp ? $this->e(date('M j, Y H:i', $timestamp)) : $this->e($value);
    }
}
