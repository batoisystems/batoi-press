<?php
declare(strict_types=1);

namespace Batoi\Press\Application {
    // Capture both providers without sending messages or making network calls.
    function mail(string $to, string $subject, string $message, string $headers): bool {
        $GLOBALS['contact_mail_calls'][] = compact('to', 'subject', 'message', 'headers');
        return $GLOBALS['contact_mail_result'] ?? true;
    }
    function curl_init(string $url): object|false { return ($GLOBALS['contact_curl_init_failure'] ?? false) ? false : (object)['url' => $url]; }
    function curl_setopt_array(object $handle, array $options): bool {
        $GLOBALS['contact_mailgun_calls'][] = ['url' => $handle->url, 'options' => $options];
        return $GLOBALS['contact_curl_configured'] ?? true;
    }
    function curl_exec(object $handle): string|false {
        $GLOBALS['contact_curl_executions'] = ($GLOBALS['contact_curl_executions'] ?? 0) + 1;
        return $GLOBALS['contact_curl_response'] ?? '{"id":"test","message":"Queued"}';
    }
    function curl_getinfo(object $handle, int $option): int { return $GLOBALS['contact_curl_status'] ?? 200; }
    function curl_error(object $handle): string { return ''; }
    function curl_close(object $handle): void { $GLOBALS['contact_curl_closes'] = ($GLOBALS['contact_curl_closes'] ?? 0) + 1; }
}
namespace {

use Batoi\Press\Application\ContactController;
use Batoi\Press\Application\MailerService;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;
use Batoi\Press\Security\SecretStore;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';

$root = sys_get_temp_dir() . '/batoi-press-contact-' . bin2hex(random_bytes(4));
try {
    mkdir($root . '/radpress/config', 0775, true);
    mkdir($root . '/radpress/data', 0775, true);
    file_put_contents($root . '/radpress/config/paths.json', json_encode(['config'=>'radpress/config','data'=>'radpress/data','content'=>'radpress/content','theme'=>'radpress/theme','public_root'=>'public_html']));
    file_put_contents($root . '/radpress/config/integrations.json', json_encode(['mail_provider'=>'disabled']));
    $controller = new ContactController(Config::load($root));
    $invalid = $controller->submit(new Request('POST', '/contact/submit', [], ['name'=>'','email'=>'invalid','message'=>''], ['REMOTE_ADDR'=>'192.0.2.1']));
    assertContact($invalid->status() === 422, 'contact submissions should validate required fields and email addresses');
    $honeypot = $controller->submit(new Request('POST', '/contact/submit', [], ['website'=>'bot','name'=>'Bot','email'=>'bot@example.com','message'=>'Spam'], ['REMOTE_ADDR'=>'192.0.2.2']));
    assertContact($honeypot->status() === 302 && str_contains((string)($honeypot->headers()['Location'] ?? ''), 'contact=sent'), 'honeypot submissions should be discarded without mail delivery');
    $disabled = $controller->submit(new Request('POST', '/contact/submit', [], ['name'=>'Person','email'=>'person@example.com','message'=>'Hello'], ['REMOTE_ADDR'=>'192.0.2.3']));
    assertContact($disabled->status() === 503 && str_contains($disabled->content(), 'Reference:') && !str_contains($disabled->content(), 'delivery is disabled'), 'valid contact submissions should report a neutral correlated delivery failure');
    file_put_contents($root . '/radpress/config/integrations.json', json_encode(['mail_provider'=>'php_mail','mail_from'=>'site@example.com','mail_to'=>'owner@example.com']));
    $config = Config::load($root);
    $pages = new \Batoi\Press\Content\PageRepository($config->paths(), new \Batoi\Press\Core\FileStore(), new \Batoi\Press\Core\HtmlContent());
    $pages->save(['title'=>'Company','slug'=>'company','status'=>'published','body'=>'Company'], 'owner');
    $pages->save(['title'=>'Get in touch','slug'=>'get-in-touch','parent_slug'=>'company','status'=>'published','template'=>'contact','body'=>'Contact'], 'owner');
    $controller = new ContactController($config);
    $request = new Request('POST', '/contact/submit', [], ['contact_page'=>'get-in-touch','name'=>'Person','email'=>'person@example.com','message'=>'Hello'], ['REMOTE_ADDR'=>'192.0.2.4']);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $sent = $controller->submit($request);
        assertContact($sent->status() === 302 && str_contains($sent->headers()['Location'], '/company/get-in-touch?contact=sent'), 'successful submissions must return to the actual contact page');
    }
    assertContact($controller->submit($request)->status() === 429, 'successful deliveries must count toward the contact rate limit');

