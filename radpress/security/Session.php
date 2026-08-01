<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

final class Session
{
    public function __construct(
        private readonly string $name = 'batoi_press_session',
        private readonly ?string $savePath = null,
        private readonly int $idleSeconds = 1800,
        private readonly int $absoluteSeconds = 43200
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->enforceLifetime();
            return;
        }

        session_name($this->name);
        if ($this->savePath !== null) {
            if (!is_dir($this->savePath)) {
                mkdir($this->savePath, 0775, true);
            }
            session_save_path($this->savePath);
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->enforceLifetime();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function markAuthenticated(): void
    {
        $this->start();
        $now = time();
        $_SESSION['_bp_created_at'] = $now;
        $_SESSION['_bp_last_seen_at'] = $now;
        unset($_SESSION['_bp_expired_reason']);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $this->start();
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    public function id(): string
    {
        $this->start();
        return session_id();
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }

    private function enforceLifetime(): void
    {
        $now = time();
        $created = (int)($_SESSION['_bp_created_at'] ?? $now);
        $lastSeen = (int)($_SESSION['_bp_last_seen_at'] ?? $now);
        $_SESSION['_bp_created_at'] ??= $created;
        if (isset($_SESSION['auth_user'])) {
            $reason = '';
            if ($this->absoluteSeconds > 0 && $now - $created > $this->absoluteSeconds) {
                $reason = 'absolute';
            } elseif ($this->idleSeconds > 0 && $now - $lastSeen > $this->idleSeconds) {
                $reason = 'idle';
            }
            if ($reason !== '') {
                $_SESSION = ['_bp_created_at' => $now, '_bp_last_seen_at' => $now, '_bp_expired_reason' => $reason];
                session_regenerate_id(true);
                return;
            }
        }
        $_SESSION['_bp_last_seen_at'] = $now;
    }
}
