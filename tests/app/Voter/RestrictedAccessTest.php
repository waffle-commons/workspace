<?php

declare(strict_types=1);

namespace WorkspaceTests\Voter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Auth\Identity\UserIdentity;
use Waffle\Commons\Auth\SecurityContext;
use Waffle\Commons\Http\Factory\ServerRequestFactory;
use Workspace\Dto\GreetedResource;
use Workspace\Voter\RestrictedAccess;

/**
 * Verrouille la table de vérité du voter {@see RestrictedAccess} (SEC-05,
 * anti-IDOR) : un nom réservé (admin, root — réservation insensible à la
 * casse) n'est saluable que par son propriétaire authentifié (correspondance
 * stricte octet à octet via hash_equals) ; tout autre sujet reste librement
 * accessible. Doubles concrets uniquement : le SecurityContext et la
 * UserIdentity réels du composant auth, jamais de mock.
 */
final class RestrictedAccessTest extends TestCase
{
    #[DataProvider('truthTable')]
    public function testOwnershipTruthTable(string $owner, ?string $authenticatedSubject, bool $expected): void
    {
        $ctx = new SecurityContext();
        if ($authenticatedSubject !== null) {
            $ctx->authenticate(new UserIdentity(subject: $authenticatedSubject));
        }

        $voter = new RestrictedAccess();

        static::assertSame($expected, $voter->decide($ctx, new GreetedResource(owner: $owner)));
    }

    /**
     * @return iterable<string, array{0: string, 1: null|string, 2: bool}>
     */
    public static function truthTable(): iterable
    {
        // (1) Nom non réservé + anonyme : accès ouvert (comportement historique).
        yield 'non réservé + anonyme' => ['alice', null, true];

        // (2) Nom réservé + anonyme : refusé (aucune identité à confronter) —
        // et la réservation est insensible à la casse (« ROOT » aussi).
        yield 'réservé + anonyme' => ['admin', null, false];
        yield 'réservé (casse ROOT) + anonyme' => ['ROOT', null, false];

        // (3) Réservé + propriétaire authentifié : admis.
        yield 'réservé + propriétaire authentifié' => ['admin', 'admin', true];

        // (4) Réservé + authentifié NON propriétaire : refusé.
        yield 'réservé + authentifié non propriétaire' => ['admin', 'mallory', false];

        // (6) Asymétrie de casse VOULUE (fail-closed) : « Admin » est réservé
        // (contrôle insensible à la casse) mais la correspondance de
        // propriétaire reste stricte octet à octet — /hello/Admin est refusé
        // même au sujet authentifié « admin »…
        yield 'Admin (casse) + sujet admin' => ['Admin', 'admin', false];
        // …et n'est admis que pour la correspondance exacte « Admin ».
        yield 'Admin (casse) + sujet Admin' => ['Admin', 'Admin', true];
    }

    /**
     * (5) Sujet non-GreetedResource : quand le résolveur ne produit pas de
     * ressource, la SecurityMiddleware vote sur la requête PSR-7 brute — le
     * voter ne durcit que la règle de propriété objet, la requête brute reste
     * donc admise, même anonyme.
     */
    public function testRawRequestFallbackSubjectIsAllowedEvenAnonymous(): void
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('GET', '/status');

        $voter = new RestrictedAccess();

        static::assertTrue($voter->decide(new SecurityContext(), $request));
    }

    /**
     * (5 bis) Absence de ressource résolue (sujet null, ou omis — la valeur
     * par défaut du contrat) : accès ouvert, même anonyme.
     */
    public function testNullOrOmittedSubjectIsAllowedEvenAnonymous(): void
    {
        $voter = new RestrictedAccess();
        $ctx = new SecurityContext();

        static::assertTrue($voter->decide($ctx, null));
        static::assertTrue($voter->decide($ctx));
    }
}
