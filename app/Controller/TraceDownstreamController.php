<?php

declare(strict_types=1);

namespace Workspace\Controller;

use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;

use function getenv;
use function is_string;

/**
 * Aval de la démonstration de traçage distribué (AXE 5 / OBS-01).
 *
 * Renvoie l'identifiant de trace actif. Si la requête entrante porte un en-tête
 * W3C `traceparent`, le `TracingMiddleware` l'a extrait et a ouvert le span serveur
 * comme ENFANT de cette trace : la réponse partage donc l'identifiant de trace de
 * l'amont. L'id est aussi exposé en en-tête `X-Waffle-Trace-Id` pour permettre une
 * assertion déterministe côté amont, sans parser le corps JSON.
 */
#[Route(path: '/trace-downstream', name: 'trace_downstream_')]
final class TraceDownstreamController extends BaseController
{
    /**
     * @throws RenderingException
     */
    #[Route(path: '', methods: [Routing::METHOD_GET], name: 'index')]
    #[PublicAccess]
    public function index(TracerInterface $tracer): ResponseInterface
    {
        $context = $tracer->currentContext();
        $traceId = $context?->traceId() ?? '(aucune)';
        $envName = getenv('WAFFLE_SERVICE_NAME');
        $service = is_string($envName) && $envName !== '' ? $envName : 'waffle';

        return $this->jsonResponse(data: [
            'service' => $service,
            'trace_id' => $traceId,
            'span_id' => $context?->spanId() ?? '(aucun)',
        ])->withHeader('X-Waffle-Trace-Id', $traceId)->withHeader('X-Waffle-Service', $service);
    }
}
