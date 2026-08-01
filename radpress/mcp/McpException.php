<?php
declare(strict_types=1);

namespace Batoi\Press\Mcp;

use RuntimeException;

final class McpException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $rpcCode,
        private readonly array $data = []
    ) {
        parent::__construct($message);
    }

    public function rpcCode(): int
    {
        return $this->rpcCode;
    }

    public function data(): array
    {
        return $this->data;
    }
}
