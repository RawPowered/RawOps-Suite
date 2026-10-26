<?php
/**
 * Shortcode Preview API
 * Renders shortcode and returns HTML
 */

// Start output buffering to catch any accidental output
ob_start();

// Load only essential files
require_once __DIR__ . '/../../required/config.php';

// Clear any output that may have occurred
$buffer = ob_get_clean();
if (!empty($buffer)) {
    error_log("Preview API - Unexpected output caught: " . substr($buffer, 0, 200));
}

// NOW set header
header('Content-Type: application/json; charset=utf-8');

// Check authentication
start_session();
$userId = current_user_id();
if (!$userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

// Check admin role
if (!user_has_role($userId, 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

// Load shortcode engine AFTER authentication
require_once __DIR__ . '/../../required/shortcode-engine.php';

try {
    $shortcode = $_POST['shortcode'] ?? '';
    
    if (empty($shortcode)) {
        throw new Exception('No shortcode provided');
    }
    
    // Log the attempt
    error_log("Shortcode Preview Request: " . $shortcode);
    
    // Process the shortcode
    $html = process_shortcodes($shortcode);
    
    // Log success
    error_log("Shortcode Preview Success: " . strlen($html) . " bytes rendered");
    
    echo json_encode([
        'success' => true,
        'html' => $html,
        'original' => $shortcode
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    // Log the error
    error_log("Shortcode Preview Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}

