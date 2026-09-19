<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Application\ContentMutationException;
use Batoi\Press\Application\ContentMutationService;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\MfaRepository;
use Batoi\Press\Security\Password;
use Batoi\Press\Security\RateLimiter;

final class ProposalController
{
    public function __construct(private readonly Config $config, private readonly ContentMutationService $mutations, private readonly Csrf $csrf, private readonly array $user)
    {
    }

    public function index(): Response
    {
        $body = AdminLayout::pageHeader('Proposed changes', 'Review client-prepared content changes before they affect the website.', '');
        $proposals = $this->mutations->proposals();
        if ($proposals === []) {
            return $this->response($body . '<section class="bp-admin-section"><h2>No proposed changes</h2><p>Changes prepared by a connected client will appear here for review. Nothing is waiting for approval.</p><p>Draft edits do not enter this queue. Publication and other public-impact changes require a proposal and explicit review in Press.</p></section>');
        }
        $body .= '<div class="bp-table-wrap"><table class="bp-table"><thead><tr><th>Content</th><th>Requested action</th><th>Administrator</th><th>Status</th><th>Review</th></tr></thead><tbody>';
        foreach ($proposals as $proposal) {
            if (!empty($proposal['unavailable'])) {
                $body .= '<tr><td><code>' . $this->e($proposal['id']) . '</code></td><td colspan="4">Unavailable: the record could not be verified for this installation. Preserve it and ask an installation operator to inspect storage or site-migration history. No review action is available.</td></tr>';
                continue;
            }
            $body .= '<tr><td>' . $this->e($proposal['type'] . ' · ' . $proposal['target_id']) . '</td><td>' . $this->e($proposal['action']) . '</td><td>' . $this->e($proposal['principal']) . '</td><td>' . $this->e($proposal['state']) . '</td><td><a href="/admin/proposals/' . $this->e($proposal['id']) . '">Review changes</a></td></tr>';
        }
        return $this->response($body . '</tbody></table></div><p>Showing up to 100 proposals, with unavailable records first and verified records newest first. Pending proposals expire within 24 hours or when the originating credential expires.</p>');
    }

    public function show(string $id): Response
    {
        try {
            $proposal = $this->mutations->proposal($id);
        } catch (ContentMutationException $error) {
            return $this->response('<p class="bp-error">' . $this->e($error->getMessage()) . '</p>', $error->httpStatus());
        }
        $body = AdminLayout::pageHeader('Review proposed changes', 'No submitted content is executed in this preview. Approval applies only this exact proposal.', '<a class="bp-button" href="/admin/proposals">All proposals</a>');
        if ($proposal['type'] === 'media') {
            $body .= '<p>Library metadata does not rewrite existing page/post embeds. Approved uploads become public at a new URL; they are not inserted into content automatically.</p>';
            if ($proposal['action'] === 'upload' && $proposal['state'] === 'pending' && $proposal['expires_at'] > time() && in_array(\Batoi\Press\Security\AdminAccess::role($this->user), ['owner', 'admin'], true)) {
                $body .= '<p><a href="/admin/proposals/' . $this->e($id) . '/media-preview" target="_blank" rel="noopener">Inspect staged file privately</a> before approving. Treat its content as untrusted data.</p>';
            }
        }
        $body .= '<section class="bp-admin-section"><p>Site: <strong>' . $this->e($proposal['site']) . '</strong><br>Requested by: ' . $this->e($proposal['principal']) . ' · Connection: <code>' . $this->e($proposal['connection_id']) . '</code><br>Action: ' . $this->e($proposal['action']) . ' · State: ' . $this->e($proposal['state']) . '<br>Expires: ' . $this->e(date(DATE_ATOM, $proposal['expires_at'])) . '</p><p>URL: <code>' . $this->e($proposal['before_url']) . '</code> → <code>' . $this->e($proposal['after_url']) . '</code>. URL changes do not create redirects automatically and may affect child links.</p></section>';
        if (!empty($proposal['affected_routes'])) {
            $body .= '<section class="bp-admin-section"><h2>Affected child URLs</h2><p>These descendant routes change with the parent. No redirects are created automatically.</p><div class="bp-table-wrap"><table class="bp-table"><thead><tr><th>Current URL</th><th>Proposed URL</th></tr></thead><tbody>';
            foreach ($proposal['affected_routes'] as $route) $body .= '<tr><td><code>' . $this->e($route['before_url']) . '</code></td><td><code>' . $this->e($route['after_url']) . '</code></td></tr>';
            $body .= '</tbody></table></div></section>';
        }
        $body .= '<div class="bp-table-wrap"><table class="bp-table"><thead><tr><th>Field</th><th>Current at proposal creation</th><th>Proposed value</th></tr></thead><tbody>';
        foreach (array_unique(array_merge(array_keys($proposal['before']), array_keys($proposal['after']))) as $field) {
            $before = $proposal['before'][$field] ?? null;
            $after = $proposal['after'][$field] ?? null;
            if ($before === $after) continue;
            $body .= '<tr><th>' . $this->e($field) . '</th><td><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . $this->e($this->display($before)) . '</pre></td><td><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . $this->e($this->display($after)) . '</pre></td></tr>';
        }
        $body .= '</tbody></table></div>';
        if ($proposal['state'] === 'pending' || $proposal['state'] === 'applying') {
            $body .= '<form class="bp-form bp-editor-panel" method="post" action="/admin/proposals/' . $this->e($id) . '/review">' . $this->csrf->field() . '<input type="hidden" name="approval_hash" value="' . $this->e($proposal['approval_hash']) . '"><p>Approval rechecks the live revision, your permissions, and the originating connection. A conflict requires a new proposal.</p><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>';
            if ((new MfaRepository($this->config->paths()))->enabled($this->user)) $body .= '<label>Authenticator or recovery code<input name="mfa_code" required autocomplete="one-time-code" maxlength="12"></label>';
            $body .= $proposal['state'] === 'applying'
                ? '<div class="bp-form-actions"><button name="decision" value="reconcile">Reconcile receipt (owner/admin only)</button></div></form>'
                : '<div class="bp-form-actions"><button name="decision" value="reject" class="bp-button bp-button-secondary">Reject</button>' . ($proposal['expires_at'] > time() ? '<button name="decision" value="approve">Approve and apply changes</button>' : '') . '</div></form>';
        }
        if ($proposal['state'] === 'applying') $body .= '<p class="bp-error">Application has no completed approval receipt. Reconciliation uses the storage journal to determine whether it committed or rolled back. It never reapplies the proposal and stops if unexpected external edits are found.</p>';
        return $this->response($body);
    }

