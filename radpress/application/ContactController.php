<?php
declare(strict_types=1);

namespace Batoi\Press\Application;

use Batoi\Press\Core\Config;
use Batoi\Press\Core\FileStore;
use Batoi\Press\Core\HtmlContent;
use Batoi\Press\Content\PageRepository;
use Batoi\Press\Content\PublicationState;
use Batoi\Press\Core\Request;
use Batoi\Press\Core\Response;
use Batoi\Press\Security\RateLimiter;
use Batoi\Press\Security\SecretStore;
use RuntimeException;

final class ContactController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function submit(Request $request): Response
    {
        $returnPath = $this->contactPath($request);
        $ip = (string)($request->server['REMOTE_ADDR'] ?? 'unknown');
        $limiter = new RateLimiter($this->config->paths(), 5, 600);
        $key = 'contact:' . $ip;
        if ($limiter->tooManyAttempts($key)) {
            return $this->result('Too many contact attempts. Please wait and try again.', 429, $returnPath);
        }
        $limiter->hit($key);
        if ($request->input('website') !== '') {
            return Response::redirect($returnPath . '?contact=sent');
        }
        $name = substr(trim($request->input('name')), 0, 120);
        $email = substr(trim($request->input('email')), 0, 254);
        $subject = substr(trim($request->input('subject')), 0, 160);
        $message = trim($request->input('message'));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '' || strlen($message) > 10000) {
            return $this->result('Enter your name, a valid email address, and a message of no more than 10,000 characters.', 422, $returnPath);
        }
        $integrations = $this->config->integrations();
        if (!$this->verifyRecaptcha($request->input('g-recaptcha-response'), $ip, $integrations)) {
            return $this->result('Human verification failed. Please try again.', 422, $returnPath);
        }
        try {
            (new MailerService($this->config->paths(), $integrations))->sendContact($name, $email, $subject !== '' ? $subject : 'Website contact from ' . $name, $message);
        } catch (RuntimeException $exception) {
            return $this->result($exception->getMessage(), 503, $returnPath);
        }
        return Response::redirect($returnPath . '?contact=sent');
    }

    private function contactPath(Request $request): string
    {
        $pages = new PageRepository($this->config->paths(), new FileStore(), new HtmlContent());
        $page = $pages->findBySlug($request->input('contact_page', 'contact'));
        if ($page === null || !PublicationState::isPublic($page) || ($page['template'] ?? '') !== 'contact') return '/contact';
        return ($page['slug'] ?? '') === ($this->config->site()['homepage'] ?? 'home') ? '/' : $pages->publicPath($page);
    }

    private function verifyRecaptcha(string $token, string $ip, array $config): bool
    {
        $encryptedSecret = (string)($config['recaptcha_secret_key'] ?? '');
        if ($encryptedSecret === '') {
            return true;
        }
        if ($token === '' || !function_exists('curl_init')) {
            return false;
        }
        try {
            $secret = (new SecretStore($this->config->paths()))->decrypt($encryptedSecret);
        } catch (RuntimeException) {
            return false;
        }
        $handle = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['secret' => $secret, 'response' => $token, 'remoteip' => $ip]]);
        $response = curl_exec($handle);
        curl_close($handle);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        return is_array($decoded) && ($decoded['success'] ?? false) === true;
    }

    private function result(string $message, int $status, string $returnPath): Response
    {
        return Response::html('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Contact</title></head><body><main><h1>Contact</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><p><a href="' . htmlspecialchars(function_exists('bp_url') ? \bp_url($returnPath) : $returnPath, ENT_QUOTES, 'UTF-8') . '">Return to contact page</a></p></main></body></html>', $status);
    }
}
