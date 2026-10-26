<?php
// API Authentication - Login Endpoint
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Return login form or status
    $uid = current_user_id();
    echo json_encode([
        'authenticated' => $uid ? true : false,
        'user_id' => $uid,
        'login_url' => '/login.php',
        'timestamp' => date('c')
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password required']);
    exit;
}

$uid = login_user($email, $password);
if ($uid) {
    echo json_encode([
        'success' => true,
        'user_id' => $uid,
        'dashboard_url' => user_dashboard_path($uid),
        'timestamp' => date('c')
    ]);
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
}
?>
