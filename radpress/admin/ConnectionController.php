<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\AccessTokenRepository;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\MfaRepository;
use Batoi\Press\Security\Password;
use Batoi\Press\Security\RateLimiter;
use DateTimeImmutable;
use InvalidArgumentException;

final class ConnectionController
{
    private const ISSUABLE_SCOPES = [
        'site:read' => 'Read site identity, configuration, and navigation.',
        'content:read' => 'Read and search pages and posts, including drafts.',
        'content:write' => 'Create and update drafts. Cannot publish content.',
        'content:publish' => 'Publish content through a separate explicit operation.',
        'media:read' => 'Reserved for the governed media read interface.',
        'audit:read' => 'Reserved for a future bounded audit reporting interface.',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly AccessTokenRepository $tokens,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function index(string $notice = '', string $error = '', string $oneTimeToken = '', int $status = 200): Response
    {
        $tokens = $this->tokens->all();
        $active = count(array_filter($tokens, static fn (array $token): bool => ($token['active'] ?? false) === true));
        $body = AdminLayout::pageHeader(
            'Connections',
            'Issue and revoke scoped machine credentials for the JSON API and MCP.',
            '<span class="bp-status-badge is-published">Owner only</span>'
        );
        if ($notice !== '') {
            $body .= '<div class="bp-notice" role="status">' . $this->e($notice) . '</div>';
        }
        if ($error !== '') {
            $body .= '<div class="bp-error" role="alert">' . $this->e($error) . '</div>';
        }
        if ($oneTimeToken !== '') {
            $body .= '<section class="bp-admin-section bp-connection-secret" aria-labelledby="connection-secret-title"><header><div><h2 id="connection-secret-title">Copy this token now</h2><p>It will not be shown again. Store it in the client secret manager, then leave this page.</p></div></header><label>Bearer token<input type="text" readonly value="' . $this->e($oneTimeToken) . '" autocomplete="off" spellcheck="false" data-bp-one-time-token></label></section>';
        }
        $body .= '<dl class="bp-admin-stats bp-admin-stats-compact">'
            . AdminLayout::statCard('Active tokens', (string)$active, 'Credentials currently accepted by machine interfaces.')
            . AdminLayout::statCard('Revoked or expired', (string)(count($tokens) - $active), 'Credentials retained as safe metadata for governance.')
            . AdminLayout::statCard('OAuth provider', $this->oauthConfigured() ? 'Configured' : 'Not configured', $this->oauthConfigured() ? 'Remote connector discovery is available.' : 'Personal tokens are available for controlled clients.')
            . AdminLayout::statCard('Write tools', 'Governed', 'Draft writes require revisions; publishing has a separate scope.')
            . '</dl>';
        $body .= '<div class="bp-admin-editor"><div class="bp-editor-main">' . $this->tokenList($tokens) . '</div><aside class="bp-editor-side">' . $this->issueForm() . $this->securityGuide() . '</aside></div>';
        return Response::html($this->layout('Connections', $body), $status)->withHeader('Cache-Control', 'private, no-store');
    }

    public function issue(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return $this->index('', 'Security token expired. Reload the page and try again.', '', 400);
        }
        $reauthentication = $this->reauthenticate($request);
        if ($reauthentication !== 'ok') {
            $this->audit->record($this->username(), 'machine.token.issue_blocked', 'new-token', $this->ip($request), 'blocked', ['reason' => 'reauthentication_failed']);
            $status = $reauthentication === 'limited' ? 429 : 403;
            $message = $reauthentication === 'limited' ? 'Too many reauthentication attempts. Try again later.' : 'Current password verification failed.';
            return Response::html($this->layout('Connections', '<p class="bp-error">' . $this->e($message) . '</p><p><a href="/admin/connections">Back to Connections</a></p>'), $status)->withHeader('Cache-Control', 'private, no-store');
        }

        $scopes = isset($request->post['scopes']) && is_array($request->post['scopes']) ? array_values($request->post['scopes']) : [];
        $scopes = array_values(array_intersect(array_keys(self::ISSUABLE_SCOPES), array_map('strval', $scopes)));
        $days = (int)$request->input('expires_days', '30');
        if (!in_array($days, [7, 30, 90, 365], true)) {
            $days = 30;
        }
        try {
            $issued = $this->tokens->issue(
                $request->input('name'),
                $scopes,
                $this->username(),
                new DateTimeImmutable('+' . $days . ' days')
            );
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->layout('Connections', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p><a href="/admin/connections">Back to Connections</a></p>'), 422)->withHeader('Cache-Control', 'private, no-store');
        }

        $access = (array)($issued['access'] ?? []);
        $this->audit->record($this->username(), 'machine.token.issued', (string)($access['id'] ?? 'token'), $this->ip($request), 'success', [
            'name' => (string)($access['name'] ?? ''),
            'scopes' => (array)($access['scopes'] ?? []),
            'expires_at' => (string)($access['expires_at'] ?? ''),
        ]);
        return $this->index('Connection token issued.', '', (string)($issued['token'] ?? ''));
    }

    public function revoke(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return Response::html($this->layout('Connections', '<p class="bp-error">Security token expired.</p>'), 400);
        }
        $reauthentication = $this->reauthenticate($request);
        if ($reauthentication !== 'ok') {
            $this->audit->record($this->username(), 'machine.token.revoke_blocked', $request->input('token_id'), $this->ip($request), 'blocked', ['reason' => 'reauthentication_failed']);
            $status = $reauthentication === 'limited' ? 429 : 403;
            $message = $reauthentication === 'limited' ? 'Too many reauthentication attempts. Try again later.' : 'Current password verification failed.';
            return Response::html($this->layout('Connections', '<p class="bp-error">' . $this->e($message) . '</p><p><a href="/admin/connections">Back to Connections</a></p>'), $status);
        }
        $id = $request->input('token_id');
        if (!$this->tokens->revoke($id)) {
            return Response::html($this->layout('Connections', '<p class="bp-error">The token was not found or was already inactive.</p><p><a href="/admin/connections">Back to Connections</a></p>'), 404);
        }
        $this->audit->record($this->username(), 'machine.token.revoked', $id, $this->ip($request));
        return Response::redirect('/admin/connections');
    }

