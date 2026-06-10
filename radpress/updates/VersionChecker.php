<?php
declare(strict_types=1);

namespace Batoi\Press\Update;

final class VersionChecker
{
    public function __construct(private readonly string $manifestUrl)
    {
    }

    public function manifestUrl(): string
    {
        return $this->manifestUrl;
    }

    public function check(string $currentVersion): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $raw = @file_get_contents($this->manifestUrl, false, $context);
        if ($raw === false || trim($raw) === '') {
            return [
                'ok' => false,
                'error' => 'Unable to fetch update manifest.',
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
}
