<?php

declare(strict_types=1);

namespace Workspace\Dto;

/**
 * Ressource « saluée » de la démo SEC-05 : l'objet métier que le
 * {@see \Workspace\Security\DemoSubjectResolver} hydrate à partir du paramètre
 * de route `{name}` de GET /hello/{name}, et contre lequel le voter
 * {@see \Workspace\Voter\RestrictedAccess} exprime sa règle de propriété.
 *
 * DTO immuable (`final readonly`, sans état — mode worker FrankenPHP) : dans
 * une vraie application, ce serait l'entité chargée depuis son dépôt (RFC-022).
 */
final readonly class GreetedResource
{
    /**
     * Propriétaires « réservés » : saluer ces noms exige d'être authentifié
     * comme le propriétaire lui-même (règle anti-IDOR de la démo).
     *
     * @var list<string>
     */
    public const array RESERVED_OWNERS = ['admin', 'root'];

    public function __construct(
        public string $owner,
    ) {}

    /**
     * Vrai lorsque la ressource appartient à un propriétaire réservé.
     *
     * Asymétrie de casse VOULUE (fail-closed, « refuser plus ») : la
     * réservation est insensible à la casse (« Admin » est réservé), mais la
     * correspondance de propriétaire du voter reste stricte octet à octet —
     * /hello/Admin est donc refusé même au sujet authentifié « admin ».
     */
    public function isReserved(): bool
    {
        return in_array(mb_strtolower($this->owner), self::RESERVED_OWNERS, true);
    }
}
