<?php

declare(strict_types=1);

namespace Workspace\Controller;

use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Async\TaskRunnerInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Commons\Log\Channel\LogChannel;
use Waffle\Commons\Log\StreamLogger;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;
use Workspace\Async\LoggingDeferredTask;

/**
 * Vitrine de la déferralisation post-réponse (AXE 2 / ASYNC-01).
 *
 * Le contrôleur diffère deux tâches courtes via le {@see TaskRunnerInterface}
 * sous un budget borné, puis répond immédiatement. Les tâches ne s'exécutent
 * qu'APRÈS l'émission de la réponse : sur `TerminateEvent`, le
 * {@see \Waffle\Event\Listener\DeferredTaskFlushListener} draine la file et lance
 * chaque tâche dans sa propre Fiber (isolation des erreurs). La latence perçue
 * par le client n'inclut donc pas ce travail.
 *
 * La réponse renvoie le nombre de tâches en attente *avant* le flush, rendant la
 * mise en file observable depuis le client.
 */
#[Route(path: '/async', name: 'async_')]
final class DeferredDemoController extends BaseController
{
    /**
     * GET /async/defer : met deux tâches en file pour la fin de requête.
     *
     * @throws RenderingException
     */
    #[Route(path: 'defer', methods: [Routing::METHOD_GET], name: 'defer')]
    #[PublicAccess]
    public function defer(TaskRunnerInterface $runner): ResponseInterface
    {
        // Logger dédié au canal applicatif : la sortie de la tâche apparaît dans
        // `docker logs` APRÈS la réponse, preuve du modèle finish-request.
        $logger = new StreamLogger(channel: LogChannel::APP);

        $runner->defer(new LoggingDeferredTask($logger, 'ORD-4242'));
        $runner->defer(new LoggingDeferredTask($logger, 'webhook-notify'));

        return $this->jsonResponse(data: [
            'deferred' => $runner->pending(),
            'note' => 'Les tâches s\'exécutent après cette réponse (TerminateEvent), hors du chemin de latence.',
        ]);
    }
}
