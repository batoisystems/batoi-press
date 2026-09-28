<?php
declare(strict_types=1);

// Simulate the Response class already loaded by an older installation before
// the updater replaces SecurityHeaders on disk in the same PHP request.
namespace Batoi\Press\Core {
    final class Response
    {
        private array $headers = [];
        public function headers(): array { return $this->headers; }
        public function withHeader(string $name, string $value): self
        {
            $copy = clone $this;
            $copy->headers[$name] = $value;
            return $copy;
        }
    }
}

namespace {
    require dirname(__DIR__) . '/autoload.php';
    $root = sys_get_temp_dir() . '/press-response-' . bin2hex(random_bytes(8));
    // Config supports an absent root; no installation data needs to be modified.
    $config = \Batoi\Press\Core\Config::load($root);
    $response = \Batoi\Press\Security\SecurityHeaders::apply(
        new \Batoi\Press\Core\Response(),
        new \Batoi\Press\Core\Request('POST', '/admin/updates/apply', [], [], ['HTTPS' => 'on']),
        $config
    );
    $headers = $response->headers();
    $policy = $headers['Content-Security-Policy-Report-Only'] ?? '';
    if (($headers['X-Content-Type-Options'] ?? '') !== 'nosniff'
        || !preg_match('/script-src ([^;]+)/', $policy, $scripts)
        || str_contains($scripts[1], "'unsafe-inline'")) {
        throw new \RuntimeException('Legacy Response compatibility must retain security headers.');
    }
    echo "Update Response compatibility checks passed\n";
}
