<?php
/**
 * Store Search API
 * AJAX endpoint for searching stores by name, email, or phone
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/demo-functions.php';

// Set JSON response headers
header('Content-Type: application/json');

// Start session for rate limiting
start_session();

// Initialize response
$response = [
    'success' => false,
    'results' => [],
    'message' => ''
];

try {
    // Get search query
    $query = trim($_GET['q'] ?? '');
    
    // Validate query length
    if (strlen($query) < 2) {
        $response['message'] = 'Please enter at least 2 characters';
        echo json_encode($response);
        exit;
    }
    
    // Rate limiting - 10 searches per minute per session
    $rate_key = 'store_search_' . session_id();
    $rate_limit = 10;
    $rate_window = 60; // seconds
    
    if (!isset($_SESSION[$rate_key])) {
        $_SESSION[$rate_key] = ['count' => 0, 'reset_time' => time() + $rate_window];
    }
    
    // Reset counter if window expired
    if (time() > $_SESSION[$rate_key]['reset_time']) {
        $_SESSION[$rate_key] = ['count' => 0, 'reset_time' => time() + $rate_window];
    }
    
    // Check rate limit
    if ($_SESSION[$rate_key]['count'] >= $rate_limit) {
        $response['message'] = 'Too many searches. Please wait a moment.';
        http_response_code(429);
        echo json_encode($response);
        exit;
    }
    
    // Increment counter
    $_SESSION[$rate_key]['count']++;
    
    // Get database connection
    $pdo = db();
    
    // Perform search
    $results = search_stores($pdo, $query);
    
    // Format results
    $formatted = [];
    foreach ($results as $result) {
        $formatted[] = [
            'id' => $result['id'] ?? null,
            'store_name' => $result['store_name'] ?? '',
            'owner_name' => trim(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? '')),
            'email' => $result['email'] ?? '',
            'phone' => $result['phone'] ?? '',
            'has_account' => !empty($result['wholesale_account_id']),
            'source' => $result['source_type'] ?? 'unknown'
        ];
    }
    
    $response['success'] = true;
    $response['results'] = $formatted;
    $response['count'] = count($formatted);
    
} catch (Exception $e) {
    $response['message'] = 'Search error occurred';
    error_log('Store search API error: ' . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);
