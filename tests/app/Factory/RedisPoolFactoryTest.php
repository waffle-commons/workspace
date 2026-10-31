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

    /**
     * RFC-023 §3.2 : chaque lecture attend au plus une seconde par défaut ; `null`
     * laisse le délai de PHP au pool de `queue:work`, qui bloque sur le flux.
     */
    public function testUneLectureAttendAuPlusUneSecondeSaufPourLeWorker(): void
    {
        $pool = RedisPoolFactory::create('redis://waffle-redis:6379');
        $lease = $pool->acquire();
        try {
            $client = $lease->client();
            static::assertInstanceOf(Redis::class, $client);
            static::assertSame(RedisPoolFactory::READ_TIMEOUT, $client->getOption(Redis::OPT_READ_TIMEOUT));
        } finally {
            $pool->release($lease);
        }

        $worker = RedisPoolFactory::create('redis://waffle-redis:6379', null);
        $lease = $worker->acquire();
        try {
            $client = $lease->client();
            static::assertInstanceOf(Redis::class, $client);
            static::assertNotSame(RedisPoolFactory::READ_TIMEOUT, $client->getOption(Redis::OPT_READ_TIMEOUT));
        } finally {
            $worker->release($lease);
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
