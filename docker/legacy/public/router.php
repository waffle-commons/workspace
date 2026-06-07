<?php

declare(strict_types=1);

/**
 * « Monolithe hérité » factice pour la démo proxy de la passerelle Waffle.
 *
 * Ce script est intentionnellement l'ANTITHÈSE d'un worker Waffle : il tourne
 * sur le serveur PHP intégré, bloque le thread de la requête avec une latence
 * artificielle et gonfle son empreinte mémoire par requête — exactement le type
 * de backend lent et lourd que la passerelle est conçue pour précéder et
 * proxifier via son client HTTP PSR-18 non bloquant et borné en mémoire.
 *
 * Il sert AUSSI d'implémentation de référence côté récepteur du Protocole
 * d'Assertion de Passerelle (RFC-021 §4.3) : ~40 lignes de PHP pur suffisent à
 * un monolithe (Symfony, Laravel, legacy maison…) pour vérifier l'en-tête
 * X-Wfl-Assert-User et démarrer une « session virtuelle » sans ré-authentifier.
 *
 * Endpoints :
 *   GET /health            → sonde de liveness rapide (ni latence, ni allocation)
 *   GET /api/legacy-heavy  → latence bloquante ~500 ms + mémoire gonflée, corps JSON
 *                            (+ identité assertée si l'en-tête RFC-021 est présent)
 *   *                      → 404 JSON
 *
 * NOTE : l'usage de $_SERVER / des superglobales ici est délibéré et correct —
 * on simule une application PHP classique avec état. Ce N'EST PAS du code Waffle
 * et il vit en dehors du périmètre source Mago du workspace (qui ne scanne que
 * app/ et tests/).
 */

/**
 * Vérifie une assertion d'identité RFC-021 §4.3 (implémentation de référence).
 *
 * Format : base64url(JSON canonique) . hex(HMAC-SHA256), revendications
 * compactes usr/eml/rol/ten/iat/exp/iph. Toute violation lève une exception ;
 * l'appelant la transforme en HTTP 403 (fail-closed, jamais de repli anonyme).
 *
 * @return array<string, mixed> Les revendications vérifiées.
 */
function verifierAssertion(string $enTete, string $ipClient, string $secret): array
{
    // 1. Découpage structurel « payload.signature ».
    $parties = explode('.', $enTete);
    if (count($parties) !== 2 || $parties[0] === '' || $parties[1] === '') {
        throw new RuntimeException('Assertion structurellement invalide.');
    }
    [$payloadEncode, $signatureRecue] = $parties;

    // 2. Recalcul du HMAC sur le payload ENCODÉ + comparaison en temps constant
    //    (hash_equals — jamais == / === sur un MAC).
    $signatureAttendue = hash_hmac('sha256', $payloadEncode, $secret);
    if (!hash_equals($signatureAttendue, $signatureRecue)) {
        throw new RuntimeException('Signature HMAC invalide (assertion altérée).');
    }

    // 3. Décodage base64url strict puis JSON.
    $json = base64_decode(strtr($payloadEncode, '-_', '+/') . str_repeat('=', (4 - (strlen($payloadEncode) % 4)) % 4), true);
    $revendications = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($revendications)) {
        throw new RuntimeException('Payload d\'assertion illisible.');
    }

    // 4. Fenêtre temporelle anti-rejeu : exp futur, iat non futur, exp − iat ≤ 5 s.
    $iat = $revendications['iat'] ?? null;
    $exp = $revendications['exp'] ?? null;
    if (!is_int($iat) || !is_int($exp) || $exp <= time() || $iat > time() || ($exp - $iat) > 5) {
        throw new RuntimeException('Assertion expirée ou fenêtre de validité élargie (anti-rejeu).');
    }

    // 5. Liaison IP : hex(HMAC-SHA256(ip client, secret)) doit égaler `iph`.
    //    L'adresse brute ne voyage jamais — seul son hachage clavetté est signé.
    $iph = $revendications['iph'] ?? null;
    if (!is_string($iph) || !hash_equals(hash_hmac('sha256', $ipClient, $secret), $iph)) {
        throw new RuntimeException('Liaison IP invalide (tentative de détournement).');
    }

    return $revendications;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

