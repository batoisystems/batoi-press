<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\Csrf;
use Batoi\Press\Security\MfaRepository;
use Batoi\Press\Security\Password;
use Batoi\Press\Security\RateLimiter;
use Batoi\Press\Security\SecretStore;
use Batoi\Press\Security\Session;
use Batoi\Press\Security\SessionRegistry;
use Batoi\Press\Security\Totp;
use RuntimeException;

final class SecurityController
{
    public function __construct(
        private readonly Config $config,
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly AuditLog $audit,
        private readonly array $user
    ) {
    }

    public function index(string $notice = '', string $error = '', int $status = 200): Response
    {
        $fresh = $this->mfa()->findUser($this->username()) ?? $this->user;
        $enabled = $this->mfa()->enabled($fresh);
        $recoveryCount = count((array)($fresh['mfa']['recovery_hashes'] ?? []));
        $session = is_array($this->config->security()['session'] ?? null) ? $this->config->security()['session'] : [];
        $headers = is_array($this->config->security()['headers'] ?? null) ? $this->config->security()['headers'] : [];
        $body = AdminLayout::pageHeader('Security', 'Protect this account, review session policy, and verify browser safeguards.');
        if ($notice !== '') $body .= '<p class="bp-notice" role="status">' . $this->e($notice) . '</p>';
        if ($error !== '') $body .= '<p class="bp-error" role="alert">' . $this->e($error) . '</p>';
        $body .= '<dl class="bp-admin-stats bp-admin-stats-compact">'
            . AdminLayout::statCard('Two-factor authentication', $enabled ? 'Enabled' : 'Not enabled', $enabled ? $recoveryCount . ' unused recovery codes.' : 'Use a TOTP authenticator to protect sign-in.')
            . AdminLayout::statCard('Idle timeout', $this->duration((int)($session['idle_seconds'] ?? 1800)), 'Inactive authenticated sessions sign out automatically.')
            . AdminLayout::statCard('Absolute timeout', $this->duration((int)($session['absolute_seconds'] ?? 43200)), 'Sessions cannot remain authenticated indefinitely.')
            . AdminLayout::statCard('CSP mode', (string)($headers['csp_mode'] ?? 'report-only'), 'Review report-only findings before switching to enforce mode.')
            . '</dl>';
        $body .= '<div class="bp-admin-editor"><div class="bp-editor-main">' . ($enabled ? $this->disableForm() : $this->enrollForm()) . '</div><aside class="bp-editor-side">';
        $body .= AdminLayout::section('Security boundary', '<ul class="bp-admin-checklist"><li>' . AdminLayout::icon('shield') . '<span>TOTP secrets are encrypted at rest with a host key.</span></li><li>' . AdminLayout::icon('shield') . '<span>Recovery codes are shown once and stored only as password hashes.</span></li><li>' . AdminLayout::icon('shield') . '<span>MFA is required after the password at login and for machine-credential changes.</span></li><li>' . AdminLayout::icon('shield') . '<span>Security changes are CSRF protected, throttled, reauthenticated, and audited.</span></li></ul>', 'Account security remains separate from publishing content.');
        $body .= '</aside></div>';
        $body .= $this->sessionInventory($enabled);
        $body .= $this->diagnostics();
        return Response::html(AdminLayout::render('Security', $body), $status)->withHeader('Cache-Control', 'private, no-store');
    }

