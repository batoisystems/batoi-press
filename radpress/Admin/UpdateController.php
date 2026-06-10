<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\Config;
use Batoi\Press\Core\Response;

final class UpdateController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function index(): Response
    {
        $update = $this->config->update();
        $current = htmlspecialchars((string)($update['current_version'] ?? '0.1.0'));
        $manifest = htmlspecialchars((string)($update['stable_manifest_url'] ?? 'https://batoi.com/press/latest.json'));

        $body = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Updates | Batoi Press</title><link rel="stylesheet" href="/assets/css/style.css"></head><body><main class="bp-admin">';
        $body .= '<h1>Updates</h1>';
        $body .= '<p>Current version: <strong>' . $current . '</strong></p>';
        $body .= '<p>Stable manifest: <code>' . $manifest . '</code></p>';
        $body .= '<p>Automated update installation is intentionally deferred until backup, staging, and rollback are complete. This screen establishes the governed update surface.</p>';
        $body .= '<p><a href="/admin">Back to admin</a></p>';
        $body .= '</main></body></html>';

        return Response::html($body);
    }
}

