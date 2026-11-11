<?php
declare(strict_types=1);

namespace Batoi\Press\Application\Social;

use RuntimeException;

final class SocialApi
{
    public const NAMES = [
        'facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X',
        'linkedin' => 'LinkedIn', 'youtube' => 'YouTube',
    ];

    private const FACEBOOK = 'https://graph.facebook.com/v25.0/';
    private const INSTAGRAM = 'https://graph.instagram.com/v25.0/';
    private const GOOGLE = 'https://www.googleapis.com/';

    public function __construct(private readonly ?\Closure $transport = null)
    {
    }

    public function identify(string $platform, string $token): array
    {
        [$url, $keys] = match ($platform) {
            'facebook' => [self::FACEBOOK . 'me?fields=id,name,category', ['id', 'name']],
            'instagram' => [self::INSTAGRAM . 'me?fields=user_id,username', ['user_id', 'username']],
            'x' => ['https://api.x.com/2/users/me', ['id', 'username']],
            'linkedin' => ['https://api.linkedin.com/v2/userinfo', ['sub', 'name']],
            'youtube' => [self::GOOGLE . 'youtube/v3/channels?part=snippet&mine=true&maxResults=2', ['id', 'title']],
        };
        $response = $this->request('GET', $url, $token);
        $data = match ($platform) {
            'x' => $response['json']['data'] ?? [],
            'youtube' => $this->channel($response['json']),
            default => $response['json'],
        };
        if (!is_array($data)) throw new RuntimeException('The provider did not return account details.', 422);
        if ($platform === 'facebook' && empty($data['category'])) {
            throw new RuntimeException('Use a Facebook Page access token.', 422);
        }
        $id = $data[$keys[0]] ?? null;
        $name = $data[$keys[1]] ?? null;
        $id = is_int($id) ? (string)$id : $id;
        $name = is_string($name) ? trim($name) : '';
        if (!is_string($id) || !self::validId($id) || $name === '') {
            throw new RuntimeException('The provider could not confirm a single destination account.', 422);
        }
        return ['id' => $id, 'name' => self::limit($name, 120)];
    }

    public function publish(string $platform, string $token, string $account, array $share, string $instagramContainer = ''): string
    {
        $title = $share['title'];
        $url = $share['url'];
        $summary = $share['summary'];
        $text = implode("\n\n", array_filter([$title, $summary], static fn (string $part): bool => $part !== ''));
        return match ($platform) {
            'facebook' => $this->publishedId($this->form(self::FACEBOOK . $account . '/feed', $token, ['message' => $text, 'link' => $url])),
            'instagram' => $this->publishedId($this->form(self::INSTAGRAM . $account . '/media_publish', $token, ['creation_id' => $instagramContainer])),
            'x' => $this->publishedId($this->json('https://api.x.com/2/tweets', $token, ['text' => self::limit($title, 120) . ' ' . $url])['json']['data'] ?? []),
            'linkedin' => $this->linkedin($token, $account, $text, $share),
            'youtube' => $this->youtube($token, $share['video']),
        };
    }

    private function channel(array $data): array
    {
        $items = $data['items'] ?? [];
        if (!is_array($items) || count($items) !== 1 || !empty($data['nextPageToken'])) return [];
        return ['id' => $items[0]['id'] ?? '', 'title' => $items[0]['snippet']['title'] ?? ''];
    }

    public function prepareInstagram(string $token, string $account, array $share, string $container, \Closure $remember): string
    {
        try {
            if ($container === '') {
                $url = $share['url'];
                $text = trim($share['title'] . "\n\n" . $share['summary']);
                $caption = self::limit($text, max(0, 2198 - strlen($url))) . "\n\n" . $url;
                $container = $this->publishedId($this->form(self::INSTAGRAM . $account . '/media', $token, [
                    'image_url' => $share['image'], 'caption' => $caption,
                ]));
                $remember($container);
            }
            $status = $this->request('GET', self::INSTAGRAM . $container . '?fields=status_code', $token)['json']['status_code'] ?? '';
        } catch (RuntimeException $error) {
            if (in_array($error->getCode(), [401, 403, 429], true)) throw $error;
            throw new RuntimeException('Instagram could not prepare the image. Check the public JPEG and account permissions, then retry.', 422, $error);
        }
        return match ($status) {
            'FINISHED' => $container,
            'IN_PROGRESS' => throw new RuntimeException('Instagram is preparing the image. Wait a minute, then return to the editor and select Check image and publish to Instagram.', 425),
            'EXPIRED', 'ERROR' => throw new RuntimeException('Instagram could not use the prepared image. Check the public JPEG, then try again to prepare a new copy.', 410),
            'PUBLISHED' => throw new RuntimeException('Instagram reports that this image was already published. Check the destination account for the post.', 409),
            default => throw new RuntimeException('Instagram did not confirm that the image is ready. Try again shortly.', 425),
        };
    }

