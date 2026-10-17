<?php

declare(strict_types=1);

namespace Workspace\Factory;

use Waffle\Commons\Cache\Health\CacheHealthCheck;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Cache\Constant as CacheConstant;
use Waffle\Commons\Contracts\Data\Connection\ConnectionPoolInterface;
use Waffle\Commons\Contracts\Telemetry\Metrics\MetricsRegistryInterface;
use Waffle\Commons\Data\Health\ConnectionPoolHealthCheck;
use Waffle\Commons\Pipeline\Health\ReadinessProbe;
use Waffle\Commons\Queue\Health\StreamsHealthCheck;
use Waffle\Commons\Queue\RedisStreams\StreamOptions;

/**
 * Sondes de disponibilité de l'application (OPS-01, RFC-023 §3.3), sorties de
 * l'AppKernelFactory pour tenir sa complexité sous le seuil de 50.
 *
 * Le pool relationnel, le cache s'il est adossé à Redis (cache brut : une sonde
 * toutes les quelques secondes fausserait le ratio hits/misses), et le flux Redis
 * de la file — non critique, l'application web n'y publie pas encore.
 * `waffle.health.required` liste les sondes indispensables.
 */
final class HealthFactory
{
    public static function readinessProbe(
        Config $config,
        ConnectionPoolInterface $pool,
        CacheInterface $cache,
        MetricsRegistryInterface $metrics,
    ): ReadinessProbe {
        $checks = [new ConnectionPoolHealthCheck($pool)];
        if ($config->getString('waffle.cache.adapter') === CacheConstant::BACKEND_REDIS) {
            $checks[] = new CacheHealthCheck($cache);
        }

        $hostname = gethostname();
        $checks[] = new StreamsHealthCheck(
            QueueFactory::redisPool($config->getString(key: 'waffle.queue.redis_dsn') ?? 'redis://127.0.0.1:6379'),
            new StreamOptions(
                consumer: $hostname === false || $hostname === '' ? 'waffle' : $hostname,
                prefix: $config->getString(key: 'waffle.queue.prefix') ?? StreamOptions::DEFAULT_PREFIX,
            ),
            critical: false,
        );

        $required = [];
        foreach ($config->getArray(key: 'waffle.health.required') ?? [] as $name) {
            if (!is_string($name)) {
                continue;
            }

            $required[] = $name;
        }

        return new ReadinessProbe($checks, $required, $metrics);
    }
}
