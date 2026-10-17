<?php

declare(strict_types=1);

namespace Workspace\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\RateLimit\TokenBucket;
use Waffle\Commons\Data\RateLimit\RedisLimiterStateStorage;
use Waffle\Commons\Http\ClientIp\TrustedProxyClientIpResolver;
use Waffle\Commons\Pipeline\MiddlewareStack;
use Waffle\Commons\Security\Middleware\RateLimitMiddleware;
use Waffle\Commons\Security\RateLimit\RateLimiter;
use Waffle\Commons\Security\RateLimit\StorageFailureMode;

/**
 * Limitation de débit de l'application (NET-01, RFC-017 §3.1), sortie de
 * l'AppKernelFactory pour tenir sa complexité sous le seuil de 50.
 *
 * Un seau à jetons partagé dans Redis (jamais en mémoire du worker), clé = sujet
 * authentifié, sinon IP cliente résolue derrière les proxys de confiance. Rien
 * n'est ajouté quand `waffle.security.rate_limit.enabled` est faux.
 */
final class RateLimitFactory
{
    public static function register(
        MiddlewareStack $stack,
        Config $config,
        SecurityContextInterface $securityContext,
        ResponseFactoryInterface $responseFactory,
        LoggerInterface $logger,
    ): void {
        $middleware = self::middleware($config, $securityContext, $responseFactory, $logger);
        if ($middleware !== null) {
            $stack->add(middleware: $middleware);
        }
    }

    public static function middleware(
        Config $config,
        SecurityContextInterface $securityContext,
        ResponseFactoryInterface $responseFactory,
        LoggerInterface $logger,
    ): ?RateLimitMiddleware {
        if ($config->getBool('waffle.security.rate_limit.enabled') !== true) {
            return null;
        }

        $proxies = [];
        foreach ($config->getArray(key: 'waffle.security.rate_limit.trusted_proxies') ?? [] as $proxy) {
            if (!is_string($proxy)) {
                continue;
            }

            $proxies[] = $proxy;
        }

        $limiter = new RateLimiter(
            new RedisLimiterStateStorage(QueueFactory::redisPool(
                $config->getString(key: 'waffle.queue.redis_dsn') ?? 'redis://127.0.0.1:6379',
            )),
            new TokenBucket(
                $config->getInt('waffle.security.rate_limit.capacity') ?? 120,
                $config->getInt('waffle.security.rate_limit.period_seconds') ?? 60,
            ),
            StorageFailureMode::tryFrom($config->getString('waffle.security.rate_limit.on_storage_failure') ?? '')
            ?? StorageFailureMode::Open,
            $logger,
        );

        return new RateLimitMiddleware(
            $limiter,
            new TrustedProxyClientIpResolver($proxies),
            $securityContext,
            $responseFactory,
        );
    }
}
