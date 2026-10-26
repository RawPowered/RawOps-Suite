<?php
// ai-chat-streamlined.php
// Streamlined AI Sales Assistant - Focus on prices, tips, URLs, and database queries

// Start output buffering to catch any accidental output
ob_start();

// CRITICAL: Suppress ALL output except JSON
ini_set('display_errors', 0);
error_reporting(0);

// Set headers for JSON response immediately
header('Content-Type: application/json');

// Custom error handlers to ensure JSON responses
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'reply' => 'A server error occurred. Please try again.',
        'error' => "PHP Error: $errstr"
    ]);
    exit;
});

set_exception_handler(function($exception) {
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'reply' => 'An error occurred. Please try again.',
        'error' => $exception->getMessage()
    ]);
    exit;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level()) ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'reply' => 'A critical error occurred.',
            'error' => 'Fatal error: ' . $error['message']
        ]);
        exit;
    }
});

// Load configuration
try {
    require_once '../required/config.php';
    require_once '../required/ai-knowledge.php';
} catch (Exception $e) {
    if (ob_get_level()) ob_clean();
    echo json_encode([
        'success' => false, 
        'reply' => 'Configuration error. Please contact support.',
        'error' => 'Config load failed'
    ]);
    exit;
}

// Make API key globally accessible
global $gemini_api_key;

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (ob_get_level()) ob_clean();
    echo json_encode(['success' => true]);
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (ob_get_level()) ob_clean();
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'reply' => 'Only POST requests are allowed.',
        'error' => 'Invalid request method'
    ]);
    exit;
}

// Get and validate input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['message'])) {
    if (ob_get_level()) ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'reply' => 'Please provide a message.',
        'error' => 'Missing message parameter'
    ]);
    exit;
}

$userMessage = trim($input['message']);
$conversationHistory = $input['history'] ?? [];

if (empty($userMessage)) {
    if (ob_get_level()) ob_clean();
    echo json_encode([
        'success' => false,
        'reply' => 'Please provide a message.',
        'error' => 'Empty message'
    ]);
    exit;
}

// Use the db() function from config.php

// Get current user context
function getCurrentUserContext() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $context = [
        'logged_in' => false,
        'user_info' => null,
        'wholesale_account' => null,
        'recent_orders' => []
    ];
    
    if (isset($_SESSION['user_id'])) {
        try {
            $pdo = db();
            
            // Get user info
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if ($user) {
                $context['logged_in'] = true;
                $context['user_info'] = $user;
                
                // Get wholesale account
                $stmt = $pdo->prepare("SELECT * FROM wholesale_accounts WHERE user_id = ?");
                $stmt->execute([$user['id']]);
                $context['wholesale_account'] = $stmt->fetch();
                
                // Get recent orders (last 5)
                $stmt = $pdo->prepare("
                    SELECT o.*, COUNT(oi.id) as item_count 
                    FROM orders o 
                    LEFT JOIN order_items oi ON o.id = oi.order_id 
                    WHERE o.user_id = ? 
                    GROUP BY o.id 
                    ORDER BY o.created_at DESC 
                    LIMIT 5
                ");
                $stmt->execute([$user['id']]);
                $context['recent_orders'] = $stmt->fetchAll();
            }
        } catch (Exception $e) {
            // Continue without user context if there's an error
        }
    }
    
    return $context;
}

// Build context for AI
$userContext = getCurrentUserContext();

// Build enriched, centralized system prompt (with dual-unit rule and navigation maps)
$system_prompt = ai_build_system_prompt($userContext);

// Prepare API call to Gemini (v1beta with correct 2.5 flash model)
$gemini_api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $gemini_api_key;

// Build conversation history with system prompt as first message
$apiContents = [];

// Only add system prompt if this is the first message in the conversation
if (empty($conversationHistory)) {
    $apiContents[] = ['role' => 'user', 'parts' => [['text' => $system_prompt]]];
    $apiContents[] = ['role' => 'model', 'parts' => [['text' => 'Understood. I am your AI Sales Assistant for Product wholesale platform. I will follow all the guidelines and provide accurate information. How can I help you?']]];
}

// Add conversation history
foreach ($conversationHistory as $turn) {
    $role = ($turn['role'] === 'assistant') ? 'model' : 'user';
    if (isset($turn['content'])) {
        $apiContents[] = [
            'role' => $role,
            'parts' => [['text' => $turn['content']]]
        ];
    }
}

// Add current message
$apiContents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

$payload = [
    'contents' => $apiContents
];

try {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $gemini_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $apiResponse = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }
    curl_close($ch);

    $responseData = json_decode($apiResponse, true);

    if ($httpcode >= 400 || isset($responseData['error'])) {
        throw new Exception('Gemini API Error: ' . ($responseData['error']['message'] ?? 'Unknown error'));
    }

    $aiReply = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? 'Sorry, I could not process that.';
    
    // Track API usage for internal analytics (NOT charged to agents - this is a support tool)
    try {
        require_once __DIR__ . '/../required/expense-automation.php';
        $sessionId = session_id() ?: 'anonymous-' . uniqid();
        $userId = $_SESSION['user_id'] ?? null;
        
        // Note: This is the AI chat support tool, NOT the AI phone call agent feature
        // It tracks to api_usage_tracking for cost monitoring but does NOT charge agents
        track_api_usage('gemini', 'generateContent', 0.0005, $userId, $sessionId, 
            ['endpoint' => 'ai-chat-support', 'message_length' => strlen($userMessage)]);
    } catch (Exception $e) {
        // Continue even if tracking fails
        error_log("API usage tracking failed: " . $e->getMessage());
    }
    
    // Log the conversation
    try {
        $pdo = db();
        $sessionId = session_id() ?: 'anonymous-' . uniqid();
        $userId = $_SESSION['user_id'] ?? null;
        
        $stmt = $pdo->prepare("
            INSERT INTO ai_chat_logs (
                session_id, user_id, ip_address, user_agent, 
                user_message, ai_response, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $sessionId,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $userMessage,
            $aiReply
        ]);
    } catch (Exception $e) {
        // Continue even if logging fails
    }
    
    // Clean output buffer and return response
    if (ob_get_level()) ob_clean();
    echo json_encode([
        'success' => true,
        'reply' => $aiReply
    ]);
    
} catch (Exception $e) {
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'reply' => 'I encountered an error processing your request. Please try again.',
        'error' => $e->getMessage()
    ]);
}

// End output buffering
if (ob_get_level()) ob_end_flush();
?>