    public function start(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) return $this->index('', 'Security token expired.', 400);
        if (!$this->passwordVerified($request)) return $this->index('', 'Current password verification failed.', 403);
        if ($this->mfa()->enabled($this->mfa()->findUser($this->username()) ?? [])) return $this->index('', 'Two-factor authentication is already enabled.', 409);
        $secret = Totp::generateSecret();
        $recovery = Totp::recoveryCodes();
        $store = new SecretStore($this->config->paths());
        $this->session->set('mfa_enrollment', [
            'expires_at' => time() + 600,
            'secret' => $store->encrypt($secret),
            'recovery' => $store->encrypt(json_encode($recovery, JSON_UNESCAPED_SLASHES) ?: '[]'),
        ]);
        $uri = Totp::provisioningUri($secret, $this->username(), (string)($this->config->site()['name'] ?? 'Batoi Press'));
        $body = AdminLayout::pageHeader('Set up two-factor authentication', 'Add the account to a TOTP authenticator, then confirm a current code.');
        $body .= '<section class="bp-editor-panel"><header><h2>Authenticator setup</h2><p>This setup expires in 10 minutes. The secret is not stored in plaintext.</p></header>';
        $body .= '<dl class="bp-meta-list"><div><dt>Account</dt><dd>' . $this->e($this->username()) . '</dd></div><div><dt>Manual key</dt><dd><code class="bp-secret-code">' . $this->e($secret) . '</code></dd></div><div><dt>Provisioning URI</dt><dd><code class="bp-secret-code">' . $this->e($uri) . '</code></dd></div></dl>';
        $body .= '<form method="post" action="/admin/security/mfa/confirm" class="bp-form">' . $this->csrf->field() . '<label>Current six-digit code<input type="text" name="mfa_code" required pattern="[0-9]{6}" maxlength="6" inputmode="numeric" autocomplete="one-time-code"></label>' . AdminLayout::submitButton('Confirm and Enable', 'shield') . '</form></section>';
        return Response::html(AdminLayout::render('Set up two-factor authentication', $body))->withHeader('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) return $this->index('', 'Security token expired.', 400);
        $pending = $this->session->get('mfa_enrollment');
        if (!is_array($pending) || (int)($pending['expires_at'] ?? 0) < time()) {
            $this->session->remove('mfa_enrollment');
            return $this->index('', 'Authenticator setup expired. Start again.', 409);
        }
        try {
            $store = new SecretStore($this->config->paths());
            $secret = $store->decrypt((string)$pending['secret']);
            $recovery = json_decode($store->decrypt((string)$pending['recovery']), true);
        } catch (RuntimeException) {
            return $this->index('', 'Unable to read the protected authenticator setup.', 500);
        }
        if (!Totp::verify($secret, $request->input('mfa_code'))) return $this->index('', 'Authenticator code is invalid or expired.', 422);
        $codes = is_array($recovery) ? array_values(array_map('strval', $recovery)) : [];
        $this->mfa()->enable($this->username(), $secret, $codes);
        $this->session->remove('mfa_enrollment');
        $this->audit->record($this->username(), 'security.mfa_enabled', $this->username(), $this->ip($request));
        $body = AdminLayout::pageHeader('Two-factor authentication enabled', 'Store these recovery codes now. They will not be shown again.');
        $body .= '<section class="bp-admin-section bp-connection-secret"><header><div><h2>One-time recovery codes</h2><p>Each code works once. Store them in a password manager separate from your authenticator.</p></div></header><pre><code>' . $this->e(implode("\n", $codes)) . '</code></pre><p>' . AdminLayout::buttonLink('Return to Security', '/admin/security', 'shield') . '</p></section>';
        return Response::html(AdminLayout::render('Two-factor authentication enabled', $body))->withHeader('Cache-Control', 'private, no-store');
    }

