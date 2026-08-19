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
        $raw = $this->fetcher !== null
            ? ($this->fetcher)($this->manifestUrl)
            : $this->fetchManifest();
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'ok' => false,
                'error' => $this->transportFailureMessage(),
                'manifest_url' => $this->manifestUrl,
            ];
        }

        $manifest = json_decode($raw, true);
        if (!is_array($manifest) || empty($manifest['version'])) {
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
            'manifest' => $manifest,
            'manifest_url' => $this->manifestUrl,
        ];
    }

    private function fetchManifest(): string|false
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($this->manifestUrl);
            if ($handle !== false) {
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_USERAGENT => 'Batoi Press Update Checker',
                ]);
                $raw = curl_exec($handle);
                $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                curl_close($handle);
                if (is_string($raw) && trim($raw) !== '' && $status >= 200 && $status < 300) {
                    return $raw;
                }
            }
        }

        if (!$this->streamTransportAvailable()) {
            return false;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: Batoi Press Update Checker\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($this->manifestUrl, false, $context);
        return is_string($raw) && trim($raw) !== '' ? $raw : false;
    }

    private function streamTransportAvailable(): bool
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    private function transportFailureMessage(): string
    {
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
