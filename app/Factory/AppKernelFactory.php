<?php

declare(strict_types=1);

namespace Workspace\Factory;

use PDO;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Waffle\Commons\Async\DeferredTaskRunner;
use Waffle\Commons\Auth\AuthenticationBridge;
use Waffle\Commons\Auth\Authenticator\ApiKeyAuthenticator;
use Waffle\Commons\Auth\Authenticator\AssertionAuthenticator;
use Waffle\Commons\Auth\Authenticator\JwtAuthenticator;
use Waffle\Commons\Auth\Client\AuthenticatedClient;
use Waffle\Commons\Auth\Credential\AssertionCredentialsProvider;
use Waffle\Commons\Auth\Identity\UserIdentity;
use Waffle\Commons\Auth\Jwt\JwtConfig;
use Waffle\Commons\Auth\Jwt\JwtValidator;
use Waffle\Commons\Auth\Jwt\Key\StaticKeyResolver;
use Waffle\Commons\Auth\Middleware\AuthenticationMiddleware;
use Waffle\Commons\Auth\SecurityContext;
use Waffle\Commons\Auth\Uab\AuthBridgeSigner;
use Waffle\Commons\Auth\Uab\AuthBridgeVerifier;
use Waffle\Commons\Auth\WebAuthn\WebAuthnAuthenticator;
use Waffle\Commons\Auth\WebAuthn\WebAuthnCeremony;
use Waffle\Commons\Auth\WebAuthn\WebAuthnLibAdapter;
use Waffle\Commons\Cache\Factory\CacheFactory;
use Waffle\Commons\Config\Config;
use Waffle\Commons\Config\DotEnv;
use Waffle\Commons\Container\Container;
use Waffle\Commons\Contracts\Async\TaskRunnerInterface;
use Waffle\Commons\Contracts\Auth\AuthenticationBridgeInterface;
use Waffle\Commons\Contracts\Auth\Constant as AuthConstant;
use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\Auth\WebAuthn\CredentialRepositoryInterface;
use Waffle\Commons\Contracts\Auth\WebAuthn\WebAuthnVerifierInterface;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Cache\Constant as CacheConstant;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\Data\Connection\ConnectionPoolInterface;
use Waffle\Commons\Contracts\Data\Connection\ConnectionTrackerInterface;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;
use Waffle\Commons\Contracts\Data\Migration\MigrationRunnerInterface;
use Waffle\Commons\Contracts\Handler\ArgumentResolverInterface;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;
use Waffle\Commons\Contracts\Reactive\BroadcastBufferInterface;
use Waffle\Commons\Contracts\Security\Csrf\Constant as CsrfConstant;
use Waffle\Commons\Contracts\Security\Csrf\CsrfTokenManagerInterface;
use Waffle\Commons\Contracts\Service\ReflectionServiceInterface;
use Waffle\Commons\Contracts\Telemetry\Metrics\MetricsCollectorInterface;
use Waffle\Commons\Contracts\Telemetry\Metrics\MetricsRegistryInterface;
use Waffle\Commons\Contracts\Telemetry\Metrics\NullMetricsRegistry;
use Waffle\Commons\Contracts\Telemetry\NullTextMapPropagator;
use Waffle\Commons\Contracts\Telemetry\NullTracer;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;
use Waffle\Commons\Contracts\Validation\ValidatorInterface;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Middleware\TransactionIsolationMiddleware;
use Waffle\Commons\Data\Migration\MigrationRunner;
use Waffle\Commons\ErrorHandler\Middleware\ErrorHandlerMiddleware;
use Waffle\Commons\ErrorHandler\Renderer\JsonErrorRenderer;
use Waffle\Commons\EventDispatcher\Dispatcher\EventDispatcher;
use Waffle\Commons\EventDispatcher\Provider\ListenerProvider;
use Waffle\Commons\Http\Factory\ResponseFactory;
use Waffle\Commons\Http\Factory\StreamFactory;
use Waffle\Commons\HttpClient\Client;
use Waffle\Commons\HttpClient\Security\SsrfGuard;
use Waffle\Commons\Log\Channel\LogChannel;
use Waffle\Commons\Log\StreamLogger;
use Waffle\Commons\Pipeline\CoreRoutingMiddleware;
use Waffle\Commons\Pipeline\Middleware\SecureHeadersMiddleware;
use Waffle\Commons\Pipeline\Middleware\TrustedHostMiddleware;
use Waffle\Commons\Pipeline\MiddlewareStack;
use Waffle\Commons\Routing\Router;
use Waffle\Commons\Runtime\Trace\ConnectionTracker;
use Waffle\Commons\Security\Container\SecureContainer;
use Waffle\Commons\Security\Cors\CorsPolicy;
use Waffle\Commons\Security\Csrf\CsrfTokenManager;
use Waffle\Commons\Security\Middleware\AnonymousSessionMiddleware;
use Waffle\Commons\Security\Middleware\CorsMiddleware;
use Waffle\Commons\Security\Middleware\CsrfMiddleware;
use Waffle\Commons\Security\Middleware\SecurityMiddleware;
use Waffle\Commons\Security\Security;
use Waffle\Commons\Telemetry\Collector\GcCollector;
use Waffle\Commons\Telemetry\Collector\MemoryCollector;
use Waffle\Commons\Telemetry\Collector\PoolUtilizationCollector;
use Waffle\Commons\Telemetry\Exporter\PrometheusExporter;
use Waffle\Commons\Telemetry\Metric\ApcuMetricStore;
use Waffle\Commons\Telemetry\Metric\MetricsRegistry;
use Waffle\Commons\Telemetry\Middleware\MetricsMiddleware;
use Waffle\Commons\Telemetry\Middleware\TracingMiddleware;
use Waffle\Commons\TelemetryOtel\Factory\OtelTracerFactory;
use Waffle\Commons\TelemetryOtel\Propagation\W3CTraceContextPropagator;
use Waffle\Commons\Utils\Validation\AssertValidator;
use Waffle\Event\Listener\BroadcastFlushListener;
use Waffle\Event\Listener\DeferredTaskFlushListener;
use Waffle\Event\Listener\OrphanedConnectionListener;
use Waffle\Event\TerminateEvent;
use Waffle\Handler\ControllerArgumentResolver;
use Waffle\Handler\ControllerDispatcher;
use Waffle\Reactive\RequestBroadcastBuffer;
use Waffle\Reactive\Sse\SseBroadcastTransport;
use Waffle\Service\ReflectionService;
use Workspace\Discovery\EventListenerDiscovery;
use Workspace\Kernel;
use Workspace\WebAuthn\InMemoryChallengeStore;
use Workspace\WebAuthn\InMemoryCredentialRepository;

