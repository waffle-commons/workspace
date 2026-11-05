<?php

declare(strict_types=1);

namespace WorkspaceTests\Factory;

use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\StreamFactory;
use Waffle\Commons\OpenApi\Middleware\ApiDocsMiddleware;
use Waffle\Commons\Pipeline\MiddlewareStack;
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Testing\PHPUnit\WaffleTestCase;
use Workspace\Factory\ApiDocsFactory;
use Workspace\Factory\AppKernelFactory;

use function define;
use function defined;
use function dirname;

/**
 * [API-01] La documentation de développement du workspace : servie à travers le
 * vrai pipeline en debug, jamais montée hors debug. Hors debug, `/_docs`
 * retomberait sur la route attrape-tout, qui relaie vers le backend hérité :
 * ce cas est vérifié sur la pile, sans requête réseau.
 */
final class ApiDocsFactoryTest extends WaffleTestCase
{
    public static function setUpBeforeClass(): void
    {
        // Constantes de boot normalement définies par public/index.php.
        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 3));
        }
        if (!defined('APP_CONFIG')) {
            define('APP_CONFIG', 'config');
        }
    }

    #[\Override]
    protected function createKernel(): KernelInterface
    {
        return AppKernelFactory::create(env: 'dev', debug: true);
    }

    public function testInDebugThePageAndTheDocumentAreServed(): void
    {
        $client = $this->createClient();

        $page = $client->request('GET', '/_docs');
        $document = $client->request('GET', '/_docs/openapi.json');

        self::assertStatus(200, $page);
        static::assertStringContainsString('swagger-ui-bundle.js', $page->body);
        static::assertStringContainsString('API du workspace Waffle', $page->body);
        self::assertStatus(200, $document);
        static::assertSame('3.1.1', $document->json()['openapi'] ?? null);
    }

    public function testOutsideDebugNothingIsMounted(): void
    {
        $stack = new MiddlewareStack();
        $router = new Router(false);

        ApiDocsFactory::register($stack, $router, new ResponseFactory(), new StreamFactory(), false);
        static::assertSame([], $stack->getMiddlewares());

        ApiDocsFactory::register($stack, $router, new ResponseFactory(), new StreamFactory(), true);
        static::assertCount(1, $stack->getMiddlewares());
        static::assertInstanceOf(ApiDocsMiddleware::class, $stack->getMiddlewares()[0] ?? null);
    }
}
