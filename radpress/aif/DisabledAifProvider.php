<?php
declare(strict_types=1);

namespace Batoi\Press\Aif;

final class DisabledAifProvider implements AifProvider
{
    public function available(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'disabled';
    }

    public function assist(string $task, array $context = []): array
    {
        return [
            'ok' => false,
            'task' => $task,
            'error' => 'Batoi AIF is disabled for this installation.',
        ];
    }
}
