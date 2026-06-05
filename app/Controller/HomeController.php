<?php

declare(strict_types=1);

namespace Workspace\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Commons\Contracts\Security\Attribute\Voter;
use Waffle\Commons\Contracts\Security\Csrf\Attribute\RequiresCsrfToken;
use Waffle\Commons\Routing\Attribute\Argument;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;
use Workspace\Dto\HelloInput;
use Workspace\Service\HomeService;
use Workspace\Service\ProxyService;
use Workspace\Voter\RestrictedAccess;

/**
 * Contrôleur de démonstration du playground : exerce le cycle requête/réponse
 * complet du framework Waffle.
 *
 * Met en scène, de bout en bout :
 *   - des paramètres de route scalaires,
 *   - l'hydratation native d'un `#[Dto]` + validation par Property Hook,
 *   - l'interception d'exception par l'ErrorHandlerMiddleware,
 *   - une route catch-all à priorité négative simulant le hand-off vers la
 *     passerelle EcoShield (proxy vers le backend hérité).
 */
#[Route(path: '/', name: 'home_')]
final class HomeController extends BaseController
{
    /**
     * Endpoint racine : GET /.
     *
     * @throws RenderingException
     */
    #[Route(path: '', methods: [Routing::METHOD_GET], name: 'index')]
    #[PublicAccess]
    public function index(HomeService $service): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello());
    }

    /**
     * Démonstration d'un paramètre de chemin scalaire : GET /hello/{name}.
     * Le segment `{name}` est injecté tel quel par le resolver d'arguments.
     *
     * @throws RenderingException
     */
    #[Route(path: 'hello/{name}', methods: [Routing::METHOD_GET], name: 'hello', arguments: [
        new Argument(classType: 'string', paramName: 'name', required: false),
    ])]
    #[Voter(name: RestrictedAccess::class)]
    public function hello(HomeService $service, string $name): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello(to: $name));
    }

    /**
     * Démonstration d'hydratation native d'un DTO : POST /greet avec un corps
     * JSON `{"name": "Ada"}`.
     *
     * Le ControllerArgumentResolver décode le corps parsé, hydrate
     * {@see HelloInput} et le Property Hook valide la valeur. Un `name` invalide
     * lève une `ValidationException` que l'ErrorHandlerMiddleware sérialise en
     * RFC 7807 « 422 » — sans une seule ligne de validation dans le contrôleur.
     *
     * @throws RenderingException
     */
    #[Route(path: 'greet', methods: [Routing::METHOD_POST], name: 'greet')]
    #[Voter(name: RestrictedAccess::class)]
    #[RequiresCsrfToken]
    public function postGreet(HomeService $service, HelloInput $input): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayGreeting(to: $input->name));
    }

    /**
     * Variante GET protégée par jeton CSRF, à des fins de démonstration.
     *
     * @throws RenderingException
     */
    #[Route(path: 'greet', methods: [Routing::METHOD_GET], name: 'greeting')]
    #[Voter(name: RestrictedAccess::class)]
    public function getGreet(HomeService $service): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayGreeting(to: 'waffle-commons'));
    }

    /**
     * Démonstration de l'interception d'erreurs : GET /crash. N'importe quelle
     * exception levée est interceptée puis rendue en JSON structuré par le
     * middleware d'erreur.
     */
    #[Route(path: 'crash', name: 'crash')]
    #[PublicAccess]
    public function crash(): ResponseInterface
    {
        throw new RuntimeException('Quelque chose s\'est mal passé pendant la salutation !');
    }

    /**
     * Hand-off catch-all vers la passerelle (priorité -1000 ⇒ évaluée en dernier,
     * après toutes les routes explicites). Dans le playground, le forward est
     * réel : il délègue à `ProxyService::passThrough()` qui retransmet la
     * requête au backend hérité — preuve que la passerelle EcoShield est
     * branchable sur un vrai upstream sans modifier le squelette du contrôleur.
     */
    #[Route(
        path: '{path:.*}',
        methods: [
            Routing::METHOD_GET,
            Routing::METHOD_POST,
            Routing::METHOD_PUT,
            Routing::METHOD_PATCH,
            Routing::METHOD_DELETE,
        ],
        name: 'forward',
        priority: -1000,
    )]
    #[PublicAccess]
    public function forward(ServerRequestInterface $request, ProxyService $proxy): ResponseInterface
    {
        return $proxy->passThrough(req: $request);
    }
}
