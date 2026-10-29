<?php

declare(strict_types=1);

namespace WorkspaceTests\Factory;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Waffle\Commons\Auth\SecurityContext;
use Waffle\Commons\Contracts\Telemetry\Metrics\MetricsRegistryInterface;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\ServerRequestFactory;
use Waffle\Commons\Security\Middleware\RateLimitMiddleware;
use Waffle\Commons\Security\RateLimit\RateLimiter;
use Workspace\Factory\RateLimitFactory;
use WorkspaceTests\Fixture\ArrayConfig;

/**
 * La limitation de débit du workspace (NET-01) : aucun middleware quand elle est
 * coupée ; construite, elle compte dans un seau partagé sur le Redis de sa propre clé
 * `redis_dsn` (service compose `waffle-redis`), et une panne de ce Redis est comptée
 * dans le registre de métriques.
 *
 * Les seaux survivent à l'exécution (ils vivent dans Redis) : chaque test compte pour
 * une IP de documentation tirée au hasard, jamais deux fois la même.
 */
final class RateLimitFactoryTest extends TestCase
{
    private const string KEY = 'waffle.security.rate_limit.';

    public function testRienNEstConstruitQuandLeLimiteurEstCoupe(): void
    {
        static::assertNull(RateLimitFactory::middleware(
            new ArrayConfig([self::KEY . 'enabled' => false]),
            new SecurityContext(),
            new ResponseFactory(),
            new NullLogger(),
        ));
    }

    public function testLeSeauPartageSeVideSurLeRedisDeSaCleEtRepond429(): void
    {
        // Sans sa clé `redis_dsn`, le limiteur viserait 127.0.0.1:6379, où rien
        // n'écoute dans le conteneur : il laisserait tout passer (mode open).
        $limiter = self::limiter(new ArrayConfig([
            self::KEY . 'enabled' => true,
            self::KEY . 'redis_dsn' => 'redis://waffle-redis:6379',
            self::KEY . 'capacity' => 2,
        ]));
        $client = self::documentationIp();

        $premier = $limiter->process(self::request($client), self::handler());
        $second = $limiter->process(self::request($client), self::handler());
        $refus = $limiter->process(self::request($client), self::handler());

        static::assertSame(200, $premier->getStatusCode());
        static::assertSame('2', $premier->getHeaderLine('RateLimit-Limit'));
        static::assertSame('0', $second->getHeaderLine('RateLimit-Remaining'));
        static::assertSame(429, $refus->getStatusCode());
        static::assertGreaterThanOrEqual(1, (int) $refus->getHeaderLine('Retry-After'));
    }

    public function testUnePanneDuStockageEstCompteeDansLeRegistreDeMetriques(): void
    {
        $metrics = $this->createMock(MetricsRegistryInterface::class);
        $metrics->expects($this->once())->method('increment')->with(RateLimiter::STORAGE_ERRORS_METRIC, 1.0, [
            'mode' => 'open',
        ]);
        $limiter = RateLimitFactory::middleware(
            new ArrayConfig([self::KEY . 'enabled' => true, self::KEY . 'redis_dsn' => 'redis://127.0.0.1:1']),
            new SecurityContext(),
            new ResponseFactory(),
            new NullLogger(),
            $metrics,
        );
        static::assertInstanceOf(RateLimitMiddleware::class, $limiter);

        static::assertSame(
            200,
            $limiter->process(self::request(self::documentationIp()), self::handler())->getStatusCode(),
        );
    }

    private static function limiter(ArrayConfig $config): RateLimitMiddleware
    {
        $limiter = RateLimitFactory::middleware(
            $config,
            new SecurityContext(),
            new ResponseFactory(),
            new NullLogger(),
        );
        static::assertInstanceOf(RateLimitMiddleware::class, $limiter);

        return $limiter;
    }

    /** Une IPv6 de documentation (RFC 3849) neuve : son seau n'a pas d'histoire. */
    private static function documentationIp(): string
    {
        return sprintf('2001:db8::%x:%x', random_int(0, 0xffff), random_int(0, 0xffff));
    }

    private static function request(string $remoteAddress): ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest('GET', 'https://localhost/', [
            'REMOTE_ADDR' => $remoteAddress,
        ]);
    }

    private static function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new ResponseFactory()->createResponse(200);
            }
        };
    }
}
