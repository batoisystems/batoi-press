<?php
declare(strict_types=1);

namespace Batoi\Press\Admin;

use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Application\Social\SocialApi;
use Batoi\Press\Application\Social\SocialPublishing;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PostRepository;
use Batoi\Press\Content\PublicationState;
use Batoi\Press\Core\AuditLog;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Core\Slug;
use Batoi\Press\Core\SocialMetadata;
use Batoi\Press\Security\AdminAccess;
use Batoi\Press\Security\Csrf;
use RuntimeException;

final class SocialPublishingController
{
    private const SETTINGS = '/admin/social-publishing';
    private const PUBLISH = self::SETTINGS . '/publish';
    private const VIDEO_LIMIT = 20 * 1024 * 1024;
    private const GUIDANCE = [
        'facebook' => 'Use a Page access token with Pages publishing permission.',
        'instagram' => 'Use an Instagram Login token for a professional account with content publishing permission.',
        'x' => 'Use a user-context OAuth 2.0 token with users.read, tweet.read and tweet.write.',
        'linkedin' => 'Use a member OAuth token with openid, profile and w_member_social.',
        'youtube' => 'Use a Google OAuth token for one channel with youtube.readonly and youtube.upload.',
    ];

    private readonly SocialPublishing $publishing;

    public function __construct(
        private readonly Config $config,
        private readonly PageRepository $pages,
        private readonly PostRepository $posts,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly array $user,
    ) {
        $this->publishing = new SocialPublishing($config);
    }

    public function handle(Request $request, array $files): Response
    {
        if ($request->path === self::SETTINGS && $request->method === 'GET') return $this->settings();
        if ($request->method !== 'POST') {
            return self::response('Social Publishing', '<p>Use the Social Publishing form to submit this action.</p>', 405)
                ->withHeader('Allow', $request->path === self::SETTINGS ? 'GET, POST' : 'POST');
        }
        if ($request->path === self::PUBLISH && $request->post === [] && $files === []) {
            $limit = ini_parse_quantity((string)ini_get('post_max_size'));
            if ($limit > 0 && (int)$request->header('Content-Length') > $limit) {
                return self::response('Social Publishing', '<p class="bp-error">The video upload exceeds PHP’s post_max_size. Go back and select a smaller MP4.</p>', 413);
            }
        }
        if (!$this->csrf->validate(self::field($request, 'csrf_token'))) {
            return self::response('Social Publishing', '<p class="bp-error">Security token expired. Reload the form and try again.</p>', 400);
        }
        return $request->path === self::PUBLISH ? $this->send($request, $files) : $this->changeConnection($request);
    }

    private function settings(string $notice = '', int $status = 200): Response
    {
        $body = AdminLayout::pageHeader('Social Publishing', 'Connect one destination per platform for publishing saved pages and posts.');
        if ($notice !== '') $body .= self::notice($notice, $status);
        $accounts = $this->publishing->accounts();
        foreach (SocialApi::NAMES as $platform => $label) {
            $account = $accounts[$platform] ?? null;
            $form = '<p>' . ($account === null ? 'No account connected.' : 'Connected as <strong>' . self::e($account['name']) . '</strong>.') . '</p>'
                . '<form method="post" action="' . self::SETTINGS . '" class="bp-form bp-compact-form">' . $this->csrf->field()
                . self::hidden('platform', $platform)
                . self::hidden('connection_version', $account['version'] ?? '')
                . '<label>' . self::e($label) . ' access token<input type="password" name="access_token" maxlength="8192" required autocomplete="off" spellcheck="false"></label>'
                . '<div class="bp-form-actions">' . AdminLayout::submitButton($account === null ? 'Verify and connect' : 'Verify replacement token', 'link') . '</div></form>';
            if ($account !== null) {
                $form .= '<form method="post" action="' . self::SETTINGS . '" class="bp-inline-form">' . $this->csrf->field()
                    . self::hidden('platform', $platform) . self::hidden('connection_version', $account['version']) . self::hidden('disconnect', '1')
                    . AdminLayout::submitButton('Disconnect ' . $label, 'link', 'class="bp-button"') . '</form>';
            }
            if ($platform === 'youtube') {
                $form .= '<p>By using the YouTube integration, you agree to the <a href="https://www.youtube.com/t/terms">YouTube Terms of Service</a>. See the <a href="https://policies.google.com/privacy">Google Privacy Policy</a> or <a href="https://myaccount.google.com/permissions">revoke Google access</a>.</p>';
                $form .= '<p>Press stores the encrypted access token, channel identity and delivery references. It sends the video and the metadata you review to YouTube when you submit an upload.</p>';
            }
            $body .= AdminLayout::section($label, $form, self::GUIDANCE[$platform]);
        }
        return self::response('Social Publishing', $body, $status);
    }