    $GLOBALS['contact_mail_calls'] = [];
    $GLOBALS['contact_mailgun_calls'] = [];
    $mailConfig = ['mail_provider'=>'php_mail','mail_from'=>'site@example.com','mail_to'=>'owner@example.com'];
    $mailer = new MailerService($config->paths(), $mailConfig);
    $name = 'Zoë <img src=x onerror=alert(1)> & team';
    $message = "First line & café\nSecond <script>bad()</script>\r\nThird <a href=\"https://evil.invalid\">link</a>";
    $mailer->sendContact($name, 'person@example.com', "Hello\r\nBcc: outsider@example.com", $message);
    $mail = $GLOBALS['contact_mail_calls'][0];
    $html = quoted_printable_decode($mail['message']);
    assertContact(str_contains($mail['headers'], 'MIME-Version: 1.0') && str_contains($mail['headers'], 'Content-Type: text/html; charset=UTF-8') && str_contains($mail['headers'], 'Content-Transfer-Encoding: quoted-printable'), 'Server mail must declare its HTML body and transfer encoding');
    assertContact(str_contains($html, 'Zoë &lt;img') && str_contains($html, '&amp; café') && str_contains($html, '&lt;script&gt;bad()&lt;/script&gt;') && !str_contains($html, '<script>') && !str_contains($html, '<a href='), 'Visitor text must remain escaped in HTML email');
    assertContact(substr_count($html, '<br>') === 3, 'HTML email must retain both LF and CRLF message breaks plus the identity separator');
    assertContact(!str_contains($mail['subject'], "\n") && !str_contains($mail['subject'], "\r"), 'Subject must not inject headers');
    foreach (['invalid', "person@example.com\r\nBcc: outsider@example.com"] as $invalidReply) {
        $rejected = false;
        try { $mailer->sendContact('Test', $invalidReply, 'Test', 'Test'); } catch (RuntimeException) { $rejected = true; }
        assertContact($rejected && count($GLOBALS['contact_mail_calls']) === 1, 'Invalid Reply-To must be rejected before delivery');
    }
    $GLOBALS['contact_mail_result'] = false;
    assertContactFailure(fn() => $mailer->sendContact('Test', 'person@example.com', 'Test', 'Test'), 'Rejected server mail must not report success');
    unset($GLOBALS['contact_mail_result']);
    $mailer->sendContact("Invalid \xFF", 'person@example.com', 'UTF-8', "Text \xFF\n続き");
    $unicodeHtml = quoted_printable_decode($GLOBALS['contact_mail_calls'][2]['message']);
    assertContact(str_contains($unicodeHtml, "Invalid \u{FFFD}") && str_contains($unicodeHtml, "Text \u{FFFD}") && str_contains($unicodeHtml, '続き'), 'Malformed UTF-8 must be safely replaced without losing valid text');
    if (function_exists('curl_init')) {
        $mailConfig['mail_provider'] = 'mailgun';
        $mailConfig['mailgun_domain'] = 'example.com';
        $mailConfig['mailgun_api_key'] = (new SecretStore($config->paths()))->encrypt('synthetic-key');
        (new MailerService($config->paths(), $mailConfig))->sendContact($name, 'person@example.com', 'Hello', $message);
        $fields = $GLOBALS['contact_mailgun_calls'][0]['options'][CURLOPT_POSTFIELDS];
        assertContact($fields['html'] === $html, 'Mailgun must receive the same safe HTML as server mail');
        assertContact($fields['text'] === "Name: {$name}\nEmail: person@example.com\n\n{$message}", 'Mailgun must retain the plain-text alternative');
        assertContact($fields['h:Reply-To'] === 'person@example.com', 'Mailgun must retain validated Reply-To');
        $mailgun = new MailerService($config->paths(), $mailConfig);
        assertContactFailure(fn() => $mailgun->sendContact('Test', "person@example.com\nBcc: other@example.com", 'Test', 'Test'), 'Mailgun must reject injected reply addresses');
        assertContact(count($GLOBALS['contact_mailgun_calls']) === 1, 'Invalid Reply-To must not initialize Mailgun delivery');
        foreach ([400, 401, 429, 500] as $status) {
            $GLOBALS['contact_curl_status'] = $status;
            assertContactFailure(fn() => $mailgun->sendContact('Test', 'person@example.com', 'Test', 'Test'), 'Mailgun non-success responses must fail delivery');
        }
        unset($GLOBALS['contact_curl_status']);
        $GLOBALS['contact_curl_response'] = false;
        assertContactFailure(fn() => $mailgun->sendContact('Test', 'person@example.com', 'Test', 'Test'), 'A failed Mailgun transport must not report success');
        unset($GLOBALS['contact_curl_response']);
        $executions = $GLOBALS['contact_curl_executions'];
        $closes = $GLOBALS['contact_curl_closes'];
        $GLOBALS['contact_curl_configured'] = false;
        assertContactFailure(fn() => $mailgun->sendContact('Test', 'person@example.com', 'Test', 'Test'), 'Mailgun setup failure must raise a controlled delivery error');
        unset($GLOBALS['contact_curl_configured']);
        assertContact($GLOBALS['contact_curl_executions'] === $executions && $GLOBALS['contact_curl_closes'] === $closes + 1, 'Failed setup must close the handle without executing delivery');
        $GLOBALS['contact_curl_init_failure'] = true;
        assertContactFailure(fn() => $mailgun->sendContact('Test', 'person@example.com', 'Test', 'Test'), 'Mailgun initialization failure must raise a controlled delivery error');
        unset($GLOBALS['contact_curl_init_failure']);
        assertContact($GLOBALS['contact_curl_executions'] === $executions, 'Failed initialization must not execute delivery');
    }
    echo "Contact integration checks passed\n";
} finally { removeContact($root); }

function assertContact(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function assertContactFailure(callable $operation, string $message): void {
    $rejected = false;
    try { $operation(); } catch (RuntimeException) { $rejected = true; }
    assertContact($rejected, $message);
}
function removeContact(string $path): void { if(!is_dir($path)) return; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir()?rmdir((string)$item):unlink((string)$item); rmdir($path); }
}
