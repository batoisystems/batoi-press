<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

final class Response
{
    public function __construct(
        private readonly string $body,
        private readonly int $status = 200,
        private readonly array $headers = [],
        private readonly array $inlineScriptHashes = [],
        private readonly ?FileBody $fileBody = null
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function xml(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public static function json(array $body, int $status = 200, array $headers = []): self
    {
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            $encoded = '{"error":{"code":"encoding_failed","message":"Unable to encode response."}}';
            $status = 500;
        }
        return new self($encoded, $status, ['Content-Type' => 'application/json; charset=UTF-8'] + $headers);
    }

    public static function body(string $body, string $contentType, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => $contentType]);
    }

    public static function redirect(string $location): self
    {
        if (str_starts_with($location, '/') && function_exists('bp_url')) {
            $location = \bp_url($location);
        }

        return new self('Redirecting to ' . $location, 302, ['Location' => $location, 'Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function content(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->body, $this->status, array_merge($this->headers, [$name => $value]), $this->inlineScriptHashes, $this->fileBody);
    }

    public function withInlineScript(string $script): self
    {
        $hash = "'sha256-" . base64_encode(hash('sha256', $script, true)) . "'";
        return new self($this->body, $this->status, $this->headers, array_values(array_unique([...$this->inlineScriptHashes, $hash])), $this->fileBody);
    }

    public function inlineScriptHashes(): array
    {
        return $this->inlineScriptHashes;
    }

    public function send(): void
    {
        // File lengths and ranges describe identity bytes, not a PHP gzip transform.
        if ($this->fileBody !== null) @ini_set('zlib.output_compression', '0');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        // PHP may infer a different status for WWW-Authenticate or Location.
        // Preserve the controller's explicit status after all headers are set.
        http_response_code($this->status);
        if ($this->fileBody !== null) $this->fileBody->send();
        else echo $this->body;
    }
}
