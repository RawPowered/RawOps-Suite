<?php
// Lightweight proxy to Google Places Web Service to avoid client-side CSP blocks
// Endpoints supported: autocomplete, details, geocode
// Query params:
//   op=ac&q=INPUT&session=TOKEN
//   op=details&place_id=PLACE_ID
//   op=geocode&lat=..&lng=..

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../required/config.php';
$pdo = db();

// Centralized Google API key
global $google_api_key, $gemini_api_key;
$API_KEY = $google_api_key ?: $gemini_api_key;

// Helper: categorize a place for client-side filtering (non-breaking enhancement)
function gp_categorize_place($name, $types) {
    $n = strtolower($name ?? '');
    // Normalize types to array of lowercase strings
    if (is_string($types)) { $types = array_filter(array_map('trim', explode(',', strtolower($types)))); }
    elseif (!is_array($types)) { $types = []; }
    else { $types = array_map(function($t){ return strtolower(trim((string)$t)); }, $types); }

    // Explicit name-based detection for Product / partner venues
    if (strpos($n, 'product') !== false || strpos($n, 'partner') !== false) {
        return 'product_kava';
    }
    // Gas / convenience
    if (in_array('gas_station', $types, true) || in_array('convenience_store', $types, true)) {
        return 'convenience_gas';
    }
    // Smoke / vape / head shops
    if (strpos($n, 'smoke') !== false || strpos($n, 'vape') !== false || strpos($n, 'head') !== false) {
        return 'smoke_vape';
    }
    // Herbal, apothecary, wellness
    if (strpos($n, 'herbal') !== false || strpos($n, 'apothecary') !== false || strpos($n, 'wellness') !== false) {
        return 'herbal';
    }
    // Bars with lounge/tea hints often used for partner bars
    if (in_array('bar', $types, true) && (strpos($n, 'tea') !== false || strpos($n, 'lounge') !== false)) {
        return 'product_kava';
    }
    return 'core';
}

function gp_haversine_meters($lat1, $lng1, $lat2, $lng2) {
    if (!is_numeric($lat1) || !is_numeric($lng1) || !is_numeric($lat2) || !is_numeric($lng2)) {
        return null;
    }
    $lat1 = (float)$lat1;
    $lng1 = (float)$lng1;
    $lat2 = (float)$lat2;
    $lng2 = (float)$lng2;
    $earthRadius = 6371000.0; // meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

function gp_resolve_radius_meters($defaultMeters = 16093) {
    $minMeters = 804; // ~0.5 mile safeguard
    $maxMeters = 80467; // 50 miles
    $radiusMeters = null;

    if (isset($_GET['radius_miles'])) {
        $radiusMiles = (float)$_GET['radius_miles'];
        if ($radiusMiles > 0) {
            $radiusMeters = $radiusMiles * 1609.344;
        }
    }

    if ($radiusMeters === null && isset($_GET['radius'])) {
        $raw = (float)$_GET['radius'];
        if ($raw > 0) {
            $units = strtolower((string)($_GET['radius_units'] ?? ''));
            if ($units === 'mi' || ($raw <= 250 && (!isset($_GET['radius_units'])))) {
                // Treat small integral values as miles for backward compatibility
                if ($units === 'mi' || abs($raw - round($raw)) < 0.0001) {
                    $radiusMeters = $raw * 1609.344;
                }
            }
            if ($radiusMeters === null) {
                $radiusMeters = $raw;
            }
        }
    }

    if ($radiusMeters === null || $radiusMeters <= 0) {
        $radiusMeters = $defaultMeters;
    }

    $radiusMeters = max($minMeters, min($maxMeters, $radiusMeters));

    return (float)$radiusMeters;
}

function http_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false) {
        http_response_code(502);
        echo json_encode(['error' => 'proxy_error', 'message' => $err]);
        exit;
    }
    http_response_code($code ?: 200);
    echo $resp;
    exit;
}

function http_post_json($url, $payload, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false) {
        http_response_code(502);
        echo json_encode(['error' => 'proxy_error', 'message' => $err]);
        exit;
    }
    http_response_code($code ?: 200);
    echo $resp;
    exit;
}

