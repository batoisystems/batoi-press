<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Paths;
use RuntimeException;

final class IdempotencyStore
{
    private const TTL = 86400;

    public function __construct(
        private readonly Paths $paths,
        private readonly FileStore $files = new FileStore()
    ) {
    }

    public function run(string $actor, string $operation, string $key, array $input, callable $callback): array
    {
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key) !== 1) {
            throw new ContentMutationException('A valid idempotency key is required.', 'idempotency_key_required', 400);
        }
        $scope = hash('sha256', $actor . '|' . $operation . '|' . $key);
        $requestHash = ContentRevision::for($input);
        $path = $this->paths->dataPath('integrations/idempotency/' . substr($scope, 0, 2) . '/' . $scope . '.json');
        $lockPath = $path . '.lock';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create idempotency storage.');
        }
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock idempotency record.');
        }

        try {
            if (is_file($path)) {
                $record = $this->files->readJson($path);
                if ((int)($record['expires_at'] ?? 0) >= time()) {
                    if (!hash_equals((string)($record['request_hash'] ?? ''), $requestHash)) {
                        throw new ContentMutationException('The idempotency key was already used with different input.', 'idempotency_conflict', 409);
                    }
                    $result = is_array($record['result'] ?? null) ? $record['result'] : [];
                    $result['idempotent_replay'] = true;
                    return $result;
                }
            }

            $result = $callback();
            if (!is_array($result)) {
                throw new RuntimeException('Idempotent operation returned an invalid result.');
            }
            $this->files->writeJson($path, [
                'schema_version' => 1,
                'request_hash' => $requestHash,
                'created_at' => time(),
                'expires_at' => time() + self::TTL,
                'result' => $result,
            ]);
            return $result + ['idempotent_replay' => false];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
