<?php

declare(strict_types=1);

namespace Workspace\Controller;

use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * Vitrine de la transaction failsafe par requête (AXE 4 / DBAL-02).
 *
 * Cette route est un *write* (POST) : le {@see \Waffle\Commons\Data\Middleware\TransactionIsolationMiddleware}
 * — placé après la sécurité, avant le dispatcher — a déjà ouvert UNE transaction
 * sur une connexion épinglée (`beginRequestScope`). Tout `acquire()` du pool
 * relationnel pendant la requête rend donc CETTE même connexion : l'INSERT
 * ci-dessous s'exécute dans la transaction du middleware. Au retour normal du
 * contrôleur, le middleware *commit* ; sur toute exception, il *rollback* — un
 * write à moitié appliqué ne peut jamais fuir d'une itération worker à l'autre.
 *
 * La route est `#[PublicAccess]` pour rester atteignable sans jeton dans la démo
 * (une vraie application la protège par `#[Voter]` + `#[RequiresCsrfToken]`).
 */
#[Route(path: '/data', name: 'data_')]
final class TransactionDemoController extends BaseController
{
    /**
     * POST /data/users : insère un utilisateur dans la transaction de requête.
     *
     * @throws RenderingException
     * @throws \PDOException Propagée ⇒ le middleware effectue le rollback (DBAL-02).
     */
    #[Route(path: 'users', methods: [Routing::METHOD_POST], name: 'create_user')]
    #[PublicAccess]
    public function createUser(RelationalConnectionPoolInterface $pool): ResponseInterface
    {
        // Même bail épinglé que celui sur lequel le middleware a ouvert la
        // transaction : l'INSERT participe donc à CETTE transaction.
        $pdo = $pool->acquire()->pdo();

        $id = bin2hex(random_bytes(16));
        $email = sprintf('demo-%s@waffle.dev', $id);

        $statement = $pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (:id, :email, :hash)');
        $statement->execute([
            ':id' => $id,
            ':email' => $email,
            // Démo : empreinte non secrète ; une vraie app utilise password_hash().
            ':hash' => bin2hex(random_bytes(16)),
        ]);

        return $this->jsonResponse(data: [
            'id' => $id,
            'email' => $email,
            'committed_by' => 'transaction-isolation-middleware',
            'note' => 'INSERT exécuté dans la transaction ouverte par le middleware ; commit au retour 2xx.',
        ], status: 201);
    }
}
