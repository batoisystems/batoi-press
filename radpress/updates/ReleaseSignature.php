<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

use RuntimeException;

final class ReleaseSignature
{
    public const ALGORITHM = 'Ed25519';

    public static function sign(array $manifest, string $secretKey, string $keyId, string $kind): array
    {
        self::requireSodium();
        if (strlen($secretKey) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($secretKey));
        }
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Release signing key must contain an Ed25519 seed or secret key.');
        }
        $manifest['trust'] = [
            'signature_required' => true,
            'signature_algorithm' => self::ALGORITHM,
            'key_id' => $keyId,
            'signed_payload' => $kind,
            'signature' => base64_encode(sodium_crypto_sign_detached(self::payload($manifest, $kind), $secretKey)),
        ];
        return $manifest;
    }

    public static function verify(array $manifest, array $publicKeys, bool $required, string $kind): ?string
    {
        $trust = is_array($manifest['trust'] ?? null) ? $manifest['trust'] : [];
        if (($trust['signature_required'] ?? false) !== true) {
            return $required ? 'Release signature is required but missing.' : null;
        }
        if (($trust['signature_algorithm'] ?? '') !== self::ALGORITHM || ($trust['signed_payload'] ?? '') !== $kind) {
            return 'Release signature metadata is unsupported.';
        }
        self::requireSodium();
        $keyId = trim((string)($trust['key_id'] ?? ''));
        $publicKey = base64_decode((string)($publicKeys[$keyId] ?? ''), true);
        $signature = base64_decode((string)($trust['signature'] ?? ''), true);
        if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'Release signature key or value is invalid.';
        }
        return sodium_crypto_sign_verify_detached($signature, self::payload($manifest, $kind), $publicKey)
            ? null
            : 'Release signature verification failed.';
    }

    public static function payload(array $manifest, string $kind): string
    {
        unset($manifest['trust']);
        $encoded = json_encode(self::sortRecursive($manifest), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new RuntimeException('Unable to canonicalize release metadata.');
        }
        return 'batoi-press:' . $kind . ':v1' . "\n" . $encoded;
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::sortRecursive($item);
        return $value;
    }

    private static function requireSodium(): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('Signed updates require PHP Sodium. Enable extension=sodium in the web server PHP configuration and restart PHP, or ask your hosting provider to enable it. Signature verification cannot be skipped.');
        }
    }
}
