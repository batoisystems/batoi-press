<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\Paths;
use RuntimeException;

final class SecretStore
{
    private const CONTEXT = 'batoi-press:secret:v1';

    public function __construct(private readonly Paths $paths)
    {
    }

    public function encrypt(string $plaintext): string
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new RuntimeException('The Sodium extension is required for encrypted secrets.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::CONTEXT, $nonce, $this->key());
        return 'v1.' . rtrim(strtr(base64_encode($nonce . $ciphertext), '+/', '-_'), '=');
    }

    public function decrypt(string $encoded): string
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt') || !str_starts_with($encoded, 'v1.')) {
            throw new RuntimeException('Encrypted secret format is unavailable.');
        }
        $payload = base64_decode(strtr(substr($encoded, 3), '-_', '+/'), true);
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (!is_string($payload) || strlen($payload) <= $nonceBytes) {
            throw new RuntimeException('Encrypted secret is invalid.');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($payload, $nonceBytes), self::CONTEXT, substr($payload, 0, $nonceBytes), $this->key());
        if (!is_string($plaintext)) {
            throw new RuntimeException('Unable to decrypt secret.');
        }
        return $plaintext;
    }

    private function key(): string
    {
        $environment = trim((string)getenv('BATOI_PRESS_SECRET_KEY'));
        if ($environment !== '') {
            $decoded = preg_match('/^[a-f0-9]{64}$/iD', $environment) === 1 ? hex2bin($environment) : base64_decode($environment, true);
            if (is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                return $decoded;
            }
            throw new RuntimeException('BATOI_PRESS_SECRET_KEY must encode exactly 32 bytes.');
        }

        $path = $this->paths->dataPath('security/master.key');
        if (is_file($path)) {
            $decoded = base64_decode(trim((string)file_get_contents($path)), true);
            if (is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                return $decoded;
            }
            throw new RuntimeException('The local secret-encryption key is invalid.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create private secret storage.');
        }
        $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        if (file_put_contents($temporary, base64_encode($key) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write local secret-encryption key.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish local secret-encryption key.');
        }
        @chmod($path, 0600);
        return $key;
    }
}
