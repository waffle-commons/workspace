<?php

declare(strict_types=1);

namespace Workspace\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Waffle\Commons\Auth\WebAuthn\WebAuthnCeremony;
use Waffle\Commons\Auth\WebAuthn\WebAuthnUser;
use Waffle\Commons\Contracts\Auth\WebAuthn\Exception\InvalidWebAuthnRegistrationExceptionInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;
use Workspace\WebAuthn\InMemoryChallengeStore;

use function bin2hex;
use function json_decode;
use function random_bytes;

/**
 * Vitrine WebAuthn / passkeys (AXE 6 / AUTH-01).
 *
 * Câble le bookend de la cérémonie (le {@see WebAuthnCeremony}) sur des routes de
 * démonstration, distinct de l'authenticator entrant {@see \Waffle\Commons\Auth\WebAuthn\WebAuthnAuthenticator}
 * (enregistré dans le tableau d'authenticators du pont). Le cœur cryptographique
 * (`web-auth/webauthn-lib`) ne vit QUE dans l'adaptateur — le contrôleur ne
 * manipule jamais de CBOR/COSE.
 *
 * Émission d'options (register-start, assert-start) : entièrement câblée — le
 * serveur émet des options signées que le navigateur passe à
 * `navigator.credentials.create()` / `.get()`.
 *
 * Vérification (register-finish) : câblée mais exige une réponse d'attestation
 * réelle produite par un authentificateur ; sans navigateur, elle échoue en
 * fermé (422), ce qui est le comportement attendu.
 */
#[Route(path: '/webauthn', name: 'webauthn_')]
final class WebAuthnDemoController extends BaseController
{
    public function __construct(
        private readonly WebAuthnCeremony $ceremony,
        private readonly InMemoryChallengeStore $challenges,
    ) {}

    /**
     * POST /webauthn/register/start : options d'enrôlement d'une nouvelle passkey.
     *
     * @throws RenderingException
     */
    #[Route(path: 'register/start', methods: [Routing::METHOD_POST], name: 'register_start')]
    #[PublicAccess]
    public function registerStart(): ResponseInterface
    {
        // Handle utilisateur opaque, stable, non-PII (WebAuthn §4) — surrogat
        // aléatoire pour la démo (jamais un email).
        $user = new WebAuthnUser(
            id: bin2hex(random_bytes(16)),
            name: 'demo@waffle.dev',
            displayName: 'Compte de démonstration Waffle',
        );

        $options = $this->ceremony->startRegistration($user);

        return $this->jsonResponse(data: [
            'publicKey' => json_decode($options->toJson(), associative: true),
            'note' => 'Passez `publicKey` à navigator.credentials.create() côté navigateur.',
        ]);
    }

    /**
     * POST /webauthn/register/finish : vérifie l'attestation et persiste la passkey.
     *
     * Le corps doit contenir les options émises ET la réponse du navigateur.
     * Sans authentificateur réel, la vérification échoue en fermé (422).
     *
     * @throws RenderingException
     */
    #[Route(path: 'register/finish', methods: [Routing::METHOD_POST], name: 'register_finish')]
    #[PublicAccess]
    public function registerFinish(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // En production, on rejoue les options persistées contre la cérémonie ;
            // la démo recrée des options jetables pour montrer le chemin de vérification.
            $options = $this->ceremony->startRegistration(new WebAuthnUser(
                id: bin2hex(random_bytes(16)),
                name: 'demo@waffle.dev',
                displayName: 'Compte de démonstration Waffle',
            ));
            $credential = $this->ceremony->finishRegistration($options, (string) $request->getBody());
        } catch (InvalidWebAuthnRegistrationExceptionInterface $e) {
            return $this->jsonResponse(data: [
                'error' => 'attestation_rejected',
                'reason' => $e->getMessage(),
                'note' => 'Attendu sans authentificateur réel : la vérification est fail-closed.',
            ], status: 422);
        }

        return $this->jsonResponse(data: [
            'credential_id' => $credential->credentialId(),
            'user_handle' => $credential->userHandle(),
            'sign_count' => $credential->signCount(),
        ], status: 201);
    }

    /**
     * POST /webauthn/login/start : options de connexion + identifiant de cérémonie.
     *
     * Les options émises sont persistées dans le store de défis à usage unique
     * sous un identifiant de cérémonie opaque ; le navigateur le renvoie dans
     * l'en-tête `X-Wfl-Webauthn-Ceremony` avec son assertion, que le
     * {@see \Waffle\Commons\Auth\WebAuthn\WebAuthnAuthenticator} replaie.
     *
     * @throws RenderingException
     */
    #[Route(path: 'login/start', methods: [Routing::METHOD_POST], name: 'login_start')]
    #[PublicAccess]
    public function loginStart(): ResponseInterface
    {
        // Connexion sans nom d'utilisateur (discoverable) : aucune passkey
        // pré-filtrée pour la démo.
        $options = $this->ceremony->startAuthentication(userHandle: '');

        $ceremonyId = bin2hex(random_bytes(16));
        $this->challenges->put($ceremonyId, $options);

        return $this->jsonResponse(data: [
            'ceremony_id' => $ceremonyId,
            'publicKey' => json_decode($options->toJson(), associative: true),
            'note' => 'Renvoyez l\'assertion avec l\'en-tête X-Wfl-Webauthn-Ceremony: <ceremony_id>.',
        ]);
    }
}
