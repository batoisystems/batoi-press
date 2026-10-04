<?php
declare(strict_types=1);

namespace Batoi\Press\Security;

use Batoi\Press\Core\Paths;
use Batoi\Press\Core\FileStore;
use Closure;
use RuntimeException;
use Throwable;

/** Optional active-state verification, never a replacement for signed JWT/local policy. */
final class OAuthIntrospectionClient
{
    private bool $unavailable = false;

    public function __construct(private readonly Paths $paths, private readonly array $configuration,
        private readonly ?Closure $transport = null) {}

    public function active(string $token, array $claims): bool
    {
        $this->unavailable = true;
        try {
            $profile = $this->configuration['introspection'] ?? null;
            if (!is_array($profile) || ($profile['required'] ?? null) !== true) return false;
            $endpoint = $profile['endpoint'] ?? null;
            $issuer = $this->configuration['issuer'] ?? null;
            if (!is_string($endpoint) || !is_string($issuer) || !$this->sameAuthority($endpoint, $issuer)) return false;
            $id = $profile['resource_id'] ?? null;
            $encrypted = $profile['secret_encrypted'] ?? null;
            if (!is_string($id) || trim($id) === '' || strlen($id) > 1900 || preg_match('/[\x00-\x1f\x7f]/', $id)
                || !is_string($encrypted) || strlen($encrypted) > 16384 || $encrypted === '') return false;
            $secret = @(new SecretStore($this->paths))->decrypt($encrypted);
            if ($secret === '' || strlen($secret) > 4096) return false;
            if (!$this->allowRequest($claims, $profile)) return false;
            $headers = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode(urlencode($id) . ':' . urlencode($secret))];
            $body = http_build_query(['token' => $token, 'token_type_hint' => 'access_token'], '', '&', PHP_QUERY_RFC1738);
            $response = $this->transport !== null ? ($this->transport)($endpoint, $headers, $body) : $this->fetch($endpoint, $headers, $body);
            if (is_array($response) && ($response['active'] ?? null) === false) {
                $this->unavailable = false;
                return false;
            }
            if (!is_array($response) || ($response['active'] ?? null) !== true || ($response['token_type'] ?? null) !== 'Bearer') return false;
            foreach (['iss', 'aud', 'sub', 'client_id', 'jti', 'iat', 'exp'] as $field) {
                if (!array_key_exists($field, $response) || !array_key_exists($field, $claims) || $response[$field] !== $claims[$field]) return false;
            }
            foreach (['scope', 'scp'] as $field) {
                if (array_key_exists($field, $claims) && (!array_key_exists($field, $response) || $response[$field] !== $claims[$field])) return false;
            }
            // A slow provider cannot extend the signed validity window.
            $this->unavailable = false;
            return is_int($claims['exp']) && $claims['exp'] > time();
        } catch (Throwable) {
            // Never expose a credential, token, response body or network exception.
            return false;
        }
    }

    public function unavailable(): bool { return $this->unavailable; }

    private function allowRequest(array $claims, array $profile): bool
    {
        // Separate from credential-failure/PAT limits; changing JTI does not reset it.
        $identity = hash('sha256', json_encode([$claims['iss'] ?? '', $profile['resource_id'], $claims['client_id'] ?? '', $claims['sub'] ?? ''], JSON_THROW_ON_ERROR));
        $directory = $this->paths->dataPath('integrations/oauth-introspection-limits');
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return false;
        $lock = @fopen($directory . '/' . $identity . '.lock', 'c');
        if ($lock === false) return false;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) return false;
            $path = $directory . '/' . $identity . '.json';
            $files = new FileStore();
            if (is_file($path)) {
                $size = @filesize($path);
                if ($size === false || $size > 65536) return false;
            }
            $attempts = is_file($path) ? @$files->readJson($path) : [];
            if (!array_is_list($attempts) || count($attempts) > 240) return false;
            $now = time();
            foreach ($attempts as $stamp) if (!is_int($stamp) || $stamp < 0 || $stamp > $now) return false;
            $attempts = array_values(array_filter($attempts, static fn (int $stamp): bool => $stamp >= $now - 60));
            if (count($attempts) >= 240) return false;
            $attempts[] = $now;
            // FileStore publication throws on storage failure; no unchecked quota write.
            @$files->writeJson($path, $attempts);
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function sameAuthority(string $endpoint, string $issuer): bool
    {
        $a = parse_url($endpoint); $b = parse_url($issuer);
        return filter_var($endpoint, FILTER_VALIDATE_URL) !== false && filter_var($issuer, FILTER_VALIDATE_URL) !== false
            && strlen($endpoint) <= 2048 && strlen($issuer) <= 2048
            && is_array($a) && is_array($b) && ($a['scheme'] ?? '') === 'https' && ($b['scheme'] ?? '') === 'https'
            && !empty($a['host']) && strtolower($a['host']) === strtolower((string)($b['host'] ?? ''))
            && ($a['port'] ?? 443) === ($b['port'] ?? 443)
            && !isset($a['user']) && !isset($a['pass']) && !isset($a['fragment']) && !isset($a['query'])
            && !isset($b['user']) && !isset($b['pass']) && !isset($b['fragment']) && !isset($b['query'])
            && !preg_match('/[\x00-\x20\x7f]/', $endpoint);
    }

    private function fetch(string $endpoint, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('OAuth active-state verification requires cURL.');
        $handle = curl_init($endpoint);
        if ($handle === false) throw new RuntimeException('OAuth active-state request unavailable.');
        $response = '';
        try {
            $configured = curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'BatoiPress-OAuth/2.0',
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 32768) return 0;
                    $response .= $chunk;
                    return strlen($chunk);
                }]);
            if (!$configured) throw new RuntimeException('OAuth active-state request unavailable.');
            $ok = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }
        $decoded = $ok === true && $status === 200 ? json_decode($response, true, 16) : null;
        if (!is_array($decoded)) throw new RuntimeException('OAuth active-state response unavailable.');
        return $decoded;
    }
}
