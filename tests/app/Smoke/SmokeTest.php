<?php

declare(strict_types=1);

namespace WorkspaceTests\Smoke;

use PHPUnit\Framework\TestCase;

/**
 * Le workspace est le playground d'intégration. Des tests d'intégration concrets
 * atterriront ici à mesure que la surface Beta 0 sera exercée de bout en bout.
 * Ce smoke test existe pour que `phpunit` rapporte un vrai résultat plutôt que
 * « No tests executed ».
 */
final class SmokeTest extends TestCase
{
    public function testWorkspaceIsBootstrappable(): void
    {
        static::assertTrue(class_exists(\Workspace\Factory\AppKernelFactory::class));
    }
}
