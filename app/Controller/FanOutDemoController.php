<?php

declare(strict_types=1);

namespace Workspace\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

/**
 * Vitrine du fan-out HTTP concurrent (AXE 2 / ASYNC-02).
 *
 * Construit plusieurs requêtes sortantes vers le service aval `waffle-downstream`
 * et les résout EN PARALLÈLE via {@see ConcurrentClientInterface::sendRequests()} :
 * une seule boucle multi-handle partagée, de sorte que N requêtes terminent en
 * gros dans le temps mur de la plus lente — et non de leur somme. Les clés
 * d'entrée sont préservées dans le tableau de réponses.
 *
 * Le client concurrent est le client HTTP PSR-18 brut (qui implémente
 * `ConcurrentClientInterface`), exposé sous ce contrat par la fabrique du kernel,
 * distinct du `ClientInterface` décoré par le pont d'authentification.
 */
#[Route(path: '/async', name: 'fanout_')]
final class FanOutDemoController extends BaseController
{
    private const int FAN_OUT_COUNT = 3;

    /**
     * GET /async/fan-out : N appels aval concurrents, résolus en parallèle.
     *
     * @throws RenderingException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    #[Route(path: 'fan-out', methods: [Routing::METHOD_GET], name: 'fanout')]
    #[PublicAccess]
    public function fanOut(ServerRequestInterface $request, ConcurrentClientInterface $client): ResponseInterface
    {
        $baseUri = $request
            ->getUri()
            ->withScheme('http')
            ->withHost('waffle-downstream')
            ->withPort(80)
            ->withPath('/trace-downstream')
            ->withQuery('');

        // Un lot de requêtes GET indépendantes, indexées par clé logique.
        $requests = [];
        for ($i = 1; $i <= self::FAN_OUT_COUNT; $i++) {
            $requests['call-' . $i] = $request
                ->withUri($baseUri->withQuery('n=' . $i))
                ->withMethod(Routing::METHOD_GET);
        }

        // Résolution concurrente : une boucle multi-handle, clés préservées.
        $responses = $client->sendRequests($requests);

        $results = [];
        foreach ($responses as $key => $response) {
            $results[$key] = [
                'status' => $response->getStatusCode(),
                'downstream_service' => $response->getHeaderLine('X-Waffle-Service'),
            ];
        }

        return $this->jsonResponse(data: [
            'fan_out' => self::FAN_OUT_COUNT,
            'results' => $results,
            'note' =>
                'Les '
                    . self::FAN_OUT_COUNT
                    . ' appels sont résolus en parallèle (≈ temps de la requête la plus lente).',
        ]);
    }
}
