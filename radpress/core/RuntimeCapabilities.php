<?php
declare(strict_types=1);

namespace Batoi\Press\Core;

/** Setup, health and optional features share this runtime contract. */
final class RuntimeCapabilities
{
    public const MIN_PHP = '8.3.0';

    public static function definitions(): array
    {
        return [
            'php' => ['PHP 8.3+', 'core', 'Use a supported web PHP release (8.3 or later).'],
            'json' => ['JSON', 'core', 'Enable native JSON support.'],
            'session' => ['Sessions', 'core', 'Enable PHP sessions for local administration.'],
            'hash' => ['Hash and random', 'core', 'Enable hash and secure random support.'],
            'filter' => ['Input validation', 'core', 'Enable PHP filter support.'],
            'password' => ['Password hashing', 'core', 'Enable native password hashing.'],
            'dom' => ['Rich HTML parser', 'feature', 'Enable DOM for rich HTML; without it new content uses escaped plain text.'],
            'fileinfo' => ['Validated uploads', 'feature', 'Enable Fileinfo for new uploads. Existing media remains available.'],
            'mbstring' => ['Unicode case folding', 'recommended', 'Enable mbstring for multilingual case-insensitive search. Original text is preserved.'],
            'zip' => ['ZIP packages', 'feature', 'Enable ZIP for archives; manual file deployment remains available.'],
            'signatures' => ['Signed updates', 'feature', 'Enable Sodium for verified automatic updates. Never disable signature verification.'],
            'encryption' => ['Encrypted secrets', 'feature', 'Enable Sodium or OpenSSL AES-256-GCM for secrets and MFA.'],
            'http' => ['Remote integrations', 'feature', 'Enable cURL for remote adapters. Local publishing and login do not require it.'],
            'images' => ['Image derivatives', 'feature', 'Enable GD for transformations; originals remain usable.'],
            'opcache' => ['OPcache', 'recommended', 'Enable OPcache for lower compilation cost.'],
        ];
    }

    public static function available(string $id): bool
    {
        return match ($id) {
            'php' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'json', 'session', 'filter' => extension_loaded($id),
            'hash' => function_exists('hash') && function_exists('random_bytes'),
            'password' => function_exists('password_hash') && function_exists('password_verify'),
            'dom' => class_exists(\DOMDocument::class),
            'fileinfo' => class_exists(\finfo::class),
            'mbstring' => function_exists('mb_strtolower'),
            'zip' => class_exists(\ZipArchive::class),
            'signatures' => function_exists('sodium_crypto_sign_verify_detached'),
            'encryption' => function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') || (function_exists('openssl_get_cipher_methods') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)),
            'http' => function_exists('curl_init'),
            'images' => function_exists('imagecreatetruecolor'),
            'opcache' => function_exists('opcache_get_status') && @opcache_get_status(false) !== false,
            default => false,
        };
    }

    public static function rows(?array $availability = null): array
    {
        $rows = [];
        foreach (self::definitions() as $id => [$name, $level, $help]) {
            $ok = $availability === null ? self::available($id) : ($availability[$id] ?? false);
            $rows[] = ['id' => $id, 'name' => $name, 'level' => $level, 'available' => $ok,
                'status' => $ok ? 'OK' : match ($level) { 'core' => 'Blocking', 'feature' => 'Blocks related feature', default => 'Recommendation' },
                'help' => $ok ? 'Available' : $help];
        }
        return $rows;
    }

    public static function missing(array $ids): array
    {
        return array_values(array_filter($ids, static fn(string $id): bool => !self::available($id)));
    }
}
