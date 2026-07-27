<?php

declare(strict_types=1);

namespace WorkspaceTests\Factory;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Workspace\Factory\AppKernelFactory;

/**
 * Garde fail-closed : `APP_ENV=prod` combiné à `APP_DEBUG=true` doit refuser
 * de démarrer (audit sécurité Beta6 AXE2 [FIX-01] #11). Un débogueur actif en
 * production exposerait des traces via `JsonErrorRenderer` — même discipline
 * que les gardes existantes sur les secrets CSRF / du pont d'authentification
 * dans la même fabrique.
 */
final class AppKernelFactoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Constantes de boot normalement définies par public/index.php.
        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 3));
        }
        if (!defined('APP_CONFIG')) {
            define('APP_CONFIG', 'config');
        }
    }

    public function testProdWithDebugTrueThrowsFailClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=prod avec APP_DEBUG=true');

        AppKernelFactory::create(env: 'prod', debug: true);
    }

    #[DataProvider('safeCombinations')]
    public function testOtherCombinationsStillBootTheKernel(string $env, bool $debug): void
    {
        $kernel = AppKernelFactory::create(env: $env, debug: $debug);

        static::assertInstanceOf(KernelInterface::class, $kernel);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function safeCombinations(): iterable
    {
        yield 'dev + debug on' => ['dev', true];
        yield 'dev + debug off' => ['dev', false];
        yield 'prod + debug off' => ['prod', false];
    }

    /**
     * FIX-01 (audit Beta6): a malformed config YAML must not blow through
     * kernel boot as an uncaught exception — AppKernelFactory::create() now
     * catches InvalidConfigurationException and retries with
     * Failsafe::ENABLED. config/app.yaml is the app's real, git-tracked
     * config (this app has no isolated test fixture the way skeleton does)
     * — corrupted here and restored in `finally`, never left invalid even if
     * the assertion itself fails.
     */
    public function testFallsBackToFailsafeWhenConfigIsMalformed(): void
    {
        $configPath = APP_ROOT . '/' . APP_CONFIG . '/app.yaml';
        $original = file_get_contents($configPath);
        static::assertIsString($original);

        file_put_contents($configPath, "waffle:\n  invalid: [ unclosed sequence\n");

        try {
            $kernel = AppKernelFactory::create(env: 'dev', debug: true);

            static::assertInstanceOf(KernelInterface::class, $kernel);
        } finally {
            file_put_contents($configPath, $original);
        }
    }
}