/**
 * Code d'assemblage : monte les composants de l'application.
 */
final class AppKernelFactory
{
    /**
     * Construit le Kernel entièrement assemblé.
     */
    public static function create(string $env = Constant::ENV_PROD, bool $debug = false): KernelInterface
    {
        /** @var string $root */
        $root = APP_ROOT;
        $rootConfig = $root . DIRECTORY_SEPARATOR . APP_CONFIG;

        $isDev = $env === Constant::ENV_DEV;

        // DIAG-03 (dev) : registre des connexions ouvertes à portée requête. Injecté
        // dans les propriétaires de connexions (pool PDO, Redis, flux) et inspecté
        // par OrphanedConnectionListener en fin de requête. Null en prod ⇒ aucun coût.
        $connectionTracker = $isDev ? new ConnectionTracker() : null;

        // 1. Instanciation du Container concret (paquet waffle-commons/container).
        // DIAG-02 (dev) : lock() refuse de démarrer si un service partagé garde un
        // état mutable sans implémenter ResettableInterface (scan worker-safety).
        $container = new Container(strictComplianceScan: $isDev);
        // Tracer enregistré comme service partagé ⇒ vidé à chaque boucle worker par
        // Container::reset() (ResettableInterface), jamais propagé entre requêtes.
        if ($connectionTracker !== null) {
            $container->set(ConnectionTrackerInterface::class, $connectionTracker);
        }

        // Enregistrement de la factory PSR-17 (requise par les contrôleurs ET l'ErrorHandler).
        $responseFactory = new ResponseFactory();
        if (class_exists(ResponseFactory::class)) {
            $container->set(ResponseFactoryInterface::class, $responseFactory);
        }

        // Enregistrement de la factory PSR-17 (requise par le client HTTP).
        $streamFactory = new StreamFactory($connectionTracker);
        $container->set(StreamFactoryInterface::class, $streamFactory);

        // Traçage distribué (AXE 5 / OBS-01). Par défaut : NullTracer + propagateur no-op
        // ⇒ surcoût ~0 et aucun en-tête de trace émis. Avec `WAFFLE_TRACING=otel`, on monte
        // le pont OpenTelemetry (paquet telemetry-otel) qui exporte chaque span en JSON sur
        // la sortie standard (la sortie du conteneur sert de collecteur : `docker logs`), et
        // le propagateur W3C qui injecte/extrait `traceparent`. `WAFFLE_SERVICE_NAME` nomme
        // le service dans la trace. Le traceur est enregistré comme service partagé pour être
        // injecté dans les contrôleurs ET réutilisé par le client HTTP + le TracingMiddleware,
        // garantissant une seule et même trace de bout en bout (amont → aval).
        $tracer = new NullTracer();
        $tracePropagator = new NullTextMapPropagator();
        if (getenv('WAFFLE_TRACING') === 'otel') {
            $otelService = getenv('WAFFLE_SERVICE_NAME');
            $tracer = OtelTracerFactory::console(
                is_string($otelService) && $otelService !== '' ? $otelService : 'waffle',
            );
            $tracePropagator = new W3CTraceContextPropagator();
        }
        $container->set(TracerInterface::class, $tracer);

        // 2. Construction du registre d'environnement à partir des fichiers .env
        //    et de l'environnement processus (durcissement Beta-1 : DotEnv ne mute
        //    plus l'environnement PHP global ; on le fusionne ici avec l'env vivant
        //    du processus. L'env processus l'emporte en cas de conflit, ce qui
        //    permet aux valeurs Docker/K8s d'écraser les défauts du .env).
        $processEnv = getenv();
        $envRegistry = array_merge(new DotEnv($root)->load(), $processEnv);

        // 3. Instanciation de la Config concrète (paquet waffle-commons/config).
        $config = new Config(configDir: $rootConfig, environment: $env, env: $envRegistry);
        // Exposée dans le conteneur pour l'injection dans les contrôleurs
        // (ex. AuthDemoController) via le résolveur d'arguments PSR-11.
        $container->set(ConfigInterface::class, $config);
        // STAB-01 (Beta-1) : plus de GlobalsFactory statique — WaffleRuntime construit
        // sa propre instance par processus. L'enforcement des hôtes de confiance vit
        // dans TrustedHostMiddleware (RFC-003 §3.2).
        $trustedHosts = $config->getArray(key: 'waffle.trusted_hosts');

        // SEC-01 (Beta-1, option C) : gestionnaire CSRF sans état, basé sur un HMAC
        // lié à un SID anonyme par navigateur. Le secret vient de la config (avec
        // interpolation d'env) ou, à défaut, d'un getenv() direct ; absent ou trop
        // court ⇒ avortement du boot en production.
        $csrfTokenManager = new CsrfTokenManager(secret: self::resolveCsrfSecret($config, $env));
        $container->set(CsrfTokenManagerInterface::class, $csrfTokenManager);

        // Client HTTP PSR-18 brut (paquet waffle-commons/http-client). Il n'est
        // PAS enregistré tel quel sous ClientInterface : il sert de transport
        // interne au décorateur AuthenticatedClient du pont d'authentification,
        // qui est enregistré comme ClientInterface plus bas (RFC-021 §4.7) —
        // ainsi tout consommateur (ex. ProxyService) hérite de la propagation
        // d'assertion sortante. Durci SEC-02 : un SsrfGuard résout et épingle
        // chaque hôte sortant (CURLOPT_RESOLVE anti-rebinding) et refuse toute
        // adresse privée / loopback / réservée. `waffle.security.ssrf.allowed_hosts`
        // exempte les backends internes de confiance — ici `legacy-backend`, la
        // cible du ProxyService (noms exacts ou CIDR ; vide ⇒ posture stricte).
        /** @var list<string> $ssrfAllowedHosts */
        $ssrfAllowedHosts = $config->getArray(key: 'waffle.security.ssrf.allowed_hosts') ?? [];
        // Le traceur + le propagateur W3C sont passés au client : chaque requête sortante
        // porte alors le `traceparent` de la trace active (SEC-02 SSRF reste en amont).
        $httpClient = new Client(
            $responseFactory,
            $streamFactory,
            new SsrfGuard(allowedHosts: $ssrfAllowedHosts),
            $tracer,
            $tracePropagator,
        );
        // AXE 2 (ASYNC-02) : le client PSR-18 brut implémente aussi
        // ConcurrentClientInterface (sendRequests/promise). On l'expose sous CE
        // contrat — distinct du ClientInterface décoré par le pont d'auth plus
        // bas — pour les contrôleurs de fan-out concurrent. Stateless (readonly),
        // donc aucun reset requis.
        $container->set(ConcurrentClientInterface::class, $httpClient);

        // 3-bis. Pont d'Authentification Universel (RFC-021, paquet waffle-commons/auth).
        // Secret partagé fail-closed : absent ou < 32 octets ⇒ avortement du boot
        // en production (MissingAuthSecretException dans les constructeurs).
        $authSecret = self::resolveAuthSecret($config, $env);

        // Porteur d'identité à portée requête — SEUL service mutable du pont.
        // Enregistré dans le conteneur : ResettableInterface ⇒ vidé à chaque
        // boucle worker par AbstractKernel::reset() → Container::reset().
        $securityContext = new SecurityContext();
        $container->set(SecurityContextInterface::class, $securityContext);

        // Signataire / vérificateur d'assertions (HMAC-SHA256, X-Wfl-Assert-User).
        $assertionSigner = new AuthBridgeSigner($authSecret);
        $assertionVerifier = new AuthBridgeVerifier($authSecret);

        // Validateur JWT HS256 de la démo (allow-list stricte, iss/aud épinglés).
        $jwtValidator = new JwtValidator(
            config: new JwtConfig(
                algorithms: ['HS256'],
                issuer: $config->getString('waffle.auth.jwt.issuer') ?? 'https://waffle-dev.local',
                audience: $config->getString('waffle.auth.jwt.audience') ?? 'waffle-workspace',
            ),
            keys: new StaticKeyResolver(['HS256' => $authSecret]),
        );

        // Schémas entrants, par ordre de priorité : assertion de passerelle,
        // Bearer JWT, puis clé API de démonstration (si configurée).
        $authenticators = [new AssertionAuthenticator($assertionVerifier), new JwtAuthenticator($jwtValidator)];
        $demoApiKey = $config->getString('waffle.auth.demo_api_key');
        if (is_string($demoApiKey) && $demoApiKey !== '') {
            $authenticators[] = new ApiKeyAuthenticator([
                $demoApiKey => new UserIdentity(
                    subject: 'svc-demo',
                    email: 'svc-demo@waffle.dev',
                    roles: ['ROLE_SERVICE'],
                ),
            ]);
        }

        // AXE 6 (AUTH-01) : schéma WebAuthn / passkeys. Le cœur cryptographique
        // (web-auth/webauthn-lib) vit dans l'adaptateur ; les deux moitiés
        // *stateful* (dépôt de credentials + store de défis) sont fournies par
        // l'app et enregistrées comme services resettables (vidés à chaque boucle
        // worker). L'authenticator entrant rejoint la liste du pont ; le bookend
        // de cérémonie (WebAuthnCeremony) est exposé pour les contrôleurs de démo.
        $rpId = $config->getString('waffle.auth.webauthn.relying_party_id') ?? 'localhost';
        $rpName = $config->getString('waffle.auth.webauthn.relying_party_name') ?? 'Waffle Workspace';
        /** @var list<string> $webAuthnOrigins */
        $webAuthnOrigins = $config->getArray(key: 'waffle.auth.webauthn.allowed_origins') ?? ['https://localhost:8443'];
        $webAuthnVerifier = new WebAuthnLibAdapter($rpId, $rpName, $webAuthnOrigins);
        $container->set(WebAuthnVerifierInterface::class, $webAuthnVerifier);

        $credentialRepository = new InMemoryCredentialRepository();
        $container->set(CredentialRepositoryInterface::class, $credentialRepository);
        $challengeStore = new InMemoryChallengeStore();
        $container->set(InMemoryChallengeStore::class, $challengeStore);

        $webAuthnCeremony = new WebAuthnCeremony($webAuthnVerifier, $credentialRepository);
        $container->set(WebAuthnCeremony::class, $webAuthnCeremony);

        $authenticators[] = new WebAuthnAuthenticator($webAuthnVerifier, $challengeStore, $credentialRepository);

        $authBridge = new AuthenticationBridge($securityContext, $authenticators);
        $container->set(AuthenticationBridgeInterface::class, $authBridge);

        // Propagation sortante (RFC-021 §4.7) : le client PSR-18 est décoré —
        // toute requête vers le monolithe hérité (hôte sur liste blanche) porte
        // automatiquement l'assertion signée quand une identité est active.
        // Le ProxyService reçoit donc CE client via le conteneur.
        $container->set(ClientInterface::class, new AuthenticatedClient($httpClient, [
            new AssertionCredentialsProvider(
                signer: $assertionSigner,
                context: $securityContext,
                allowedHosts: ['legacy-backend'],
                tenant: $config->getString('waffle.auth.tenant'),
            ),
        ]));

        // 3. Instanciation de Security (paquet waffle-commons/security).
        $security = new Security($config);

        // 4. Décoration du container par le SecureContainer. Le SecurityContext
        // (alimenté par le pont d'authentification ci-dessus) est injecté pour que
        // les voters #[Voter] reçoivent l'identité authentifiée (AUTHZ-01).
        $secureContainer = new SecureContainer($container, $security, $securityContext);

        // 5. Instanciation des middlewares du pipeline.
        $stack = new MiddlewareStack();

        // On crée le renderer et le middleware d'erreur.
        // Il doit être PREPEND-é pour capter les erreurs de tous les middlewares
        // suivants (Routing, Security, Dispatcher).
        $errorRenderer = new JsonErrorRenderer($responseFactory, $debug);
        $errorLogger = new StreamLogger();
        $errorHandler = new ErrorHandlerMiddleware(renderer: $errorRenderer, logger: $errorLogger);

        $stack->prepend(middleware: $errorHandler);

        // 5t. Télémétrie (AXE 5 OBS-02) — endpoint /waffle-metrics + métriques de requête.
        //     Compteurs en mémoire partagée APCu (jamais sur le tas worker) ; repli no-op
        //     sans APCu. Placé tôt : /waffle-metrics court-circuite avant le pipeline applicatif
        //     et applique sa propre sécurité (fail-closed : localhost uniquement par défaut).
        $metricsRegistry = apcu_enabled() ? new MetricsRegistry(new ApcuMetricStore()) : new NullMetricsRegistry();
        $container->set(MetricsRegistryInterface::class, $metricsRegistry);

        $collectors = [new MemoryCollector(), new GcCollector(), new PoolUtilizationCollector()];
        if ($metricsRegistry instanceof MetricsCollectorInterface) {
            $collectors[] = $metricsRegistry;
        }

        $stack->add(
            middleware: new MetricsMiddleware(
                new PrometheusExporter($collectors),
                $responseFactory,
                $streamFactory,
                null,
                ['127.0.0.1', '::1'],
            ),
        );
        $stack->add(middleware: new TracingMiddleware($tracer, $metricsRegistry, $tracePropagator));

        // 5a. Allow-list des hôtes — première barrière exécutable du pipeline,
        // alimentée par `waffle.trusted_hosts` (app.yaml). L'ErrorHandler reste
        // « prepend »-é au-dessus, à dessein : il transforme un rejet d'hôte
        // malformé en réponse JSON 400 propre plutôt qu'en erreur fatale.
        // Ordre canonique Beta-1 : ErrorHandler → TrustedHost → AnonymousSession →
        // Routing → Csrf → Security → SecureHeaders → Dispatcher.
        $stack->add(middleware: new TrustedHostMiddleware($trustedHosts));

        // 5a-cors. CORS fail-closed (SEC-04) — doit précéder le routage pour
        // répondre au pré-vol OPTIONS avant le court-circuit OPTIONS du routeur.
        // Liste blanche d'origines vide par défaut ⇒ toute requête cross-origin
        // est refusée. Renseignez `waffle.security.cors.allowed_origins` (app.yaml).
        /** @var list<string> $corsOrigins */
        $corsOrigins = $config->getArray(key: 'waffle.security.cors.allowed_origins') ?? [];
        $stack->add(middleware: new CorsMiddleware(new CorsPolicy(allowedOrigins: $corsOrigins), $responseFactory));

        // 5a-bis. SID anonyme par navigateur. Doit s'exécuter avant Csrf pour que
        // l'attribut SID soit déjà alimenté lors du binding HMAC (SEC-01). Le
        // SecurityContext est injecté pour la ROTATION du SID à l'authentification
        // (SEC-01 fixation de session) : un SID présenté avant login est régénéré.
        $stack->add(middleware: new AnonymousSessionMiddleware($securityContext));

        // 5a-ter. Pont d'Authentification Universel (RFC-021 §3.2) : authentifie
        // la requête entrante (assertion de passerelle / Bearer JWT / clé API),
        // alimente le SecurityContext et publie l'identité vérifiée en attribut
        // `_auth_identity`. Identifiants invalides ⇒ 401/403 fail-closed (rendus
        // par l'ErrorHandler) ; identifiants absents ⇒ requête anonyme (l'ABAC
        // du composant security garde la décision d'accès). Ordre canonique :
        // ErrorHandler → TrustedHost → AnonymousSession → Authentication →
        // Routing → Csrf → Security → SecureHeaders → Dispatcher.
        $stack->add(middleware: new AuthenticationMiddleware($authBridge));

        // 5b. Mise en place du dispatcher d'événements.
        $listenerProvider = new ListenerProvider();
        $eventDispatcher = new EventDispatcher($listenerProvider);

        // Auto-découverte des listeners dans le dossier EventListener.
        $eventListenersPath = $config->getString('waffle.paths.event_listeners');
        if (is_string($eventListenersPath)) {
            EventListenerDiscovery::discover($listenerProvider, $eventListenersPath);
        }

        // 6. Logger du kernel. Le kernel lui-même est construit plus bas (étape 9),
        //    une fois tous ses collaborateurs prêts — injection par constructeur (ARCH-03).
        $kernelLogger = new StreamLogger(channel: LogChannel::CORE);

        // 5c. AXE 3 (REACTIVE-01) : tampon de diffusion à portée requête + transport
        //     SSE. Les write-hooks `#[Broadcast]` y enregistrent les mutations SANS
        //     I/O ; le BroadcastFlushListener draine et pousse en fin de requête. Le
        //     tampon est enregistré sous son contrat (resettable ⇒ vidé par
        //     Container::reset() à chaque boucle worker) pour que les contrôleurs et
        //     les DTO mutables l'injectent. Le sink SSE écrit sur stderr — la sortie
        //     conteneur sert de collecteur de démo (`docker logs`).
        $broadcastBuffer = new RequestBroadcastBuffer();
        $container->set(BroadcastBufferInterface::class, $broadcastBuffer);
        $broadcastLogger = new StreamLogger(channel: LogChannel::APP);
        $broadcastTransport = new SseBroadcastTransport(sink: static function (string $frame) use (
            $broadcastLogger,
        ): void {
            $broadcastLogger->info('Trame SSE diffusée : {frame}', ['frame' => $frame]);
        });
        $listenerProvider->addListener(
            TerminateEvent::class,
            new BroadcastFlushListener($broadcastBuffer, $broadcastTransport),
        );

        // 5d. AXE 2 (ASYNC-01) : runner de tâches différées (finish-request). Les
        //     contrôleurs y diffèrent du travail court post-réponse sous un budget
        //     borné ; le DeferredTaskFlushListener les draine sur TerminateEvent —
        //     APRÈS le flush de diffusion (le temps réel est plus sensible à la
        //     latence). Le runner porte l'unique état à portée requête (sa file) et
        //     implémente ResettableInterface : il est enregistré sous son contrat et
        //     vidé à chaque boucle worker.
        $taskRunner = new DeferredTaskRunner(
            budget: DeferredTaskRunner::DEFAULT_BUDGET,
            logger: new StreamLogger(channel: LogChannel::APP),
        );
        $container->set(TaskRunnerInterface::class, $taskRunner);
        $listenerProvider->addListener(
            TerminateEvent::class,
            new DeferredTaskFlushListener($taskRunner, $kernelLogger),
        );

        // DIAG-03 (dev) : alerte de fin de requête sur les connexions non libérées.
        // Sur TerminateEvent (après émission de la réponse), le listener inspecte le
        // tracer : un handle PDO encore emprunté ⇒ warning (fuite probable en worker) ;
        // une connexion Redis persistante ou un flux ouvert ⇒ info (visibilité).
        if ($connectionTracker !== null) {
            $listenerProvider->addListener(
                TerminateEvent::class,
                new OrphanedConnectionListener($connectionTracker, $kernelLogger),
            );
        }

        // 6a. Câblage découplé du trio terminal (Beta 2 — Phase 4).
        // La factory ne construit plus les services à la main : elle déclare des
        // définitions dans le Container, et AbstractKernel::handle() les résout
        // à la volée via le standard PSR-11 (Container::build() invoque chaque
        // Closure avec lui-même en argument, cf. container/src/Container.php).
        // Le dispatcher d'événements est capturé dans la closure pour rester
        // injecté sans dépendre d'un slot supplémentaire dans le conteneur.
        self::registerTerminalHandlers($container, $eventDispatcher);

        // 7. Cache PSR-16 (RFC-013), pool de connexions + migration runner (RFC-022).
        $cache = self::registerDataServices($container, $root, $config, $connectionTracker);

        // 8. Instanciation et démarrage du Router.
        $controllersPath = $config->getString(key: 'waffle.paths.controllers');
        if (is_string($controllersPath)) {
            // Router instancié avec le cache PSR-16 partagé.
            $router = new Router($root . DIRECTORY_SEPARATOR . $controllersPath, $cache);
            $router->boot(container: $secureContainer);

            // Création du middleware de pont et ajout dans le stack.
            // Il relie le Router au pipeline.
            $routingMiddleware = new CoreRoutingMiddleware($router, $responseFactory);
            // Il relie le SecureMiddleware au pipeline.
            $secureLogger = new StreamLogger(channel: LogChannel::SECURITY);
            $secureMiddleware = new SecurityMiddleware(secureContainer: $secureContainer, logger: $secureLogger);
            $stack->add(middleware: $routingMiddleware);
            // Le CsrfMiddleware doit s'exécuter après Routing (il lit `_classname`
            // et `_method` pour repérer #[RequiresCsrfToken]) et avant Security.
            $stack->add(middleware: new CsrfMiddleware($csrfTokenManager));
            $stack->add(middleware: $secureMiddleware);

            // Middleware d'en-têtes sécurisés.
            $stack->add(middleware: new SecureHeadersMiddleware());

            // 8b. AXE 4 (DBAL-02) : transaction failsafe par requête d'écriture.
            //     Placé APRÈS la sécurité et JUSTE avant le dispatcher terminal :
            //     il ouvre une transaction sur une connexion épinglée
            //     (beginRequestScope) pour chaque verbe non-sûr, *commit* au retour
            //     2xx et *rollback* sur toute exception — un write à moitié
            //     appliqué ne fuit jamais d'une itération worker à l'autre. Le pool
            //     relationnel est déjà lié au conteneur par registerDataServices().
            /** @var RelationalConnectionPoolInterface $relationalPool */
            $relationalPool = $container->get(RelationalConnectionPoolInterface::class);
            $stack->add(middleware: new TransactionIsolationMiddleware($relationalPool));
        }

        // 9. Construction du Kernel par injection de constructeur (ARCH-03) : tous les
        //    collaborateurs requis sont prêts (config, conteneur sécurisé, sécurité,
        //    pile de middlewares, dispatcher) — plus de setters ni d'objet à moitié construit.
        $kernel = new Kernel(
            config: $config,
            container: $secureContainer,
            security: $security,
            middlewareStack: $stack,
            logger: $kernelLogger,
        );
        // L'event dispatcher est le SEUL collaborateur optionnel (les hooks de cycle
        // de vie sont inertes sans lui) — câblé via le setter dédié au boot (ARCH-03).
        $kernel->setEventDispatcher($eventDispatcher);

        return $kernel;
    }

