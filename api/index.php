<?php
// API Directory Index
header('Content-Type: application/json');

$endpoints = [
    'health' => [
        'url' => '/api/health',
        'methods' => ['GET'],
        'description' => 'System health check'
    ],
    'auth' => [
        'login' => [
            'url' => '/api/auth/login',
            'methods' => ['GET', 'POST'],
            'description' => 'User authentication'
        ],
        'logout' => [
            'url' => '/api/auth/logout', 
            'methods' => ['GET', 'POST'],
            'description' => 'User logout'
        ]
    ],
    'user' => [
        'profile' => [
            'url' => '/api/user/profile',
            'methods' => ['GET', 'POST'],
            'description' => 'User profile management'
        ]
    ],
    'training' => [
        'progress' => [
            'url' => '/api/training/progress',
            'methods' => ['GET', 'POST'],
            'description' => 'Training progress tracking'
        ],
        'activity' => [
            'url' => '/api/training-activity.php',
            'methods' => ['GET', 'POST'],
            'description' => 'Training activity logging'
        ]
    ]
];

$baseUrl = site_origin() . outbound_path('/api/');

echo json_encode([
    'api_version' => '1.0.0',
    'status' => 'operational',
    'timestamp' => date('c'),
    'base_url' => $baseUrl,
    'endpoints' => $endpoints
], JSON_PRETTY_PRINT);
?>
