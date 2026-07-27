<?php

declare(strict_types=1);

namespace WorkspaceTests\Controller;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Contracts\Data\Connection\PdoConnectionInterface;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Workspace\Controller\TransactionDemoController;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Régression audit sécurité Beta6 AXE2 [FIX-01] #10 : `POST /data/users` est
 * `#[PublicAccess]` sans `#[RequiresCsrfToken]` ni limitation de débit — elle
 * ne doit donc plus jamais committer d'écriture durable. Seule une lecture
 * neutre (`SELECT 1`) est autorisée dans la transaction ouverte par le
 * middleware ; `prepare()`/`exec()` (les points d'entrée d'une écriture PDO)
 * ne doivent jamais être sollicités.
 */
#[AllowMockObjectsWithoutExpectations]
final class TransactionDemoControllerTest extends TestCase
{
    public function testCreateUserOnlyRunsAReadOnlySelectAndNeverWrites(): void
    {
        $statement = $this->createMock(PDOStatement::class);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('query')->with('SELECT 1')->willReturn($statement);
        // Preuve négative : aucune écriture n'est tentée sur cette route publique.
        $pdo->expects(self::never())->method('prepare');
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::once())->method('inTransaction')->willReturn(true);

        $connection = $this->createMock(PdoConnectionInterface::class);
        $connection->expects(self::once())->method('pdo')->willReturn($pdo);

        $pool = $this->createMock(RelationalConnectionPoolInterface::class);
        $pool->expects(self::once())->method('acquire')->willReturn($connection);

        $controller = new TransactionDemoController();
        $controller->setResponseFactory(new ResponseFactory());

        $response = $controller->createUser($pool);

        static::assertSame(200, $response->getStatusCode());

        /** @var array{applied: bool, in_transaction: bool, committed_by: string, note: string} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        static::assertTrue($payload['applied']);
        static::assertTrue($payload['in_transaction']);
        static::assertStringNotContainsStringIgnoringCase('insert', $payload['note']);
    }
}