// Check API restriction before processing
try {
    require_once __DIR__ . '/../required/api-restriction-checker.php';
    $userId = $_SESSION['user_id'] ?? null;
    
    if ($userId) {
        $check = check_api_access($userId, 'google_api');
        if (!$check['allowed']) {
            // Track blocked attempt
            try {
                require_once __DIR__ . '/../required/expense-automation.php';
                $op = $_GET['op'] ?? 'ac';
                $pdo = db();
                $stmt = $pdo->prepare('INSERT INTO api_usage_tracking (service, endpoint, cost, user_id, session_id, blocked_by_restriction, created_at) VALUES (?, ?, 0, ?, ?, 1, NOW())');
                $stmt->execute(['google_places', $op, $userId, session_id()]);
            } catch (Exception $e) {}
            
            http_response_code(403);
            echo json_encode([
                'error' => 'api_restricted',
                'message' => $check['reason'],
                'stats' => $check['stats'],
                'needs_details_count' => $check['needs_details_count'],
                'threshold' => $check['threshold'] ?? 80
            ]);
            exit;
        }
    }
} catch (Throwable $e) {
    error_log("API Restriction Check Error (Google Places): " . $e->getMessage());
    // Continue on error (fail open)
}

// Track API usage
try {
    require_once __DIR__ . '/../required/expense-automation.php';
    $op = $_GET['op'] ?? 'ac';
    $cost = ($op === 'ac' || $op === 'details') ? 0.017 : 0.01;
    track_api_usage('google_places', $op, $cost, $_SESSION['user_id'] ?? null, session_id());
} catch (Exception $e) {
    // Continue even if tracking fails
}

$op = $_GET['op'] ?? 'ac';
if ($op === 'ac') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(['predictions' => []]); exit; }
    $session = urlencode($_GET['session'] ?? '');
    $url = 'https://maps.googleapis.com/maps/api/place/autocomplete/json?'
         . 'input=' . urlencode($q)
         . '&types=geocode&components=country:us'
         . '&key=' . urlencode($API_KEY)
         . ($session ? '&sessiontoken=' . $session : '');
    http_get($url);
}
if ($op === 'details') {
    $pid = $_GET['place_id'] ?? '';
    if (!$pid) { http_response_code(400); echo json_encode(['error' => 'missing_place_id']); exit; }
    $url = 'https://maps.googleapis.com/maps/api/place/details/json?'
         . 'place_id=' . urlencode($pid)
         . '&fields=address_component,formatted_address,geometry,name,place_id'
         . '&key=' . urlencode($API_KEY);
    http_get($url);
}
if ($op === 'geocode') {
    $lat = $_GET['lat'] ?? '';
    $lng = $_GET['lng'] ?? '';
    if (!$lat || !$lng) { http_response_code(400); echo json_encode(['error' => 'missing_latlng']); exit; }
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?latlng=' . urlencode($lat . ',' . $lng) . '&key=' . urlencode($API_KEY);
    http_get($url);
}

if ($op === 'textsearch') {
    $query = trim($_GET['query'] ?? '');
    $lat = $_GET['lat'] ?? '';
    $lng = $_GET['lng'] ?? '';
    $radiusMeters = gp_resolve_radius_meters();
    if (!$query || !$lat || !$lng) { http_response_code(400); echo json_encode(['error' => 'missing_params']); exit; }
    $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?'
         . 'query=' . urlencode($query)
         . '&location=' . urlencode($lat . ',' . $lng)
         . '&radius=' . (int)round($radiusMeters)
         . '&key=' . urlencode($API_KEY);
    http_get($url);
}

if ($op === 'geocode_addr') {
    $addr = trim($_GET['address'] ?? '');
    if (!$addr) { http_response_code(400); echo json_encode(['error' => 'missing_address']); exit; }
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($addr) . '&key=' . urlencode($API_KEY);
    http_get($url);
}

if ($op === 'staticmap') {
    $lat = (float)($_GET['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? 0);
    $zoom = intval($_GET['zoom'] ?? 13);
    $size = preg_replace('/[^0-9x]/', '', $_GET['size'] ?? '600x260');
    if (!$lat || !$lng) { http_response_code(400); echo json_encode(['error' => 'missing_latlng']); exit; }
    $url = 'https://maps.googleapis.com/maps/api/staticmap?center=' . urlencode($lat . ',' . $lng)
        . '&zoom=' . $zoom . '&size=' . $size . '&markers=color:red|' . urlencode($lat . ',' . $lng)
        . '&key=' . urlencode($API_KEY);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>true]);
    $img = curl_exec($ch);
    curl_close($ch);
    if ($img === false) { http_response_code(502); echo json_encode(['error'=>'static_map_failed']); exit; }
    header('Content-Type: image/png');
    echo $img;
    exit;
}

