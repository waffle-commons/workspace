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
use Waffle\Commons\Security\Container\SecureContainer;
use Waffle\Commons\Security\Security;
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

        // 3. Instantiate Security (from waffle-commons/security)
        $security = new Security($config);

        // 4. Wrap the container with Security Decorator
        $secureContainer = new SecureContainer($container, $security);

        // 5. Instantiate the Kernel
        $kernel = new Kernel();

        // 6. Inject Dependencies
        if (method_exists($kernel, 'setConfiguration')) {
            $kernel->setConfiguration($config);
        }
        
        if (method_exists($kernel, 'setSecurity')) {
            $kernel->setSecurity($security);
        }

        if (method_exists($kernel, 'setContainerImplementation')) {
            $kernel->setContainerImplementation($secureContainer);
        }

        // 7. Instantiate and Boot Router
        $controllersPath = $config->getString('waffle.paths.controllers');
        if (is_string($controllersPath)) {
            $router = new \Waffle\Commons\Routing\Router($root . DIRECTORY_SEPARATOR . $controllersPath);
            $router->boot($secureContainer);
            
            if (method_exists($kernel, 'setRouter')) {
                $kernel->setRouter($router);
            }
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
