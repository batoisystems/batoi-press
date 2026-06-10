<?php
declare(strict_types=1);

use Batoi\Press\Core\App;
use Batoi\Press\Core\Request;

require dirname(__DIR__) . '/radpress/autoload.php';
require dirname(__DIR__) . '/radpress/Helpers/esc.php';
require dirname(__DIR__) . '/radpress/Helpers/url.php';
require dirname(__DIR__) . '/radpress/Helpers/date.php';

(new App(dirname(__DIR__)))->handle(Request::fromGlobals())->send();

