<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;

final class Auth
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Session $session,
        private readonly FileStore $files
    ) {
    }

    public function user(): ?array
    {
        $username = $this->session->get('auth_user');
        if (!is_string($username) || $username === '') {
            return null;
        }

        $user = $this->findUser($username);
        if ($user !== null && $this->isDisabled($user)) {
            $this->session->remove('auth_user');
            $this->session->regenerate();
            return null;
        }

        return $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function attempt(string $username, string $password): bool
    {
        return $this->beginAttempt($username, $password) === 'authenticated';
    }

    public function beginAttempt(string $username, string $password): string
    {
        $user = $this->findUser($username);
        if (!$user || !isset($user['password_hash']) || !is_string($user['password_hash'])) {
            return 'invalid';
        }

        if ($this->isDisabled($user)) {
            return 'invalid';
        }

        if (!Password::verify($password, $user['password_hash'])) {
            return 'invalid';
        }

        if ((new MfaRepository($this->paths, $this->files))->enabled($user)) {
            $this->session->regenerate();
            $this->session->set('pending_mfa', ['username' => (string)$user['username'], 'expires_at' => time() + 300, 'attempts' => 0]);
            return 'mfa_required';
        }

        $this->loginUser($user);
        return 'authenticated';
    }

    public function pendingMfa(): ?array
    {
        $pending = $this->session->get('pending_mfa');
        if (!is_array($pending) || (int)($pending['expires_at'] ?? 0) < time() || (int)($pending['attempts'] ?? 0) >= 5) {
            $this->session->remove('pending_mfa');
            return null;
        }
        $user = $this->findUser((string)($pending['username'] ?? ''));
        return $user !== null && !$this->isDisabled($user) ? $user : null;
    }

    public function completeMfa(string $code): ?string
    {
        $user = $this->pendingMfa();
        if ($user === null) return null;
        $method = (new MfaRepository($this->paths, $this->files))->verify((string)$user['username'], $code);
        if ($method === null) {
            $pending = (array)$this->session->get('pending_mfa', []);
            $pending['attempts'] = (int)($pending['attempts'] ?? 0) + 1;
            $this->session->set('pending_mfa', $pending);
            return null;
        }
        $this->session->remove('pending_mfa');
        $this->loginUser($user);
        return $method;
    }

    private function loginUser(array $user): void
    {

        $this->session->regenerate();
        $this->session->set('auth_user', (string)$user['username']);
        $this->session->markAuthenticated();
    }

    public function logout(): void
    {
        $this->session->remove('auth_user');
        $this->session->remove('pending_mfa');
        $this->session->regenerate();
    }

    public function hasUsers(): bool
    {
        return $this->users() !== [];
    }

    private function findUser(string $username): ?array
    {
        foreach ($this->users() as $user) {
            if (isset($user['username']) && hash_equals((string)$user['username'], $username)) {
                return $user;
            }
        }

        return null;
    }

    private function isDisabled(array $user): bool
    {
        return (string)($user['disabled_at'] ?? '') !== '' || (string)($user['status'] ?? '') === 'disabled';
    }

    private function users(): array
    {
        $path = $this->paths->configPath('users.json');
        if (!is_file($path)) {
            return [];
        }

        $config = $this->files->readJson($path);
        return isset($config['users']) && is_array($config['users']) ? $config['users'] : [];
    }
}
