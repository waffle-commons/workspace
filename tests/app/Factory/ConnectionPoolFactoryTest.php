<?php

declare(strict_types=1);

namespace WorkspaceTests\Factory;

use PDO;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Data\Exception\DatabaseException;
use Workspace\Factory\ConnectionPoolFactory;
use WorkspaceTests\Fixture\ArrayConfig;

/**
 * [CARRY-01] : la fenêtre de fraîcheur du ping du pool PDO, pilotée par
 * DB_PING_FRESHNESS_MS (`waffle.database.ping_freshness_ms`). Seule la lecture est
 * verrouillée ici (défaut 500, entier >= 0, repli sur le défaut si invalide) ; le
 * pool lui-même est paresseux et n'ouvre aucune connexion à sa construction.
 */
final class ConnectionPoolFactoryTest extends TestCase
{
    private const string KEY = 'waffle.database.ping_freshness_ms';

    public function testLaFenetreVautCinqCentsMillisecondesParDefaut(): void
    {
        static::assertSame(500, ConnectionPoolFactory::resolvePingFreshness(new ArrayConfig([])));
    }

    public function testUneFenetreValideEstLueZeroCompris(): void
    {
        static::assertSame(250, ConnectionPoolFactory::resolvePingFreshness(new ArrayConfig([self::KEY => '250'])));
        // 0 est légitime : il rétablit le ping à chaque emprunt.
        static::assertSame(0, ConnectionPoolFactory::resolvePingFreshness(new ArrayConfig([self::KEY => '0'])));
    }

    public function testUneFenetreInvalideRetombeSurLeDefaut(): void
    {
        foreach (['-1', 'cinq-cents', ''] as $invalide) {
            static::assertSame(500, ConnectionPoolFactory::resolvePingFreshness(new ArrayConfig([
                self::KEY => $invalide,
            ])));
        }
    }

    public function testLaCleDuFichierAppYamlSuitDbPingFreshnessMs(): void
    {
        // Le vrai config/app.yaml : la clé lue par la fabrique est celle qu'il déclare.
        $config = new Config(configDir: dirname(__DIR__, 3) . '/config', environment: Constant::ENV_DEV, env: [
            'DB_PING_FRESHNESS_MS' => '250',
        ]);

        static::assertSame(250, ConnectionPoolFactory::resolvePingFreshness($config));
    }

    public function testLeDelaiDeConnexionVautDeuxSecondesParDefautEtLitUneValeurValide(): void
    {
        $key = 'waffle.database.connect_timeout';
        static::assertSame(2, ConnectionPoolFactory::resolveConnectTimeout(new ArrayConfig([])));
        static::assertSame(5, ConnectionPoolFactory::resolveConnectTimeout(new ArrayConfig([$key => '5'])));
        // Zéro supprimerait le délai (attente illimitée) : refusé, comme une valeur non numérique.
        foreach (['0', '-3', 'deux', ''] as $invalide) {
            static::assertSame(2, ConnectionPoolFactory::resolveConnectTimeout(new ArrayConfig([$key => $invalide])));
        }
    }

    /**
     * RFC-023 §3.2 : une base qui ne répond jamais (10.255.255.1 ne répond à aucun
     * SYN) fait échouer l'emprunt dans le délai de connexion, au lieu de bloquer le
     * worker — et la sonde /readyz — pendant les minutes du délai TCP du système.
     */
    public function testUneBaseQuiNeRepondJamaisEchoueDansLeDelaiDeConnexion(): void
    {
        $pool = ConnectionPoolFactory::create(new ArrayConfig([
            'waffle.database.driver' => 'pgsql',
            'waffle.database.host' => '10.255.255.1',
            'waffle.database.port' => '5432',
            'waffle.database.database' => 'waffle_test',
            'waffle.database.connect_timeout' => '2',
        ]));
        $debut = hrtime(true);

        try {
            $pool->acquire();
            static::fail('Une base qui ne répond jamais ne peut pas prêter de connexion.');
        } catch (DatabaseException) {
            static::assertLessThan(5.0, (hrtime(true) - $debut) / 1e9);
        }
    }

    public function testLePoolSQLiteSOuvreAuPremierEmprunt(): void
    {
        $pool = ConnectionPoolFactory::create(new ArrayConfig([
            'waffle.database.driver' => 'sqlite',
            'waffle.database.database' => ':memory:',
        ]));
        static::assertSame(0, $pool->idleCount());
        static::assertSame(0, $pool->activeCount());

        $pdo = $pool->acquire()->pdo();

        static::assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        static::assertSame(1, $pool->activeCount());

        $pool->reset();
    }
}
