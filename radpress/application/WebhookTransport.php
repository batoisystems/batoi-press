<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use RuntimeException;

final class WebhookTransport
{
    public function __construct(private readonly ?\Closure $resolver = null) {}

    public function destination(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new RuntimeException('Use an HTTPS webhook URL on port 443 without credentials or fragments.');
        $host = strtolower($parts['host']);
        if (!preg_match('/^[a-z0-9.-]+$/D', $host) || !str_contains($host, '.') || strlen($host) > 253) throw new RuntimeException('Use a public DNS hostname.');
        if ($this->resolver !== null) $addresses = ($this->resolver)($host);
        else {
            $addresses = gethostbynamel($host) ?: [];
            if (function_exists('dns_get_record')) foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) if (isset($record['ipv6'])) $addresses[] = $record['ipv6'];
        }
        if (!is_array($addresses) || $addresses === [] || count($addresses) > 32) throw new RuntimeException('Webhook DNS is unavailable or invalid.');
        foreach ($addresses as $address) {
            if (!is_string($address) || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || str_starts_with(strtolower($address), '::ffff:')) throw new RuntimeException('Webhook destinations must resolve only to public addresses.');
            if (str_contains($address, ':') && (ord(inet_pton($address)[0]) & 0xe0) !== 0x20) throw new RuntimeException('Webhook IPv6 address is not globally routable.');
        }
        return ['url' => $url, 'host' => $host, 'addresses' => array_values(array_unique($addresses))];
    }

    public function send(string $url, string $secret, string $id, array $payload): void
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Webhook transport is unavailable.');
        $destination = $this->destination($url);
        $body = json_encode(['id' => $id, 'event' => 'form.submitted', 'data' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($body) > 65536 || strlen($secret) < 32) throw new RuntimeException('Webhook payload or signing secret is invalid.');
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $ip = $destination['addresses'][0];
        $pin = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Unable to initialize webhook.');
        $received = 0;
        try {
            $ok = curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Idempotency-Key: ' . $id, 'X-Press-Timestamp: ' . $timestamp, 'X-Press-Signature: sha256=' . $signature], CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5, CURLOPT_RESOLVE => [$destination['host'] . ':443:' . $pin], CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received): int { $received += strlen($chunk); return $received > 65536 ? 0 : strlen($chunk); }]);
            if (!$ok || curl_exec($handle) === false || ($status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE)) < 200 || $status >= 300) throw new RuntimeException('Webhook delivery failed.');
        } finally { curl_close($handle); }
    }
}
