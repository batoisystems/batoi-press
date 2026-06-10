<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class AuditLog
{
    public function __construct(
        private readonly Paths $paths,
        private readonly FileStore $files
    ) {
    }

    public function record(string $user, string $action, string $target, string $ip = ''): void
    {
        $this->files->write(
            $this->paths->dataPath('log/audit.jsonl'),
            json_encode([
                'time' => date(DATE_ATOM),
                'user' => $user,
                'action' => $action,
                'target' => $target,
                'ip' => $ip,
            ], JSON_UNESCAPED_SLASHES) . "\n",
            append: true
        );
    }
}