    public function mediaPreview(string $id): Response
    {
        $current = (new MfaRepository($this->config->paths()))->findUser((string)($this->user['username'] ?? ''));
        if ($current === null || !in_array(\Batoi\Press\Security\AdminAccess::role($current), ['owner', 'admin'], true) || !empty($current['disabled_at']) || ($current['status'] ?? '') === 'disabled') return $this->response('<p>Administrator access is required.</p>', 403);
        try {
            $proposal = $this->mutations->proposal($id);
            if ($proposal['type'] !== 'media' || $proposal['action'] !== 'upload' || $proposal['state'] !== 'pending' || $proposal['expires_at'] <= time()) return $this->response('<p>Private media preview is unavailable.</p>', 404);
            $file = $this->mutations->mediaRepository()->stagedFile($id, $proposal['after'], (array)($this->config->security()['uploads'] ?? []));
            return new Response($file['bytes'], 200, ['Content-Type' => $file['mime_type'], 'Content-Length' => (string)$file['size'], 'Content-Disposition' => 'inline; filename="' . $proposal['after']['name'] . '"', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
        } catch (ContentMutationException $error) { return $this->response('<p>Private media preview is unavailable.</p>', $error->httpStatus()); }
        catch (\RuntimeException|\InvalidArgumentException) { return $this->response('<p>Private media preview is unavailable or requires recovery.</p>', 503); }
    }

    public function review(string $id, Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) return $this->response('<p class="bp-error">Security token expired.</p>', 400);
        if (!in_array($request->input('decision'), ['approve', 'reject', 'reconcile'], true)) return $this->response('<p class="bp-error">Invalid decision.</p>', 422);
        $username = (string)($this->user['username'] ?? '');
        $limiter = new RateLimiter($this->config->paths(), 5, 300);
        $key = 'proposal-review:' . $username . ':' . (string)($request->server['REMOTE_ADDR'] ?? '');
        if ($limiter->tooManyAttempts($key)) return $this->response('<p class="bp-error">Too many attempts. Try again later.</p>', 429);
        $current = (new MfaRepository($this->config->paths(), new FileStore()))->findUser($username);
        $mfa = new MfaRepository($this->config->paths());
        if ($current === null || !Password::verify($request->input('current_password'), (string)($current['password_hash'] ?? ''))
            || ($mfa->enabled($current) && $mfa->verify($username, $request->input('mfa_code')) === null)) {
            $limiter->hit($key);
            return $this->response('<p class="bp-error">Password and any required second factor must be verified.</p>', 403);
        }
        $limiter->clear($key);
        try {
            if ($request->input('decision') === 'reconcile') {
                $this->mutations->reconcileProposal($id, $request->input('approval_hash'), $username);
            } else {
                $this->mutations->reviewProposal($id, $request->input('approval_hash'), $username, $request->input('decision') === 'approve');
            }
            return Response::redirect('/admin/proposals/' . rawurlencode($id))->withHeader('Cache-Control', 'private, no-store');
        } catch (ContentMutationException $error) {
            return $this->response('<p class="bp-error">' . $this->e($error->getMessage()) . '</p><p><a href="/admin/proposals">Return to proposals</a></p>', $error->httpStatus());
        }
    }

    private function display(mixed $value): string
    {
        return is_string($value) ? $value : (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function response(string $body, int $status = 200): Response
    {
        return Response::html(AdminLayout::render('Proposed changes', $body), $status)->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
