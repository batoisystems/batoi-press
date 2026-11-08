<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Application\{ContentMutationException, ContentRevision, EditorRecovery};
use Batoi\Press\Core\{AuditLog, Config, Request, Response};
use Batoi\Press\Security\{Csrf, RateLimiter};

final class EditorRecoveryController
{
    public function __construct(private readonly Config $config, private readonly Csrf $csrf, private readonly AuditLog $audit, private readonly array $user) {}
    public function handle(Request $request): Response
    {
        $service = new EditorRecovery($this->config,$this->user);
        try {
            if ($request->path === '/admin/editor-recovery/save') {
                if ($request->method !== 'POST') return $this->json(['error'=>'POST required.'],405)->withHeader('Allow','POST');
                if (!$this->csrf->validate($request->header('X-CSRF-Token'))) return $this->json(['error'=>'Security token expired. Reload or copy your unsaved text before signing in again.'],400);
                if (strlen($request->rawBody) > 1200000 || (int)$request->header('Content-Length','0') > 1200000) return $this->json(['error'=>'Recovery copy exceeds its size limit.'],413);
                if (!(new RateLimiter($this->config->paths(),120,60))->consume('editor-recovery:' . $this->user['username'])) return $this->json(['error'=>'Recovery is temporarily rate limited. Your editor values remain available.'],429);
                $data = $request->json();
                if ($data === null || array_diff(array_keys($data),['type','identifier','branch','base_revision','recovery_revision','fields']) || !is_array($data['fields'] ?? null)) return $this->json(['error'=>'Invalid recovery request.'],422);
                foreach (['type','identifier','branch','base_revision','recovery_revision'] as $name) if (!is_string($data[$name] ?? null)) return $this->json(['error'=>'Invalid recovery request.'],422);
                $result = $service->save($data['type'],$data['identifier'],$data['branch'],$data['base_revision'],$data['recovery_revision'],$data['fields']);
                $this->audit->record($this->user['username'],'editor.recovery_saved',$data['type'] . ':' . ($data['identifier'] ?: 'new'),'','success',['request_id'=>$request->requestId]);
                return $this->json(['ok'=>true,'recovery'=>$result]);
            }
            if ($request->method !== 'GET') return $this->json(['error'=>'GET required.'],405)->withHeader('Allow','GET');
            if ($request->path === '/admin/editor-recovery/list') {
                $items = $service->listing($request->input('type'),$request->input('identifier'));
                if ($request->input('format') !== 'html') return $this->json(['items'=>$items]);
                $body = AdminLayout::pageHeader('Editor recovery','Private editing copies for your account. Opening a copy does not change published content.');
                if ($items === []) $body .= '<p>No recovery copies for this content.</p>';
                else {
                    $body .= '<ul class="bp-content-mini-list">';
                    foreach ($items as $item) $body .= '<li><div><strong>' . AdminLayout::e(gmdate('Y-m-d H:i:s',$item['updated_at'])) . ' UTC</strong><span>' . ($item['matches_source'] ? 'Source unchanged' : 'Source changed — compare before merging') . '</span></div>' . AdminLayout::buttonLink('Compare', '/admin/editor-recovery/' . $item['branch'],'edit',true) . '</li>';
                    $body .= '</ul>';
                }
                $body .= '<p>Recovery stores content, blocks and metadata. Publication settings and page CSS/JavaScript use the normal save workflow. Copies are encrypted, account-bound and subject to storage limits.</p>';
                return Response::html(AdminLayout::render('Editor recovery',$body))->withHeader('Cache-Control','private, no-store');
            }
            if (preg_match('#^/admin/editor-recovery/([a-f0-9]{32})$#D',$request->path,$match)) {
                $copy = $service->load($match[1]); $source = $service->source($copy['type'],$copy['identifier']);
                $snapshotSource = $source;
                if (isset($copy['source_body'])) { $snapshotSource ??= []; $snapshotSource['body'] = $copy['source_body']; }
                try { $candidate = EditorRecovery::values($snapshotSource,$copy['fields'],$copy['type']); }
                catch (ContentMutationException) { $candidate = ['body'=>json_encode($copy['fields']['body_text'] ?? [],JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]; }
                $editor = '/admin/' . $copy['type'] . 's/' . ($copy['identifier'] === '' ? 'new' : 'edit/' . rawurlencode($copy['identifier']));
                $restore = $editor . '?recovery=' . $copy['branch'];
                $actions = AdminLayout::buttonLink('Open current editor',$editor,'back',true);
                if ($copy['matches_source']) $actions = AdminLayout::buttonLink('Open recovery in editor',$restore,'edit') . $actions;
                $body = AdminLayout::pageHeader('Compare recovery','Review saved content and metadata before returning to the editor.',$actions);
                $body .= '<p role="status">' . ($copy['matches_source'] ? 'The source revision matches this copy. Opening it fills an editing form; use its save action to apply changes.' : 'The source changed. Both versions are retained. Copy the desired text into the current editor after reviewing the differences.') . '</p>';
                $body .= '<div class="bp-recovery-comparison">';
                foreach (['Current content'=>$source ?? [],'Recovery copy'=>$candidate] as $label => $record) {
                    $body .= '<section><h2>' . $label . '</h2><h3>' . AdminLayout::e((string)($record['title'] ?? 'Untitled')) . '</h3><pre tabindex="0">' . AdminLayout::e((string)($record['body'] ?? '')) . '</pre></section>';
                }
                $body .= '</div><details><summary>Recovered field values</summary><label>Copy content and metadata<textarea readonly rows="15">' . AdminLayout::e(json_encode($copy['fields'],JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . '</textarea></label></details>';
                return Response::html(AdminLayout::render('Compare recovery',$body))->withHeader('Cache-Control','private, no-store');
            }
            return $this->json(['error'=>'Recovery route not found.'],404);
        } catch (ContentMutationException $error) { return $this->json(['error'=>$error->getMessage(),'code'=>$error->errorCode()],$error->httpStatus()); }
        catch (\Throwable) { return $this->json(['error'=>'Recovery is unavailable. Keep this editor open and save or copy your content.','request_id'=>$request->requestId],503); }
    }
    private function json(array $data,int $status = 200): Response { return Response::json($data,$status,['Cache-Control'=>'private, no-store']); }
}
