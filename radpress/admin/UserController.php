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
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'role' => trim((string)($_GET['role'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
        ];
        $filteredUsers = $this->filterUsers($users, $filters);
        $body = AdminLayout::pageHeader(
            'Users',
            'Manage installation users and role assignments.',
            AdminLayout::buttonLink('Create User', '/admin/users/new', 'plus')
        );
        $body .= AdminLayout::section('Access governance', $this->accessGovernance(), 'Review user access regularly and keep roles aligned with operational responsibility.');
        $body .= $this->filterForm($filters);

        if ($users === []) {
            $body .= '<section class="bp-empty-state"><h2>No users configured</h2><p>Create the first administrator account before opening this installation to editors.</p>' . AdminLayout::buttonLink('Create User', '/admin/users/new', 'plus') . '</section>';
            return Response::html($this->layout('Users', $body));
        }

        if ($filteredUsers === []) {
            $body .= '<section class="bp-empty-state"><h2>No users match these filters</h2><p>Adjust the search, role, or status filters to review other accounts.</p>' . AdminLayout::buttonLink('Reset Filters', '/admin/users', 'back') . '</section>';
            return Response::html($this->layout('Users', $body));
        }

        $body .= '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Created</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        foreach ($filteredUsers as $user) {
            $role = (string)($user['role'] ?? 'viewer');
            $username = (string)($user['username'] ?? '');
            $body .= '<tr><td><strong>' . $this->e($username) . '</strong><small>User account</small></td><td>' . $this->e((string)($user['email'] ?? '')) . '</td><td>' . $this->roleBadge($role) . '</td><td>' . $this->formatDate((string)($user['created_at'] ?? '')) . '</td><td>' . $this->statusBadge($user) . '</td><td>' . $this->userActions($user) . '</td></tr>';
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
        $body .= '<label>Role ' . $this->roleSelect('role', 'admin') . '<span class="bp-field-help">Choose Owner only for people responsible for installation recovery and governance.</span></label>';
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

        $role = $this->validRole($request->input('role')) ? $request->input('role') : 'viewer';
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

    public function edit(string $username): Response
    {
        $user = $this->findUser($username);
        if ($user === null) {
            return Response::html($this->layout('Users', '<p class="bp-error">User not found.</p>'), 404);
        }

        $body = AdminLayout::pageHeader(
            'Edit User',
            'Update account contact details and role assignment.',
            AdminLayout::buttonLink('Back to Users', '/admin/users', 'back', true)
        );
        $body .= '<form method="post" action="/admin/users/update" class="bp-form bp-settings-form">';
        $body .= $this->csrf->field();
        $body .= '<input type="hidden" name="username" value="' . $this->e((string)$user['username']) . '">';
        $body .= '<div class="bp-admin-editor"><div class="bp-editor-main">';
        $body .= '<section class="bp-editor-panel"><header><h2>Account identity</h2><p>Usernames are stable identifiers. Update contact details and access only.</p></header><div class="bp-form-grid">';
        $body .= '<label>Username <input type="text" value="' . $this->e((string)$user['username']) . '" disabled><span class="bp-field-help">Create a new user if the username must change.</span></label>';
        $body .= '<label>Email <input type="email" name="email" autocomplete="email" value="' . $this->e((string)($user['email'] ?? '')) . '"></label>';
        $body .= '<label>Role ' . $this->roleSelect('role', (string)($user['role'] ?? 'viewer')) . '<span class="bp-field-help">At least one active owner account must remain.</span></label>';
        $body .= '</div></section></div><aside class="bp-editor-side">';
        $body .= AdminLayout::section('Account status', $this->accountStatusPanel($user), 'Use disable/reactivate for account access. Password resets are handled separately.');
        $body .= AdminLayout::section('Role guide', $this->roleGuide(), 'Use the lowest role that can complete the person\'s normal work.');
        $body .= '</aside></div>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin/users', 'back', true) . AdminLayout::submitButton('Save Changes', 'save') . '</div></form>';

        return Response::html($this->layout('Edit User', $body));
    }

    public function update(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Users', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $username = $request->input('username');
        $role = $request->input('role');
        if (!$this->validRole($role)) {
            return Response::html($this->layout('Users', '<p class="bp-error">Invalid role.</p>'), 400);
        }

        $email = $request->input('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::html($this->layout('Users', '<p class="bp-error">Email address is invalid.</p>'), 400);
        }

        $config = ['users' => $this->users()];
        $index = $this->findUserIndex($config['users'], $username);
        if ($index === null) {
            return Response::html($this->layout('Users', '<p class="bp-error">User not found.</p>'), 404);
        }

        $existing = $config['users'][$index];
        if ((string)($existing['role'] ?? '') === 'owner' && $role !== 'owner' && $this->activeOwnerCount($config['users']) <= 1 && !$this->isDisabled($existing)) {
            return Response::html($this->layout('Users', '<p class="bp-error">At least one active owner account must remain.</p>'), 400);
        }

        $config['users'][$index]['email'] = $email;
        $config['users'][$index]['role'] = $role;
        $config['users'][$index]['updated_at'] = date(DATE_ATOM);
        $this->writeUsers($config['users']);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'user.updated', $username, (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'success', ['role' => $role]);

        return Response::redirect('/admin/users');
    }

    public function reset(string $username): Response
    {
        $user = $this->findUser($username);
        if ($user === null) {
            return Response::html($this->layout('Users', '<p class="bp-error">User not found.</p>'), 404);
        }

        $body = AdminLayout::pageHeader(
            'Reset Password',
            'Set a new password for an existing user account.',
            AdminLayout::buttonLink('Back to Users', '/admin/users', 'back', true)
        );
        $body .= '<form method="post" action="/admin/users/reset-password" class="bp-form bp-settings-form">';
        $body .= $this->csrf->field();
        $body .= '<input type="hidden" name="username" value="' . $this->e((string)$user['username']) . '">';
        $body .= '<section class="bp-editor-panel"><header><h2>' . $this->e((string)$user['username']) . '</h2><p>Share the new password through a secure channel. Password values are never written to the audit log.</p></header><div class="bp-form-grid">';
        $body .= '<label>New password <input type="password" name="password" required minlength="10" autocomplete="new-password"><span class="bp-field-help">Use at least 10 characters. Avoid passwords reused on other systems.</span></label>';
        $body .= '</div></section>';
        $body .= '<div class="bp-form-actions">' . AdminLayout::buttonLink('Cancel', '/admin/users', 'back', true) . AdminLayout::submitButton('Reset Password', 'save') . '</div></form>';

        return Response::html($this->layout('Reset Password', $body));
    }

    public function resetPassword(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Users', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $password = $request->input('password');
        if (strlen($password) < 10) {
            return Response::html($this->layout('Users', '<p class="bp-error">Password must be at least 10 characters.</p>'), 400);
        }

        $username = $request->input('username');
        $users = $this->users();
        $index = $this->findUserIndex($users, $username);
        if ($index === null) {
            return Response::html($this->layout('Users', '<p class="bp-error">User not found.</p>'), 404);
        }

        $users[$index]['password_hash'] = Password::hash($password);
        $users[$index]['password_reset_at'] = date(DATE_ATOM);
        $this->writeUsers($users);
        $this->audit->record((string)($this->user['username'] ?? 'admin'), 'user.password_reset', $username, (string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return Response::redirect('/admin/users');
    }

    public function toggle(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Users', '<p class="bp-error">Security token expired.</p>'), 400);
        }

        $username = $request->input('username');
        $action = $request->input('action');
        $users = $this->users();
        $index = $this->findUserIndex($users, $username);
        if ($index === null) {
            return Response::html($this->layout('Users', '<p class="bp-error">User not found.</p>'), 404);
        }

        if ($action === 'disable') {
            if ($username === (string)($this->user['username'] ?? '')) {
                return Response::html($this->layout('Users', '<p class="bp-error">You cannot disable your current account.</p>'), 400);
            }
            if ((string)($users[$index]['role'] ?? '') === 'owner' && $this->activeOwnerCount($users) <= 1) {
                return Response::html($this->layout('Users', '<p class="bp-error">At least one active owner account must remain.</p>'), 400);
            }
            $users[$index]['disabled_at'] = date(DATE_ATOM);
            $users[$index]['disabled_by'] = (string)($this->user['username'] ?? 'admin');
            $this->writeUsers($users);
            $this->audit->record((string)($this->user['username'] ?? 'admin'), 'user.disabled', $username, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
            return Response::redirect('/admin/users');
        }

        if ($action === 'reactivate') {
            unset($users[$index]['disabled_at'], $users[$index]['disabled_by']);
            $users[$index]['reactivated_at'] = date(DATE_ATOM);
            $this->writeUsers($users);
            $this->audit->record((string)($this->user['username'] ?? 'admin'), 'user.reactivated', $username, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
            return Response::redirect('/admin/users');
        }

        return Response::html($this->layout('Users', '<p class="bp-error">Unsupported user action.</p>'), 400);
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

    private function writeUsers(array $users): void
    {
        $this->files->writeJson($this->config->paths()->configPath('users.json'), ['users' => array_values($users)]);
    }

    private function findUser(string $username): ?array
    {
        $users = $this->users();
        $index = $this->findUserIndex($users, $username);
        return $index === null ? null : $users[$index];
    }

    private function findUserIndex(array $users, string $username): ?int
    {
        foreach ($users as $index => $user) {
            if (hash_equals((string)($user['username'] ?? ''), $username)) {
                return $index;
            }
        }

        return null;
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
        $safeRole = $this->validRole($role) ? $role : 'viewer';
        return '<span class="bp-role-badge is-' . $safeRole . '">' . $this->e(ucfirst($safeRole)) . '</span>';
    }

    private function statusBadge(array $user): string
    {
        if ($this->isDisabled($user)) {
            return '<span class="bp-status-badge is-disabled">Disabled</span>';
        }

        return '<span class="bp-status-badge is-published">Active</span>';
    }

    private function userActions(array $user): string
    {
        $username = (string)($user['username'] ?? '');
        $encoded = rawurlencode($username);
        $html = '<div class="bp-table-actions">';
        $html .= '<a href="/admin/users/edit/' . $this->e($encoded) . '">Edit</a>';
        $html .= '<a href="/admin/users/reset/' . $this->e($encoded) . '">Reset password</a>';
        $html .= '<form method="post" action="/admin/users/toggle" class="bp-inline-form">';
        $html .= $this->csrf->field();
        $html .= '<input type="hidden" name="username" value="' . $this->e($username) . '">';
        if ($this->isDisabled($user)) {
            $html .= '<input type="hidden" name="action" value="reactivate"><button type="submit" class="bp-link-button">Reactivate</button>';
        } else {
            $html .= '<input type="hidden" name="action" value="disable"><button type="submit" class="bp-link-button bp-link-danger">Disable</button>';
        }
        return $html . '</form></div>';
    }

    private function roleSelect(string $name, string $selected): string
    {
        $html = '<select name="' . $this->e($name) . '" required>';
        foreach ($this->roles() as $role) {
            $html .= '<option value="' . $this->e($role) . '"' . ($selected === $role ? ' selected' : '') . '>' . $this->e(ucfirst($role)) . '</option>';
        }
        return $html . '</select>';
    }

    private function validRole(string $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    private function roles(): array
    {
        return ['owner', 'admin', 'editor', 'author', 'viewer'];
    }

    private function isDisabled(array $user): bool
    {
        return (string)($user['disabled_at'] ?? '') !== '' || (string)($user['status'] ?? '') === 'disabled';
    }

    private function activeOwnerCount(array $users): int
    {
        $count = 0;
        foreach ($users as $user) {
            if ((string)($user['role'] ?? '') === 'owner' && !$this->isDisabled($user)) {
                $count++;
            }
        }

        return $count;
    }

    private function filterUsers(array $users, array $filters): array
    {
        $q = strtolower((string)($filters['q'] ?? ''));
        $role = (string)($filters['role'] ?? '');
        $status = (string)($filters['status'] ?? '');

        return array_values(array_filter($users, function (array $user) use ($q, $role, $status): bool {
            if ($role !== '' && (string)($user['role'] ?? '') !== $role) {
                return false;
            }
            if ($status === 'active' && $this->isDisabled($user)) {
                return false;
            }
            if ($status === 'disabled' && !$this->isDisabled($user)) {
                return false;
            }
            if ($q === '') {
                return true;
            }

            $haystack = strtolower((string)($user['username'] ?? '') . ' ' . (string)($user['email'] ?? ''));
            return str_contains($haystack, $q);
        }));
    }

    private function filterForm(array $filters): string
    {
        $role = (string)($filters['role'] ?? '');
        $status = (string)($filters['status'] ?? '');
        $html = '<form method="get" action="/admin/users" class="bp-filter-form"><div class="bp-filter-field bp-filter-field-search"><label for="bp-user-filter-q">Search</label><input id="bp-user-filter-q" type="search" name="q" value="' . $this->e((string)($filters['q'] ?? '')) . '" placeholder="Username or email"></div>';
        $html .= '<div class="bp-filter-field"><label for="bp-user-filter-role">Role</label><select id="bp-user-filter-role" name="role"><option value="">All roles</option>';
        foreach ($this->roles() as $option) {
            $html .= '<option value="' . $this->e($option) . '"' . ($role === $option ? ' selected' : '') . '>' . $this->e(ucfirst($option)) . '</option>';
        }
        $html .= '</select></div><div class="bp-filter-field"><label for="bp-user-filter-status">Status</label><select id="bp-user-filter-status" name="status"><option value="">All statuses</option>';
        foreach (['active' => 'Active', 'disabled' => 'Disabled'] as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($status === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        return $html . '</select></div><div class="bp-filter-actions">' . AdminLayout::submitButton('Apply Filters', 'check') . AdminLayout::buttonLink('Reset', '/admin/users', 'back', true) . '</div></form>';
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
            'Owner' => 'Installation recovery and governance ownership. At least one active owner must remain.',
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

    private function accountStatusPanel(array $user): string
    {
        $status = $this->isDisabled($user) ? 'Disabled' : 'Active';
        $html = '<dl class="bp-user-role-guide"><div><dt>Status</dt><dd>' . $this->e($status) . '</dd></div>';
        if ((string)($user['disabled_at'] ?? '') !== '') {
            $html .= '<div><dt>Disabled</dt><dd>' . $this->formatDate((string)$user['disabled_at']) . '</dd></div>';
        }
        if ((string)($user['updated_at'] ?? '') !== '') {
            $html .= '<div><dt>Updated</dt><dd>' . $this->formatDate((string)$user['updated_at']) . '</dd></div>';
        }
        return $html . '</dl>';
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