    private function changeConnection(Request $request): Response
    {
        $platform = self::field($request, 'platform');
        $version = self::field($request, 'connection_version');
        try {
            if (self::field($request, 'disconnect') === '1') {
                $this->publishing->disconnect($platform, $version);
                $action = 'disconnected';
            } else {
                $this->publishing->connect($platform, self::field($request, 'access_token'), $version);
                $action = 'connected';
            }
        } catch (RuntimeException $error) {
            return $this->settings(self::safeError($error), self::errorStatus($error));
        }
        $this->audit($request, 'social.' . $action, $platform);
        return $this->settings(SocialApi::NAMES[$platform] . ' ' . $action . '.');
    }

    public static function editor(Config $config, Csrf $csrf, array $user, string $type, array $record, string $path): string
    {
        if (!AdminAccess::canAccess($user, self::PUBLISH, 'POST') || !PublicationState::isPublic($record)) return '';
        try {
            $publishing = new SocialPublishing($config);
            $accounts = $publishing->accounts();
        } catch (RuntimeException) {
            return '<p class="bp-error">Social connections are unavailable.</p>';
        }
        if ($accounts === []) return '';
        $share = self::share($config, $record, $path);
        $body = '';
        $videoForm = '';
        $revision = ContentRevision::for($record);
        foreach ($accounts as $platform => $account) {
            $label = SocialApi::NAMES[$platform];
            try {
                $delivery = $publishing->delivery($platform, $type, (string)$record['slug'], $revision, $account['id']);
            } catch (RuntimeException) {
                $delivery = ['state' => 'unconfirmed'];
            }
            $state = $delivery['state'] ?? '';
            $destination = self::e($label . ' · ' . $account['name']);
            if ($state === 'sent') {
                $body .= '<p><strong>' . $destination . '</strong> · Sent. Reference ' . self::e((string)$delivery['id']) . '</p>';
                continue;
            }
            if (in_array($state, ['pending', 'unconfirmed'], true)) {
                $body .= '<p><strong>' . $destination . '</strong> · Delivery unconfirmed. Check the destination before taking further action.</p>';
                continue;
            }
            $missing = $share['url'] === '' ? 'Set a public HTTPS site URL before publishing.' : '';
            if ($missing === '' && $platform === 'instagram' && $share['image'] === '') {
                $missing = 'Save a public JPEG as the social or featured image first.';
            }
            if ($missing !== '') {
                $body .= '<p><strong>' . self::e($label) . '</strong> ' . self::e($missing) . '</p>';
                continue;
            }
            $form = '<form method="post" action="' . self::PUBLISH . '" class="' . ($platform === 'youtube' ? 'bp-form bp-compact-form' : 'bp-inline-form') . '"'
                . ($platform === 'youtube' ? ' enctype="multipart/form-data"' : '') . '>'
                . $csrf->field() . self::hidden('platform', $platform) . self::hidden('account', $account['id'])
                . self::hidden('type', $type) . self::hidden('slug', (string)$record['slug']) . self::hidden('revision', $revision);
            if ($platform === 'youtube') {
                $form .= '<label>MP4 video, up to 20 MiB<input type="file" name="video" accept=".mp4,video/mp4" required></label>'
                    . '<label>Video title<input name="title" maxlength="100" required value="' . self::e(SocialApi::limit(str_replace(['<', '>'], '', $share['title']), 100)) . '"></label>'
                    . '<label>Video description<textarea name="description" rows="3" maxlength="5000">' . self::e(str_replace(['<', '>'], '', $share['summary']) . "\n\n" . $share['url']) . '</textarea></label>'
                    . '<label>Visibility<select name="privacy"><option value="private">Private</option><option value="unlisted">Unlisted</option><option value="public">Public</option></select></label>'
                    . '<label>Made for kids<select name="audience" required><option value="">Choose an audience</option><option value="yes">Yes</option><option value="no">No</option></select></label>';
                $videoForm = '<details><summary>YouTube · ' . self::e($account['name']) . '</summary>' . $form
                    . '<div class="bp-form-actions">' . AdminLayout::submitButton('Upload to YouTube', 'link') . '</div></form></details>';
                continue;
            }
            $action = $state === 'processing' ? 'Check image and publish to ' : 'Publish to ';
            $body .= $form . AdminLayout::submitButton($action . $label . ' · ' . $account['name'], 'link', 'class="bp-button"') . '</form>';
        }
        return AdminLayout::section('Social publishing', '<div class="bp-table-actions bp-social-actions">' . $body . '</div>' . $videoForm, 'These actions send the saved public version. Save any edits first.');
    }