    /**
     * Enregistre le trio terminal (ReflectionService + ArgumentResolver +
     * RequestHandler) sous forme de définitions paresseuses résolues à la volée
     * par le Container (PSR-11) ; le dispatcher d'événements est capturé dans la
     * closure du handler.
     */
    private static function registerTerminalHandlers(
        ContainerInterface $container,
        EventDispatcher $eventDispatcher,
    ): void {
        $container->set(ReflectionServiceInterface::class, new ReflectionService());
        // Validateur injectable et mockable (DX-05) : enveloppe la façade statique Assert.
        $container->set(ValidatorInterface::class, new AssertValidator());
        $container->set(ArgumentResolverInterface::class, static function (ContainerInterface $c): ArgumentResolverInterface {
            /** @var ReflectionServiceInterface $reflection */
            $reflection = $c->get(ReflectionServiceInterface::class);

            return new ControllerArgumentResolver($c, $reflection);
        });
        $container->set(RequestHandlerInterface::class, static function (ContainerInterface $c) use (
            $eventDispatcher,
        ): RequestHandlerInterface {
            /** @var ArgumentResolverInterface $resolver */
            $resolver = $c->get(ArgumentResolverInterface::class);

            return new ControllerDispatcher(container: $c, dispatcher: $eventDispatcher, argumentResolver: $resolver);
        });
    }

