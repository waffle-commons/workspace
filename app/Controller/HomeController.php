<?php

declare(strict_types=1);

namespace Workspace\Controller;

use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Security\Attribute\Voter;
use Waffle\Commons\Routing\Attribute\Argument;
use Waffle\Commons\Routing\Attribute\Route;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;
use Workspace\Service\HomeService;
use Workspace\Voter\RestrictedAccess;

/**
 * This is a simple template controller to test the end-to-end
 * request/response lifecycle of the Waffle framework.
 */
#[Route(path: '/', name: 'home_')]
final class HomeController extends BaseController
{
    /**
     * Handles requests to the root path ("/").
     * @throws RenderingException
     */
    #[Route(path: '', name: 'index')]
    public function index(HomeService $service): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello());
    }

    /**
     * Handles dynamic requests to "/hello/{name}".
     * This tests the router's ability to handle parameters.
     * @throws RenderingException
     */
    #[Route(path: 'hello/{name}', name: 'hello', arguments: [
        new Argument(classType: 'string', paramName: 'name', required: false),
    ])]
    public function hello(HomeService $service, string $name): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello(to: $name));
    }

    /**
     * Handles errors handling.
     */
    #[Route(path: 'crash', name: 'crash')]
    public function crash(): ResponseInterface
    {
        throw new \RuntimeException('Something wrong appending!');
    }

    /**
     * Handles voters handling.
     * @throws RenderingException
     */
    #[Voter(name: RestrictedAccess::class)]
    #[Route(path: 'voter', name: 'voter')]
    public function voter(HomeService $service): ResponseInterface
    {
        return $this->jsonResponse(data: $service->sayHello());
    }
}
