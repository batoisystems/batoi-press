<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\{AuditLog, Config, FileBody, FileStore, Request, Response, ThemeCompatibilityInspector, UifThemeKit};
use Batoi\Press\Security\{Csrf, RateLimiter};

final class ThemeKitController
{
    public function __construct(private readonly Config $config, private readonly Csrf $csrf, private readonly AuditLog $audit, private readonly array $user) {}
    public function handle(Request $request): Response
    {
        if (($this->user['role'] ?? '') !== 'owner') return Response::html('Owner access required.', 403);
        $definition = json_encode(UifThemeKit::starter(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $draft = null;
        if ($request->input('draft') !== '') {
            try {
                $draft = (new \Batoi\Press\Application\ThemeDrafts($this->config))->get($request->input('draft'));
                $definition = json_encode($draft['definition'],JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\Throwable) { return Response::html(AdminLayout::message('Theme draft','This private theme draft is unavailable or belongs to another installation.',true),409); }
        }
        if ($request->method === 'GET' && $request->input('download') === 'schema') return Response::json(UifThemeKit::schema())->withHeader('Content-Disposition','attachment; filename="press-uif-theme.schema.json"')->withHeader('Cache-Control','private, no-store');
        if ($request->method === 'GET' && $request->input('download') === 'starter') return Response::body($definition . "\n",'application/json')->withHeader('Content-Disposition','attachment; filename="press-uif-theme-starter.json"')->withHeader('Cache-Control','private, no-store');
        $error = '';
        if ($request->method === 'POST') {
            if (!$this->csrf->validate($request->input('csrf_token'))) return Response::html(AdminLayout::message('UIF Theme Kit','Security token expired.',true),400);
            if (!(new RateLimiter($this->config->paths(),10,60))->consume('theme-kit:' . (string)$this->user['username'])) return Response::html('Too many theme generation attempts.',429);
            if ($draft === null) $definition = $request->input('definition');
            try {
                if (strlen($definition) > 65536) throw new \RuntimeException('Theme definition exceeds 64 KiB.');
                $decoded = $draft !== null ? (new \Batoi\Press\Application\ThemeDrafts($this->config))->forDownload($draft['id'],$request->input('approval_hash')) : json_decode($definition,true,32,JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || array_is_list($decoded)) throw new \RuntimeException('Provide one JSON theme object.');
                $kit = new UifThemeKit(); $decoded = $kit->validate($decoded);
                $dir = $this->config->paths()->dataPath('tmp/theme-kit');
                if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new \RuntimeException('Unable to prepare private theme staging.');
                $path = $dir . '/' . bin2hex(random_bytes(16)) . '.zip';
                $kit->writeZip($decoded,$path); chmod($path,0600);
                $report = (new ThemeCompatibilityInspector())->inspect($path,$decoded['slug'] . '.zip');
                if (!$report['installable']) throw new \RuntimeException('Generated theme did not pass ordinary compatibility inspection. Review the kit and bundled source before continuing.');
                $stream = fopen($path,'rb');
                if ($stream === false) throw new \RuntimeException('Unable to open generated package.');
                $size = filesize($path);
                // The open stream retains the bytes on supported Unix hosts; avoid accumulating download files.
                @unlink($path);
                $this->audit->record($this->user['username'],'theme.kit_generated',$decoded['slug'],'','success',['sha256'=>$report['archive_sha256'],'draft_id'=>$draft['id'] ?? null]);
                return new Response('',200,['Content-Type'=>'application/zip','Content-Disposition'=>'attachment; filename="' . $decoded['slug'] . '.zip"','Content-Length'=>(string)$size,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff'],[],new FileBody($stream,0,$size));
            } catch (\Throwable $failure) { $error = $failure instanceof \JsonException ? 'Provide valid UTF-8 JSON: ' . $failure->getMessage() : $failure->getMessage(); }
        } elseif ($request->method !== 'GET') return Response::html('Method not allowed.',405)->withHeader('Allow','GET, POST');
        $body = AdminLayout::pageHeader('Create a UIF theme','Generate a responsive theme from a portable JSON definition.',AdminLayout::buttonLink('Back to themes','/admin/themes','back',true));
        if ($error !== '') $body .= '<p class="bp-error" role="alert">' . AdminLayout::e($error) . '</p>';
        if ($draft !== null) $body .= '<section class="bp-editor-panel"><h2>Review AI theme draft</h2><p>State: <strong>' . AdminLayout::e($draft['state']) . '</strong>. This definition has not been installed or activated. Download binds to this exact definition and bundled source revision. Upload the package inactive in Themes, then review all layouts and devices before activation.</p><p>Source revision: <code>' . AdminLayout::e($draft['source_revision']) . '</code></p></section>';
        $identity = $draft === null ? '' : '<input type="hidden" name="draft" value="' . AdminLayout::e($draft['id']) . '"><input type="hidden" name="approval_hash" value="' . AdminLayout::e($draft['approval_hash']) . '">';
        $body .= '<ol><li>Download the starter and schema. Give them to Claude, Gemini, ChatGPT or another tool.</li><li>Ask it to change the approved tokens, preset and introduction sections, returning JSON only. Compatible MCP/API clients can create a private review link using explicit theme scopes.</li><li>Review the definition below and download the validated theme.</li><li>Inspect and upload the ZIP in Themes. Preview every layout before activating it.</li></ol><p>' . AdminLayout::buttonLink('Download starter','/admin/themes/uif-kit?download=starter','download',true) . ' ' . AdminLayout::buttonLink('Download schema','/admin/themes/uif-kit?download=schema','download',true) . '</p><form method="post" action="/admin/themes/uif-kit" class="bp-form">' . $this->csrf->field() . $identity . '<label>Theme definition (JSON)<textarea name="definition" rows="24" maxlength="65536" spellcheck="false" required' . ($draft !== null ? ' readonly' : '') . '>' . AdminLayout::e($definition) . '</textarea></label><button type="submit">Download validated theme ZIP</button></form><p>The kit accepts data only. Executable templates and UIF assets come from the bundled implementation. Use governed Forms blocks for contact forms. Drafts expire within 24 hours or with their connection, and never change the active theme. Mobile and desktop refer to responsive browser layouts.</p>';
        return Response::html(AdminLayout::render('UIF Theme Kit',$body),$error !== '' ? 422 : 200)->withHeader('Cache-Control','private, no-store');
    }
}
