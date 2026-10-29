<?php

declare(strict_types=1);

namespace WorkspaceTests\Factory;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Redis;
use Workspace\Factory\RedisPoolFactory;

/**
 * Le pool Redis de l'état partagé (file QUEUE-02, limiteur NET-01, disjoncteur
 * NET-03), contre le Redis du workspace (service compose `waffle-redis`).
 */
final class RedisPoolFactoryTest extends TestCase
{
    public function testLePoolPreteUneConnexionPhpredisVivante(): void
    {
        $pool = RedisPoolFactory::create('redis://waffle-redis:6379');

        $lease = $pool->acquire();
        try {
            $client = $lease->client();
            static::assertInstanceOf(Redis::class, $client);
            static::assertTrue($lease->isAlive());
        } finally {
            $pool->release($lease);
        }
    }

    public function testUnDsnIllisibleEstRefuseSansEtreRecopie(): void
    {
        try {
            RedisPoolFactory::create('redis://:dsn-secret@waffle-redis:99999');
            static::fail('Le DSN aurait dû être refusé.');
        } catch (InvalidArgumentException $refus) {
            static::assertStringNotContainsString('dsn-secret', $refus->getMessage());
        }
    }
}