    private function send(Request $request, array $files): Response
    {
        $platform = self::field($request, 'platform');
        $type = self::field($request, 'type');
        $slug = self::field($request, 'slug');
        $back = '/admin';
        $status = 200;
        try {
            if (!isset(SocialApi::NAMES[$platform]) || !in_array($type, ['page', 'post'], true) || $slug === '' || Slug::normalize($slug) !== $slug) {
                throw new RuntimeException('Select a valid saved page or post and social platform.', 422);
            }
            $repository = $type === 'page' ? $this->pages : $this->posts;
            $record = $repository->findBySlug($slug);
            $back = '/admin/' . $type . 's/edit/' . rawurlencode($slug);
            if ($record === null || !PublicationState::isPublic($record)) {
                throw new RuntimeException('Only saved public pages and posts can be published to social accounts.', 409);
            }
            $path = $type === 'page' && $slug === Slug::normalize((string)($this->config->site()['homepage'] ?? 'home'))
                ? '/' : $repository->publicPath($record);
            $share = self::share($this->config, $record, $path);
            if ($share['url'] === '') throw new RuntimeException('Set a public HTTPS site URL before publishing.', 422);
            if ($platform === 'instagram' && $share['image'] === '') throw new RuntimeException('Save a public JPEG as the social or featured image first.', 422);
            if ($platform === 'youtube') $share['video'] = self::video($request, $files);
            $result = $this->publishing->publish($platform, $type, $record, self::field($request, 'revision'), self::field($request, 'account'), $share);
            $message = ($result['duplicate'] ? 'This revision was already sent to ' : 'Sent to ') . SocialApi::NAMES[$platform] . '. Reference ' . $result['id'] . '.';
            $this->audit($request, $result['duplicate'] ? 'social.already_sent' : 'social.sent', "$platform:$type:$slug");
        } catch (RuntimeException $error) {
            $status = self::errorStatus($error);
            $message = self::safeError($error);
            if (isset(SocialApi::NAMES[$platform]) && $back !== '/admin') {
                [$action, $outcome] = match ($status) {
                    202 => ['social.processing', 'pending'],
                    502 => ['social.unconfirmed', 'unconfirmed'],
                    default => ['social.send_failed', 'failed'],
                };
                $this->audit($request, $action, "$platform:$type:$slug", $outcome);
            }
        }
        return self::response('Social Publishing', AdminLayout::pageHeader('Social Publishing', '', AdminLayout::buttonLink('Back to editor', $back, 'back', true))
            . self::notice($message, $status), $status);
    }