    private function tokenList(array $tokens): string
    {
        $html = '<section class="bp-admin-section"><header><div><h2>Machine credentials</h2><p>Review scope, issuer, expiry, and status. Secret values are never stored or listed.</p></div></header>';
        if ($tokens === []) {
            return $html . '<div class="bp-empty-state"><h3>No connection tokens</h3><p>Issue a narrowly scoped token when a trusted client is ready to connect.</p></div></section>';
        }
        $html .= '<div class="bp-table-wrap"><table class="bp-table"><thead><tr><th>Connection</th><th>Scopes</th><th>Issued</th><th>Expiry</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($tokens as $token) {
            $active = ($token['active'] ?? false) === true;
            $id = (string)($token['id'] ?? '');
            $html .= '<tr><td><strong>' . $this->e((string)($token['name'] ?? 'Unnamed')) . '</strong><small><code>' . $this->e($id) . '</code> · by ' . $this->e((string)($token['issued_by'] ?? 'unknown')) . '</small></td>';
            $html .= '<td><div class="bp-token-scopes">' . implode('', array_map(fn (mixed $scope): string => '<span class="bp-status-badge">' . $this->e((string)$scope) . '</span>', (array)($token['scopes'] ?? []))) . '</div></td>';
            $html .= '<td>' . $this->date((string)($token['created_at'] ?? '')) . '</td><td>' . $this->date((string)($token['expires_at'] ?? '')) . '</td>';
            $html .= '<td><span class="bp-status-badge ' . ($active ? 'is-published' : 'is-draft') . '">' . ($active ? 'Active' : 'Inactive') . '</span></td><td>';
            if ($active) {
                $html .= '<details class="bp-details"><summary>Revoke</summary><form method="post" action="/admin/connections/revoke" class="bp-form bp-compact-form">' . $this->csrf->field() . '<input type="hidden" name="token_id" value="' . $this->e($id) . '"><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>' . $this->mfaField() . '<button type="submit" class="bp-button bp-button-danger">Revoke token</button></form></details>';
            } else {
                $html .= '<span class="bp-muted">No action</span>';
            }
            $html .= '</td></tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    private function issueForm(): string
    {
        $scopeFields = '';
        foreach (self::ISSUABLE_SCOPES as $scope => $description) {
            $scopeFields .= '<label class="bp-check-option"><input type="checkbox" name="scopes[]" value="' . $this->e($scope) . '"><span><strong>' . $this->e($scope) . '</strong><small>' . $this->e($description) . '</small></span></label>';
        }
        return '<section class="bp-editor-panel"><header><h2>Issue connection token</h2><p>Create one credential per client so it can be revoked independently.</p></header><form method="post" action="/admin/connections/issue" class="bp-form">' . $this->csrf->field()
            . '<label>Connection name<input type="text" name="name" required maxlength="80" placeholder="Claude editorial review"></label>'
            . '<fieldset><legend>Scopes</legend><div class="bp-check-stack">' . $scopeFields . '</div></fieldset>'
            . '<label>Expires after<select name="expires_days"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option><option value="365">1 year</option></select></label>'
            . '<label>Current password<input type="password" name="current_password" required autocomplete="current-password"><span class="bp-field-help">Required immediately before issuing a credential.</span></label>'
            . $this->mfaField()
            . '<button type="submit">Issue one-time token</button></form></section>';
    }

    private function securityGuide(): string
    {
        return '<section class="bp-editor-panel"><header><h2>Connection policy</h2><p>Keep external access narrow and attributable.</p></header><ul class="bp-admin-checklist"><li>' . AdminLayout::icon('shield') . '<span>Use HTTPS and one token per client or automation.</span></li><li>' . AdminLayout::icon('shield') . '<span>Grant <code>content:write</code> for drafts without granting publication.</span></li><li>' . AdminLayout::icon('shield') . '<span>Grant <code>content:publish</code> only to clients approved to make content public.</span></li><li>' . AdminLayout::icon('shield') . '<span>Revoke tokens immediately when a device, client, or operator changes.</span></li></ul></section>';
    }

    private function oauthConfigured(): bool
    {
        $oauth = is_array($this->config->security()['oauth'] ?? null) ? $this->config->security()['oauth'] : [];
        return ($oauth['enabled'] ?? false) === true
            && trim((string)($oauth['issuer'] ?? '')) !== ''
            && (is_array($oauth['jwks'] ?? null) || trim((string)($oauth['jwks_uri'] ?? '')) !== '');
    }

    private function reauthenticate(Request $request): string
    {
        $limiter = new RateLimiter($this->config->paths(), 5, 300);
        $key = 'connection-reauth:' . $this->username() . ':' . $this->ip($request);
        if ($limiter->tooManyAttempts($key)) {
            return 'limited';
        }
        $password = $request->input('current_password');
        $hash = (string)($this->user['password_hash'] ?? '');
        if ($password !== '' && $hash !== '' && Password::verify($password, $hash)) {
            if ($this->mfaEnabled() && (new MfaRepository($this->config->paths()))->verify($this->username(), $request->input('mfa_code')) === null) {
                $limiter->hit($key);
                return 'invalid';
            }
            $limiter->clear($key);
            return 'ok';
        }
        $limiter->hit($key);
        return 'invalid';
    }

    private function mfaEnabled(): bool
    {
        return (new MfaRepository($this->config->paths()))->enabled($this->user);
    }

    private function mfaField(): string
    {
        return $this->mfaEnabled() ? '<label>Authenticator or recovery code<input type="text" name="mfa_code" required maxlength="12" autocomplete="one-time-code"><span class="bp-field-help">Required because two-factor authentication is enabled.</span></label>' : '';
    }

    private function username(): string
    {
        return (string)($this->user['username'] ?? 'owner');
    }

    private function ip(Request $request): string
    {
        return (string)($request->server['REMOTE_ADDR'] ?? '');
    }

    private function date(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? '—' : date('Y-m-d H:i', $timestamp);
    }

    private function layout(string $title, string $body): string
    {
        return AdminLayout::render($title, $body);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
