<?php

declare(strict_types=1);

namespace Workspace\WebAuthn;

use Waffle\Commons\Auth\WebAuthn\RegisteredCredential;
use Waffle\Commons\Contracts\Auth\WebAuthn\CredentialRepositoryInterface;
use Waffle\Commons\Contracts\Auth\WebAuthn\RegisteredCredentialInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;

use function array_values;

/**
 * Dépôt de passkeys en mémoire (AXE 6 / AUTH-01) — la moitié *stateful* de la
 * surface WebAuthn, fournie par l'application.
 *
 * Une vraie application persiste les credentials en base (ou Redis) ; le
 * playground en garde une copie à portée *processus* pour rester autoporteur,
 * sans dépendance de stockage externe. Comme ce service partagé porte un état
 * mutable, il implémente DIRECTEMENT {@see ResettableInterface} : le scan de
 * conformité stricte (DIAG-02) l'exige et le kernel vide la table à chaque
 * boucle worker — aucun credential ne fuit d'une requête à l'autre.
 *
 * NB : un store *par processus* signifie qu'un enregistrement effectué sur un
 * worker n'est PAS visible des autres — acceptable pour la démo, jamais en prod.
 */
final class InMemoryCredentialRepository implements CredentialRepositoryInterface, ResettableInterface
{
    /** @var array<string, RegisteredCredentialInterface> indexé par credentialId. */
    private array $byCredentialId = [];

    #[\Override]
    public function findByCredentialId(string $credentialId): ?RegisteredCredentialInterface
    {
        return $this->byCredentialId[$credentialId] ?? null;
    }

    /**
     * @return list<RegisteredCredentialInterface>
     */
    #[\Override]
    public function findByUserHandle(string $userHandle): array
    {
        $matches = [];
        foreach ($this->byCredentialId as $credential) {
            if ($credential->userHandle() !== $userHandle) {
                continue;
            }

            $matches[] = $credential;
        }

        return array_values($matches);
    }

    #[\Override]
    public function save(RegisteredCredentialInterface $credential): void
    {
        $this->byCredentialId[$credential->credentialId()] = $credential;
    }

    #[\Override]
    public function updateSignCount(string $credentialId, int $signCount): void
    {
        // Un credentialId inconnu est un no-op (contrat). Le credential étant
        // immuable, on avance le compteur (détection de clonage) en réécrivant
        // une copie avec le nouveau `signCount`.
        $existing = $this->byCredentialId[$credentialId] ?? null;
        if ($existing === null) {
            return;
        }

        $this->byCredentialId[$credentialId] = new RegisteredCredential(
            credentialId: $existing->credentialId(),
            publicKey: $existing->publicKey(),
            userHandle: $existing->userHandle(),
            signCount: $signCount,
            transports: $existing->transports(),
        );
    }

    #[\Override]
    public function reset(): void
    {
        $this->byCredentialId = [];
    }
}
