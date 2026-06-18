<?php

declare(strict_types=1);

namespace Workspace\Controller;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

use function getenv;
use function is_string;

/**
 * Amont de la démonstration de traçage distribué (AXE 5 / OBS-01).
 *
 * Ouvre (via le `TracingMiddleware`) un span serveur racine, puis appelle le service
 * `waffle-downstream` avec le client HTTP : celui-ci injecte automatiquement le
 * `traceparent` W3C de la trace active. L'aval poursuit donc la MÊME trace ; on le
 * vérifie en comparant l'id de trace amont à l'en-tête `X-Waffle-Trace-Id` renvoyé.
 */
#[Route(path: '/trace-demo', name: 'trace_demo_')]
final class TraceDemoController extends BaseController
{
    /**
     * @throws RenderingException
     */
    #[Route(path: '', methods: [Routing::METHOD_GET], name: 'index')]
    #[PublicAccess]
    public function index(
        ServerRequestInterface $request,
        TracerInterface $tracer,
        ClientInterface $client,
    ): ResponseInterface {
        $context = $tracer->currentContext();
        $upstreamTraceId = $context?->traceId() ?? '(aucune)';
        $envName = getenv('WAFFLE_SERVICE_NAME');
        $service = is_string($envName) && $envName !== '' ? $envName : 'waffle';

        // Appel sortant vers le service aval sur le réseau interne Docker. Le client
        // HTTP injecte le `traceparent` de la trace active avant l'envoi (RFC-005 §5.5).
        $uri = $request
            ->getUri()
            ->withScheme('http')
            ->withHost('waffle-downstream')
            ->withPort(80)
            ->withPath('/trace-downstream')
            ->withQuery('');
        $response = $client->sendRequest($request->withUri($uri)->withMethod(Routing::METHOD_GET));

        $downstreamTraceId = $response->getHeaderLine('X-Waffle-Trace-Id');
        $downstreamService = $response->getHeaderLine('X-Waffle-Service');

        return $this->jsonResponse(data: [
            'service' => $service,
            'upstream_trace_id' => $upstreamTraceId,
            'downstream_service' => $downstreamService === '' ? '(inconnu)' : $downstreamService,
            'downstream_trace_id' => $downstreamTraceId === '' ? '(aucune)' : $downstreamTraceId,
            'shared_trace' => $upstreamTraceId !== '(aucune)' && $upstreamTraceId === $downstreamTraceId,
        ]);
    }
}
