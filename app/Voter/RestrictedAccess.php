<?php

declare(strict_types=1);

namespace Workspace\Voter;

use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\Security\VoterInterface;
use Workspace\Dto\GreetedResource;

/**
 * Voter de démonstration : règle de propriété au niveau objet (SEC-05, anti-IDOR).
 *
 * Quand le {@see \Workspace\Security\DemoSubjectResolver} a résolu une
 * {@see GreetedResource} pour GET /hello/{name}, le voter décide contre CETTE
 * ressource (et non plus contre la simple requête PSR-7) : un nom réservé
 * (admin, root) ne peut être salué que par son propriétaire authentifié.
 * Toute autre ressource — ou l'absence de ressource résolue — reste librement
 * accessible : le comportement historique de la démo est préservé.
 */
class RestrictedAccess implements VoterInterface
{
    #[\Override]
    public function decide(SecurityContextInterface $ctx, mixed $subject = null): bool
    {
        // Pas de ressource résolue, ou ressource non réservée : accès ouvert —
        // le voter ne durcit que la règle de propriété objet.
        if (!$subject instanceof GreetedResource || !$subject->isReserved()) {
            return true;
        }

        // Règle anti-IDOR : seul le propriétaire authentifié de la ressource
        // réservée est admis (comparaison en temps constant).
        $identity = $ctx->getIdentity();

        return $identity !== null && hash_equals($subject->owner, $identity->subject);
    }
}
