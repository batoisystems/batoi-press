<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/url.php';
use Batoi\Press\Core\Config;
use Batoi\Press\Admin\HealthController;
function mediaPerfCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$root = dirname(__DIR__, 2);
$config = Config::load($root);
    foreach (['editor','author','viewer'] as $role) mediaPerfCheck((new HealthController($config,['role'=>$role]))->index()->status() === 403, 'health role restriction');
    $health = (new HealthController($config,['role'=>'owner']))->index();
    mediaPerfCheck($health->headers()['Cache-Control'] === 'private, no-store' && str_contains($health->content(), 'Not verified') && !str_contains($health->content(), $root), 'health is private with honest compression status and no paths');
echo "Hosting health checks passed\n";
