<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

final class VersionChecker
{
    private readonly ?\Closure $fetcher;

    public function __construct(private readonly string $manifestUrl, ?callable $fetcher = null, private readonly array $publicKeys = [], private readonly bool $signatureRequired = false)
    {
        $this->fetcher = $fetcher !== null ? \Closure::fromCallable($fetcher) : null;
    }

    public function manifestUrl(): string
    {
        return $this->manifestUrl;
    }

    public function check(string $currentVersion): array
    {
        try {
            $raw = $this->fetcher !== null
                ? ($this->fetcher)($this->manifestUrl)
                : $this->fetchManifest();
        } catch (\Throwable) {
            // Transport diagnostics must not disclose credentials or internal exception details.
            $raw = false;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'ok' => false,
                'error' => $this->transportFailureMessage(),
                'manifest_url' => $this->manifestUrl,
            ];
        }

        $manifest = json_decode($raw, true);
        if (!is_array($manifest) || !is_string($manifest['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $manifest['version'])) {
            return [
                'ok' => false,
                'error' => 'Update manifest is invalid.',
                'manifest_url' => $this->manifestUrl,
            ];
        }
        try {
            $signatureError = ReleaseSignature::verify($manifest, $this->publicKeys, $this->signatureRequired, 'release-index');
        } catch (\RuntimeException $exception) {
            $signatureError = $exception->getMessage();
        }
        if ($signatureError !== null) {
            return ['ok' => false, 'error' => $signatureError, 'manifest_url' => $this->manifestUrl];
        }

        $latest = (string)$manifest['version'];
        return [
            'ok' => true,
            'current_version' => $currentVersion,
            'latest_version' => $latest,
            'update_available' => version_compare($latest, $currentVersion, '>'),
            'manifest_behind' => version_compare($latest, $currentVersion, '<'),
            'manifest' => $manifest,
            'manifest_url' => $this->manifestUrl,
        ];
    }

    private function fetchManifest(): string|false
    {
        // Both cURL and streams can abort in macOS getaddrinfo after fork.
        // Resolve DNS records directly for these workers, retaining the original
        // HTTPS hostname for SNI and certificate verification. Fail closed if
        // this transport is unavailable; do not fall back to the crashing path.
        $nativeResolverUnsafe = self::requiresDirectDns(PHP_OS_FAMILY, PHP_SAPI);
        if ($nativeResolverUnsafe) {
            foreach (['HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy'] as $variable) {
                if (getenv($variable)) return false; // Do not bypass a configured proxy or resolve it unsafely.
            }
        }
        $resolve = $nativeResolverUnsafe ? $this->directDnsEntry() : null;
        if ($nativeResolverUnsafe && $resolve === null) return false;
        if (function_exists('curl_init')) {
            $handle = curl_init($this->manifestUrl);
            if ($handle !== false) {
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => !$nativeResolverUnsafe,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_USERAGENT => 'Batoi Press Update Checker',
                ]);
                if ($nativeResolverUnsafe) {
                    curl_setopt($handle, CURLOPT_RESOLVE, [$resolve]);
                }
                $raw = curl_exec($handle);
                $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                unset($handle); // PHP 8+ releases the handle automatically; curl_close is deprecated in 8.5.
                if (is_string($raw) && trim($raw) !== '' && $status >= 200 && $status < 300) {
                    return $raw;
                }
            }
        }

        if ($nativeResolverUnsafe) return false;
        return $this->fetchStreamManifest();
    }

    private static function requiresDirectDns(string $os, string $sapi): bool
    {
        return $os === 'Darwin' && !in_array($sapi, ['cli', 'phpdbg', 'cli-server'], true);
    }

    private function directDnsEntry(): ?string
    {
        $parts = parse_url($this->manifestUrl);
        $host = (string)($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || !preg_match('/^[a-z0-9.-]+$/iD', $host) || !function_exists('dns_get_record')) return null;
        $addresses = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? '';
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $addresses[] = $address;
            elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $addresses[] = '[' . $address . ']';
        }
        return $addresses === [] ? null : $host . ':' . (int)($parts['port'] ?? 443) . ':' . implode(',', $addresses);
    }

    private function fetchStreamManifest(): string|false
    {
        if (!$this->streamTransportAvailable()) {
            return false;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
                'max_redirects' => 3,
                'header' => "Accept: application/json\r\nUser-Agent: Batoi Press Update Checker\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($this->manifestUrl, false, $context);
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
        $status = 0;
        foreach ($headers ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) $status = (int)$matches[1];
        }
        return $status >= 200 && $status < 300 && is_string($raw) && trim($raw) !== '' ? $raw : false;
    }

    private function streamTransportAvailable(): bool
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    private function transportFailureMessage(): string
    {
        if (self::requiresDirectDns(PHP_OS_FAMILY, PHP_SAPI)) {
            return 'Unable to fetch update manifest. macOS web workers require PHP cURL, DNS record lookup and direct outbound HTTPS to avoid native resolver crashes. Use the final HTTPS manifest URL without redirects, and confirm CA certificates. Signature verification remains required.';
        }
        if (!function_exists('curl_init')) {
            return $this->streamTransportAvailable()
                ? 'Unable to fetch update manifest. Enable PHP cURL, or confirm outbound HTTPS access and CA certificates for PHP HTTPS streams.'
                : 'Unable to fetch update manifest. PHP cURL is required when allow_url_fopen is disabled.';
        }

        return $this->streamTransportAvailable()
            ? 'Unable to fetch update manifest. Confirm outbound HTTPS access, DNS resolution, CA certificates, and PHP cURL support.'
            : 'Unable to fetch update manifest through PHP cURL. Confirm outbound HTTPS access, DNS resolution, and CA certificates. allow_url_fopen is not required when PHP cURL is available.';
    }
}
