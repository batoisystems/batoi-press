<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

final class Password
{
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