if ($op === 'nearby') {
    $lat = $_GET['lat'] ?? '';
    $lng = $_GET['lng'] ?? '';
    $radiusMeters = gp_resolve_radius_meters();
    $keyword = trim($_GET['keyword'] ?? '');
    $type = trim($_GET['type'] ?? 'store');
    if (!$lat || !$lng || !$keyword) { http_response_code(400); echo json_encode(['error' => 'missing_params']); exit; }
    $url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?'
         . 'location=' . urlencode($lat . ',' . $lng)
         . '&radius=' . (int)round($radiusMeters)
         . '&type=' . urlencode($type)
         . '&keyword=' . urlencode($keyword)
         . '&key=' . urlencode($API_KEY);
    http_get($url);
}

// Places API (New) — searchText with location restriction
if ($op === 'v1_textsearch') {
    $lat = (float)($_GET['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? 0);
    $radiusMeters = gp_resolve_radius_meters();
    $includeGas = intval($_GET['include_gas'] ?? 0);
    $withDetails = intval($_GET['details'] ?? 0) ? 1 : 0; // optionally enrich with details for top results
    $filterCategories = array_values(array_filter(array_map('trim', explode(',', strtolower($_GET['categories'] ?? '')))));
    if (!$lat || !$lng) { http_response_code(400); echo json_encode(['error' => 'missing_latlng']); exit; }

    $keywords = ['retail partners','product','partner bar','vape shop','head shop','herbal store','partner lounge','product shop'];
    if ($includeGas) { $keywords[] = 'gas station'; $keywords[] = 'convenience store'; }

    $url = 'https://places.googleapis.com/v1/places:searchText';
    $headers = [
        'Content-Type: application/json',
        'X-Goog-Api-Key: ' . $API_KEY,
        'X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.shortFormattedAddress,places.location,places.types',
        'Referer: https://product.example/'
    ];

    $uniq = [];
    $merged = [];
    $lastErr = null;
    foreach ($keywords as $kw) {
        $payload = [
            'textQuery' => $kw,
            'maxResultCount' => 20,
            // Use locationBias.circle for GA compatibility
            'locationBias' => [
                'circle' => [
                    'center' => [ 'latitude' => $lat, 'longitude' => $lng ],
                    'radius' => (float)$radiusMeters
                ]
            ]
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp === false) { continue; }
        $obj = json_decode($resp, true);
        if (isset($obj['error'])) { $lastErr = $obj['error']['message'] ?? 'Places API error'; continue; }
        foreach (($obj['places'] ?? []) as $p) {
            $pid = $p['id'] ?? '';
            if (!$pid || isset($uniq[$pid])) continue;
            $uniq[$pid] = true;
            $name = isset($p['displayName']['text']) ? $p['displayName']['text'] : ($p['displayName'] ?? '');
            $addr = $p['formattedAddress'] ?? ($p['shortFormattedAddress'] ?? '');
            $loc = $p['location'] ?? [];
            $typesArr = isset($p['types']) ? (is_array($p['types']) ? $p['types'] : explode(',', (string)$p['types'])) : [];
            $latVal = isset($loc['latitude']) ? (float)$loc['latitude'] : null;
            $lngVal = isset($loc['longitude']) ? (float)$loc['longitude'] : null;
            $distanceMeters = ($latVal !== null && $lngVal !== null) ? gp_haversine_meters($lat, $lng, $latVal, $lngVal) : null;
            if ($distanceMeters !== null && $distanceMeters > ($radiusMeters * 1.02)) { continue; }
            $categoryKey = gp_categorize_place($name, $typesArr);
            if (!empty($filterCategories) && !in_array($categoryKey, $filterCategories, true)) { continue; }
            $merged[] = [
                'place_id' => $pid,
                'name' => $name,
                'formatted_address' => $addr,
                'vicinity' => $p['shortFormattedAddress'] ?? $addr,
                'types' => !empty($typesArr) ? implode(',', array_map('strtolower', $typesArr)) : null,
                'category_key' => $categoryKey,
                'geometry' => [ 'location' => [ 'lat' => $latVal, 'lng' => $lngVal ]],
                'distance_m' => $distanceMeters
            ];
        }
    }
    // Fallback: if v1 returned nothing and no explicit error, try legacy Web Service
    if (empty($merged) && !$lastErr) {
        $legacyUniq = [];
        foreach ($keywords as $kw) {
            $legacyUrl = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?'
                . 'location=' . urlencode($lat . ',' . $lng)
                . '&radius=' . (int)round($radiusMeters)
                . '&keyword=' . urlencode($kw)
                . '&key=' . urlencode($API_KEY);
            $json = @file_get_contents($legacyUrl);
            if ($json === false) { continue; }
            $obj = json_decode($json, true);
            foreach (($obj['results'] ?? []) as $r) {
                $pid = $r['place_id'] ?? '';
                if (!$pid || isset($legacyUniq[$pid])) continue;
                $legacyUniq[$pid] = true;
                $loc = $r['geometry']['location'] ?? [];
                $typesArr = isset($r['types']) ? (is_array($r['types']) ? $r['types'] : explode(',', (string)$r['types'])) : [];
                $latVal = isset($loc['lat']) ? (float)$loc['lat'] : null;
                $lngVal = isset($loc['lng']) ? (float)$loc['lng'] : null;
                $distanceMeters = ($latVal !== null && $lngVal !== null) ? gp_haversine_meters($lat, $lng, $latVal, $lngVal) : null;
                if ($distanceMeters !== null && $distanceMeters > ($radiusMeters * 1.02)) { continue; }
                $categoryKey = gp_categorize_place($r['name'] ?? '', $typesArr);
                if (!empty($filterCategories) && !in_array($categoryKey, $filterCategories, true)) { continue; }
                $merged[] = [
                    'place_id' => $pid,
                    'name' => $r['name'] ?? '',
                    'formatted_address' => $r['formatted_address'] ?? ($r['vicinity'] ?? ''),
                    'vicinity' => $r['vicinity'] ?? ($r['formatted_address'] ?? ''),
                    'types' => !empty($typesArr) ? implode(',', array_map('strtolower', $typesArr)) : null,
                    'category_key' => $categoryKey,
                    'geometry' => [ 'location' => [ 'lat' => $latVal, 'lng' => $lngVal ]],
                    'distance_m' => $distanceMeters
                ];
            }
        }
    }

    http_response_code(200);
    if (empty($merged) && $lastErr) {
        echo json_encode(['results' => [], 'error' => $lastErr]);
        exit;
    }
    // Optional details enrichment for the first N results to get phone/website
    if ($withDetails && !empty($merged)) {
        $max = min(10, count($merged));
        for ($i = 0; $i < $max; $i++) {
            $pid = $merged[$i]['place_id'];
            $url = 'https://places.googleapis.com/v1/places/' . rawurlencode($pid);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => [
                    'X-Goog-Api-Key: ' . $API_KEY,
                    'X-Goog-FieldMask: id,displayName,internationalPhoneNumber,nationalPhoneNumber,websiteUri,googleMapsUri',
                    'Referer: https://product.example/'
                ]
            ]);
            $d = curl_exec($ch);
            curl_close($ch);
            if ($d !== false) {
                $obj = json_decode($d, true);
                if (is_array($obj)) {
                    $merged[$i]['intl_phone'] = $obj['internationalPhoneNumber'] ?? null;
                    $merged[$i]['phone'] = $obj['nationalPhoneNumber'] ?? null;
                    $merged[$i]['website'] = $obj['websiteUri'] ?? null;
                    $merged[$i]['maps_uri'] = $obj['googleMapsUri'] ?? null;
                }
            }
        }
    }

    echo json_encode(['results' => array_values($merged)]);
    exit;
}

