<?php

declare(strict_types=1);

namespace WorkspaceTests\Factory;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Container\Container;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitBreakerPolicy;
use Waffle\Commons\Contracts\CircuitBreaker\Exception\CircuitOpenExceptionInterface;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;
use Waffle\Commons\Contracts\HttpClient\RetryPolicy;
use Waffle\Commons\Contracts\HttpClient\TimeoutPolicy;
use Waffle\Commons\Http\Factory\RequestFactory;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\StreamFactory;
use Waffle\Commons\HttpClient\Client;
use Waffle\Commons\HttpClient\Resilience\CircuitObservers;
use Waffle\Commons\HttpClient\Resilience\RetryingClient;
use Waffle\Commons\HttpClient\Security\SsrfGuard;
use Workspace\Factory\HttpClientFactory;

/**
 * La résilience sortante du workspace (NET-02/03) : politiques lues dans la
 * configuration, clients résilients dans le conteneur, disjoncteur sur le Redis
 * du workspace (service compose `waffle-redis`).
 */
final class HttpClientFactoryTest extends TestCase
{
    private const string REDIS = 'redis://waffle-redis:6379';

    public function testLesPolitiquesViennentDeLaConfiguration(): void
    {
        $config = self::config([
            'waffle.http_client.resilience.connect_timeout_ms' => 500,
            'waffle.http_client.resilience.total_timeout_ms' => 4000,
            'waffle.http_client.resilience.max_attempts' => 5,
            'waffle.http_client.resilience.base_delay_ms' => 50,
            'waffle.http_client.resilience.max_delay_ms' => 800,
            'waffle.http_client.resilience.budget_ms' => 6000,
            'waffle.http_client.resilience.retry_non_idempotent' => true,
            'waffle.http_client.breaker.failure_rate_percent' => 25,
            'waffle.http_client.breaker.minimum_calls' => 4,
            'waffle.http_client.breaker.window_seconds' => 30,
            'waffle.http_client.breaker.cool_down_seconds' => 15,
            'waffle.http_client.breaker.half_open_trials' => 2,
        ]);

        static::assertEquals(new TimeoutPolicy(500, 4000), HttpClientFactory::timeouts($config));
        static::assertEquals(new RetryPolicy(5, 50, 800, 6000, true), HttpClientFactory::retryPolicy($config));
        static::assertEquals(new CircuitBreakerPolicy(25, 4, 30, 15, 2), HttpClientFactory::breakerPolicy($config));
    }

    public function testUneCleAbsentePrendLaValeurDuContrat(): void
    {
        $config = self::config([]);

        static::assertEquals(new TimeoutPolicy(), HttpClientFactory::timeouts($config));
        static::assertEquals(new RetryPolicy(), HttpClientFactory::retryPolicy($config));
        static::assertEquals(new CircuitBreakerPolicy(), HttpClientFactory::breakerPolicy($config));
    }

    public function testLeConteneurRecoitDesClientsResilients(): void
    {
        $container = new Container();
        $transport = new Client(new ResponseFactory(), new StreamFactory());

        HttpClientFactory::register(
            $container,
            self::config([
                'waffle.http_client.breaker.enabled' => true,
                'waffle.http_client.breaker.redis_dsn' => self::REDIS,
            ]),
            $transport,
            $transport,
            new CircuitObservers(),
        );

        static::assertInstanceOf(RetryingClient::class, $container->get(ConcurrentClientInterface::class));
        static::assertInstanceOf(RetryingClient::class, $container->get(ClientInterface::class));
    }

    public function testUnDisjoncteurOuvertRepondSansAppelerLeTransport(): void
    {
        $config = self::config([
            'waffle.http_client.breaker.redis_dsn' => self::REDIS,
            'waffle.http_client.resilience.max_attempts' => 1,
            'waffle.http_client.breaker.minimum_calls' => 2,
        ]);
        $transport = new class implements ClientInterface {
            public int $calls = 0;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls++;

                return new ResponseFactory()->createResponse(503);
            }
        };
        $client = HttpClientFactory::resilient(
            $transport,
            $config,
            HttpClientFactory::circuitStorage($config),
            new CircuitObservers(),
        );
        // Une origine neuve à chaque exécution : son circuit n'a pas d'histoire.
        $request = new RequestFactory()->createRequest(
            'GET',
            'https://amont-' . bin2hex(random_bytes(6)) . '.example/x',
        );

