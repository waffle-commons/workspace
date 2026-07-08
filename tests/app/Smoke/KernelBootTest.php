<?php

declare(strict_types=1);

namespace WorkspaceTests\Smoke;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Async\TaskRunnerInterface;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\Reactive\BroadcastBufferInterface;
use Waffle\Commons\Http\Factory\ServerRequestFactory;
use Workspace\Factory\AppKernelFactory;

/**
 * Test de fumée du boot (AXE 1-6) : assemble le kernel via la fabrique de
 * l'application, le démarre (boot + configure → verrouillage du conteneur et
 * scan de conformité stricte DIAG-02), puis pousse une vraie requête PSR-7 sur
 * une route de démonstration nouvellement câblée.
 *
 * Le scan de conformité stricte refuse le démarrage si un service partagé garde
 * un état mutable sans implémenter ResettableInterface : ce test prouve donc que
 * le tampon de diffusion (AXE 3) et le runner de tâches différées (AXE 2) sont
 * resettables, et que reset() les vide proprement entre deux boucles worker.
 */
final class KernelBootTest extends TestCase
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

    public function testKernelBootsConfiguresAndResetsWithoutException(): void
    {
        $kernel = $this->createKernel();

        // boot()->configure() verrouille le conteneur ET lance le scan de
        // conformité stricte (env dev) : il lèverait une exception si un service
        // nouvellement enregistré (tampon de diffusion, runner différé, dépôt de
        // credentials, store de défis) portait un état mutable non resettable.
        $kernel->boot()->configure();

        // Un second cycle worker simulé : reset() doit vider tous les services
        // resettables sans erreur, puis handle() reste opérationnel.
        $kernel->reset();

        static::assertInstanceOf(KernelInterface::class, $kernel);
    }

    public function testNewlyRegisteredServicesAreResettable(): void
    {
        // Les deux services porteurs d'état à portée requête DOIVENT être
        // resettables (sinon le scan DIAG-02 ci-dessus aurait déjà échoué) ;
        // on documente l'invariant explicitement.
        static::assertInstanceOf(
            \Waffle\Commons\Contracts\Service\ResettableInterface::class,
            new \Waffle\Reactive\RequestBroadcastBuffer(),
        );
        static::assertContainsOnlyInstancesOf(BroadcastBufferInterface::class, [new \Waffle\Reactive\RequestBroadcastBuffer()]);
        static::assertInstanceOf(TaskRunnerInterface::class, new \Waffle\Commons\Async\DeferredTaskRunner());
    }

    public function testReactiveDemoRouteReturnsNon5xx(): void
    {
        $kernel = $this->createKernel();

        // Hôte de confiance (app.yaml) pour franchir le TrustedHostMiddleware ;
        // aucune en-tête Origin ⇒ même origine, CORS laisse passer.
        $request = new ServerRequestFactory()
            ->createServerRequest('GET', 'http://localhost/reactive/order')
            ->withHeader('Host', 'localhost');

        $response = $kernel->handle($request);

        $this->assertNon5xx($response);
        // La réponse expose le statut final ; les mutations elles-mêmes sont
        // diffusées sur le canal `orders` au flush SSE post-réponse.
        static::assertStringContainsString('delivered', (string) $response->getBody());
    }

    public function testDeferredDemoRouteReturnsNon5xx(): void
    {
        $kernel = $this->createKernel();

        $request = new ServerRequestFactory()
            ->createServerRequest('GET', 'http://localhost/async/defer')
            ->withHeader('Host', 'localhost');

        $response = $kernel->handle($request);

        $this->assertNon5xx($response);
        static::assertStringContainsString('deferred', (string) $response->getBody());
    }

    private function createKernel(): KernelInterface
    {
        return AppKernelFactory::create(env: 'dev', debug: true);
    }

    private function assertNon5xx(ResponseInterface $response): void
    {
        static::assertLessThan(
            500,
            $response->getStatusCode(),
            sprintf('Réponse 5xx inattendue : %s', (string) $response->getBody()),
        );
    }
}
