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

        return $this->findUser($username);
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function attempt(string $username, string $password): bool
    {
        $user = $this->findUser($username);
        if (!$user || !isset($user['password_hash']) || !is_string($user['password_hash'])) {
            return false;
        }

        if (!Password::verify($password, $user['password_hash'])) {
            return false;
        }

        $this->session->regenerate();
        $this->session->set('auth_user', (string)$user['username']);
        return true;
    }

    public function logout(): void
    {
        $this->session->remove('auth_user');
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
