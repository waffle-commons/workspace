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

/**
 * Vitrine de la transaction failsafe par requête (AXE 4 / DBAL-02).
 *
 * Cette route est un *write* (POST) : le {@see \Waffle\Commons\Data\Middleware\TransactionIsolationMiddleware}
 * — placé après la sécurité, avant le dispatcher — a déjà ouvert UNE transaction
 * sur une connexion épinglée (`beginRequestScope`). Tout `acquire()` du pool
 * relationnel pendant la requête rend donc CETTE même connexion : l'instruction
 * ci-dessous s'exécute dans la transaction du middleware, qui *commit* au
 * retour normal et *rollback* sur toute exception.
 *
 * L'instruction est volontairement neutre vis-à-vis du schéma (`SELECT 1`) :
 * en production, ce serait un INSERT / UPDATE via un dépôt authentifié. Ce qui
 * est démontré ici, c'est la FRONTIÈRE transactionnelle, pas la requête elle-même
 * — un endpoint `#[PublicAccess]` sans `#[RequiresCsrfToken]` ni limitation de
 * débit ne doit jamais committer une écriture durable (audit sécurité Beta6
 * AXE2 [FIX-01] #11 ; même correctif que `WriteDemoController` dans skeleton).
 *
 * La route est `#[PublicAccess]` pour rester atteignable sans jeton dans la démo
 * (une vraie application la protège par `#[Voter]` + `#[RequiresCsrfToken]`).
 */
#[Route(path: '/data', name: 'data_')]
final class TransactionDemoController extends BaseController
{
    /**
     * POST /data/users : exécute une lecture no-op dans la transaction de requête.
     *
     * @throws RenderingException
     * @throws \PDOException Propagée ⇒ le middleware effectue le rollback (DBAL-02).
     */
    #[Route(path: 'users', methods: [Routing::METHOD_POST], name: 'create_user')]
    #[PublicAccess]
    public function createUser(RelationalConnectionPoolInterface $pool): ResponseInterface
    {
        // Même bail épinglé que celui sur lequel le middleware a ouvert la
        // transaction : la lecture participe donc à CETTE transaction.
        $pdo = $pool->acquire()->pdo();
        $statement = $pdo->query('SELECT 1');
        $applied = $statement !== false;

        return $this->jsonResponse(data: [
            'applied' => $applied,
            'in_transaction' => $pdo->inTransaction(),
            'committed_by' => 'transaction-isolation-middleware',
            'note' => 'SELECT 1 exécuté dans la transaction ouverte par le middleware ; aucune écriture durable (démo publique sans CSRF).',
        ]);
    }
}
