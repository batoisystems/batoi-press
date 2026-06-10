<?php
declare(strict_types=1);

namespace Batoi\Press\Aif;

interface BatoiWorkspaceAifProvider extends AifProvider
{
    public function workspaceId(): string;

    public function connected(): bool;
}
