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
        if ($request->method === 'GET' && $this->auth->pendingMfa() !== null) {
            return Response::redirect('/admin/login/mfa');
        }

        if (!$this->auth->hasUsers()) {
            return Response::html($this->layout(
                'Admin Setup Required',
                '<h1>Admin Setup Required</h1><p>No owner account is configured. Remove <code>radpress/config/installed.lock</code> and open <a href="/install.php">the installer</a> to create the first owner.</p>'
            ), 503);
        }

        $error = '';
        $expired = $this->csrfSessionNotice();
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
                } else {
                    $result = $this->auth->beginAttempt($username, $request->input('password'));
                    if ($result === 'authenticated') {
                        $this->rateLimiter->clear($key);
                        $this->record($username, 'auth.login', 'admin', $request);
                        return Response::redirect('/admin');
                    }
                    if ($result === 'mfa_required') {
                        $this->rateLimiter->clear($key);
                        $this->record($username, 'auth.mfa_required', 'admin', $request, 'success');
                        return Response::redirect('/admin/login/mfa');
                    }
                    $this->rateLimiter->hit($key);
                    $this->record($username, 'auth.login_failed', 'credentials', $request, 'failed');
                    $error = 'Invalid username or password.';
                }
            }
        }

        $html = '<h1>Admin Login</h1>';
        if ($error !== '') {
            $html .= '<p class="bp-error">' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        } elseif ($expired !== '') {
            $html .= '<p class="bp-notice">' . htmlspecialchars($expired, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        $html .= '<form method="post" class="bp-form">';
        $html .= $this->csrf->field();
        $html .= '<label>Username <input type="text" name="username" autocomplete="username" required></label>';
        $html .= '<label>Password <input type="password" name="password" autocomplete="current-password" required></label>';
        $html .= AdminLayout::submitButton('Log In', 'check');
        $html .= '</form>';
        $html .= '<p><a href="/admin/forgot-password">Forgot password?</a></p>';

        return Response::html($this->layout('Admin Login', $html));
    }

    public function mfa(Request $request): Response
    {
        if ($this->auth->check()) return Response::redirect('/admin');
        $user = $this->auth->pendingMfa();
        if ($user === null) return Response::redirect('/admin/login');

        $error = '';
        $username = (string)($user['username'] ?? 'user');
        $key = 'login-mfa:' . $username . ':' . (string)($request->server['REMOTE_ADDR'] ?? 'local');
        if ($request->method === 'POST') {
            if (!$this->csrf->validate($request->input('csrf_token'))) {
                $error = 'Security token expired. Try again.';
                $this->record($username, 'auth.mfa_failed', 'csrf', $request, 'blocked');
            } elseif ($this->rateLimiter->tooManyAttempts($key)) {
                $error = 'Too many verification attempts. Start sign-in again later.';
                $this->record($username, 'auth.mfa_failed', 'rate_limit', $request, 'blocked');
            } else {
                $method = $this->auth->completeMfa($request->input('mfa_code'));
                if ($method !== null) {
                    $this->rateLimiter->clear($key);
                    $this->record($username, 'auth.login', 'admin', $request, 'success', ['mfa' => $method]);
                    return Response::redirect('/admin');
                }
                $this->rateLimiter->hit($key);
                $error = 'Verification code is invalid or expired.';
                $this->record($username, 'auth.mfa_failed', 'code', $request, 'failed');
            }
        }

        $html = '<h1>Two-factor verification</h1>';
        if ($error !== '') $html .= '<p class="bp-error">' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        $html .= '<p>Enter the current six-digit authenticator code or one unused recovery code for <strong>' . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>.</p>';
        $html .= '<form method="post" class="bp-form">' . $this->csrf->field();
        $html .= '<label>Verification code <input type="text" name="mfa_code" required maxlength="12" inputmode="numeric" autocomplete="one-time-code" spellcheck="false"></label>';
        $html .= AdminLayout::submitButton('Verify and Continue', 'shield') . '</form>';
        return Response::html($this->layout('Two-factor verification', $html))->withHeader('Cache-Control', 'private, no-store');
    }

    public function forgotPassword(): Response
    {
        $html = '<h1>Reset your password</h1>';
        $html .= '<p>Batoi Press does not require an email address, so it does not send password-reset links.</p>';
        $html .= '<ol><li>Ask another owner or administrator to open <strong>Users</strong> and reset your password.</li><li>If this is the only owner account, run <code>php radpress/bin/reset-admin-password.php USERNAME</code> from the installation directory. The command prompts for a new password without storing or emailing a reset token.</li></ol>';
        $html .= '<p><a href="/admin/login">Back to login</a></p>';
        return Response::html($this->layout('Reset Password', $html));
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

    private function record(string $user, string $action, string $target, Request $request, string $outcome = 'success', array $details = []): void
    {
        $this->audit?->record($user, $action, $target, (string)($request->server['REMOTE_ADDR'] ?? ''), $outcome, [
            'method' => $request->method,
            'route' => $request->path,
        ] + $details);
    }

    private function csrfSessionNotice(): string
    {
        $reason = $this->csrf->session()->pull('_bp_expired_reason', '');
        return in_array($reason, ['idle', 'absolute'], true) ? 'Your previous session expired. Sign in again to continue.' : '';
    }
}
