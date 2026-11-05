<?php

declare(strict_types=1);

namespace WorkspaceTests\Smoke;

use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Testing\PHPUnit\WaffleTestCase;
use Workspace\Factory\AppKernelFactory;

use function define;
use function defined;
use function dirname;

/**
 * Le pont de test `waffle-commons/testing` (TEST-01) sur le vrai kernel du
 * workspace : chaque requête traverse le pipeline PSR-15 réel, en processus,
 * sans serveur web ni réseau, puis le kernel est réinitialisé comme dans le
 * worker.
 */
final class KernelBridgeTest extends WaffleTestCase
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
        return AppKernelFactory::create(env: 'dev', debug: false);
    }

    public function testTheHomePageAnswersThroughTheRealPipeline(): void
    {
        $client = $this->createClient();

        $first = $client->request('GET', '/');
        $second = $client->request('GET', '/');

        self::assertStatus(200, $first);
        // Même requête, même réponse : aucun état ne fuit d'une requête à la suivante.
        self::assertJsonBody($first->json(), $second);
    }

    public function testARegistrationIsHydratedFromItsJsonBody(): void
    {
        $response = $this->createClient()->jsonRequest('POST', '/register', [
            'email' => 'Ada@Example.com',
            'username' => 'ada',
            'age' => 36,
            'signupIp' => '192.0.2.10',
        ]);

        self::assertStatus(200, $response);
        self::assertJsonBody(
            [
                'email' => 'ada@example.com',
                'username' => 'ada',
                'age' => 36,
                'signup_ip' => '192.0.2.10',
            ],
            $response,
        );
    }

    public function testAnInvalidRegistrationIsRefusedByTheDtoHooks(): void
    {
        $response = $this->createClient()->jsonRequest('POST', '/register', [
            'email' => 'ada@example.com',
            'username' => 'ada',
            'age' => 12,
            'signupIp' => '192.0.2.10',
        ]);

        self::assertStatus(422, $response);
    }
}
