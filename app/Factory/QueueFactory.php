<?php

declare(strict_types=1);

namespace Workspace\Factory;

use InvalidArgumentException;
use Redis;
use Waffle\Commons\Data\Connection\RedisConnectionPool;

/**
 * Les briques de la file d'attente (QUEUE-02) que `bin/waffle queue:work` assemble.
 */
final class QueueFactory
{
    /**
     * Construit le pool de connexions Redis (phpredis) de la file d'attente
     * (QUEUE-02) à partir d'un DSN `redis://[:mot-de-passe@]hôte[:port]`.
     *
     * Comme le pool PDO, il n'ouvre une connexion que lorsqu'on lui en emprunte
     * une, et la sonde `PING` écarte une connexion morte avant de la prêter.
     *
     * @throws InvalidArgumentException Si le DSN est illisible (son contenu n'est pas
     *                                  repris dans le message : il peut porter un mot de passe).
     */
    public static function redisPool(string $dsn): RedisConnectionPool
    {
        $parts = parse_url($dsn);
        if ($parts === false) {
            throw new InvalidArgumentException('Le DSN Redis de la file d\'attente est illisible.');
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 6379;
        $password = array_key_exists('pass', $parts) ? rawurldecode($parts['pass']) : null;

        return new RedisConnectionPool(
            factory: static function () use ($host, $port, $password): Redis {
                $redis = new Redis();
                $redis->connect($host, $port, 1.0);
                if ($password !== null) {
                    $redis->auth($password);
                }

                return $redis;
            },
            healthCheck: static fn(object $client): bool => $client instanceof Redis && $client->ping() !== false,
        );
    }
}
