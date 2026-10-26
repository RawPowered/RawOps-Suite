<?php
// required/api/oauth-google-start.php
// Initiates the Google OAuth 2.0 flow

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../google_oauth.php';

// Only admins can connect their Google account
require_admin_or_redirect(path_with_base('/admin/'));

global $google_oauth_client_id;
$clientId = getenv('GOOGLE_OAUTH_CLIENT_ID') ?: ($google_oauth_client_id ?? '');

if (empty($clientId)) {
    die('OAuth client ID is not configured. Please set it on the Admin -> Google Integrations page.');
}

// These scopes are required for sending emails and managing calendar events
$scopes = [
    'https://www.googleapis.com/auth/gmail.send',
    'https://www.googleapis.com/auth/calendar.events',
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile'
];

$redirectUri = absolute_url(path_with_base('/required/api/oauth-google-callback.php'));

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => implode(' ', $scopes),
    'access_type' => 'offline', // Required to get a refresh token
    'prompt' => 'consent'      // Ensures the user is prompted for consent every time
]);

header('Location: ' . $authUrl);
exit;


