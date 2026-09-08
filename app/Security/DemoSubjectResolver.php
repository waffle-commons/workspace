<?php

declare(strict_types=1);

namespace Workspace\Security;

use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Security\SubjectResolverInterface;
use Workspace\Controller\HomeController;
use Workspace\Dto\GreetedResource;

/**
 * Résolveur de sujet de la démo SEC-05 : transforme le paramètre de route
 * `{name}` de GET /hello/{name} en ressource métier {@see GreetedResource},
 * que le SecureContainer transmet ensuite aux voters comme sujet de
 * décision — des règles au niveau objet (anti-IDOR) deviennent possibles.
 *
 * Le SecureContainer l'invoque paresseusement, post-routage / pré-dispatch :
 * les paramètres de la route appariée sont déjà des attributs PSR-7
 * (`_params`, posés par la CoreRoutingMiddleware), mais aucune entité n'est
 * encore hydratée — c'est précisément le travail du résolveur. La résolution
 * est conditionnée aux voters : seules les actions portant au moins un
 * #[Voter] la déclenchent ; les routes #[PublicAccess] sans voter ne
 * sollicitent jamais le résolveur. Ici la « ressource » est un DTO typé
 * construit depuis le paramètre ; une vraie application chargerait l'entité
 * via son dépôt (RFC-022) et LÈVERAIT une exception en cas d'échec de
 * résolution (fail-closed : le SecureContainer refuse alors la requête, 403
 * journalisé par la SecurityMiddleware), plutôt que de retourner null.
 *
 * Sans état (mode worker FrankenPHP) : `final readonly`, tout est lu depuis la
 * requête courante.
 */
final readonly class DemoSubjectResolver implements SubjectResolverInterface
{
    #[\Override]
    public function resolve(ServerRequestInterface $request): mixed
    {
        // Seule la route de démo GET /hello/{name} expose une ressource
        // « possédée » ; toute autre route vote sur la requête brute (null).
        if (
            $request->getAttribute(Constant::ATTR_CLASSNAME) !== HomeController::class
            || $request->getAttribute(Constant::ATTR_METHOD) !== 'hello'
        ) {
            return null;
        }

        $params = $request->getAttribute('_params');
        $owner = is_array($params) ? $params['name'] ?? null : null;
        if (!is_string($owner) || $owner === '') {
            return null;
        }

        return new GreetedResource(owner: $owner);
    }
}
