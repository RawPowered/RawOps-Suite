<?php
// API Authentication - Logout Endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../required/config.php';

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Handle GET requests for status
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'method' => 'logout',
        'description' => 'Send POST request to logout',
        'authenticated' => current_user_id() ? true : false,
        'timestamp' => date('c')
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Logout user
logout_user();

echo json_encode([
    'success' => true,
    'message' => 'Logged out successfully',
    'login_url' => '/login.php',
    'timestamp' => date('c')
]);
?>
