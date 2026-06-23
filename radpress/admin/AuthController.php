<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\Config;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Auth;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\RateLimiter;

final class AuthController
{
    public function __construct(
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly RateLimiter $rateLimiter,
        private readonly ?AuditLog $audit = null
    ) {
    }

    public function login(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/admin');
        }

        if (!$this->auth->hasUsers()) {
            return Response::html($this->layout(
                'Admin Setup Required',
                '<h1>Admin Setup Required</h1><p>No owner account is configured. Remove <code>radpress/config/installed.lock</code> and open <a href="/install.php">the installer</a> to create the first owner.</p>'
            ), 503);
        }

        $error = '';
        if ($request->method === 'POST') {
            if (!$this->csrf->validate($request->input('csrf_token'))) {
                $this->record('system', 'auth.login_failed', 'csrf', $request, 'blocked');
                $error = 'Security token expired. Try again.';
            } else {
                $username = $request->input('username');
                $key = 'login:' . $username . ':' . (string)($request->server['REMOTE_ADDR'] ?? 'local');

                if ($this->rateLimiter->tooManyAttempts($key)) {
                    $this->record($username, 'auth.login_failed', 'rate_limit', $request, 'blocked');
                    $error = 'Too many login attempts. Try again later.';
                } elseif ($this->auth->attempt($username, $request->input('password'))) {
                    $this->rateLimiter->clear($key);
                    $this->record($username, 'auth.login', 'admin', $request);
                    return Response::redirect('/admin');
                } else {
                    $this->rateLimiter->hit($key);
                    $this->record($username, 'auth.login_failed', 'credentials', $request, 'failed');
                    $error = 'Invalid username or password.';
                }
            }
        }

        $html = '<h1>Admin Login</h1>';
        if ($error !== '') {
            $html .= '<p class="bp-error">' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        $html .= '<form method="post" class="bp-form">';
        $html .= $this->csrf->field();
        $html .= '<label>Username <input type="text" name="username" autocomplete="username" required></label>';
        $html .= '<label>Password <input type="password" name="password" autocomplete="current-password" required></label>';
        $html .= AdminLayout::submitButton('Log In', 'check');
        $html .= '</form>';

        return Response::html($this->layout('Admin Login', $html));
    }

    public function logout(Request $request): Response
    {
        if ($request->method === 'POST' && $this->csrf->validate($request->input('csrf_token'))) {
            $user = $this->auth->user();
            $this->auth->logout();
            $this->record((string)($user['username'] ?? 'admin'), 'auth.logout', 'admin', $request);
        } elseif ($request->method === 'POST') {
            $this->record('system', 'auth.logout_failed', 'csrf', $request, 'blocked');
        }

        return Response::redirect('/admin/login');
    }

    private function layout(string $title, string $body): string
    {
        return AdminLayout::render($title, $body);
    }

    private function record(string $user, string $action, string $target, Request $request, string $outcome = 'success'): void
    {
        $this->audit?->record($user, $action, $target, (string)($request->server['REMOTE_ADDR'] ?? ''), $outcome, [
            'method' => $request->method,
            'route' => $request->path,
        ]);
    }
}
