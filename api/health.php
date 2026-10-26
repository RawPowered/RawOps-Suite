<?php
// API Health Check Endpoint
header('Content-Type: application/json');

// Basic health check response
$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'version' => '1.0.0',
    'environment' => 'production',
    'database' => 'connected',
    'services' => [
        'auth' => 'operational',
        'shop' => 'operational', 
        'training' => 'operational',
        'api' => 'operational'
    ]
];

try {
    // Test database connection
    require_once __DIR__ . '/../required/config.php';
    $pdo = db();
    $pdo->query('SELECT 1')->fetch();
    $health['database'] = 'connected';
} catch (Exception $e) {
    $health['database'] = 'error';
    $health['status'] = 'degraded';
    http_response_code(503);
}

echo json_encode($health, JSON_PRETTY_PRINT);
?>
