<?php

declare(strict_types=1);

namespace Workspace\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Waffle\Commons\Contracts\Pipeline\MiddlewareStackInterface;
use Waffle\Commons\Contracts\Routing\RouterInterface;
use Waffle\Commons\OpenApi\Middleware\ApiDocsMiddleware;

/**
 * [API-01] Documentation de développement : `GET /_docs` (Swagger UI) et
 * `GET /_docs/openapi.json` (le document OpenAPI 3.1 généré depuis les routes),
 * en mode debug UNIQUEMENT, comme dans le skeleton. Le document décrit toutes les
 * routes, y compris celles qu'un voter protège ; hors debug, ces chemins
 * retombent sur le routeur.
 *
 * Extraite d'AppKernelFactory pour que la branche debug ne s'ajoute pas à sa
 * complexité, déjà au plafond CPLX-04.
 */
final class ApiDocsFactory
{
    public const string TITLE = 'API du workspace Waffle';

    /**
     * En debug, ajoute le middleware de documentation à la pile, à l'endroit de
     * l'appel : avant le routage, pour que `/_docs` ne soit jamais une route.
     */
    public static function register(
        MiddlewareStackInterface $stack,
        RouterInterface $router,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        bool $debug,
    ): void {
        if (!$debug) {
            return;
        }

        $stack->add(new ApiDocsMiddleware($router, $responseFactory, $streamFactory, self::TITLE));
    }
}
