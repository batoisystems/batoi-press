<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Core\Paths;
use Batoi\Press\Security\SecretStore;
use RuntimeException;

final class MailerService
{
    public function __construct(private readonly Paths $paths, private readonly array $config)
    {
    }

    public function sendContact(string $name, string $email, string $subject, string $message): void
    {
        $provider = (string)($this->config['mail_provider'] ?? 'disabled');
        if ($provider === 'disabled') {
            throw new RuntimeException('Contact email delivery is disabled.');
        }
        $from = trim((string)($this->config['mail_from'] ?? ''));
        $to = trim((string)($this->config['mail_to'] ?? ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Contact email delivery is not configured.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strpbrk($email, "\r\n") !== false) {
            throw new RuntimeException('Contact reply address is invalid.');
        }
        $safeSubject = preg_replace('/[\r\n]+/', ' ', trim($subject)) ?: 'Website contact';
        $body = "Name: {$name}\nEmail: {$email}\n\n{$message}";
        // Contact fields are visitor text, not trusted email markup.
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>'
            . '<p><strong>Name:</strong> ' . $escape($name) . '<br><strong>Email:</strong> ' . $escape($email) . '</p>'
            . '<p>' . nl2br($escape($message), false) . '</p></body></html>';
        if ($provider === 'php_mail') {
            $headers = ['From: ' . $from, 'Reply-To: ' . $email, 'MIME-Version: 1.0', 'Content-Type: text/html; charset=UTF-8', 'Content-Transfer-Encoding: quoted-printable'];
            if (!mail($to, $safeSubject, quoted_printable_encode($html), implode("\r\n", $headers))) {
                throw new RuntimeException('The configured mail service did not accept the message.');
            }
            return;
        }
        if ($provider !== 'mailgun') {
            throw new RuntimeException('The configured mail provider is unsupported.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Mailgun delivery requires the PHP cURL extension.');
        }
        $domain = trim((string)($this->config['mailgun_domain'] ?? ''));
        $encryptedKey = (string)($this->config['mailgun_api_key'] ?? '');
        if ($domain === '' || $encryptedKey === '') {
            throw new RuntimeException('Mailgun domain and API key are required.');
        }
        $apiKey = (new SecretStore($this->paths))->decrypt($encryptedKey);
        $host = ($this->config['mailgun_region'] ?? 'us') === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
        $handle = curl_init($host . '/v3/' . rawurlencode($domain) . '/messages');
        if ($handle === false) {
            throw new RuntimeException('Mailgun transport could not be initialized.');
        }
        $configured = curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERPWD => 'api:' . $apiKey,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['from' => $from, 'to' => $to, 'h:Reply-To' => $email, 'subject' => $safeSubject, 'text' => $body, 'html' => $html],
        ]);
        if (!$configured) {
            curl_close($handle);
            throw new RuntimeException('Mailgun transport could not be configured.');
        }
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new RuntimeException('Mailgun delivery failed' . ($error !== '' ? ': ' . $error : '.'));
        }
    }
}
