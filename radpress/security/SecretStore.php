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
            if (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
                throw new RuntimeException('Enable PHP Sodium or OpenSSL with AES-256-GCM to save encrypted secrets.');
            }
            $nonce = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag, 'batoi-press:secret:v2', 16);
            if (!is_string($ciphertext) || strlen($tag) !== 16) throw new RuntimeException('Unable to encrypt secret.');
            return 'v2.' . rtrim(strtr(base64_encode($nonce . $tag . $ciphertext), '+/', '-_'), '=');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::CONTEXT, $nonce, $this->key());
        return 'v1.' . rtrim(strtr(base64_encode($nonce . $ciphertext), '+/', '-_'), '=');
    }

    public function decrypt(string $encoded): string
    {
        if (str_starts_with($encoded, 'v2.')) {
            if (!function_exists('openssl_decrypt')) throw new RuntimeException('PHP OpenSSL is required to read this saved secret.');
            $payload = base64_decode(strtr(substr($encoded, 3), '-_', '+/'), true);
            if (!is_string($payload) || strlen($payload) < 28) throw new RuntimeException('Encrypted secret is invalid.');
            $plaintext = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16), 'batoi-press:secret:v2');
            if (!is_string($plaintext)) throw new RuntimeException('Unable to decrypt secret.');
            return $plaintext;
        }
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
            if (is_string($decoded) && strlen($decoded) === 32) {
                return $decoded;
            }
            throw new RuntimeException('BATOI_PRESS_SECRET_KEY must encode exactly 32 bytes.');
        }

        $path = $this->paths->dataPath('security/master.key');
        if (is_file($path)) {
            $decoded = base64_decode(trim((string)file_get_contents($path)), true);
            if (is_string($decoded) && strlen($decoded) === 32) {
                return $decoded;
            }
            throw new RuntimeException('The local secret-encryption key is invalid.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create private secret storage.');
        }
        $key = random_bytes(32);
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
