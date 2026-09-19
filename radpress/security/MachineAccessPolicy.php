<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;

/** Resolve machine credentials against current installation authority, never token claims alone. */
final class MachineAccessPolicy
{
    public function __construct(private readonly Paths $paths, private readonly FileStore $files = new FileStore())
    {
    }

    public function resolve(array $access): ?array
    {
        $security = $this->read('security.json');
        $scopes = (array)($access['scopes'] ?? []);
        $username = (string)($access['principal_username'] ?? $access['issued_by'] ?? '');
        if (($access['oauth'] ?? false) === true) {
            $binding = null;
            foreach ((new OAuthBindingRepository($this->paths))->all() as $candidate) {
                if (is_array($candidate)
                    && ($candidate['issuer'] ?? null) === ($access['issuer'] ?? null)
                    && ($candidate['subject'] ?? null) === ($access['subject'] ?? null)
                    && !empty($candidate['client_id']) && ($candidate['client_id'] ?? null) === ($access['client_id'] ?? null)
                    && ($candidate['enabled'] ?? false) === true) {
                    if ($binding !== null) return null; // Ambiguous identity is not authority.
                    $binding = $candidate;
                }
            }
            if ($binding === null) return null;
            if (!empty($binding['expires_at']) && (strtotime($binding['expires_at']) ?: 0) <= time()) return null;
            $username = (string)($binding['username'] ?? '');
            $access['id'] = $binding['id'];
            $access['principal_created_at'] = $binding['principal_created_at'] ?? null;
            $scopes = array_intersect($scopes, (array)($binding['scopes'] ?? []));
        }

        $matches = array_values(array_filter((array)($this->read('users.json')['users'] ?? []),
            static fn ($user): bool => is_array($user) && ($user['username'] ?? null) === $username));
        if ($username === '' || count($matches) !== 1) return null;
        $user = $matches[0];
        if ((string)($user['disabled_at'] ?? '') !== '' || ($user['status'] ?? '') === 'disabled') return null;
        if (isset($access['principal_created_at'])
            && $access['principal_created_at'] !== (string)($user['created_at'] ?? '')) return null;
        if (($access['oauth'] ?? false) !== true) {
            // A legacy credential cannot belong to an account created after it was issued.
            $created = strtotime((string)($user['created_at'] ?? ''));
            $issued = strtotime((string)($access['created_at'] ?? ''));
            if ($created !== false && ($issued === false || $created > $issued)) return null;
        }

        // Authors require per-record ownership enforcement before machine access can be enabled.
        $allowed = match (AdminAccess::role($user)) {
            'owner', 'admin' => AccessTokenRepository::SCOPES,
            'editor' => ['site:read', 'content:read', 'content:write', 'content:publish', 'media:read', 'media:write'],
            default => [],
        };
        if (isset($security['machine_allowed_scopes'])) {
            $allowed = array_intersect($allowed, (array)$security['machine_allowed_scopes']);
        }
        $access['scopes'] = array_values(array_intersect($scopes, $allowed));
        if ($access['scopes'] === []) return null;
        $access['principal'] = $username;
        $access['role'] = AdminAccess::role($user);
        unset($access['issuer'], $access['subject']);
        return $access;
    }

    /** Recheck a stored proposal's connection without storing its bearer credential. */
    public function connection(string $id): ?array
    {
        foreach ((new AccessTokenRepository($this->paths))->all() as $record) {
            if ($record['id'] === $id) return $record['active'] ? $this->resolve($record) : null;
        }
        $oauth = (array)($this->read('security.json')['oauth'] ?? []);
        if (($oauth['enabled'] ?? false) !== true) return null;
        foreach ((new OAuthBindingRepository($this->paths))->all() as $binding) {
            if (!is_array($binding)) continue;
            $issuer = (string)($binding['issuer'] ?? '');
            $subject = (string)($binding['subject'] ?? '');
            if ($issuer !== (string)($oauth['issuer'] ?? '') || $subject === '' || !($binding['enabled'] ?? false)) continue;
            if (($binding['id'] ?? '') === $id) {
                return $this->resolve(['id' => $id, 'oauth' => true, 'issuer' => $issuer, 'subject' => $subject, 'client_id' => $binding['client_id'] ?? '', 'scopes' => (array)($binding['scopes'] ?? [])]);
            }
        }
        return null;
    }

    private function read(string $name): array
    {
        $path = $this->paths->configPath($name);
        return is_file($path) ? $this->files->readJson($path) : [];
    }
}
