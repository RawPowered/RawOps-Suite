<?php
// Lightweight helpers for Google OAuth token retrieval/refresh
require_once __DIR__ . '/config.php';

function google_get_access_token_for_user(int $userId): ?string {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT access_token, refresh_token, expires_at FROM google_oauth_tokens WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $access = $row['access_token'];
    $refresh = $row['refresh_token'] ?? null;
    $exp = $row['expires_at'] ? strtotime($row['expires_at']) : 0;
    if ($exp > 0 && $exp > time() + 30) return $access; // still valid

    if (!$refresh) return $access; // cannot refresh, return possibly-expired token

    global $google_oauth_client_id, $google_oauth_client_secret;
    $clientId = getenv('GOOGLE_OAUTH_CLIENT_ID') ?: ($google_oauth_client_id ?? '');
    $clientSecret = getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: ($google_oauth_client_secret ?? '');
    if ($clientId === '' || $clientSecret === '') return $access;

    $payload = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refresh,
        'grant_type' => 'refresh_token'
    ];
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>http_build_query($payload), CURLOPT_TIMEOUT=>8]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 400) return $access;
    $tok = json_decode($resp, true) ?: [];
    $newAccess = $tok['access_token'] ?? null;
    $expiresIn = intval($tok['expires_in'] ?? 0);
    if ($newAccess) {
        $expiresAt = $expiresIn > 0 ? date('Y-m-d H:i:s', time() + $expiresIn - 30) : null;
        $upd = $pdo->prepare('UPDATE google_oauth_tokens SET access_token = ?, expires_at = ?, updated_at = NOW() WHERE user_id = ?');
        $upd->execute([$newAccess, $expiresAt, $userId]);
        return $newAccess;
    }
    return $access;
}


