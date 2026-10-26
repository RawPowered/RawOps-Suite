<?php
/**
 * Beta Sample Request API
 * 
 * Handles public beta sample requests from retail customers
 * - One free sample per user (Product A OR Product B)
 * - Requires legal waiver acceptance
 * - Auto-approved for operations fulfillment
 * - SendGrid email notifications
 */

require_once __DIR__ . '/../required/config.php';

header('Content-Type: application/json');

try {
    start_session();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Session initialization failed']);
    exit;
}

$pdo = db();
$userId = current_user_id();

// Require authentication
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'You must be logged in to request a beta sample']);
    exit;
}

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get input
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    // ============================================
    // 1. VALIDATE USER IS RETAIL CUSTOMER
    // ============================================
    
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ? AND r.name = "retail_customer"
    ');
    $stmt->execute([$userId]);
    $isRetailCustomer = (bool)$stmt->fetchColumn();
    
    if (!$isRetailCustomer) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Beta samples are only available to retail customers']);
        exit;
    }
    
    // ============================================
    // 2. CHECK IF USER ALREADY REQUESTED
    // ============================================
    
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM sample_requests
        WHERE agent_id = ? AND is_beta_sample = 1
    ');
    $stmt->execute([$userId]);
    $existingRequests = (int)$stmt->fetchColumn();
    
    if ($existingRequests > 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'You have already requested a beta sample. Limit: one per customer.']);
        exit;
    }
    
    // ============================================
    // 3. VALIDATE INPUT
    // ============================================
    
    $productChoice = trim($input['product_choice'] ?? '');
    $recipientName = trim($input['recipient_name'] ?? '');
    $addressLine1 = trim($input['address_line1'] ?? '');
    $addressLine2 = trim($input['address_line2'] ?? '');
    $city = trim($input['city'] ?? '');
    $state = trim($input['state'] ?? '');
    $zip = trim($input['zip'] ?? '');
    $country = trim($input['country'] ?? 'USA');
    $phone = trim($input['phone'] ?? '');
    $agreeWaiver = !empty($input['agree_waiver']);
    
    // Validate required fields
    if (!$productChoice || !in_array($productChoice, ['Product A', 'Product B'])) {
        throw new Exception('Please select a product (Product A or Product B)');
    }
    
    if (!$recipientName) {
        throw new Exception('Recipient name is required');
    }
    
    if (!$addressLine1 || !$city || !$state || !$zip) {
        throw new Exception('Complete shipping address is required');
    }
    
    if (!$agreeWaiver) {
        throw new Exception('You must agree to the beta sample waiver to continue');
    }
    
    // ============================================
    // 4. GET SAMPLE PRODUCT ID
    // ============================================
    
    $productSku = $productChoice === 'Product A' ? 'BETA-Product A-product-pack' : 'BETA-Product B-product-pack';
    
    $stmt = $pdo->prepare('SELECT id, name FROM products WHERE sku = ? AND is_sample = 1 AND active = 1 LIMIT 1');
    $stmt->execute([$productSku]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        throw new Exception('Selected product is not available at this time');
    }
    
    $sampleProductId = (int)$product['id'];
    $productName = $product['name'];
    
    // ============================================
    // 5. CREATE BETA SAMPLE REQUEST
    // ============================================
    
    $pdo->beginTransaction();
    
    try {
        // Insert sample request
        $stmt = $pdo->prepare('
            INSERT INTO sample_requests (
                agent_id,
                lead_id,
                account_id,
                sample_product_id,
                request_type,
                quantity,
                recipient_name,
                recipient_address_line1,
                recipient_address_line2,
                recipient_city,
                recipient_state,
                recipient_zip,
                recipient_country,
                recipient_phone,
                agent_notes,
                status,
                is_beta_sample,
                beta_waiver_signed_at,
                beta_waiver_ip,
                requested_at
            ) VALUES (
                ?, NULL, NULL, ?, \'shipping\', 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', 1, NOW(), ?, NOW()
            )
        ');
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $agentNotes = 'Public beta sample request - ' . $productName;
        
        $stmt->execute([
            $userId,
            $sampleProductId,
            $recipientName,
            $addressLine1,
            $addressLine2 ?: null,
            $city,
            $state,
            $zip,
            $country,
            $phone ?: null,
            $agentNotes,
            $ipAddress
        ]);
        
        $requestId = (int)$pdo->lastInsertId();
        
        // ============================================
        // 6. LOG LEGAL AGREEMENT
        // ============================================
        
        // Get agreement template
        $stmt = $pdo->prepare('
            SELECT id, version FROM agreement_templates 
            WHERE agreement_type = \'beta_sample_waiver\' AND is_active = 1 
            LIMIT 1
        ');
        $stmt->execute();
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($template) {
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            
            $stmt = $pdo->prepare('
                INSERT INTO user_agreements (
                    user_id,
                    agreement_template_id,
                    signature_type,
                    signature_data,
                    ip_address,
                    user_agent,
                    agreement_version,
                    created_at
                ) VALUES (?, ?, \'checkbox\', ?, ?, ?, ?, NOW())
            ');
            
            $signatureData = json_encode([
                'sample_request_id' => $requestId,
                'product_choice' => $productChoice,
                'agreed_at' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([
                $userId,
                $template['id'],
                $signatureData,
                $ipAddress,
                $userAgent,
                $template['version']
            ]);
        }
        
        $pdo->commit();
        
        // ============================================
        // 7. SEND EMAIL NOTIFICATION
        // ============================================
        
        // Get user email
        $stmt = $pdo->prepare('SELECT email, first_name, last_name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            try {
                // Send confirmation email via SendGrid
                require_once __DIR__ . '/../required/sendgrid-helpers.php';
                
                $emailData = [
                    'to' => $user['email'],
                    'name' => trim($user['first_name'] . ' ' . $user['last_name']),
                    'subject' => 'Beta Sample Request Received - Product',
                    'product_name' => $productName,
                    'request_id' => $requestId,
                    'shipping_address' => implode(', ', array_filter([
                        $addressLine1,
                        $addressLine2,
                        $city,
                        $state,
                        $zip
                    ]))
                ];
                
                send_beta_sample_email('request_received', $emailData);
            } catch (Throwable $e) {
                // Log error but don't fail the request
                error_log('Beta sample email failed: ' . $e->getMessage());
            }
        }
        
        // ============================================
        // 8. RETURN SUCCESS
        // ============================================
        
        echo json_encode([
            'ok' => true,
            'message' => 'Beta sample request submitted successfully! You will receive an email when your sample ships.',
            'request_id' => $requestId,
            'product_name' => $productName
        ]);
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}



