<?php

declare(strict_types=1);

namespace WorkspaceTests\Smoke;

use PHPUnit\Framework\TestCase;

/**
 * Workspace is the integration playground. Concrete integration tests will land
 * here as the Beta 0 surface is exercised end-to-end. This smoke test exists so
 * that `phpunit` reports a real outcome instead of "No tests executed".
 */
final class SmokeTest extends TestCase
{
    public function testWorkspaceIsBootstrappable(): void
    {
        static::assertTrue(class_exists(\Workspace\Factory\AppKernelFactory::class));
    }
}
