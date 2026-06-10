<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

final class Csrf
{
    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf_token', $token);
        }

        return $token;
    }

    public function validate(string $token): bool
    {
        $stored = $this->session->get('csrf_token');
        return is_string($stored) && $stored !== '' && hash_equals($stored, $token);
    }

    public function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($this->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }
}