        static::assertSame(503, $client->sendRequest($request)->getStatusCode());
        static::assertSame(503, $client->sendRequest($request)->getStatusCode());

        try {
            $client->sendRequest($request);
            static::fail('Deux échecs sur deux appels doivent ouvrir le circuit.');
        } catch (CircuitOpenExceptionInterface $ouvert) {
            static::assertSame(503, $ouvert->getCode());
            static::assertSame(2, $transport->calls);
        }
    }

    public function testUnePanneDnsDUnHotePublicOuvreSonCircuit(): void
    {
        $config = self::config([
            'waffle.http_client.breaker.redis_dsn' => self::REDIS,
            'waffle.http_client.resilience.max_attempts' => 1,
            'waffle.http_client.breaker.minimum_calls' => 2,
        ]);
        // La garde SSRF stricte résout elle-même l'hôte : une panne DNS est un échec réseau, pas un refus.
        $transport = new Client(new ResponseFactory(), new StreamFactory(), new SsrfGuard());
        $client = HttpClientFactory::resilient(
            $transport,
            $config,
            HttpClientFactory::circuitStorage($config),
            new CircuitObservers(),
        );
        // `.invalid` ne se résout jamais (RFC 2606) ; une origine neuve à chaque exécution.
        $request = new RequestFactory()->createRequest(
            'GET',
            'https://amont-' . bin2hex(random_bytes(6)) . '.invalid/',
        );

        self::assertPanneDns($client, $request);
        self::assertPanneDns($client, $request);

        $this->expectException(CircuitOpenExceptionInterface::class);
        $client->sendRequest($request);
    }

    public function testSansDisjoncteurLesReprisesSAppliquentSeules(): void
    {
        $transport = new class implements ClientInterface {
            public int $calls = 0;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls++;

                return new ResponseFactory()->createResponse($this->calls === 1 ? 503 : 200);
            }
        };
        $config = self::config(['waffle.http_client.resilience.base_delay_ms' => 1]);

        $client = HttpClientFactory::resilient($transport, $config, null, new CircuitObservers());

        static::assertSame(
            200,
            $client
                ->sendRequest(new RequestFactory()->createRequest('GET', 'https://amont.example/x'))
                ->getStatusCode(),
        );
        static::assertSame(2, $transport->calls);
    }

    private static function assertPanneDns(ClientInterface $client, RequestInterface $request): void
    {
        try {
            $client->sendRequest($request);
            static::fail('Un hôte introuvable doit échouer.');
        } catch (NetworkExceptionInterface $panne) {
            static::assertSame(CURLE_COULDNT_RESOLVE_HOST, $panne->getCode());
        }
    }

    /**
     * Une configuration en mémoire, clé pointée → valeur.
     *
     * @param array<string, int|string|bool> $values
     */
    private static function config(array $values): ConfigInterface
    {
        return new readonly class($values) implements ConfigInterface {
            /** @param array<string, int|string|bool> $values */
            public function __construct(
                private array $values,
            ) {}

            #[\Override]
            public function getInt(string $key, ?int $default = null): ?int
            {
                $value = $this->values[$key] ?? null;

                return is_int($value) ? $value : $default;
            }

            #[\Override]
            public function getString(string $key, ?string $default = null): ?string
            {
                $value = $this->values[$key] ?? null;

                return is_string($value) ? $value : $default;
            }

            #[\Override]
            public function getArray(string $key, ?array $default = null): ?array
            {
                return $default;
            }

            #[\Override]
            public function getBool(string $key, ?bool $default = null): ?bool
            {
                $value = $this->values[$key] ?? null;

                return is_bool($value) ? $value : $default;
            }
        };
    }
}