    /**
     * Enregistre le cache PSR-16, le pool de connexions et le migration runner ;
     * retourne le cache (réutilisé par le routeur).
     */
    private static function registerDataServices(
        ContainerInterface $container,
        string $root,
        Config $config,
        ?ConnectionTrackerInterface $tracker = null,
    ): CacheInterface {
        $cache = self::buildCache($root, $config, $tracker);
        $container->set(CacheInterface::class, $cache);

        $connectionPool = self::buildConnectionPool($config, $tracker);
        $container->set(ConnectionPoolInterface::class, $connectionPool);
        // Contrat relationnel typé : les dépôts SQL s'injectent dessus pour un accès PDO typé.
        $container->set(RelationalConnectionPoolInterface::class, $connectionPool);
        $container->set(PDOConnectionPool::class, $connectionPool);

        $migrationRunner = new MigrationRunner(pool: $connectionPool, config: $config);
        $container->set(MigrationRunnerInterface::class, $migrationRunner);
        $container->set(MigrationRunner::class, $migrationRunner);

        return $cache;
    }

    /**
     * Résout le secret de signature CSRF avec des fallbacks raisonnables. La
     * config gagne ; sinon on lit directement l'environnement. La production
     * refuse de démarrer si le secret est absent ou plus court que
     * `CsrfConstant::MIN_SECRET_BYTES` ; en hors-prod, un secret aléatoire
     * éphémère permet à dev/test de démarrer proprement.
     */
    private static function resolveCsrfSecret(Config $config, string $env): string
    {
        $fromConfig = $config->getString('waffle.security.csrf.secret');
        $candidate = is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;

        if ($candidate === null) {
            $fromEnv = getenv(CsrfConstant::SECRET_ENV_KEY);
            if (is_string($fromEnv) && $fromEnv !== '') {
                $candidate = $fromEnv;
            }
        }

        if ($candidate !== null && strlen($candidate) >= CsrfConstant::MIN_SECRET_BYTES) {
            return $candidate;
        }

        if ($env === Constant::ENV_PROD) {
            throw new RuntimeException(sprintf(
                'Secret CSRF manquant ou plus court que %d octets en production. '
                . 'Renseignez "waffle.security.csrf.secret" ou la variable d\'environnement %s.',
                CsrfConstant::MIN_SECRET_BYTES,
                CsrfConstant::SECRET_ENV_KEY,
            ));
        }

        // Fallback dev/test : secret éphémère par processus. Les jetons émis sous
        // ce secret ne survivront PAS au redémarrage d'un worker — ce qui est
        // acceptable hors production.
        return random_bytes(CsrfConstant::MIN_SECRET_BYTES);
    }

