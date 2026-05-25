<?php

declare(strict_types=1);

namespace Workspace\Service;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ProxyService
{
    public function __construct(
        protected(set) ClientInterface $client,
    ) {}

    public function passThrough(RequestInterface $req): ResponseInterface
    {
        $uri = $req->getUri()
            ->withScheme('http')
            ->withHost('legacy-backend')
            ->withPort(80);

        return $this->client->sendRequest(request: $req->withUri($uri));
    }
}