<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\AccessTokenRepository;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\MfaRepository;
use Batoi\Press\Security\Password;
use Batoi\Press\Security\RateLimiter;
use Batoi\Press\Security\MachineAccessPolicy;
use Batoi\Press\Security\OAuthBindingRepository;
use DateTimeImmutable;
use InvalidArgumentException;

final class ConnectionController
{
    private const ISSUABLE_SCOPES = [
        'site:read' => 'Read site identity, public configuration, navigation, and widgets.',
        'site:write' => 'Propose navigation, widgets and public settings for administrator approval; also grant site:read.',
        'content:read' => 'Read and search pages and posts, including drafts.',
        'content:write' => 'Create and update drafts. Cannot publish content.',
        'content:publish' => 'Request publication for administrator approval in Press. Cannot publish unattended.',
        'media:read' => 'Read the media library and metadata without access to page/post drafts.',
        'media:write' => 'Propose bounded image/text uploads and metadata changes for administrator approval; also grant media:read.',
        'audit:read' => 'Read bounded recent activity reports without raw audit details (administrators only).',
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
        $policy = new MachineAccessPolicy($this->config->paths());
        foreach ($tokens as &$token) {
            $effective = $policy->resolve($token);
            $token['policy_allowed'] = $effective !== null;
            $token['effective_scopes'] = $effective['scopes'] ?? [];
        }
        unset($token);
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
            . AdminLayout::statCard('Unexpired tokens', (string)$active, 'Acceptance also requires an active administrator and current policy grants.')
            . AdminLayout::statCard('Revoked or expired', (string)(count($tokens) - $active), 'Credentials retained as safe metadata for governance.')
            . AdminLayout::statCard('OAuth provider', $this->oauthConfigured() ? 'Configured' : 'Not configured', $this->oauthConfigured() ? 'Remote connector discovery is available.' : 'Personal tokens are available for controlled clients.')
            . AdminLayout::statCard('Write tools', 'Governed', 'Draft writes require revisions; publishing has a separate scope.')
            . '</dl>';
        $body .= '<div class="bp-admin-editor"><div class="bp-editor-main">' . $this->tokenList($tokens) . $this->oauthBindings() . $this->clientGuide() . '</div><aside class="bp-editor-side">' . $this->issueForm() . $this->securityGuide() . '</aside></div>';
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
        $profiles = [
            'read' => ['site:read', 'content:read'],
            'editor' => ['site:read', 'content:read', 'content:write'],
            'publisher' => ['site:read', 'content:read', 'content:write', 'content:publish'],
        ];
        $profile = $request->input('profile', 'custom');
        if ($profile !== 'custom' && !isset($profiles[$profile])) return $this->index('', 'Unknown permission profile.', '', 422);
        if (isset($profiles[$profile])) $scopes = $profiles[$profile];
        $days = (int)$request->input('expires_days', '30');
        if (!in_array($days, [7, 30, 90, 365], true)) {
            $days = 30;
        }
        try {
            $principal = $request->input('principal', $this->username());
            $effective = (new MachineAccessPolicy($this->config->paths()))->resolve([
                'principal_username' => $principal, 'scopes' => $scopes, 'created_at' => date(DATE_ATOM),
            ]);
            if ($effective === null || array_diff($scopes, $effective['scopes']) !== []) {
                throw new InvalidArgumentException('Choose an active administrator and scopes allowed by their current role and site policy.');
            }
            $issued = $this->tokens->issue(
                $request->input('name'),
                $scopes,
                $this->username(),
                new DateTimeImmutable('+' . $days . ' days'),
                $principal
            );
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->layout('Connections', '<p class="bp-error">' . $this->e($exception->getMessage()) . '</p><p><a href="/admin/connections">Back to Connections</a></p>'), 422)->withHeader('Cache-Control', 'private, no-store');
        }