    private function linkedin(string $token, string $account, string $text, array $share): string
    {
        $result = $this->json('https://api.linkedin.com/rest/posts', $token, [
            'author' => 'urn:li:person:' . $account, 'commentary' => self::limit($text, 2500),
            'visibility' => 'PUBLIC', 'lifecycleState' => 'PUBLISHED',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'content' => ['article' => ['source' => $share['url'], 'title' => self::limit($share['title'], 200), 'description' => self::limit($share['summary'], 256)]],
        ], ['LinkedIn-Version: 202610', 'X-Restli-Protocol-Version: 2.0.0']);
        return $this->publishedId(['id' => $result['id']]);
    }

    private function youtube(string $token, array $video): string
    {
        $metadata = [
            'snippet' => ['title' => $video['title'], 'description' => $video['description'], 'categoryId' => '22'],
            'status' => ['privacyStatus' => $video['privacy'], 'selfDeclaredMadeForKids' => $video['made_for_kids']],
        ];
        $boundary = 'press-' . bin2hex(random_bytes(16));
        $body = tmpfile();
        $file = fopen($video['file'], 'rb');
        try {
            if ($body === false || $file === false) throw new RuntimeException('Unable to prepare the video upload.', 422);
            $start = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
                . json_encode($metadata, JSON_THROW_ON_ERROR) . "\r\n--$boundary\r\nContent-Type: video/mp4\r\n\r\n";
            $end = "\r\n--$boundary--\r\n";
            if (fwrite($body, $start) !== strlen($start)
                || stream_copy_to_stream($file, $body, $video['size'] + 1) !== $video['size']
                || fwrite($body, $end) !== strlen($end) || !rewind($body)) {
                throw new RuntimeException('Unable to prepare the complete video upload.', 422);
            }
            return $this->publishedId($this->request('POST', self::GOOGLE . 'upload/youtube/v3/videos?uploadType=multipart&part=snippet,status',
                $token, ['Content-Type: multipart/related; boundary=' . $boundary, 'Expect:'], $body)['json']);
        } finally {
            if (is_resource($file)) fclose($file);
            if (is_resource($body)) fclose($body);
        }
    }

    private function publishedId(mixed $data): string
    {
        $id = is_array($data) ? ($data['id'] ?? '') : '';
        $id = is_int($id) ? (string)$id : $id;
        if (!is_string($id) || !self::validId($id)) throw new RuntimeException('The provider did not return a publication ID.');
        return $id;
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_:-]{1,150}$/D', $id) === 1;
    }

    public static function limit(string $text, int $length): string
    {
        preg_match('/^.{0,' . $length . '}/us', $text, $match);
        return $match[0] ?? '';
    }

    private function form(string $url, string $token, array $data): array
    {
        return $this->request('POST', $url, $token, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($data))['json'];
    }

    private function json(string $url, string $token, array $data, array $headers = []): array
    {
        return $this->request('POST', $url, $token, ['Content-Type: application/json', ...$headers], json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function request(string $method, string $url, string $token, array $headers = [], mixed $body = null): array
    {
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json', ...$headers];
        [$status, $response, $id] = ($this->transport ?? self::send(...))($method, $url, $headers, $body);
        if ($status < 200 || $status >= 300) {
            $message = match ($status) {
                401, 403 => 'The provider rejected the credential or permission. Ask the owner to reconnect the account.',
                429 => 'The provider rate limit was reached. Try again later.',
                default => 'The provider did not accept the request' . ($status > 0 ? ' (HTTP ' . $status . ').' : '.'),
            };
            throw new RuntimeException($message, $status);
        }
        $decoded = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);
        return ['json' => is_array($decoded) ? $decoded : [], 'id' => $id];
    }

    private static function send(string $method, string $url, array $headers, mixed $body): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Enable PHP cURL to use social publishing.', 422);
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Unable to start the provider request.', 422);
        $response = '';
        $id = '';
        $options = [
            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => is_resource($body) ? 45 : 10, CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 65536) return 0;
                $response .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$id): int {
                if (stripos($line, 'x-restli-id:') === 0) $id = trim(substr($line, strlen('x-restli-id:')));
                return strlen($line);
            },
        ];
        if (is_resource($body)) {
            $options += [CURLOPT_UPLOAD => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_INFILE => $body, CURLOPT_INFILESIZE => fstat($body)['size']];
        } elseif ($method === 'POST') {
            $options += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body];
        }
        if (!curl_setopt_array($handle, $options) || curl_exec($handle) === false) return [0, '', ''];
        return [(int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $response, $id];
    }
}