    /**
     * Résout le secret partagé du Pont d'Authentification Universel (RFC-021
     * §4.2) avec la même discipline que le secret CSRF : la config gagne
     * (`waffle.auth.secret`, interpolée depuis WAFFLE_AUTH_SECRET) ; sinon
     * lecture directe de l'environnement. En production, absent ou plus court
     * que 32 octets ⇒ refus de démarrer (fail-closed — jamais de contournement
     * non authentifié). Hors prod, un secret éphémère permet de démarrer, mais
     * les assertions émises ne seront vérifiables que par CE processus.
     */
    private static function resolveAuthSecret(Config $config, string $env): string
    {
        $fromConfig = $config->getString('waffle.auth.secret');
        $candidate = is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;

        if ($candidate === null) {
            $fromEnv = getenv(AuthConstant::SECRET_ENV_KEY);
            if (is_string($fromEnv) && $fromEnv !== '') {
                $candidate = $fromEnv;
            }
        }

        if ($candidate !== null && strlen($candidate) >= AuthConstant::MIN_SECRET_BYTES) {
            return $candidate;
        }

        if ($env === Constant::ENV_PROD) {
            throw new RuntimeException(sprintf(
                'Secret du pont d\'authentification manquant ou plus court que %d octets en production. '
                . 'Renseignez "waffle.auth.secret" ou la variable d\'environnement %s.',
                AuthConstant::MIN_SECRET_BYTES,
                AuthConstant::SECRET_ENV_KEY,
            ));
        }

        return random_bytes(AuthConstant::MIN_SECRET_BYTES);
    }

