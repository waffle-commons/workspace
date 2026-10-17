<?php

declare(strict_types=1);

namespace WorkspaceTests\Factory;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Redis;
use Workspace\Factory\QueueFactory;

/**
 * Le pool Redis de la file d'attente (QUEUE-02), contre le Redis du workspace
 * (service compose `waffle-redis`).
 */
final class QueueFactoryTest extends TestCase
{
    public function testLePoolPreteUneConnexionPhpredisVivante(): void
    {
        $pool = QueueFactory::redisPool('redis://waffle-redis:6379');

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
            QueueFactory::redisPool('redis:///');
            static::fail('Le DSN aurait dû être refusé.');
        } catch (InvalidArgumentException $refus) {
            static::assertStringNotContainsString('redis:', $refus->getMessage());
        }
    }
}
