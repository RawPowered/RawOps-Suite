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
    global $gemini_api_key;
} catch (Exception $e) {
    if (ob_get_level()) ob_clean();
    echo json_encode([
        'success' => false, 
        'reply' => 'Configuration error. Please contact support.',
        'error' => 'Config load failed'
    ]);
    exit;
}

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

// Database connection helper
function db() {
    global $host, $username, $password, $database;
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            throw new Exception('Database connection failed');
        }
    }
    return $pdo;
}

// Get current user context
function getCurrentUserContext() {
    if (!isset($_SESSION)) {
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

// Get product information
function getProductInfo() {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM products WHERE active = 1 ORDER BY name");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// Build context for AI
$userContext = getCurrentUserContext();
$products = getProductInfo();

// Create system prompt focused on core capabilities
$system_prompt = "You are the AI Sales Assistant for Product, a premium Product wholesale platform.

CORE CAPABILITIES:
- Provide product information, prices, and recommendations
- Give business tips and profit calculations
- Direct users to relevant URLs and pages
- Access user account and order information when available

PRODUCT CATALOG:";

foreach ($products as $product) {
    $profit_margin = (($product['msrp'] - $product['wholesale_price']) / $product['msrp']) * 100;
    $system_prompt .= "\n- {$product['name']} (SKU: {$product['sku']})";
    $system_prompt .= "\n  Wholesale: \${$product['wholesale_price']} | MSRP: \${$product['msrp']} | Profit Margin: " . round($profit_margin, 1) . "%";
    $system_prompt .= "\n  Description: {$product['description']}";
}

$system_prompt .= "\n\nUSER CONTEXT:";
if ($userContext['logged_in']) {
    $user = $userContext['user_info'];
    $system_prompt .= "\n- User: {$user['first_name']} {$user['last_name']} ({$user['email']})";
    
    if ($userContext['wholesale_account']) {
        $account = $userContext['wholesale_account'];
        $system_prompt .= "\n- Business: {$account['business_name']}";
        $system_prompt .= "\n- Account Status: " . ($account['approved'] ? 'Approved' : 'Pending Approval');
    }
    
    if (!empty($userContext['recent_orders'])) {
        $system_prompt .= "\n- Recent Orders: " . count($userContext['recent_orders']) . " orders";
        foreach ($userContext['recent_orders'] as $order) {
            $system_prompt .= "\n  * Order #{$order['order_number']}: \${$order['total']} ({$order['status']})";
        }
    }
} else {
    $system_prompt .= "\n- User: Not logged in (guest)";
}

$system_prompt .= "\n\nKEY URLS:
- Shop: /shop/
- Login: /login.php
- Account Creation: /login.php (has signup form)
- Profit Calculator: /tools/profit-calculator.php
- Sales Reference: /tools/sales-reference.php

RESPONSE GUIDELINES:
- Be conversational and helpful
- Always include specific prices and profit margins when discussing products
- Suggest relevant URLs when appropriate
- For account-related questions, direct to login page
- Focus on business value and profitability
- Keep responses concise but informative
- Use actual data from the database when available

NEVER create forms or complex interactions - just provide information and direct to appropriate pages.";

// Prepare API call to Gemini
$gemini_api_url = "https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash-8b:generateContent?key=" . $gemini_api_key;

// Build conversation history
$apiContents = [];
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
    'contents' => $apiContents,
    'system_instruction' => [
        'parts' => [
            ['text' => $system_prompt]
        ]
    ]
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
