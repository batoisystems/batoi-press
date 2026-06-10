<?php
declare(strict_types=1);

use Batoi\Press\Core\App;
use Batoi\Press\Core\Request;

require dirname(__DIR__) . '/radpress/autoload.php';
require dirname(__DIR__) . '/radpress/helpers/esc.php';
require dirname(__DIR__) . '/radpress/helpers/url.php';
require dirname(__DIR__) . '/radpress/helpers/date.php';

(new App(dirname(__DIR__)))->handle(Request::fromGlobals())->send();
