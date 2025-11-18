<?php

declare(strict_types=1);

use Waffle\Commons\Runtime\WaffleRuntime;
use Workspace\Kernel;

require_once __DIR__ . '/../vendor/autoload.php';

define('APP_ROOT', realpath(path: dirname(path: __DIR__)));
const APP_CONFIG = 'config';

$kernel = new Kernel();

$runtime = new WaffleRuntime();

$runtime->run($kernel);