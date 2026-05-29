<?php

declare(strict_types=1);

/**
 * « Monolithe hérité » factice pour la démo proxy de la passerelle EcoShield.
 *
 * Ce script est intentionnellement l'ANTITHÈSE d'un worker Waffle : il tourne
 * sur le serveur PHP intégré, bloque le thread de la requête avec une latence
 * artificielle et gonfle son empreinte mémoire par requête — exactement le type
 * de backend lent et lourd qu'EcoShield est conçu pour précéder et proxifier via
 * son client HTTP PSR-18 non bloquant et borné en mémoire.
 *
 * Endpoints :
 *   GET /health            → sonde de liveness rapide (ni latence, ni allocation)
 *   GET /api/legacy-heavy  → latence bloquante ~500 ms + mémoire gonflée, corps JSON
 *   *                      → 404 JSON
 *
 * NOTE : l'usage de $_SERVER / des superglobales ici est délibéré et correct —
 * on simule une application PHP classique avec état. Ce N'EST PAS du code Waffle
 * et il vit en dehors du périmètre source Mago du workspace (qui ne scanne que
 * app/ et tests/).
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

header('Content-Type: application/json');
header('X-Backend: legacy-monolith');

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
    ], JSON_THROW_ON_ERROR);

    // On garde le ballast jusqu'après la capture du pic pour déjouer le GC précoce.
    unset($ballast);

    return;
}

http_response_code(404);
echo json_encode(['error' => 'Not Found', 'path' => $path], JSON_THROW_ON_ERROR);
