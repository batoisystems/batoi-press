<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

final class VersionChecker
{
    private readonly ?\Closure $fetcher;

    public function __construct(private readonly string $manifestUrl, ?callable $fetcher = null)
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
                'error' => 'Unable to fetch update manifest. Confirm outbound HTTPS access, CA certificates, and PHP cURL or allow_url_fopen support.',
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
}
