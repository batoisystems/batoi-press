<?php
declare(strict_types=1);
namespace Batoi\Press\Admin;

use Batoi\Press\Core\{Config, Response, AssetResponse};
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Update\UpdateHealthCheck;

final class HealthController
{
    public function __construct(private readonly Config $config, private readonly array $user) {}
    public function index(): Response
    {
        if (!in_array(AdminAccess::role($this->user), ['owner', 'admin'], true)) return Response::html('Forbidden', 403)->withHeader('Cache-Control', 'private, no-store');
        $body = AdminLayout::pageHeader('Hosting Health', 'Local checks from the active PHP runtime. No outbound requests are made.');
        $rows = (new UpdateHealthCheck($this->config->paths()))->hosting();
        $rows[] = ['name' => 'Public asset Cache-Control', 'status' => 'Application policy', 'help' => AssetResponse::policy($this->config->site())];
        $rows[] = ['name' => 'Verified theme fingerprint Cache-Control', 'status' => 'Application policy', 'help' => AssetResponse::policy($this->config->site(), true)];
        $rows[] = ['name' => 'Authenticated / cookie asset requests', 'status' => 'Application policy', 'help' => 'private, no-store'];
        $rows[] = ['name' => 'Compression', 'status' => 'Not verified', 'help' => 'PHP compression setting: ' . ((bool)ini_get('zlib.output_compression') ? 'enabled' : 'disabled') . '. Inspect actual Content-Encoding and Vary headers in the browser; hosting/CDN behavior cannot be established locally.'];
        $body .= '<div class="bp-table-wrap"><table class="bp-table"><thead><tr><th>Check</th><th>Status</th><th>Action / result</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach (['name', 'status', 'help'] as $key) $body .= '<td>' . htmlspecialchars($row[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
            $body .= '</tr>';
        }
        $body .= '</tbody></table></div><p>Application cache policies above apply to PHP-served public assets. Direct static files and reverse proxies may use different headers; verify those in your browser.</p>';
        return Response::html(AdminLayout::render('Hosting Health', $body))->withHeader('Cache-Control', 'private, no-store');
    }
}
