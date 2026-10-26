<?php
// Auth API Directory Index
header('Content-Type: application/json');

echo json_encode([
    'auth_api' => 'v1.0',
    'status' => 'operational',
    'endpoints' => [
        'login' => [
            'url' => '/api/auth/login',
            'file' => 'login.php',
            'methods' => ['GET', 'POST']
        ],
        'logout' => [
            'url' => '/api/auth/logout', 
            'file' => 'logout.php',
            'methods' => ['GET', 'POST']
        ]
    ],
    'direct_access' => [
        'login_direct' => '/api/auth/login.php',
        'logout_direct' => '/api/auth/logout.php'
    ],
    'timestamp' => date('c')
]);
?>
