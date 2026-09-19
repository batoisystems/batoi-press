<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;

final class AccessTokenRepository
{
    public const SCOPES = [
        'site:read',
        'site:write',
        'content:read',
        'content:write',
        'content:publish',
        'media:read',
        'media:write',
        'audit:read',
    ];

    private const TOKEN_PATTERN = '/^bp2_([a-f0-9]{24})_([A-Za-z0-9_-]{43})$/D';

    public function __construct(
        private readonly Paths $paths,
        private readonly FileStore $files = new FileStore()
    ) {
    }

    public function issue(
        string $name,
        array $scopes,
        string $issuedBy,
        ?DateTimeInterface $expiresAt = null,
        ?string $principal = null
    ): array {
        $name = trim($name);
        $issuedBy = trim($issuedBy);
        $principal = $principal === null ? $issuedBy : trim($principal);
        if ($principal === '' || strlen($principal) > 120) {
            throw new InvalidArgumentException('A local connection principal is required.');
        }
        if ($name === '' || strlen($name) > 80) {
            throw new InvalidArgumentException('Access token name must contain between 1 and 80 bytes.');
        }
        if ($issuedBy === '' || strlen($issuedBy) > 120) {
            throw new InvalidArgumentException('Access token issuer is required.');
        }

        $normalizedScopes = $this->normalizeScopes($scopes);
        if ($normalizedScopes === []) {
            throw new InvalidArgumentException('At least one access token scope is required.');
        }

        $now = new DateTimeImmutable();
        if ($expiresAt !== null && $expiresAt->getTimestamp() <= $now->getTimestamp()) {
            throw new InvalidArgumentException('Access token expiry must be in the future.');
        }

        $id = bin2hex(random_bytes(12));
        $secret = $this->base64Url(random_bytes(32));
        $secretHash = password_hash($secret, PASSWORD_DEFAULT);
        if (!is_string($secretHash) || $secretHash === '') {
            throw new RuntimeException('Unable to secure access token.');
        }

        $record = [
            'id' => $id,
            'name' => $name,
            'secret_hash' => $secretHash,
            'scopes' => $normalizedScopes,
            'issued_by' => $issuedBy,
            'principal_username' => $principal,
            'created_at' => $now->format(DATE_ATOM),
            'expires_at' => $expiresAt?->format(DATE_ATOM),
            'revoked_at' => null,
        ];
        $usersPath = $this->paths->configPath('users.json');
        if (is_file($usersPath)) {
            foreach ((array)($this->files->readJson($usersPath)['users'] ?? []) as $user) {
                if (is_array($user) && ($user['username'] ?? null) === $principal) {
                    $record['principal_created_at'] = (string)($user['created_at'] ?? '');
                    break;
                }
            }
        }

        $this->mutate(function (array $data) use ($record): array {
            $data['tokens'][] = $record;
            return $data;
        });

        return [
            'token' => 'bp2_' . $id . '_' . $secret,
            'access' => $this->publicRecord($record),
        ];
    }

    public function authenticate(string $token, array $requiredScopes = []): ?array
    {
        if (preg_match(self::TOKEN_PATTERN, trim($token), $matches) !== 1) {
            return null;
        }

        $requiredScopes = $this->normalizeScopes($requiredScopes);
        $record = $this->find($matches[1]);
        if ($record === null || !$this->active($record)) {
            return null;
        }

        $secretHash = (string)($record['secret_hash'] ?? '');
        if ($secretHash === '' || !password_verify($matches[2], $secretHash)) {
            return null;
        }

        $grantedScopes = is_array($record['scopes'] ?? null) ? $record['scopes'] : [];
        if (array_diff($requiredScopes, $grantedScopes) !== []) {
            return null;
        }

        return $this->publicRecord($record);
    }

    public function all(): array
    {
        $records = array_map(fn (array $record): array => $this->publicRecord($record), $this->read()['tokens']);
        usort($records, static fn (array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));
        return $records;
    }

    /** Replace the secret atomically; scopes, expiry and principal are unchanged. */
    public function rotate(string $id): ?array
    {
        $issued = null;
        $this->mutate(function (array $data) use ($id, &$issued): array {
            foreach ($data['tokens'] as &$record) {
                if (($record['id'] ?? '') !== $id || !$this->active($record)) continue;
                $secret = $this->base64Url(random_bytes(32));
                $record['secret_hash'] = password_hash($secret, PASSWORD_DEFAULT);
                $record['rotated_at'] = date(DATE_ATOM);
                $issued = ['token' => 'bp2_' . $id . '_' . $secret, 'access' => $this->publicRecord($record)];
                break;
            }
            unset($record);
            return $data;
        });
        return $issued;
    }