    public function disable(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) return $this->index('', 'Security token expired.', 400);
        if (!$this->passwordVerified($request)) return $this->index('', 'Current password verification failed.', 403);
        if ($this->mfa()->verify($this->username(), $request->input('mfa_code')) === null) return $this->index('', 'Authenticator or recovery code is invalid.', 403);
        $this->mfa()->disable($this->username());
        $this->audit->record($this->username(), 'security.mfa_disabled', $this->username(), $this->ip($request));
        return $this->index('Two-factor authentication was disabled.');
    }

    public function revokeSession(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('csrf_token'))) return $this->index('', 'Security token expired.', 400);
        if (!$this->passwordVerified($request)) return $this->index('', 'Current password verification failed.', 403);
        $fresh = $this->mfa()->findUser($this->username()) ?? $this->user;
        if ($this->mfa()->enabled($fresh) && $this->mfa()->verify($this->username(), $request->input('mfa_code')) === null) {
            return $this->index('', 'Authenticator or recovery code is invalid.', 403);
        }
        $hash = strtolower(trim($request->input('session_id')));
        if (hash_equals(hash('sha256', $this->session->id()), $hash)) return $this->index('', 'Use Log Out to end the current session.', 409);
        if (!$this->registry()->revoke($hash, $this->username())) return $this->index('', 'The selected session was not found.', 404);
        $this->audit->record($this->username(), 'security.session_revoked', substr($hash, 0, 16), $this->ip($request));
        return $this->index('The selected session was revoked.');
    }

    private function enrollForm(): string
    {
        return '<section class="bp-editor-panel"><header><h2>Enable two-factor authentication</h2><p>Use any standards-based TOTP authenticator. Current-password verification is required.</p></header><form method="post" action="/admin/security/mfa/start" class="bp-form">' . $this->csrf->field() . '<label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>' . AdminLayout::submitButton('Begin Setup', 'shield') . '</form></section>';
    }

    private function disableForm(): string
    {
        return '<section class="bp-editor-panel"><header><h2>Two-factor authentication is enabled</h2><p>Disabling it requires both the current password and a current authenticator or unused recovery code.</p></header><form method="post" action="/admin/security/mfa/disable" class="bp-form" data-confirm="Disable two-factor authentication for this account?">' . $this->csrf->field() . '<label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><label>Authenticator or recovery code<input type="text" name="mfa_code" required maxlength="12" autocomplete="one-time-code"></label><button type="submit" class="bp-button bp-button-danger">Disable Two-factor Authentication</button></form></section>';
    }

    private function sessionInventory(bool $mfaEnabled): string
    {
        $sessions = $this->registry()->allFor($this->username());
        if ($sessions === []) return AdminLayout::section('Active sessions', '<p class="bp-muted">No session inventory is available yet. The current session will appear after its next authenticated request.</p>', 'Session identifiers are stored only as hashes.');
        $current = hash('sha256', $this->session->id());
        $html = '<div class="bp-table-wrap"><table class="bp-table bp-content-table"><thead><tr><th>Session</th><th>Last active</th><th>Source</th><th>Action</th></tr></thead><tbody>';
        foreach ($sessions as $record) {
            $id = (string)($record['id'] ?? '');
            $isCurrent = hash_equals($current, $id);
            $source = trim((string)($record['ip'] ?? '')) . (!empty($record['user_agent']) ? '<small>' . $this->e($this->browserLabel((string)$record['user_agent'])) . '</small>' : '');
            $action = $isCurrent ? '<span class="bp-status-badge is-published">Current</span>' : '<form method="post" action="/admin/security/sessions/revoke" class="bp-form bp-compact-form">' . $this->csrf->field() . '<input type="hidden" name="session_id" value="' . $this->e($id) . '"><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>' . ($mfaEnabled ? '<label>MFA code<input type="text" name="mfa_code" required maxlength="12" autocomplete="one-time-code"></label>' : '') . '<button type="submit" class="bp-button bp-button-danger">Revoke</button></form>';
            $html .= '<tr><td><code>' . $this->e(substr($id, 0, 12)) . '</code>' . ($isCurrent ? '<small>This browser</small>' : '') . '</td><td>' . $this->e(date('M j, Y H:i', (int)($record['last_seen_at'] ?? 0))) . '</td><td>' . $this->e(trim((string)($record['ip'] ?? ''))) . (!empty($record['user_agent']) ? '<small>' . $this->e($this->browserLabel((string)$record['user_agent'])) . '</small>' : '') . '</td><td>' . $action . '</td></tr>';
        }
        return AdminLayout::section('Active sessions', $html . '</tbody></table></div>', 'Review recent sessions and revoke unfamiliar browsers. Revocation takes effect on their next request.');
    }

    private function diagnostics(): string
    {
        $update = $this->config->update();
        $securityDir = $this->config->paths()->dataPath('security');
        $checks = [
            ['Sodium cryptography', function_exists('sodium_crypto_sign_verify_detached'), 'Required for secrets, MFA, and signed updates.'],
            ['Signed updates', ($update['require_signed_packages'] ?? false) === true && (array)($update['release_public_keys'] ?? []) !== [], 'Stable update verification must fail closed.'],
            ['Private security storage', is_dir($securityDir) && !is_writable($securityDir) ? true : is_dir($securityDir), 'Runtime keys and revocation state remain outside public files.'],
            ['ZIP support', class_exists('ZipArchive'), 'Required to inspect and stage update packages.'],
            ['HTTPS request', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'Production admin sessions and remote MCP require HTTPS.'],
        ];
        $html = '<ul class="bp-admin-checklist">';
        foreach ($checks as [$label, $ok, $help]) {
            $html .= '<li><span class="bp-status-badge ' . ($ok ? 'is-published' : 'is-draft') . '">' . ($ok ? 'Ready' : 'Review') . '</span><span><strong>' . $this->e($label) . '</strong><small>' . $this->e($help) . '</small></span></li>';
        }
        return AdminLayout::section('Security diagnostics', $html . '</ul>', 'Operational readiness checks reveal no secrets and do not change configuration.');
    }

    private function registry(): SessionRegistry
    {
        return new SessionRegistry($this->config->paths());
    }

    private function browserLabel(string $userAgent): string
    {
        foreach (['Edg/' => 'Edge', 'Chrome/' => 'Chrome', 'Firefox/' => 'Firefox', 'Safari/' => 'Safari'] as $needle => $label) {
            if (str_contains($userAgent, $needle)) return $label;
        }
        return 'Browser or client';
    }

    private function passwordVerified(Request $request): bool
    {
        $limiter = new RateLimiter($this->config->paths(), 5, 300);
        $key = 'security-reauth:' . $this->username() . ':' . $this->ip($request);
        if ($limiter->tooManyAttempts($key)) return false;
        $ok = Password::verify($request->input('current_password'), (string)($this->user['password_hash'] ?? ''));
        $ok ? $limiter->clear($key) : $limiter->hit($key);
        return $ok;
    }

    private function mfa(): MfaRepository
    {
        return new MfaRepository($this->config->paths());
    }

    private function username(): string
    {
        return (string)($this->user['username'] ?? 'admin');
    }

    private function ip(Request $request): string
    {
        return (string)($request->server['REMOTE_ADDR'] ?? '');
    }

    private function duration(int $seconds): string
    {
        if ($seconds % 3600 === 0) return (string)($seconds / 3600) . ' hours';
        return (string)max(1, (int)round($seconds / 60)) . ' minutes';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
