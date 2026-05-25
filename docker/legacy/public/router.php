<?php

declare(strict_types=1);

/**
 * Mock "Legacy Monolith" for the EcoShield Gateway proxy demonstration.
 *
 * This is intentionally the ANTITHESIS of a Waffle worker: it runs on the plain
 * PHP built-in server, blocks the request thread with artificial latency, and
 * inflates its per-request memory footprint — exactly the kind of slow, heavy
 * backend EcoShield is designed to sit in front of and proxy through its
 * non-blocking, memory-bounded PSR-18 HTTP client.
 *
 * Endpoints:
 *   GET /health            → fast liveness probe (no latency, no allocation)
 *   GET /api/legacy-heavy  → ~500ms blocking latency + inflated memory, JSON body
 *   *                      → 404 JSON
 *
 * NOTE: the use of $_SERVER / superglobals here is deliberate and correct — this
 * mocks a classic stateful PHP app. It is NOT Waffle code and lives outside the
 * workspace Mago source perimeter (which only scans app/ and tests/).
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
    // 1. Simulate a slow, blocking legacy operation (~500ms) that pins the thread.
    usleep(500_000);

    // 2. Simulate a heavy in-request memory footprint (~24 MiB) so the response
    //    carries visible "this is expensive" indicators for the FinOps narrative.
    $ballast = str_repeat('x', 24 * 1024 * 1024);
    $peakBytes = memory_get_peak_usage(true);

    header('X-Memory-Peak-Bytes: ' . $peakBytes);
    header('X-Latency-Ms: 500');
    http_response_code(200);

    echo json_encode([
        'source' => 'legacy-monolith',
        'message' => 'This response was deliberately slow and memory-heavy.',
        'latency_ms' => 500,
        'memory_peak_bytes' => $peakBytes,
        'memory_peak_mib' => round($peakBytes / (1024 * 1024), 1),
        'served_by' => 'php-built-in-server (blocking)',
    ], JSON_THROW_ON_ERROR);

    // Hold the ballast until after the peak is captured to defeat early GC.
    unset($ballast);

    return;
}

http_response_code(404);
echo json_encode(['error' => 'Not Found', 'path' => $path], JSON_THROW_ON_ERROR);
