<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\Config;
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
        private readonly RateLimiter $rateLimiter
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
                $error = 'Security token expired. Try again.';
            } else {
                $username = $request->input('username');
                $key = 'login:' . $username . ':' . (string)($request->server['REMOTE_ADDR'] ?? 'local');

                if ($this->rateLimiter->tooManyAttempts($key)) {
                    $error = 'Too many login attempts. Try again later.';
                } elseif ($this->auth->attempt($username, $request->input('password'))) {
                    $this->rateLimiter->clear($key);
                    return Response::redirect('/admin');
                } else {
                    $this->rateLimiter->hit($key);
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
        $html .= '<button type="submit">Log In</button>';
        $html .= '</form>';

        return Response::html($this->layout('Admin Login', $html));
    }

    public function logout(Request $request): Response
    {
        if ($request->method === 'POST' && $this->csrf->validate($request->input('csrf_token'))) {
            $this->auth->logout();
        }

        return Response::redirect('/admin/login');
    }

    private function layout(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin">' . $body . '</main></body></html>';
    }
}