    /** Called only after credential and policy authorization; throttle metadata writes. */
    public function markUsed(string $id): void
    {
        $record = $this->find($id);
        if ($record === null || time() - (int)strtotime((string)($record['last_used_at'] ?? '')) < 60) return;
        $this->mutate(function (array $data) use ($id): array {
            foreach ($data['tokens'] as &$record) {
                if (($record['id'] ?? '') === $id && $this->active($record)) {
                    $record['last_used_at'] = date(DATE_ATOM);
                    break;
                }
            }
            unset($record);
            return $data;
        });
    }

    public function revoke(string $id, ?DateTimeInterface $revokedAt = null): bool
    {
        if (preg_match('/^[a-f0-9]{24}$/D', $id) !== 1) {
            return false;
        }

        $changed = false;
        $when = ($revokedAt ?? new DateTimeImmutable())->format(DATE_ATOM);
        $this->mutate(function (array $data) use ($id, $when, &$changed): array {
            foreach ($data['tokens'] as &$record) {
                if (($record['id'] ?? null) === $id && empty($record['revoked_at'])) {
                    $record['revoked_at'] = $when;
                    $changed = true;
                    break;
                }
            }
            unset($record);
            return $data;
        });

        return $changed;
    }

    private function find(string $id): ?array
    {
        foreach ($this->read()['tokens'] as $record) {
            if (isset($record['id']) && is_string($record['id']) && hash_equals($record['id'], $id)) {
                return $record;
            }
        }
        return null;
    }

    private function active(array $record): bool
    {
        if (!empty($record['revoked_at'])) {
            return false;
        }

        $expiresAt = (string)($record['expires_at'] ?? '');
        if ($expiresAt === '') {
            return true;
        }

        try {
            return (new DateTimeImmutable($expiresAt))->getTimestamp() > time();
        } catch (\Exception) {
            return false;
        }
    }

    private function normalizeScopes(array $scopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = strtolower(trim((string)$scope));
            if (!in_array($scope, self::SCOPES, true)) {
                throw new InvalidArgumentException('Unsupported access token scope: ' . $scope);
            }
            $normalized[$scope] = true;
        }

        $result = array_keys($normalized);
        sort($result, SORT_STRING);
        return $result;
    }

    private function publicRecord(array $record): array
    {
        return [
            'id' => (string)($record['id'] ?? ''),
            'name' => (string)($record['name'] ?? ''),
            'scopes' => array_values(is_array($record['scopes'] ?? null) ? $record['scopes'] : []),
            'issued_by' => (string)($record['issued_by'] ?? ''),
            'principal_username' => (string)($record['principal_username'] ?? $record['issued_by'] ?? ''),
            'principal_created_at' => $record['principal_created_at'] ?? null,
            'legacy_identity' => !isset($record['principal_username'], $record['principal_created_at']),
            'created_at' => (string)($record['created_at'] ?? ''),
            'expires_at' => isset($record['expires_at']) ? (string)$record['expires_at'] : null,
            'revoked_at' => isset($record['revoked_at']) ? (string)$record['revoked_at'] : null,
            'rotated_at' => $record['rotated_at'] ?? null,
            'last_used_at' => $record['last_used_at'] ?? null,
            'active' => $this->active($record),
        ];
    }

    private function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return ['schema_version' => 1, 'tokens' => []];
        }

        $data = $this->files->readJson($path);
        $tokens = is_array($data['tokens'] ?? null) ? $data['tokens'] : [];
        return ['schema_version' => 1, 'tokens' => array_values(array_filter($tokens, 'is_array'))];
    }

    private function mutate(callable $callback): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create access token storage directory.');
        }

        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock access token storage.');
        }

        try {
            $data = $callback($this->read());
            if (!is_array($data)) {
                throw new RuntimeException('Invalid access token storage mutation.');
            }
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                throw new RuntimeException('Unable to encode access token storage.');
            }

            $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
            if (file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) === false || !rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('Unable to publish access token storage.');
            }
            @chmod($path, 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(): string
    {
        return $this->paths->dataPath('integrations/access-tokens.json');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
