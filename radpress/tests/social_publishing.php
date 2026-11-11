<?php
declare(strict_types=1);

use Batoi\Press\Admin\SocialPublishingController;
use Batoi\Press\Application\ContentRevision;
use Batoi\Press\Application\Social\{SocialApi, SocialPublishing};
use Batoi\Press\Content\{PageRepository, PostRepository};
use Batoi\Press\Core\{AuditLog, Config, FileStore, HtmlContent, Request};
use Batoi\Press\Security\{AdminAccess, Csrf, Session};

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
require dirname(__DIR__) . '/helpers/esc.php';

function socialCheck(bool $condition, string $failure): void
{
    if (!$condition) throw new RuntimeException($failure);
}

function socialReject(callable $action, int $code): void
{
    try {
        $action();
    } catch (RuntimeException $error) {
        socialCheck($error->getCode() === $code, 'Unexpected error: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected publication refusal.');
}

$root = sys_get_temp_dir() . '/press-social-' . bin2hex(random_bytes(8));
$files = new FileStore();
$calls = [];
$unconfirmed = false;
$instagramReady = false;
$credential = 'fixture-secret-token';
$transport = static function (string $method, string $url, array $headers, mixed $body) use (&$calls, &$unconfirmed, &$instagramReady, $credential): array {
    socialCheck(in_array('Authorization: Bearer ' . $credential, $headers, true), 'Wrong authorization header.');
    socialCheck(!str_contains($url, $credential), 'Credential leaked into request URL.');
    $calls[] = [$method, $url, $headers, is_resource($body) ? stream_get_contents($body) : $body];
    if ($unconfirmed) return [0, '', ''];
    $response = match (true) {
        str_contains($url, 'facebook.com') && $method === 'GET' => ['id' => '111', 'name' => 'Page & Co', 'category' => 'Company'],
        str_contains($url, 'instagram.com') && str_contains($url, '/me?') => ['user_id' => '222', 'username' => 'studio'],
        str_ends_with($url, '/container-01?fields=status_code') => ['status_code' => $instagramReady ? 'FINISHED' : 'IN_PROGRESS'],
        str_contains($url, '/users/me') => ['data' => ['id' => '333', 'username' => 'studio']],
        str_contains($url, '/userinfo') => ['sub' => 'member-444', 'name' => 'A Member'],
        str_contains($url, '/channels?') => ['items' => [['id' => 'UC555', 'snippet' => ['title' => 'Channel']]]],
        str_ends_with($url, '/media') => ['id' => 'container-01'],
        str_ends_with($url, '/tweets') => ['data' => ['id' => 'sent-x']],
        default => ['id' => 'sent-01'],
    };
    return [200, json_encode($response, JSON_THROW_ON_ERROR), str_contains($url, '/rest/posts') ? 'urn:li:share:777' : ''];
};

try {
    $files->writeJson($root . '/radpress/config/paths.json', ['config' => 'radpress/config', 'content' => 'radpress/content', 'data' => 'radpress/data']);
    $files->writeJson($root . '/radpress/config/site.json', ['name' => 'Studio', 'base_url' => 'https://example.test/press']);
    $config = Config::load($root);
    $service = new SocialPublishing($config, new SocialApi($transport));
    foreach (SocialApi::NAMES as $platform => $label) $service->connect($platform, $credential);
    socialCheck(count($service->accounts()) === 5, 'All five provider identities must resolve.');
    $accountFile = $config->paths()->dataPath('integrations/social-publishing/accounts.json');
    socialCheck(!str_contains($files->read($accountFile), $credential), 'Connection stored plaintext.');
    socialCheck(!str_contains(json_encode($service->accounts()), 'credential'), 'Connection metadata exposed a credential.');

    $pages = new PageRepository($config->paths(), $files, new HtmlContent());
    $posts = new PostRepository($config->paths(), $files, new HtmlContent());
    $pages->save(['slug' => 'event', 'title' => 'Open Studio', 'status' => 'published', 'og_image' => 'https://example.test/studio.jpg'], 'owner');
    $record = $pages->findBySlug('event');
    $revision = ContentRevision::for($record);
    $session = new Session('press_social_fixture', $config->paths()->dataPath('sessions'));
    $csrf = new Csrf($session);
    $owner = ['role' => 'owner', 'username' => 'owner'];
    $html = SocialPublishingController::editor($config, $csrf, $owner, 'page', $record, '/event');
    socialCheck(str_contains($html, 'Publish to Facebook · Page &amp; Co') && str_contains($html, 'Upload to YouTube') && !str_contains($html, $credential), 'Editor must render destination actions and escape names.');
    $share = ['title' => 'Open Studio', 'summary' => 'Visit the studio.', 'url' => 'https://example.test/press/event', 'image' => 'https://example.test/studio.jpg'];
    $video = $root . '/fixture.mp4';
    $files->write($video, 'fixture-video-data');
    $share['video'] = ['file' => $video, 'size' => filesize($video), 'title' => 'Open Studio', 'description' => $share['url'], 'privacy' => 'private', 'made_for_kids' => false];
    foreach ($service->accounts() as $platform => $account) {
        if ($platform === 'instagram') {
            socialReject(fn () => $service->publish($platform, 'page', $record, $revision, $account['id'], $share), 425);
            socialCheck($service->delivery($platform, 'page', 'event', $revision, $account['id'])['state'] === 'processing', 'Instagram processing must be retryable.');
            $html = SocialPublishingController::editor($config, $csrf, $owner, 'page', $record, '/event');
            socialCheck(str_contains($html, 'Check image and publish to Instagram'), 'Editor must show how to resume a pending image.');
            $instagramReady = true;
        }
        $result = $service->publish($platform, 'page', $record, $revision, $account['id'], $share);
        socialCheck(!$result['duplicate'], 'First publish was marked a duplicate.');
        $count = count($calls);
        socialCheck($service->publish($platform, 'page', $record, $revision, $account['id'], $share)['duplicate'] && count($calls) === $count, 'Repeat submit called the provider.');
    }
    $postCalls = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'POST'));
    socialCheck(count($postCalls) === 6, 'Expected five publications and one Instagram container.');
    socialCheck(str_ends_with($postCalls[0][1], '/111/feed') && str_contains($postCalls[0][3], 'link=https'), 'Facebook must send the verified Page and public URL.');
    socialCheck(str_contains($postCalls[1][3], 'image_url=') && str_contains($postCalls[2][3], 'creation_id=container-01'), 'Instagram must publish the created image container.');
    socialCheck(json_decode($postCalls[3][3], true)['text'] === 'Open Studio ' . $share['url'], 'X link post mismatch.');
    socialCheck(json_decode($postCalls[4][3], true)['author'] === 'urn:li:person:member-444', 'LinkedIn member identity mismatch.');
    socialCheck(in_array('LinkedIn-Version: 202610', $postCalls[4][2], true), 'LinkedIn must use the supported API version.');
    socialCheck(str_contains($postCalls[5][3], 'fixture-video-data') && str_contains($postCalls[5][3], '"selfDeclaredMadeForKids":false'), 'YouTube must send media and explicit audience metadata.');

    $before = count($calls);
    socialReject(fn () => $service->publish('x', 'page', $record, 'stale', '333', $share), 409);
    socialReject(fn () => $service->publish('x', 'page', $record, $revision, 'wrong', $share), 409);
    $draft = array_replace($record, ['status' => 'draft']);
    socialReject(fn () => $service->publish('x', 'page', $draft, ContentRevision::for($draft), '333', $share), 409);
    socialCheck(count($calls) === $before, 'Invalid publication called a provider.');
    $unconfirmed = true;
    $modified = array_replace($record, ['title' => 'Updated']);
    socialReject(fn () => $service->publish('x', 'page', $modified, ContentRevision::for($modified), '333', $share), 502);
    socialReject(fn () => $service->publish('x', 'page', $modified, ContentRevision::for($modified), '333', $share), 409);
    socialCheck(count($calls) === $before + 1, 'Unconfirmed publication was retried.');
    $html = SocialPublishingController::editor($config, $csrf, $owner, 'page', $modified, '/event');
    socialCheck(str_contains($html, 'Delivery unconfirmed') && !str_contains($html, 'Publish to X'), 'Editor must block an uncertain delivery from being resent.');
    $unconfirmed = false;

    $controller = new SocialPublishingController($config, $pages, $posts, $csrf, new AuditLog($config->paths(), $files), $owner);
    $url = '/admin/social-publishing/publish';
    $html = SocialPublishingController::editor($config, $csrf, $owner, 'page', $record, '/event');
    socialCheck(str_contains($html, 'Sent. Reference sent-x') && !str_contains($html, 'Upload to YouTube'), 'Editor must show receipts instead of repeat publication actions.');
    socialCheck(!AdminAccess::canAccess(['role' => 'admin'], '/admin/social-publishing', 'POST') && !AdminAccess::canAccess(['role' => 'editor'], $url, 'POST'), 'Connection and publishing permissions are too broad.');
    $input = ['platform' => 'x', 'type' => 'page', 'slug' => 'event', 'revision' => $revision, 'account' => '333'];
    socialCheck($controller->handle(new Request('GET', $url, [], [], []), [])->status() === 405, 'GET must not publish.');
    socialCheck($controller->handle(new Request('POST', $url, ['csrf_token' => $csrf->token()], $input, []), [])->status() === 400, 'Publishing must require a POST CSRF token.');
    $result = $controller->handle(new Request('POST', $url, [], $input + ['csrf_token' => $csrf->token()], []), []);
    socialCheck($result->status() === 200 && str_contains($result->content(), 'already sent to X'), 'Admin must render the prior delivery result without a second provider call.');
    $oldVersion = $service->accounts()['x']['version'];
    $service->connect('x', $credential, $oldVersion);
    socialReject(fn () => $service->disconnect('x', $oldVersion), 409);
    socialReject(fn () => $service->connect('x', $credential, $oldVersion), 409);
    $service->disconnect('x', $service->accounts()['x']['version']);
    socialCheck(!isset($service->accounts()['x']), 'Disconnect must remove the destination.');
    session_write_close();
    echo "Social publishing checks passed\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (is_dir($root)) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($root);
    }
}
