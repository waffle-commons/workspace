<?php

declare(strict_types=1);

namespace Workspace\Service;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Proxy transparent vers le monolithe hérité.
 *
 * Le client injecté est l'AuthenticatedClient du pont d'authentification
 * (RFC-021 §4.7) : lorsque le SecurityContext porte une identité vérifiée,
 * l'assertion signée X-Wfl-Assert-User est attachée automatiquement à la
 * requête sortante — ce service n'a RIEN à faire pour la propagation.
 */
class ProxyService
{
    public function __construct(
        protected(set) ClientInterface $client,
    ) {}

    public function passThrough(ServerRequestInterface $req): ResponseInterface
    {
        $uri = $req->getUri()->withScheme('http')->withHost('legacy-backend')->withPort(80);

        // L'IP du client original voyage en X-Forwarded-For : le monolithe la
        // compare au hachage clavetté `iph` SIGNÉ dans l'assertion (RFC-021
        // §4.3) — un X-Forwarded-For falsifié ferait donc échouer la liaison IP.
        $remote = $req->getServerParams()['REMOTE_ADDR'] ?? '';
        $forwarded = $req->withUri($uri);
        if (is_string($remote) && $remote !== '') {
            $forwarded = $forwarded->withHeader('X-Forwarded-For', $remote);
        }

        return $this->client->sendRequest(request: $forwarded);
    }
}
