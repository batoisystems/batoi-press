<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class MaintenanceMode
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function enable(string $reason): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode([
            'enabled_at' => date(DATE_ATOM),
            'reason' => $reason,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function disable(): void
    {
        $path = $this->path();
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function active(): bool
    {
        return is_file($this->path());
    }

    public function response(): Response
    {
        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Maintenance | Batoi Press</title></head><body><main><h1>Maintenance</h1><p>This site is temporarily unavailable while maintenance is in progress.</p></main></body></html>';
        return new Response($body, 503, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Retry-After' => '120',
        ]);
    }

    private function path(): string
    {
        return $this->paths->dataPath('maintenance.json');
    }
}
