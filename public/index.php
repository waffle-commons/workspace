<?php

declare(strict_types=1);

use Waffle\Commons\Runtime\WaffleRuntime;
use Workspace\Factory\AppKernelFactory;

require_once __DIR__ . '/../vendor/autoload.php';

define('APP_ROOT', realpath(path: dirname(path: __DIR__)));
const APP_CONFIG = 'config';

// 1. Context & Glue
// We use the Factory to create the concrete implementations
$kernel = AppKernelFactory::create(env: 'dev');
$request = AppKernelFactory::createRequest();
$emitter = AppKernelFactory::createEmitter();

// 2. Runtime (Agnostic)
// The runtime now just orchestrates: Kernel + Request -> Emitter
$runtime = new WaffleRuntime();

// This fixes your Fatal Error by passing exactly 3 arguments:
$runtime->run($kernel, $request, $emitter);