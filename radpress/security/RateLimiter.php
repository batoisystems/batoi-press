<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\Paths;

final class RateLimiter
{
    public function __construct(
        private readonly Paths $paths,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 300
    ) {
    }

    public function tooManyAttempts(string $key): bool
    {
        return count($this->attempts($key)) >= $this->maxAttempts;
    }

    public function hit(string $key): void
    {
        $attempts = $this->attempts($key);
        $attempts[] = time();
        $this->write($key, $attempts);
    }

    public function clear(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function attempts(string $key): array
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($file), true);
        $attempts = is_array($decoded) ? array_filter($decoded, 'is_int') : [];
        $cutoff = time() - $this->windowSeconds;

        return array_values(array_filter($attempts, static fn (int $stamp): bool => $stamp >= $cutoff));
    }

    private function write(string $key, array $attempts): void
    {
        $dir = $this->paths->dataPath('tmp/rate');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->file($key), json_encode(array_values($attempts), JSON_PRETTY_PRINT));
    }

    private function file(string $key): string
    {
        return $this->paths->dataPath('tmp/rate/' . hash('sha256', $key) . '.json');
    }
}
