<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use RuntimeException;

final class MfaRepository
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FileStore $files = new FileStore(),
        private readonly ?SecretStore $secrets = null
    ) {
    }

    public function enabled(array $user): bool
    {
        $mfa = is_array($user['mfa'] ?? null) ? $user['mfa'] : [];
        return ($mfa['enabled'] ?? false) === true && is_string($mfa['secret'] ?? null) && $mfa['secret'] !== '';
    }

    public function findUser(string $username): ?array
    {
        foreach ($this->users() as $user) {
            if (isset($user['username']) && hash_equals((string)$user['username'], $username)) {
                return $user;
            }
        }
        return null;
    }

    public function enable(string $username, string $secret, array $recoveryCodes): void
    {
        $hashes = [];
        foreach ($recoveryCodes as $code) {
            $hashes[] = password_hash($this->normalizeRecovery((string)$code), PASSWORD_DEFAULT);
        }
        $this->mutateUser($username, function (array $user) use ($secret, $hashes): array {
            $user['mfa'] = [
                'enabled' => true,
                'enabled_at' => date(DATE_ATOM),
                'secret' => $this->secretStore()->encrypt($secret),
                'recovery_hashes' => $hashes,
            ];
            return $user;
        });
    }

    public function disable(string $username): void
    {
        $this->mutateUser($username, function (array $user): array {
            unset($user['mfa']);
            $user['mfa_disabled_at'] = date(DATE_ATOM);
            return $user;
        });
    }

    public function verify(string $username, string $code): ?string
    {
        $user = $this->findUser($username);
        if ($user === null || !$this->enabled($user)) {
            return null;
        }
        $mfa = (array)$user['mfa'];
        if (preg_match('/^[0-9]{6}$/D', preg_replace('/\s+/', '', $code) ?? '') === 1) {
            try {
                return Totp::verify($this->secretStore()->decrypt((string)$mfa['secret']), $code) ? 'totp' : null;
            } catch (RuntimeException) {
                return null;
            }
        }

        $normalized = $this->normalizeRecovery($code);
        if (preg_match('/^[A-F0-9]{8}$/D', $normalized) !== 1) {
            return null;
        }
        $consumed = false;
        $this->mutateUser($username, function (array $record) use ($normalized, &$consumed): array {
            if (!$this->enabled($record) || !empty($record['disabled'])) return $record;
            $hashes = array_values((array)($record['mfa']['recovery_hashes'] ?? []));
            foreach ($hashes as $index => $hash) {
                if (!is_string($hash) || !password_verify($normalized, $hash)) continue;
                unset($hashes[$index]);
                $record['mfa']['recovery_hashes'] = array_values($hashes);
                $record['mfa']['recovery_used_at'] = date(DATE_ATOM);
                $consumed = true;
                break;
            }
            return $record;
        });
        return $consumed ? 'recovery' : null;
    }

    private function users(): array
    {
        $path = $this->paths->configPath('users.json');
        if (!is_file($path)) return [];
        $data = $this->files->readJson($path);
        return array_values(array_filter((array)($data['users'] ?? []), 'is_array'));
    }

    private function mutateUser(string $username, callable $callback): void
    {
        $path = $this->paths->configPath('users.json');
        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Unable to lock user security settings.');
        }
        try {
            $users = $this->users();
            $found = false;
            foreach ($users as &$user) {
                if (hash_equals((string)($user['username'] ?? ''), $username)) {
                    $user = $callback($user);
                    $found = true;
                    break;
                }
            }
            unset($user);
            if (!$found) throw new RuntimeException('User not found.');
            $this->files->writeJson($path, ['users' => $users]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function secretStore(): SecretStore
    {
        return $this->secrets ?? new SecretStore($this->paths);
    }

    private function normalizeRecovery(string $code): string
    {
        return strtoupper(preg_replace('/[^A-F0-9]/i', '', trim($code)) ?? '');
    }
}