    private static function video(Request $request, array $files): array
    {
        $file = $files['video'] ?? [];
        $path = is_array($file) && is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($path)) {
            throw new RuntimeException('Select an MP4 video within the server’s upload limit and try again.', 422);
        }
        $size = filesize($path);
        if (!$size || $size > self::VIDEO_LIMIT || !class_exists(\finfo::class) || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== 'video/mp4') {
            throw new RuntimeException('Select a valid MP4 video no larger than 20 MiB. PHP Fileinfo is required.', 422);
        }
        $title = self::field($request, 'title');
        $description = self::field($request, 'description');
        $privacy = self::field($request, 'privacy');
        $audience = self::field($request, 'audience');
        if ($title === '' || SocialApi::limit($title, 100) !== $title || strlen($description) > 5000
            || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $title . $description) !== 0
            || !in_array($privacy, ['private', 'unlisted', 'public'], true) || !in_array($audience, ['yes', 'no'], true)) {
            throw new RuntimeException('Enter a valid video title, description, visibility and audience.', 422);
        }
        return ['file' => $path, 'size' => $size, 'title' => $title, 'description' => $description, 'privacy' => $privacy, 'made_for_kids' => $audience === 'yes'];
    }

    private static function share(Config $config, array $record, string $path): array
    {
        $base = rtrim((string)($config->site()['base_url'] ?? ''), '/');
        $image = '';
        foreach (['og_image', 'featured_image'] as $field) {
            $candidate = trim((string)($record[$field] ?? ''));
            if (str_starts_with($candidate, '/') && !str_starts_with($candidate, '//')) {
                $prefix = (string)parse_url($base, PHP_URL_PATH);
                $candidate = $prefix !== '' && str_starts_with($candidate, $prefix . '/') ? substr($base, 0, -strlen($prefix)) . $candidate : $base . $candidate;
            }
            if (self::publicHttps($candidate) && preg_match('/\.jpe?g$/iD', (string)parse_url($candidate, PHP_URL_PATH)) === 1) {
                $image = $candidate;
                break;
            }
        }
        $summary = ($record['og_description'] ?? '') ?: (($record['seo_description'] ?? '') ?: ($record['subtitle'] ?? ''));
        $canonical = self::publicHttps($base) && !strpbrk($base, '?#') ? SocialMetadata::canonical($base, $path) : '';
        return [
            'title' => SocialApi::limit(self::plain((string)$record['title']), 200) ?: 'Read on ' . self::plain((string)($config->site()['name'] ?? 'Batoi Press')),
            'summary' => SocialApi::limit(self::plain((string)$summary), 600),
            'url' => strlen($canonical) <= 1000 ? $canonical : '',
            'image' => $image,
        ];
    }

    private static function publicHttps(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || strlen($url) > 2048) return false;
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) return false;
        $host = strtolower(rtrim(trim((string)($parts['host'] ?? ''), '[]'), '.'));
        if (filter_var($host, FILTER_VALIDATE_IP)) return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
        return str_contains($host, '.') && !preg_match('/(?:^|\.)(?:localhost|local|internal)$/D', $host);
    }

    private static function plain(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function audit(Request $request, string $action, string $target, string $outcome = 'success'): void
    {
        try {
            $this->audit->record((string)($this->user['username'] ?? 'admin'), $action, $target, (string)($request->server['REMOTE_ADDR'] ?? ''), $outcome);
        } catch (RuntimeException) {
            error_log('Press social publishing audit unavailable. Reference ' . $request->requestId);
        }
    }

    private static function safeError(RuntimeException $error): string
    {
        return $error->getCode() > 0 ? $error->getMessage() : 'Social publishing is unavailable. Check private storage and the provider connection.';
    }

    private static function errorStatus(RuntimeException $error): int
    {
        return match ($error->getCode()) {
            425 => 202,
            409, 410, 429, 502 => $error->getCode(),
            default => 422,
        };
    }

    private static function response(string $title, string $body, int $status): Response
    {
        return Response::html(AdminLayout::render($title, $body), $status)->withHeader('Cache-Control', 'private, no-store');
    }

    private static function notice(string $text, int $status): string
    {
        return $status < 400
            ? '<p class="bp-notice" role="status">' . self::e($text) . '</p>'
            : '<p class="bp-error" role="alert">' . self::e($text) . '</p>';
    }

    private static function field(Request $request, string $name): string
    {
        $value = $request->post[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    private static function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
    }

    private static function e(string $text): string
    {
        return AdminLayout::e($text);
    }
}
