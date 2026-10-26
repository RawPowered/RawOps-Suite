<?php
// ai-chat-v2.php
// Enhanced AI Sales Assistant with improved session management and order placement

// Set headers for JSON response
header('Content-Type: application/json');

// Include base configuration
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/ai-knowledge-retriever.php';
require_once __DIR__ . '/../required/ai-advanced-context.php';

// Start session FIRST before any operations
start_session();

// Debug logging function
function ai_log($message) {
    error_log("[AI Chat] " . $message);
}

// Get the user's message from the POST request
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = $input['message'] ?? '';
$conversationHistory = $input['history'] ?? [];
$action = $input['action'] ?? null;

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Stateful Conversation Management
if (!isset($_SESSION['ai_conversation_state'])) {
    $_SESSION['ai_conversation_state'] = [
        'current_task' => null, 
        'collected_data' => [],
        'session_started' => time()
    ];
}
$conversationState = &$_SESSION['ai_conversation_state'];

// Get comprehensive user information
$userId = current_user_id();
$currentUser = null;
$hasWholesaleAccount = false;
$cartInfo = [];

if ($userId) {
    try {
        // Get full user profile with wholesale account
        $stmt = db()->prepare("
            SELECT u.*, 
                   wa.id as wholesale_id,
                   wa.business_name,
                   wa.approved as wholesale_approved,
                   wa.sales_agent_id
            FROM users u
            LEFT JOIN wholesale_accounts wa ON wa.user_id = u.id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
        $hasWholesaleAccount = !empty($currentUser['wholesale_id']) && $currentUser['wholesale_approved'];
        
        ai_log("User logged in: {$currentUser['first_name']} {$currentUser['last_name']} (ID: $userId)");
        ai_log("Has wholesale account: " . ($hasWholesaleAccount ? 'Yes' : 'No'));
        
    } catch (Throwable $e) {
        ai_log("Error fetching user: " . $e->getMessage());
    }
}

// Get cart information
if (!empty($_SESSION['cart'])) {
    require_once __DIR__ . '/../required/product-data.php';
    foreach ($_SESSION['cart'] as $cartItem) {
        foreach ($products as $p) {
            if (strtolower(str_replace('BLEND-', 'blend-', $p['sku'])) === $cartItem['product_id']) {
                $cartInfo[] = [
                    'name' => $p['name'],
                    'sku' => $p['sku'],
                    'quantity' => $cartItem['quantity'],
                    'price' => $p['sale_price']
                ];
                break;
            }
        }
    }
}

// Handle feedback logging
if ($action === 'log_feedback') {
    $logId = $input['log_id'] ?? null;
    $feedback = $input['feedback'] ?? null;
    if ($logId && in_array($feedback, [1, -1], true)) {
        try {
            $stmt = db()->prepare("UPDATE ai_chat_logs SET feedback = ? WHERE id = ?");
            $stmt->execute([$feedback, $logId]);
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            ai_log("Feedback Update Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to save feedback.']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid feedback data.']);
    }
    exit;
}

// Validate message
if (empty($userMessage)) {
    echo json_encode([
        'success' => false,
        'reply' => "I didn't receive a message.",
        'error' => 'Empty message received.'
    ]);
    exit;
}

// Build comprehensive context
$knowledgeRetriever = new KnowledgeRetriever(db());
$advancedContext = new AdvancedAIContext(db());

// Get product and customer context
$context = $knowledgeRetriever->getContext($userMessage);
$customerContext = $advancedContext->getCustomerContext($userId);

// Build user status description
$userStatus = "GUEST (Not logged in)";
if ($currentUser) {
    $userStatus = "LOGGED IN\n";
    $userStatus .= "- Name: {$currentUser['first_name']} {$currentUser['last_name']}\n";
    $userStatus .= "- Email: {$currentUser['email']}\n";
    $userStatus .= "- User ID: {$currentUser['id']}\n";
    if ($currentUser['business_name']) {
        $userStatus .= "- Business: {$currentUser['business_name']}\n";
    }
    $userStatus .= "- Can Place Orders: " . ($hasWholesaleAccount ? "YES" : "NO - Needs wholesale account") . "\n";
}

// Build cart status
$cartStatus = "Empty";
if (!empty($cartInfo)) {
    $cartTotal = 0;
    $cartStatus = "CART CONTENTS:\n";
    foreach ($cartInfo as $item) {
        $lineTotal = $item['price'] * $item['quantity'];
        $cartTotal += $lineTotal;
        $cartStatus .= "- {$item['name']} (SKU: {$item['sku']}): Qty {$item['quantity']} × \${$item['price']} = \$" . number_format($lineTotal, 2) . "\n";
    }
    $cartStatus .= "Cart Subtotal: \$" . number_format($cartTotal, 2) . "\n";
}

// Build conversation state description
$stateDescription = "No active task.";
if (!empty($conversationState['current_task'])) {
    $task = $conversationState['current_task'];
    $stateDescription = "ACTIVE TASK: {$task}\n";
    
    $requiredFields = [];
    if ($task === 'collecting_account_info') {
        $requiredFields = ['first_name', 'last_name', 'business_name', 'email', 'phone'];
    } elseif ($task === 'collecting_order_info') {
        $requiredFields = ['shipping_address']; // Notes are optional
    }

    $collected = $conversationState['collected_data'] ?? [];
    $stateDescription .= "Collected Data: " . (!empty($collected) ? json_encode($collected) : "None") . "\n";

    $missingFields = array_diff($requiredFields, array_keys($collected));
    if (!empty($missingFields)) {
        $stateDescription .= "STILL NEED: " . implode(', ', $missingFields);
    } else if (!empty($requiredFields)) {
        $stateDescription .= "✓ All required information collected - Ready to complete action!";
    }
}

// Build comprehensive system prompt
$system_prompt = "You are a WORLD-CLASS AI Sales Assistant for Product. You have FULL awareness of the user's session, login status, cart contents, and conversation state.

=== CURRENT SESSION STATUS ===

USER STATUS:
{$userStatus}

SHOPPING CART:
{$cartStatus}

CONVERSATION STATE:
{$stateDescription}

=== YOUR CAPABILITIES ===

You MUST be aware that:
1. If the user is LOGGED IN, greet them by name and acknowledge their business
2. If they are a GUEST, you can help them create an account
3. To place an order, the user MUST be logged in AND have a wholesale account
4. You can see exactly what's in their cart at all times
5. You maintain state between messages in this conversation

=== CRITICAL RULES ===

1. ALWAYS check if user is logged in before trying to place orders
2. If user is not logged in, offer to create an account first
3. If user is logged in but has no wholesale account, they need to create one
4. Only proceed with orders if cart has items AND user has wholesale account
5. When collecting information, ask for ONE field at a time
6. Use the user's name when they're logged in

=== ACTION INSTRUCTIONS ===

Respond with JSON ONLY when performing an action. Otherwise, respond conversationally.

AVAILABLE ACTIONS:

1. Start Account Creation (ONLY for guests):
{
  \"speak\": \"I'd be happy to create an account for you! What's your first name?\",
  \"action\": \"start_task\",
  \"parameters\": {\"task\": \"collecting_account_info\"}
}

2. Update Collected Info (when user provides data):
{
  \"speak\": \"Got it! Now, what's your [next_field]?\",
  \"action\": \"update_collected_info\",
  \"parameters\": {\"field_name\": \"value\"}
}

3. Create Account (when all fields collected):
{
  \"speak\": \"Perfect! Creating your account now...\",
  \"action\": \"create_account\",
  \"parameters\": {}
}

4. Add to Cart (anytime):
{
  \"speak\": \"I've added [product] to your cart!\",
  \"action\": \"add_to_cart\",
  \"parameters\": {\"sku\": \"BLEND-32-20\", \"quantity\": 1}
}

5. Start Order (ONLY if logged in with wholesale account AND cart has items):
{
  \"speak\": \"Great! I can place your order. What's your shipping address?\",
  \"action\": \"start_task\",
  \"parameters\": {\"task\": \"collecting_order_info\"}
}

6. Place Order (when shipping address collected):
{
  \"speak\": \"Placing your order now...\",
  \"action\": \"place_order\",
  \"parameters\": {}
}

7. View Cart:
{
  \"speak\": \"Here's what's in your cart: [list items]\",
  \"action\": \"view_cart\",
  \"parameters\": {}
}

8. Clear Cart:
{
  \"speak\": \"I've cleared your cart.\",
  \"action\": \"clear_cart\",
  \"parameters\": {}
}";

// Add context to prompt
if (!empty($context)) {
    $system_prompt .= "\n\n=== PRODUCT KNOWLEDGE ===\n" . $context;
}

// Add customer history if available
if ($customerContext['profile'] && $customerContext['lifetime_value'] > 0) {
    $system_prompt .= "\n\n=== CUSTOMER HISTORY ===\n";
    $system_prompt .= "Lifetime Value: \$" . number_format($customerContext['lifetime_value'], 2) . "\n";
    $system_prompt .= "Total Orders: {$customerContext['preferences']['total_orders']}\n";
    if (!empty($customerContext['preferences']['favorite_products'])) {
        $system_prompt .= "Favorite Products: ";
        foreach ($customerContext['preferences']['favorite_products'] as $fav) {
            $system_prompt .= "{$fav['product_name']} ";
        }
    }
}

// Prepare API request
$gemini_api_url = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash-8b:generateContent?key=' . $gemini_api_key;

// Build conversation for API
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
    // Call Gemini API
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

    $rawReply = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? 'Sorry, I could not process that.';
    
    // Process response
    $aiReply = $rawReply;
    $actionData = null;
    
    // Check for JSON action response
    $jsonString = $rawReply;
    if (preg_match('/^```json\s*(.*)\s*```$/s', $rawReply, $matches)) {
        $jsonString = $matches[1];
    }
    
    $jsonReply = json_decode($jsonString, true);
    
    // Handle actions
    if ($jsonReply && isset($jsonReply['action']) && isset($jsonReply['speak'])) {
        $aiReply = $jsonReply['speak'];
        $actionData = [
            'action' => $jsonReply['action'],
            'parameters' => $jsonReply['parameters'] ?? []
        ];
        
        ai_log("Action requested: " . $actionData['action']);
        
        // Process actions
        switch ($actionData['action']) {
            case 'start_task':
                $conversationState['current_task'] = $actionData['parameters']['task'];
                $conversationState['collected_data'] = [];
                ai_log("Started task: " . $actionData['parameters']['task']);
                break;
                
            case 'update_collected_info':
                foreach ($actionData['parameters'] as $key => $value) {
                    $conversationState['collected_data'][$key] = $value;
                }
                ai_log("Updated collected info: " . json_encode($actionData['parameters']));
                break;
                
            case 'add_to_cart':
                $sku = $actionData['parameters']['sku'] ?? '';
                $quantity = intval($actionData['parameters']['quantity'] ?? 1);
                
                if ($sku) {
                    $productIdSlug = strtolower(str_replace('BLEND-', 'blend-', $sku));
                    
                    $found = false;
                    foreach ($_SESSION['cart'] as &$item) {
                        if ($item['product_id'] === $productIdSlug) {
                            $item['quantity'] += $quantity;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $_SESSION['cart'][] = ['product_id' => $productIdSlug, 'quantity' => $quantity];
                    }
                    
                    ai_log("Added to cart: $sku x $quantity");
                }
                break;
                
            case 'clear_cart':
                $_SESSION['cart'] = [];
                ai_log("Cart cleared");
                break;
                
            case 'create_account':
                if ($userId) {
                    $aiReply = "You're already logged in! No need to create another account.";
                    $actionData = null;
                } else {
                    $params = $conversationState['collected_data'];
                    $email = trim($params['email'] ?? '');
                    
                    $pdo = db();
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    
                    if ($stmt->fetch()) {
                        $aiReply = "An account with that email already exists. Please log in instead.";
                        $actionData = null;
                    } else {
                        try {
                            $pdo->beginTransaction();
                            
                            // Create user
                            $tempPassword = bin2hex(random_bytes(8));
                            $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                            
                            $stmt = $pdo->prepare(
                                "INSERT INTO users (first_name, last_name, email, phone, password_hash, status) 
                                 VALUES (?, ?, ?, ?, ?, 'active')"
                            );
                            $stmt->execute([
                                $params['first_name'],
                                $params['last_name'],
                                $email,
                                $params['phone'],
                                $passwordHash
                            ]);
                            $newUserId = $pdo->lastInsertId();
                            
                            // Create wholesale account
                            $stmt = $pdo->prepare(
                                "INSERT INTO wholesale_accounts (user_id, business_name, phone, approved) 
                                 VALUES (?, ?, ?, 1)"
                            );
                            $stmt->execute([$newUserId, $params['business_name'], $params['phone']]);
                            
                            // Assign customer role
                            $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 4)");
                            $stmt->execute([$newUserId]);
                            
                            $pdo->commit();
                            
                            // Log the user in
                            $_SESSION['uid'] = $newUserId;
                            
                            // Clear task
                            $conversationState['current_task'] = null;
                            $conversationState['collected_data'] = [];
                            
                            $aiReply = "Great news! Your account has been created and you're now logged in. ";
                            $aiReply .= "Your temporary password is: {$tempPassword} - Please save this! ";
                            $aiReply .= "You can now place orders. Would you like to add some products to your cart?";
                            
                            ai_log("Account created for: $email");
                            
                        } catch (Throwable $e) {
                            $pdo->rollBack();
                            ai_log("Account creation error: " . $e->getMessage());
                            $aiReply = "I'm sorry, there was an error creating your account. Please try again.";
                            $actionData = null;
                        }
                    }
                }
                break;
                
            case 'place_order':
                $userId = current_user_id();
                
                if (!$userId) {
                    $aiReply = "You need to be logged in to place an order. Would you like to create an account?";
                    $actionData = null;
                } elseif (!$hasWholesaleAccount) {
                    $aiReply = "You need a wholesale account to place orders. Let me help you set that up.";
                    $actionData = null;
                } elseif (empty($_SESSION['cart'])) {
                    $aiReply = "Your cart is empty! What products would you like to add?";
                    $actionData = null;
                } else {
                    try {
                        $pdo = db();
                        $pdo->beginTransaction();
                        
                        // Get order details
                        $params = $conversationState['collected_data'];
                        $shippingAddress = $params['shipping_address'] ?? 'Not provided';
                        $orderNotes = $params['notes'] ?? '';
                        
                        // Get wholesale account ID
                        $stmt = $pdo->prepare('
                            SELECT wa.id, wa.sales_agent_id 
                            FROM wholesale_accounts wa 
                            WHERE wa.user_id = ? AND wa.approved = 1
                        ');
                        $stmt->execute([$userId]);
                        $account = $stmt->fetch();
                        
                        if (!$account) {
                            throw new Exception("No approved wholesale account found");
                        }
                        
                        $wholesaleAccountId = $account['id'];
                        $salesAgentId = $account['sales_agent_id'];
                        
                        // Calculate totals
                        require_once __DIR__ . '/../required/product-data.php';
                        $subtotal = 0;
                        $orderItems = [];
                        
                        foreach ($_SESSION['cart'] as $cartItem) {
                            foreach ($products as $p) {
                                if (strtolower(str_replace('BLEND-', 'blend-', $p['sku'])) === $cartItem['product_id']) {
                                    $quantity = intval($cartItem['quantity']);
                                    $lineTotal = $p['sale_price'] * $quantity;
                                    $subtotal += $lineTotal;
                                    
                                    $orderItems[] = [
                                        'product_name' => $p['name'],
                                        'product_sku' => $p['sku'],
                                        'quantity' => $quantity,
                                        'unit_price' => $p['sale_price'],
                                        'line_total' => $lineTotal
                                    ];
                                    break;
                                }
                            }
                        }
                        
                        $shipping = $subtotal >= 500 ? 0 : 25;
                        $tax = $subtotal * 0.08;
                        $total = $subtotal + $shipping + $tax;
                        
                        // Create order
                        $orderNumber = 'AI-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        
                        $fullNotes = "AI Assistant Order\n";
                        $fullNotes .= "Shipping: $shippingAddress\n";
                        if ($orderNotes) {
                            $fullNotes .= "Notes: $orderNotes";
                        }
                        
                        $stmt = $pdo->prepare('
                            INSERT INTO orders (order_number, user_id, wholesale_account_id, sales_agent_id, 
                                              subtotal, shipping, tax, total, status, payment_status, notes) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending", "pending", ?)
                        ');
                        $stmt->execute([
                            $orderNumber, $userId, $wholesaleAccountId, $salesAgentId,
                            $subtotal, $shipping, $tax, $total, $fullNotes
                        ]);
                        $orderId = $pdo->lastInsertId();
                        
                        // Insert order items
                        $itemStmt = $pdo->prepare('
                            INSERT INTO order_items (order_id, product_id, product_name, product_sku, 
                                                   quantity, unit_price, line_total) 
                            VALUES (?, (SELECT id FROM products WHERE sku = ?), ?, ?, ?, ?, ?)
                        ');
                        
                        foreach ($orderItems as $item) {
                            $itemStmt->execute([
                                $orderId, 
                                $item['product_sku'],
                                $item['product_name'],
                                $item['product_sku'],
                                $item['quantity'],
                                $item['unit_price'],
                                $item['line_total']
                            ]);
                        }
                        
                        $pdo->commit();
                        
                        // Clear cart and state
                        $_SESSION['cart'] = [];
                        $conversationState['current_task'] = null;
                        $conversationState['collected_data'] = [];
                        
                        $aiReply = "🎉 Excellent! Your order #{$orderNumber} has been placed successfully!\n\n";
                        $aiReply .= "Order Total: $" . number_format($total, 2) . "\n";
                        $aiReply .= "Shipping to: {$shippingAddress}\n\n";
                        $aiReply .= "You'll receive an email confirmation shortly. Thank you for your business!";
                        
                        ai_log("Order placed: $orderNumber for user $userId");
                        
                    } catch (Throwable $e) {
                        if (isset($pdo)) {
                            $pdo->rollBack();
                        }
                        ai_log("Order placement error: " . $e->getMessage());
                        $aiReply = "I'm sorry, there was an error placing your order: " . $e->getMessage();
                        $actionData = null;
                    }
                }
                break;
        }
    }
    
    // Log conversation
    try {
        $stmt = db()->prepare(
            "INSERT INTO ai_chat_logs (session_id, user_id, ip_address, user_agent, 
                                      user_message, ai_response, prompt_context, 
                                      action_taken, action_parameters) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            session_id(),
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $userMessage,
            $aiReply,
            substr($context ?? '', 0, 1000), // Limit context size
            $actionData['action'] ?? null,
            isset($actionData['parameters']) ? json_encode($actionData['parameters']) : null
        ]);
        $logId = db()->lastInsertId();
    } catch (Throwable $e) {
        ai_log("Logging error: " . $e->getMessage());
        $logId = null;
    }
    
    // Return response
    echo json_encode([
        'success' => true,
        'reply' => $aiReply,
        'actionData' => $actionData,
        'logId' => $logId,
        'debug' => [
            'user_logged_in' => $userId !== null,
            'has_wholesale' => $hasWholesaleAccount,
            'cart_items' => count($_SESSION['cart'] ?? [])
        ]
    ]);
    
} catch (Exception $e) {
    ai_log("API Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'reply' => 'I apologize, but I encountered an error. Please try again.',
        'error' => $e->getMessage()
    ]);
}
?>
