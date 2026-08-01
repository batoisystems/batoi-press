<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use RuntimeException;

final class MachineAccessException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $errorCode,
        private readonly array $headers = []
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function headers(): array
    {
        return $this->headers;
    }
}