header('Content-Type: application/json');
header('X-Backend: legacy-monolith');

// --- Pont d'Authentification Universel (RFC-021 §4.2/§4.3, côté récepteur) ---
// En-tête absent ⇒ requête anonyme (règles publiques). En-tête présent ⇒
// vérification stricte ; tout échec ⇒ 403 immédiat, AVANT tout contrôleur métier.
$identiteAssertee = null;
$assertion = $_SERVER['HTTP_X_WFL_ASSERT_USER'] ?? '';
if ($assertion !== '') {
    $secret = getenv('WAFFLE_AUTH_SECRET') ?: '';
    // Fail-closed : sans secret partagé, impossible de vérifier ⇒ on refuse.
    if (strlen($secret) < 32) {
        http_response_code(500);
        echo json_encode(['error' => 'WAFFLE_AUTH_SECRET manquant côté monolithe (fail-closed).'], JSON_THROW_ON_ERROR);

        return;
    }

    // L'IP « client » vue par le monolithe est celle transmise par la passerelle
    // (X-Forwarded-For). Elle est de confiance ICI uniquement parce que le
    // hachage signé `iph` doit y correspondre : un X-Forwarded-For falsifié
    // ferait échouer la liaison IP.
    $ipClient = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');

    try {
        $identiteAssertee = verifierAssertion($assertion, $ipClient, $secret);
    } catch (RuntimeException $e) {
        // « Session virtuelle » refusée : 403 immédiat, aucun repli anonyme.
        http_response_code(403);
        echo json_encode(['error' => 'Assertion rejetée', 'reason' => $e->getMessage()], JSON_THROW_ON_ERROR);

        return;
    }
}

if ($path === '/health') {
    http_response_code(200);
    echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);

    return;
}

if ($path === '/api/legacy-heavy') {
    // 1. Simulation d'une opération héritée lente et bloquante (~500 ms) qui
    //    immobilise le thread.
    usleep(500_000);

    // 2. Simulation d'une empreinte mémoire lourde en cours de requête (~24 MiB)
    //    pour que la réponse porte des indicateurs visibles « c'est coûteux »
    //    utiles au discours FinOps.
    $ballast = str_repeat('x', 24 * 1024 * 1024);
    $peakBytes = memory_get_peak_usage(true);

    header('X-Memory-Peak-Bytes: ' . $peakBytes);
    header('X-Latency-Ms: 500');
    http_response_code(200);

    echo json_encode([
        'source' => 'legacy-monolith',
        'message' => 'Cette réponse a été délibérément lente et lourde en mémoire.',
        'latency_ms' => 500,
        'memory_peak_bytes' => $peakBytes,
        'memory_peak_mib' => round($peakBytes / (1024 * 1024), 1),
        'served_by' => 'php-built-in-server (blocking)',
        // « Session virtuelle » RFC-021 : l'identité assertée par la passerelle,
        // sans aucune ré-authentification côté monolithe.
        'asserted_user' => $identiteAssertee === null ? null : [
            'usr' => $identiteAssertee['usr'] ?? null,
            'eml' => $identiteAssertee['eml'] ?? null,
            'rol' => $identiteAssertee['rol'] ?? [],
            'ten' => $identiteAssertee['ten'] ?? null,
        ],
    ], JSON_THROW_ON_ERROR);

    // On garde le ballast jusqu'après la capture du pic pour déjouer le GC précoce.
    unset($ballast);

    return;
}

http_response_code(404);
echo json_encode(['error' => 'Not Found', 'path' => $path], JSON_THROW_ON_ERROR);
