<?php
declare(strict_types=1);

namespace Batoi\Press\Application {
    // Prevent real mail delivery while exercising the successful submission path.
    function mail(string $to, string $subject, string $message, string $headers): bool { return true; }
}
namespace {

use Batoi\Press\Application\ContactController;
use Batoi\Press\Core\Config;
use Batoi\Press\Core\Request;

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
    assertContact($disabled->status() === 503 && str_contains($disabled->content(), 'disabled'), 'valid contact submissions should report disabled delivery safely');
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
    echo "Contact integration checks passed\n";
} finally { removeContact($root); }

function assertContact(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function removeContact(string $path): void { if(!is_dir($path)) return; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item) $item->isDir()?rmdir((string)$item):unlink((string)$item); rmdir($path); }
}