// Places API v1 details passthrough (explicit endpoint)
if ($op === 'v1_details') {
    $pid = trim($_GET['place_id'] ?? '');
    if ($pid === '') { http_response_code(400); echo json_encode(['error' => 'missing_place_id']); exit; }
    $fields = trim($_GET['fields'] ?? 'id,displayName,formattedAddress,googleMapsUri,location,types,internationalPhoneNumber,nationalPhoneNumber,websiteUri,rating,userRatingCount,currentOpeningHours');
    $url = 'https://places.googleapis.com/v1/places/' . rawurlencode($pid);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'X-Goog-Api-Key: ' . $API_KEY,
            'X-Goog-FieldMask: ' . $fields,
            'Referer: https://product.example/'
        ]
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false) { http_response_code(502); echo json_encode(['error' => 'proxy_error', 'message' => $err]); exit; }
    http_response_code($code ?: 200);
    echo $resp;
    exit;
}

if ($op === 'nearby_cached') {
    $lat = (float)($_GET['lat'] ?? 0);
    $lng = (float)($_GET['lng'] ?? 0);
    $radiusMeters = gp_resolve_radius_meters();
    $includeGas = intval($_GET['include_gas'] ?? 0);
    $leadId = intval($_GET['lead_id'] ?? 0);
    $categoryKey = trim($_GET['category_key'] ?? 'core');

    // Try cache first (last 24h)
    try {
        $stmt = $pdo->prepare('SELECT place_id, name, address, vicinity, types, source, place_lat, place_lng, fetched_at FROM crm_places_cache WHERE lead_id = ? AND radius_m = ? AND include_gas = ? AND category_key = ? AND fetched_at >= (NOW() - INTERVAL 1 DAY) ORDER BY fetched_at DESC LIMIT 20');
        $stmt->execute([$leadId ?: null, (int)round($radiusMeters), $includeGas, $categoryKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            echo json_encode(['cached' => true, 'results' => array_map(function($r){ return [
                'place_id' => $r['place_id'], 'name' => $r['name'], 'formatted_address' => $r['address'], 'vicinity' => $r['vicinity'], 'types' => $r['types'],
                'geometry' => [ 'location' => [ 'lat' => isset($r['place_lat']) ? (float)$r['place_lat'] : null, 'lng' => isset($r['place_lng']) ? (float)$r['place_lng'] : null ]]
            ]; }, $rows)]);
            exit;
        }
    } catch (Throwable $e) {}

    // Fetch fresh using Nearby for each category keyword
    $keywords = ['retail partners','product bar','partner bar','vape shop','head shop','herbal store','partner lounge','product shop'];
    if ($includeGas) { $keywords[] = 'gas station'; $keywords[] = 'convenience store'; }
    $all = [];
    foreach ($keywords as $kw) {
        $url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?'
             . 'location=' . urlencode($lat . ',' . $lng)
             . '&radius=' . (int)round($radiusMeters)
             . '&type=store'
             . '&keyword=' . urlencode($kw)
             . '&key=' . urlencode($API_KEY);
        $json = file_get_contents($url);
        if ($json !== false) {
            $obj = json_decode($json, true);
            foreach (($obj['results'] ?? []) as $r) {
                $all[$r['place_id']] = $r + ['source' => 'nearby'];
            }
        }
    }

    // Fallback textsearch if empty
    if (empty($all)) {
        foreach ($keywords as $kw) {
            $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?'
                 . 'query=' . urlencode($kw)
                 . '&location=' . urlencode($lat . ',' . $lng)
                 . '&radius=' . (int)round($radiusMeters)
                 . '&key=' . urlencode($API_KEY);
            $json = file_get_contents($url);
            if ($json !== false) {
                $obj = json_decode($json, true);
                foreach (($obj['results'] ?? []) as $r) {
                    $all[$r['place_id']] = $r + ['source' => 'textsearch'];
                }
            }
        }
    }

    if (!empty($all) && $radiusMeters > 0) {
        foreach ($all as $pid => $r) {
            $loc = $r['geometry']['location'] ?? [];
            $latVal = isset($loc['lat']) ? (float)$loc['lat'] : null;
            $lngVal = isset($loc['lng']) ? (float)$loc['lng'] : null;
            $distanceMeters = ($latVal !== null && $lngVal !== null) ? gp_haversine_meters($lat, $lng, $latVal, $lngVal) : null;
            if ($distanceMeters !== null && $distanceMeters > ($radiusMeters * 1.02)) {
                unset($all[$pid]);
                continue;
            }
            $all[$pid]['distance_m'] = $distanceMeters;
        }
    }

    // Upsert cache
    try {
        $ins = $pdo->prepare('INSERT INTO crm_places_cache (lead_id, lat, lng, radius_m, include_gas, category_key, place_id, name, address, vicinity, types, source, place_lat, place_lng, fetched_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name), address=VALUES(address), vicinity=VALUES(vicinity), types=VALUES(types), source=VALUES(source), place_lat=VALUES(place_lat), place_lng=VALUES(place_lng), fetched_at=NOW()');
        foreach ($all as $r) {
            $pl = $r['geometry']['location'] ?? ['lat' => null, 'lng' => null];
            $ins->execute([
                $leadId ?: null,
                $lat, $lng, (int)round($radiusMeters), $includeGas, $categoryKey,
                $r['place_id'] ?? '',
                $r['name'] ?? '',
                $r['formatted_address'] ?? null,
                $r['vicinity'] ?? null,
                isset($r['types']) ? (is_array($r['types']) ? implode(',', $r['types']) : $r['types']) : null,
                $r['source'] ?? 'nearby',
                isset($pl['lat']) ? (float)$pl['lat'] : null,
                isset($pl['lng']) ? (float)$pl['lng'] : null
            ]);
        }
    } catch (Throwable $e) {}

    echo json_encode(['cached' => false, 'results' => array_values($all)]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid_op']);


