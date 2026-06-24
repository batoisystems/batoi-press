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
            AdminLayout::buttonLink('Create User', '/admin/users/new', 'plus')
        );
        $body .= AdminLayout::section('Access governance', $this->accessGovernance(), 'Review user access regularly and keep roles aligned with operational responsibility.');

        if ($users === []) {
            $body .= '<section class="bp-empty-state"><h2>No users configured</h2><p>Create the first administrator account before opening this installation to editors.</p>' . AdminLayout::buttonLink('Create User', '/admin/users/new', 'plus') . '</section>';
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
            'Create a governed admin-console account with the minimum access required.'
        );
        $body .= '<form method="post" action="/admin/users/save" class="bp-form bp-settings-form">';
        $body .= $this->csrf->field();
        $body .= '<div class="bp-admin-editor">';
        $body .= '<div class="bp-editor-main">';
        $body .= '<section class="bp-editor-panel"><header><h2>Account identity</h2><p>Use a durable username and a reachable email address for ownership and recovery records.</p></header><div class="bp-form-grid">';
        $body .= '<label>Username <input type="text" name="username" required minlength="3" maxlength="64" pattern="[A-Za-z0-9._-]{3,64}" autocomplete="username" inputmode="text" placeholder="name or name.surname"><span class="bp-field-help">3-64 characters. Letters, numbers, dots, underscores, and hyphens only.</span></label>';
        $body .= '<label>Email <input type="email" name="email" autocomplete="email" placeholder="person@example.com"><span class="bp-field-help">Used for contact records. Email delivery is not enabled by this form.</span></label>';
        $body .= '</div></section>';
        $body .= '<section class="bp-editor-panel"><header><h2>Access and credentials</h2><p>Assign the least-privileged role and set a temporary password to be shared through a secure channel.</p></header><div class="bp-form-grid">';
        $body .= '<label>Role <select name="role" required><option value="admin">Admin</option><option value="editor">Editor</option><option value="author">Author</option><option value="viewer">Viewer</option></select><span class="bp-field-help">Choose Admin only for trusted operators who manage site settings and governance.</span></label>';
        $body .= '<label>Password <input type="password" name="password" required minlength="10" autocomplete="new-password"><span class="bp-field-help">Use at least 10 characters. Avoid passwords reused on other systems.</span></label>';
        $body .= '</div></section>';
        $body .= '</div>';
        $body .= '<aside class="bp-editor-side">';
        $body .= AdminLayout::section('Role guide', $this->roleGuide(), 'Use the lowest role that can complete the person\'s normal work.');
        $body .= AdminLayout::section('Security notes', $this->securityNotes(), 'What happens when this account is saved.');
        $body .= '</aside></div>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin/users', 'back', true) . AdminLayout::submitButton('Save User', 'save') . '</div></form>';
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

    private function accessGovernance(): string
    {
        return '<div class="bp-admin-guidance-grid">'
            . $this->guidanceCard('Least privilege', 'Create accounts with the narrowest role needed for routine work.', 'shield')
            . $this->guidanceCard('Audit trail', 'User creation is logged and can be reviewed from the Audit Log.', 'check')
            . $this->guidanceCard('Review cycle', 'Periodically remove or downgrade accounts that no longer require access.', 'users')
            . '</div>';
    }

    private function guidanceCard(string $title, string $description, string $icon): string
    {
        return '<article><span>' . AdminLayout::icon($icon) . '</span><div><strong>' . $this->e($title) . '</strong><p>' . $this->e($description) . '</p></div></article>';
    }

    private function roleGuide(): string
    {
        $roles = [
            'Admin' => 'Full console administration, including users, settings, themes, updates, exports, and audit review.',
            'Editor' => 'Content publishing and editorial operations without installation governance.',
            'Author' => 'Drafting and maintaining assigned content with limited publishing authority.',
            'Viewer' => 'Read-only access for review, support, or operational oversight.',
        ];

        $html = '<dl class="bp-user-role-guide">';
        foreach ($roles as $role => $description) {
            $html .= '<div><dt>' . $this->e($role) . '</dt><dd>' . $this->e($description) . '</dd></div>';
        }
        return $html . '</dl>';
    }

    private function securityNotes(): string
    {
        return '<ul class="bp-admin-checklist">'
            . '<li>' . AdminLayout::icon('check') . '<span>Passwords are stored as hashes, never as plain text.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>User creation is written to the audit log.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>The new user can sign in immediately after the account is saved.</span></li>'
            . '<li>' . AdminLayout::icon('check') . '<span>Review access periodically and remove accounts that are no longer needed.</span></li>'
            . '</ul>';
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
