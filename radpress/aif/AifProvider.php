<?php
declare(strict_types=1);

namespace Batoi\Press\Aif;

interface AifProvider
{
    public function available(): bool;

    public function name(): string;

    public function assist(string $task, array $context = []): array;
}