    /**
     * Construit l'adaptateur de cache PSR-16 choisi par `waffle.cache.adapter`.
     *
     * Retombe sur le ArrayCache en mémoire si aucun adaptateur n'est configuré.
     */
    public static function buildCache(
        string $root,
        Config $config,
        ?ConnectionTrackerInterface $tracker = null,
    ): CacheInterface {
        $adapter = $config->getString('waffle.cache.adapter') ?? CacheConstant::BACKEND_ARRAY;
        $directory = $config->getString('waffle.cache.directory') ?? 'var/cache/psr16';
        $options = [
            'directory' => $root . DIRECTORY_SEPARATOR . $directory,
            'dsn' => $config->getString('waffle.cache.redis_dsn'),
            'default_ttl' => $config->getInt('waffle.cache.default_ttl'),
            'prefix' => $config->getString('waffle.cache.prefix'),
        ];

        return new CacheFactory($tracker)->create($adapter, $options);
    }

    /**
     * Construit le pool de connexions PDO (RFC-022) à partir de `waffle.database.*`.
     *
     * La fabrique injectée n'ouvre une connexion que lorsque le pool en a besoin :
     * en mode worker FrankenPHP, les sockets restent tièdes entre les requêtes,
     * sont sondés (« ping-before-dispense ») puis reconnectés de façon transparente.
     */
    public static function buildConnectionPool(
        Config $config,
        ?ConnectionTrackerInterface $tracker = null,
    ): PDOConnectionPool {
        $driver = $config->getString('waffle.database.driver') ?? 'pgsql';
        $host = $config->getString('waffle.database.host') ?? '127.0.0.1';
        $port = $config->getString('waffle.database.port') ?? '5432';
        $database = $config->getString('waffle.database.database') ?? '';
        $username = $config->getString('waffle.database.username') ?? 'waffle';
        $password = $config->getString('waffle.database.password') ?? '';
        $charset = $config->getString('waffle.database.charset') ?? 'utf8';

        $dsn = self::buildDsn($driver, $host, $port, $database, $charset);

        // Oracle exige `FROM DUAL` pour un SELECT sans table : la sonde de vie
        // (« ping-before-dispense ») du pool doit donc être adaptée au moteur.
        $ping = $driver === 'oci' ? 'SELECT 1 FROM DUAL' : 'SELECT 1';

        // Fabrique sans état, rejouée à chaque création de connexion par le pool.
        return new PDOConnectionPool(factory: static fn(): PDO => new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]), pingQuery: $ping, tracker: $tracker);
    }

    /**
     * Construit le DSN PDO propre à chaque moteur (RFC-022).
     *
     * PostgreSQL ne reconnaît pas l'attribut DSN `charset` (l'encodage client est
     * négocié avec le serveur — UTF8 par défaut) ; SQLite n'attend qu'un chemin de
     * fichier ; SQL Server et Oracle ont leur propre grammaire de DSN. Le format
     * MySQL/MariaDB reste le cas par défaut.
     */
    private static function buildDsn(
        string $driver,
        string $host,
        string $port,
        string $database,
        string $charset,
    ): string {
        return match ($driver) {
            'pgsql' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database),
            'sqlite' => sprintf('sqlite:%s', $database),
            'sqlsrv' => sprintf('sqlsrv:Server=%s,%s;Database=%s', $host, $port, $database),
            'oci' => sprintf('oci:dbname=//%s:%s/%s;charset=%s', $host, $port, $database, $charset),
            default => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset),
        };
    }
}
