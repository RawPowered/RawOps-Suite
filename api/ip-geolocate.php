<?php
// Approximate IP-based geolocation fallback (no precise GPS)
// Uses HTTPS-only providers to avoid mixed-content issues

require_once __DIR__ . '/../required/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function curl_post_json($url, $payload, $timeout = 8) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) { return null; }
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

// Google Geolocation API using IP (approximate) — single authoritative source
// Centralized Google API key
global $google_api_key, $gemini_api_key;
$API_KEY = $google_api_key ?: $gemini_api_key;
$g = curl_post_json('https://www.googleapis.com/geolocation/v1/geolocate?key=' . urlencode($API_KEY), [ 'considerIp' => true ]);
if (!is_array($g) || !isset($g['location']['lat']) || !isset($g['location']['lng'])) {
    http_response_code(200);
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode([
    'ok' => true,
    'approximate' => true,
    'lat' => (float)$g['location']['lat'],
    'lng' => (float)$g['location']['lng'],
    'source' => 'google_geolocate'
]);
exit;


