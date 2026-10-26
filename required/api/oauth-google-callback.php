<?php
// required/api/oauth-google-callback.php
// Handles the redirect from Google, exchanges code for tokens, and stores them.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../google_oauth.php';

require_admin_or_redirect(path_with_base('/admin/'));

$error = $_GET['error'] ?? null;
if ($error) {
    die('OAuth Error: ' . htmlspecialchars($error));
}

$code = $_GET['code'] ?? null;
if (!$code) {
    die('Authorization code not found.');
}

global $google_oauth_client_id, $google_oauth_client_secret;
$clientId = getenv('GOOGLE_OAUTH_CLIENT_ID') ?: ($google_oauth_client_id ?? '');
$clientSecret = getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: ($google_oauth_client_secret ?? '');

if (empty($clientId) || empty($clientSecret)) {
    die('OAuth client ID or secret is not configured.');
}

$redirectUri = absolute_url(path_with_base('/required/api/oauth-google-callback.php'));

// Exchange authorization code for tokens
$tokenUrl = 'https://oauth2.googleapis.com/token';
$payload = [
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code'
];

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => 1,
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode >= 400) {
    die('Failed to exchange code for tokens. Response: ' . $response);
}

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    die('Access token not found in response.');
}

$accessToken = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? null; // Refresh token is only sent on the first consent
$expiresIn = $tokenData['expires_in'] ?? 3599;
$expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
$adminId = current_admin_id();

try {
    $pdo = db();
    
    // Use an UPSERT to either insert a new token or update an existing one
    // We must preserve the refresh token if a new one isn't provided
    $stmt = $pdo->prepare(
        "INSERT INTO google_oauth_tokens (user_id, access_token, refresh_token, expires_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            access_token = VALUES(access_token),
            refresh_token = IF(VALUES(refresh_token) IS NOT NULL, VALUES(refresh_token), refresh_token),
            expires_at = VALUES(expires_at),
            updated_at = NOW()"
    );
    
    $stmt->execute([$adminId, $accessToken, $refreshToken, $expiresAt]);

    // Redirect back to the integrations page with a success message
    header('Location: ' . path_with_base('/admin/tools-google-integrations.php?connected=true'));
    exit;
    
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}