        $access = (array)($issued['access'] ?? []);
        $this->audit->record($this->username(), 'machine.token.issued', (string)($access['id'] ?? 'token'), $this->ip($request), 'success', [
            'name' => (string)($access['name'] ?? ''),
            'scopes' => (array)($access['scopes'] ?? []),
            'principal' => (string)($access['principal_username'] ?? ''),
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

    public function rotate(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) {
            return $this->index('', 'Security token expired.', '', 400);
        }
        $verified = $this->reauthenticate($request);
        if ($verified !== 'ok') {
            $this->audit->record($this->username(), 'machine.token.rotate_blocked', $request->input('token_id'), $this->ip($request), 'blocked');
            return $this->index('', 'Current password and any required second factor must be verified.', '', $verified === 'limited' ? 429 : 403);
        }
        $issued = $this->tokens->rotate($request->input('token_id'));
        if ($issued === null) return $this->index('', 'Only an unexpired, unrevoked token can be rotated.', '', 404);
        $this->audit->record($this->username(), 'machine.token.rotated', $request->input('token_id'), $this->ip($request));
        return $this->index('Token rotated. The previous secret is invalid immediately; update the client secret store.', '', $issued['token']);
    }

    private function tokenList(array $tokens): string
    {
        $html = '<section class="bp-admin-section"><header><div><h2>Machine credentials</h2><p>Review scope, issuer, expiry, and status. Secret values are never stored or listed.</p></div></header>';
        if ($tokens === []) {
            return $html . '<div class="bp-empty-state"><h3>No connection tokens</h3><p>Issue a narrowly scoped token when a trusted client is ready to connect.</p></div></section>';
        }
        $legacyCount = count(array_filter($tokens, static fn (array $token): bool => ($token['legacy_identity'] ?? false) === true && ($token['active'] ?? false) === true));
        if ($legacyCount > 0) {
            $html .= '<p class="bp-notice" role="status">' . $legacyCount . ' active legacy credential(s) use issuer-based identity mapping. Review the principal and effective scopes, issue an account-bound replacement, verify the client, then revoke the old credential. Secret rotation alone does not migrate identity. Resolve pending proposals before revoking their connection.</p>';
        }
        $html .= '<div class="bp-table-wrap"><table class="bp-table"><thead><tr><th>Connection</th><th>Scopes</th><th>Issued</th><th>Expiry</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($tokens as $token) {
            $active = ($token['active'] ?? false) === true;
            $id = (string)($token['id'] ?? '');
            $html .= '<tr><td><strong>' . $this->e((string)($token['name'] ?? 'Unnamed')) . '</strong><small><code>' . $this->e($id) . '</code> · issued by ' . $this->e((string)($token['issued_by'] ?? 'unknown')) . '</small><small>Principal: ' . $this->e((string)($token['principal_username'] ?? '')) . '</small></td>';
            $identity = ($token['legacy_identity'] ?? false) ? 'Legacy issuer mapping — reissue to migrate' : 'Account-bound identity';
            $html .= '<td><div class="bp-token-scopes">' . implode('', array_map(fn (mixed $scope): string => '<span class="bp-status-badge">' . $this->e((string)$scope) . '</span>', (array)($token['scopes'] ?? []))) . '</div></td>';
            $html .= '<td>' . $this->date((string)($token['created_at'] ?? '')) . '</td><td>' . $this->date((string)($token['expires_at'] ?? '')) . '</td>';
            $label = !$active ? 'Inactive' : (($token['policy_allowed'] ?? false) ? 'Allowed' : 'Blocked by policy');
            $html .= '<td><span class="bp-status-badge ' . ($active ? 'is-published' : 'is-draft') . '">' . $label . '</span><small>' . $this->e($identity) . '</small><small>Last used: ' . $this->date((string)($token['last_used_at'] ?? '')) . '</small><small>Effective: ' . $this->e(implode(', ', $token['effective_scopes'] ?? [])) . '</small></td><td>';
            if ($active) {
                $html .= '<details class="bp-details"><summary>Revoke</summary><form method="post" action="/admin/connections/revoke" class="bp-form bp-compact-form">' . $this->csrf->field() . '<input type="hidden" name="token_id" value="' . $this->e($id) . '"><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>' . $this->mfaField() . '<button type="submit" class="bp-button bp-button-danger">Revoke token</button></form></details>';
                $html .= '<details class="bp-details"><summary>Rotate secret</summary><form method="post" action="/admin/connections/rotate" class="bp-form bp-compact-form">' . $this->csrf->field() . '<input type="hidden" name="token_id" value="' . $this->e($id) . '"><p>The old secret stops working immediately. Scope and expiry remain unchanged.</p><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>' . $this->mfaField() . '<button type="submit">Rotate token</button></form></details>';
            } else {
                $html .= '<span class="bp-muted">No action</span>';
            }
            $html .= '</td></tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    public function oauthChange(Request $request, bool $revoke): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) return $this->index('', 'Security token expired.', '', 400);
        $verified = $this->reauthenticate($request);
        if ($verified !== 'ok') {
            $this->audit->record($this->username(), 'machine.oauth.change_blocked', 'binding', $this->ip($request), 'blocked');
            return $this->index('', 'Current password and any required second factor must be verified.', '', $verified === 'limited' ? 429 : 403);
        }
        $bindings = new OAuthBindingRepository($this->config->paths());
        if ($revoke) {
            $id = $request->input('binding_id');
            if (!$bindings->revoke($id)) return $this->index('', 'Active OAuth connection not found.', '', 404);
            $this->audit->record($this->username(), 'machine.oauth.revoked', $id, $this->ip($request));
            return $this->index('OAuth access revoked locally. Revoke provider consent separately if needed.');
        }
        $profiles = ['read' => ['site:read', 'content:read'], 'editor' => ['site:read', 'content:read', 'content:write'], 'publisher' => ['site:read', 'content:read', 'content:write', 'content:publish']];
        $profile = $request->input('profile', 'read');
        if (!isset($profiles[$profile])) return $this->index('', 'Choose a supported profile.', '', 422);
        try {
            $record = $bindings->link($request->input('name'), $request->input('subject'), $request->input('client_id'), $request->input('principal'), $profiles[$profile], $this->username(), (int)$request->input('expires_days', '30'));
        } catch (InvalidArgumentException $error) {
            return $this->index('', $error->getMessage(), '', 422);
        }
        $this->audit->record($this->username(), 'machine.oauth.linked', $record['id'], $this->ip($request), 'success', ['principal' => $record['username'], 'scopes' => $record['scopes']]);
        return $this->index('OAuth identity linked. Complete OAuth login in the client; linking does not prove provider interoperability.');
    }

    private function oauthBindings(): string
    {
        $html = '<section class="bp-admin-section"><header><div><h2>OAuth account links</h2><p>Grant access to a verified provider subject and specific client. No provider passwords or tokens are stored here.</p></div></header>';
        $records = (new OAuthBindingRepository($this->config->paths()))->all();
        $policy = new MachineAccessPolicy($this->config->paths());
        if ($records !== []) {
            $html .= '<div class="bp-table-wrap"><table class="bp-table"><thead><tr><th>Connection</th><th>Principal / client</th><th>Access</th><th>Action</th></tr></thead><tbody>';
            foreach ($records as $record) {
                $effective = $policy->connection($record['id']);
                $html .= '<tr><td>' . $this->e((string)($record['name'] ?? 'OAuth connection')) . '<small>' . $this->e((string)$record['id']) . '</small></td><td>' . $this->e((string)($record['username'] ?? '')) . '<small>' . $this->e((string)($record['client_id'] ?? 'Client identity missing')) . '</small></td><td>' . ($effective === null ? 'Blocked or revoked' : 'Allowed') . '<small>' . $this->e(implode(', ', $effective['scopes'] ?? [])) . '</small></td><td>';
                if ($record['migration_required'] ?? false) $html .= '<p>Re-link with the verified client ID.</p>';
                if ($record['enabled'] ?? false) {
                    $html .= '<details><summary>Revoke local access</summary><form method="post" action="/admin/connections/oauth/revoke" class="bp-form">' . $this->csrf->field() . '<input type="hidden" name="binding_id" value="' . $this->e($record['id']) . '"><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>' . $this->mfaField() . '<button type="submit">Revoke OAuth access</button></form></details>';
                }
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        if (!$this->oauthConfigured()) return $html . '<p>Provider setup is required before linking. An installation operator must configure the trusted issuer, resource audience, and keys; this form does not create an authorization server.</p></section>';
        $html .= '<details><summary>Link administrator and client</summary><form method="post" action="/admin/connections/oauth/link" class="bp-form">' . $this->csrf->field()
            . '<p>Issuer: <code>' . $this->e((string)($this->config->security()['oauth']['issuer'] ?? '')) . '</code>. Obtain the exact subject and client ID from your trusted provider. Do not paste access tokens or link by email unless it is the provider’s actual immutable subject.</p>'
            . '<label>Name<input name="name" required maxlength="200"></label><label>Provider subject<input name="subject" required maxlength="200" autocomplete="off"></label><label>OAuth client ID<input name="client_id" required maxlength="200" autocomplete="off"></label>'
            . '<label>Local administrator<select name="principal">' . $this->principalOptions() . '</select></label>'
            . '<label>Profile<select name="profile"><option value="read">Read only (includes drafts)</option><option value="editor">Draft editor</option><option value="publisher">Publisher with Press review</option></select></label>'
            . '<label>Grant expires after<select name="expires_days"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option></select></label>'
            . '<label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>' . $this->mfaField()
            . '<p>Linking replaces any active grant for this subject/client; its previous proposals cannot use the new grant.</p><button type="submit">Link OAuth account</button></form></details></section>';
        return $html;
    }

    private function issueForm(): string
    {
        $scopeFields = '';
        foreach (self::ISSUABLE_SCOPES as $scope => $description) {
            $scopeFields .= '<label class="bp-check-option"><input type="checkbox" name="scopes[]" value="' . $this->e($scope) . '"><span><strong>' . $this->e($scope) . '</strong><small>' . $this->e($description) . '</small></span></label>';
        }
        return '<section class="bp-editor-panel"><header><h2>Issue connection token</h2><p>Create one credential per client so it can be revoked independently.</p></header><form method="post" action="/admin/connections/issue" class="bp-form">' . $this->csrf->field()
            . '<label>Connection name<input type="text" name="name" required maxlength="80" placeholder="Claude editorial review"></label>'
            . '<label>Local administrator<select name="principal">' . $this->principalOptions() . '</select><span class="bp-field-help">Every request uses this account’s current permissions, not the issuer’s role.</span></label>'
            . '<label>Permission profile<select name="profile"><option value="read" selected>Read only (includes drafts)</option><option value="editor">Draft editor</option><option value="publisher">Publisher (approval required)</option><option value="custom">Custom scopes below</option></select></label>'
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

    private function principalOptions(): string
    {
        $path = $this->config->paths()->configPath('users.json');
        $users = is_file($path) ? (array)((new FileStore())->readJson($path)['users'] ?? []) : [];
        $html = '';
        foreach ($users as $user) {
            if (!is_array($user) || !in_array($user['role'] ?? '', ['owner', 'admin', 'editor'], true)
                || (string)($user['disabled_at'] ?? '') !== '' || ($user['status'] ?? '') === 'disabled') continue;
            $username = (string)($user['username'] ?? '');
            $html .= '<option value="' . $this->e($username) . '"' . ($username === $this->username() ? ' selected' : '') . '>' . $this->e($username . ' (' . $user['role'] . ')') . '</option>';
        }
        return $html;
    }

    private function clientGuide(): string
    {
        $base = rtrim((string)($this->config->site()['base_url'] ?? ''), '/');
        $parts = parse_url($base);
        $html = '<section class="bp-admin-section"><header><div><h2>Connect Claude or Codex</h2><p>Read-only is the recommended starting profile. Requested draft content will be shared with your chosen AI client.</p></div></header>';
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return $html . '<p class="bp-error">Configure a canonical HTTPS site URL without credentials, query, or fragment before connecting a remote client.</p></section>';
        }
        $endpoint = $base . '/mcp';
        $name = 'press_' . substr(hash('sha256', $endpoint), 0, 10);
        $variable = strtoupper($name) . '_TOKEN';
        $codex = '[mcp_servers.' . $name . "]\nurl = " . json_encode($endpoint, JSON_UNESCAPED_SLASHES) . "\nbearer_token_env_var = \"" . $variable . "\"\n";
        $claude = json_encode(['mcpServers' => [$name => ['type' => 'http', 'url' => $endpoint, 'headers' => ['Authorization' => 'Bearer ${' . $variable . '}']]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $html .= '<p>Site: <strong>' . $this->e((string)($this->config->site()['name'] ?? $base)) . '</strong><br>Endpoint: <code>' . $this->e($endpoint) . '</code></p>';
        $html .= '<ol><li>Issue a separate credential for this client and administrator.</li><li>Store the one-time secret securely and make it available as <code>' . $variable . '</code> in the client’s environment. Never paste it into a prompt, source file, or shell history. Desktop clients may not inherit terminal variables.</li><li>Add the secret-free configuration below. Reconnect, then ask for the site identity before any edits.</li></ol>';
        $html .= '<details><summary>Codex — config.toml</summary><pre><code>' . $this->e($codex) . '</code></pre><p><a href="https://developers.openai.com/codex/mcp" target="_blank" rel="noopener noreferrer">Official Codex MCP setup</a></p></details>';
        $html .= '<details><summary>Claude Code — .mcp.json</summary><pre><code>' . $this->e((string)$claude) . '</code></pre><p><a href="https://code.claude.com/docs/en/mcp" target="_blank" rel="noopener noreferrer">Official Claude Code MCP setup</a></p></details>';
        $html .= '<details><summary>OAuth and hosted Claude</summary><p>With a configured authorization provider and an explicit local account binding, use the same endpoint without the bearer-token setting. Complete the client’s OAuth login. Hosted Claude requires a publicly reachable HTTPS endpoint and organization permission; localhost is not a hosted acceptance environment. Provider configuration alone is not proof that client login works.</p></details>';
        $html .= '<p>Diagnose 401 by checking expiry, account status and identity binding; 403 by checking current effective scopes. Rotate a compromised secret, revoke unused connections, and keep each installation’s credential separate.</p></section>';
        return $html;
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
