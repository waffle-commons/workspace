<?php

declare(strict_types=1);

namespace Workspace\Factory;

use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Container\Container;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\Http\ResponseEmitterInterface;
use Waffle\Commons\Http\Emitter\ResponseEmitter;
use Waffle\Commons\Http\Factory\GlobalsFactory;
use Workspace\Kernel;

/**
 * The Glue Code: Assembles the application components.
 */
final class AppKernelFactory
{
    /**
     * Creates the fully assembled Kernel.
     */
    public static function create(string $env): KernelInterface
    {
        /** @var string $root */
        $root = APP_ROOT;
        $rootConfig = $root . DIRECTORY_SEPARATOR . APP_CONFIG;
        // 1. Instantiate the concrete Container (from waffle-commons/container)
        $container = new Container();

        // 2. Instantiate the concrete Config (from waffle-commons/config)
        $config = new Config(
            configDir: $rootConfig,
            environment: $env,
        );

        // 3. Instantiate the Kernel (from your workspace app)
        // Note: As we migrate, we will inject the container here in the future.
        // For now, we assume your Kernel might instantiate its dependencies internally
        // or accept them via constructor depending on your current Kernel refactoring state.
        $kernel = new Kernel();

        // If your Kernel allows injecting the container implementation:
        if (method_exists($kernel, 'setContainerImplementation')) {
            $kernel->setContainerImplementation($container);
        }

        // If your Kernel allows injecting the container implementation:
        if (method_exists($kernel, 'setConfiguration')) {
            $kernel->setConfiguration($config);
        }

        return $kernel;
    }

    /**
     * Creates the PSR-7 Request from globals.
     */
    public static function createRequest(): ServerRequestInterface
    {
        return new GlobalsFactory()->createFromGlobals();
    }

    /**
     * Creates the Response Emitter.
     */
    public static function createEmitter(): ResponseEmitterInterface
    {
        return new ResponseEmitter();
    }
}
