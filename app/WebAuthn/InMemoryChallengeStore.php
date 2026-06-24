<?php

declare(strict_types=1);

namespace Workspace\WebAuthn;

use Waffle\Commons\Auth\WebAuthn\WebAuthnChallengeStoreInterface;
use Waffle\Commons\Contracts\Auth\WebAuthn\AssertionOptionsInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;

/**
 * Store de défis de connexion à usage unique, en mémoire (AXE 6 / AUTH-01).
 *
 * La moitié *stateful* du protocole d'assertion : `put()` enregistre les options
 * émises au début d'une cérémonie de connexion contre un identifiant opaque, et
 * `take()` les rejoue — exactement une fois — quand le navigateur renvoie son
 * assertion. Une vraie application utiliserait un cache/Redis à TTL court ; le
 * playground en garde une copie *par processus* pour rester autoporteur.
 *
 * État mutable partagé ⇒ implémente DIRECTEMENT {@see ResettableInterface}
 * (exigence du scan DIAG-02) ; le kernel le vide à chaque boucle worker. NB : un
 * store par processus implique que la cérémonie démarrée sur un worker n'est
 * vérifiable que par CE worker — suffisant pour la démo, jamais pour la prod.
 */
final class InMemoryChallengeStore implements WebAuthnChallengeStoreInterface, ResettableInterface
{
    /** @var array<string, AssertionOptionsInterface> indexé par identifiant de cérémonie. */
    private array $pending = [];

    /**
     * Enregistre les options émises pour une cérémonie de connexion en attente.
     */
    public function put(string $ceremonyId, AssertionOptionsInterface $options): void
    {
        $this->pending[$ceremonyId] = $options;
    }

    #[\Override]
    public function take(string $ceremonyId): ?AssertionOptionsInterface
    {
        $options = $this->pending[$ceremonyId] ?? null;
        // Usage unique : on consomme le défi pour défaire toute relecture.
        unset($this->pending[$ceremonyId]);

        return $options;
    }

    #[\Override]
    public function reset(): void
    {
        $this->pending = [];
    }
}
