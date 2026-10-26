<?php
// User API Directory Index  
header('Content-Type: application/json');

echo json_encode([
    'user_api' => 'v1.0',
    'status' => 'operational',
    'endpoints' => [
        'profile' => [
            'url' => '/api/user/profile',
            'file' => 'profile.php', 
            'methods' => ['GET', 'POST']
        ]
    ],
    'direct_access' => [
        'profile_direct' => '/api/user/profile.php'
    ],
    'timestamp' => date('c')
]);
?>
