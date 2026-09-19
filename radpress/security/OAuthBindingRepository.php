<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use InvalidArgumentException;
use RuntimeException;

/** Local consent records, separate from external authorization-server configuration. */
final class OAuthBindingRepository
{
    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore()) {}

    public function all(): array
    {
        $stored = $this->stored();
        $keys = array_map(fn (array $record): string => $this->identity($record), $stored);
        $securityPath = $this->paths->configPath('security.json');
        $security = is_file($securityPath) ? $this->files->readJson($securityPath) : [];
        $legacy = [];
        foreach ((array)($security['oauth']['bindings'] ?? []) as $record) {
            if (!is_array($record) || in_array($this->identity($record), $keys, true)) continue;
            $record['id'] = 'oauth_' . substr(hash('sha256', $this->identity($record)), 0, 24);
            $record['name'] = 'Configured OAuth binding';
            $record['migration_required'] = empty($record['client_id']);
            if ($record['migration_required']) $record['enabled'] = false;
            $legacy[] = $record;
        }
        return array_merge($stored, $legacy);
    }

    public function link(string $name, string $subject, string $client, string $username, array $scopes, string $owner, int $days): array
    {
        foreach ([$name, $subject, $client, $username] as $value) {
            if (trim($value) === '' || strlen($value) > 200 || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new InvalidArgumentException('Connection name, subject, client ID and administrator are required (up to 200 bytes each).');
        }
        $security = $this->files->readJson($this->paths->configPath('security.json'));
        $oauth = (array)($security['oauth'] ?? []);
        $issuer = (string)($oauth['issuer'] ?? '');
        if (($oauth['enabled'] ?? false) !== true || !str_starts_with($issuer, 'https://')) throw new InvalidArgumentException('Configure the trusted HTTPS OAuth provider before linking accounts.');
        $scopes = array_values(array_unique($scopes));
        if ($scopes === [] || array_diff($scopes, AccessTokenRepository::SCOPES) !== []) throw new InvalidArgumentException('Select supported connection scopes.');
        $allowed = (new MachineAccessPolicy($this->paths))->resolve(['principal_username' => $username, 'scopes' => $scopes, 'created_at' => date(DATE_ATOM)]);
        if ($allowed === null || array_diff($scopes, $allowed['scopes']) !== []) throw new InvalidArgumentException('The administrator or site policy does not allow these scopes.');
        $users = $this->files->readJson($this->paths->configPath('users.json'));
        $matches = array_values(array_filter($users['users'] ?? [], static fn ($user): bool => is_array($user) && ($user['username'] ?? '') === $username));
        if (count($matches) !== 1) throw new InvalidArgumentException('Choose one active local administrator.');
        $record = ['id' => 'oauth_' . bin2hex(random_bytes(12)), 'name' => trim($name), 'issuer' => $issuer,
            'subject' => $subject, 'client_id' => $client, 'username' => $username,
            'principal_created_at' => (string)($matches[0]['created_at'] ?? ''), 'scopes' => $scopes,
            'enabled' => true, 'created_by' => $owner, 'created_at' => date(DATE_ATOM),
            'expires_at' => date(DATE_ATOM, time() + max(1, min(365, $days)) * 86400), 'revoked_at' => null];
        $this->mutate(function (array $records) use ($record): array {
            foreach ($records as &$old) {
                if ($this->identity($old) === $this->identity($record) && ($old['enabled'] ?? false)) {
                    $old['enabled'] = false;
                    $old['revoked_at'] = date(DATE_ATOM);
                }
            }
            unset($old);
            $records[] = $record;
            return $records;
        });
        return $record;
    }

    public function revoke(string $id): bool
    {
        $changed = false;
        $this->mutate(function (array $records) use ($id, &$changed): array {
            foreach ($records as &$record) {
                if (($record['id'] ?? '') === $id && ($record['enabled'] ?? false)) {
                    $record['enabled'] = false;
                    $record['revoked_at'] = date(DATE_ATOM);
                    $changed = true;
                }
            }
            unset($record);
            return $records;
        });
        return $changed;
    }

    private function identity(array $record): string { return json_encode([$record['issuer'] ?? '', $record['subject'] ?? '', $record['client_id'] ?? ''], JSON_THROW_ON_ERROR); }
    private function stored(): array
    {
        $path = $this->path();
        return is_file($path) ? array_values(array_filter((array)($this->files->readJson($path)['bindings'] ?? []), 'is_array')) : [];
    }
    private function path(): string { return $this->paths->dataPath('integrations/oauth-bindings.json'); }
    private function mutate(callable $callback): void
    {
        $path = $this->path();
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) throw new RuntimeException('Unable to create OAuth binding storage.');
        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock OAuth binding storage.');
        try {
            $this->files->writeJson($path, ['schema_version' => 1, 'bindings' => $callback($this->all())]);
            @chmod($path, 0600);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
