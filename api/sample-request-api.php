<?php
/**
 * Sample Request API
 * 
 * CRUD operations for sample requests with inventory management
 * 
 * Endpoints:
 * - action=create: Create new sample request
 * - action=list: List sample requests with filters
 * - action=get: Get single request details
 * - action=approve: Admin approves request
 * - action=reject: Admin rejects request
 * - action=mark_shipped: Mark request as shipped
 * - action=mark_delivered: Mark request as delivered
 * - action=resend_tracking: Resend tracking information email
 * - action=get_inventory: Get current inventory levels
 * - action=restock: Restock sample inventory
 * - action=get_shipping_quote: Get shipping quotes for request
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/sample-inventory-helpers.php';
require_once __DIR__ . '/../required/sample-email-triggers.php';
require_once __DIR__ . '/../required/sample-suite-helpers.php';

header('Content-Type: application/json');

try {
    start_session();
} catch (Throwable $e) {
    // Continue without session
}

$pdo = db();
$userId = current_user_id();

// Require authentication
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * Check if user is admin
 */
function is_admin_user(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('
        SELECT 1 FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ? AND r.name IN ("admin", "accountant")
        LIMIT 1
    ');
    $stmt->execute([$userId]);
    return (bool)$stmt->fetch();
}

// ============================================
// ACTION: Create Sample Request
// ============================================

if ($action === 'create') {
    try {
        // Ensure no active transaction before starting
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            // Ignore if not in transaction
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        
        // Validate required fields
        $sampleProductId = intval($input['sample_product_id'] ?? 0);
        $requestType = trim($input['request_type'] ?? 'shipping');
        $quantity = intval($input['quantity'] ?? 1);
        $recipientName = trim($input['recipient_name'] ?? '');
        
        if (!$sampleProductId || !$recipientName) {
            throw new Exception('Missing required fields: sample_product_id, recipient_name');
        }
        
        if (!in_array($requestType, ['shipping', 'drop_off'])) {
            throw new Exception('Invalid request_type. Must be: shipping or drop_off');
        }
        
        if ($quantity < 1 || $quantity > 5) {
            throw new Exception('Quantity must be between 1 and 5');
        }
        
        // Verify product is a sample product
        $stmt = $pdo->prepare('SELECT id, sku, name, is_sample FROM products WHERE id = ? AND is_sample = 1 LIMIT 1');
        $stmt->execute([$sampleProductId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            throw new Exception('Invalid sample product');
        }
        
        // Check inventory availability
        if (!check_sample_availability($pdo, $sampleProductId, $quantity)) {
            throw new Exception('Insufficient inventory available');
        }
        
        // Extract address fields
        $leadId = !empty($input['lead_id']) ? intval($input['lead_id']) : null;
        $accountId = !empty($input['account_id']) ? intval($input['account_id']) : null;
        $addressLine1 = trim($input['address_line1'] ?? '');
        $addressLine2 = trim($input['address_line2'] ?? '');
        $city = trim($input['city'] ?? '');
        $state = trim($input['state'] ?? '');
        $zip = trim($input['zip'] ?? '');
        $country = trim($input['country'] ?? 'USA');
        $phone = trim($input['phone'] ?? '');
        $agentNotes = trim($input['agent_notes'] ?? '');
        
        // For shipping requests, address is required
        if ($requestType === 'shipping' && (!$addressLine1 || !$city || !$state || !$zip)) {
            throw new Exception('Complete address required for shipping requests');
        }
        
        // Get payment options
        $chargeAgentShipping = !empty($input['charge_agent_shipping']) ? 1 : 0;
        $invoiceCustomer = !empty($input['invoice_customer']) ? 1 : 0;
        
        // Drop-off requests are auto-approved (agent has product in hand)
        $initialStatus = ($requestType === 'drop_off') ? 'approved' : 'pending';
        $approvedAt = ($requestType === 'drop_off') ? date('Y-m-d H:i:s') : null;
        $approvedBy = ($requestType === 'drop_off') ? $userId : null;
        
        // Begin transaction
        $pdo->beginTransaction();
        
        try {
            // Create sample request
            $stmt = $pdo->prepare('
                INSERT INTO sample_requests (
                    agent_id, lead_id, account_id, sample_product_id, request_type,
                    quantity, recipient_name, recipient_address_line1, recipient_address_line2,
                    recipient_city, recipient_state, recipient_zip, recipient_country,
                    recipient_phone, agent_notes,
                    status, approved_at, approved_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                $userId,
                $leadId,
                $accountId,
                $sampleProductId,
                $requestType,
                $quantity,
                $recipientName,
                $addressLine1 ?: null,
                $addressLine2 ?: null,
                $city ?: null,
                $state ?: null,
                $zip ?: null,
                $country,
                $phone ?: null,
                $agentNotes ?: null,
                $initialStatus,
                $approvedAt,
                $approvedBy
            ]);
            
            $requestId = (int)$pdo->lastInsertId();
            
            // Reserve inventory
            reserve_sample_inventory($pdo, $sampleProductId, $quantity, $requestId, $userId);
            
            // For drop-off requests, create automatic follow-up task
            if ($requestType === 'drop_off' && $leadId) {
                $followUpDate = date('Y-m-d', strtotime('+3 days'));
                $taskStmt = $pdo->prepare('
                    INSERT INTO crm_tasks (
                        agent_id, lead_id, account_id, sample_request_id,
                        task_type, title, description,
                        priority, due_date, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $taskStmt->execute([
                    $userId,
                    $leadId,
                    $accountId,
                    $requestId,
                    'follow_up',
                    'Follow up on sample drop-off',
                    'Check in with customer about the sample dropped off on ' . date('M j, Y'),
                    'high',
                    $followUpDate,
                    'pending'
                ]);
            }
            
            $pdo->commit();
            
            $successMessage = ($requestType === 'drop_off') 
                ? 'Drop-off recorded successfully! Follow-up task created.'
                : 'Sample request created successfully. Awaiting admin approval.';
            
            echo json_encode([
                'ok' => true,
                'request_id' => $requestId,
                'message' => $successMessage
            ]);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        
    } catch (Throwable $e) {
        // Ensure transaction is closed
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $rollbackError) {
            // Ignore rollback errors
        }
        
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// ACTION: List Sample Requests
// ============================================

if ($action === 'list') {
    try {
        $isAdmin = is_admin_user($pdo, $userId);
        
        // Build query with filters
        $where = ['1=1'];
        $params = [];
        
        // Non-admins can only see their own requests
        if (!$isAdmin) {
            $where[] = 'sr.agent_id = ?';
            $params[] = $userId;
        }
        
        // Filter by status
        if (!empty($_GET['status'])) {
            $where[] = 'sr.status = ?';
            $params[] = $_GET['status'];
        }
        
        // Filter by request type
        if (!empty($_GET['request_type'])) {
            $where[] = 'sr.request_type = ?';
            $params[] = $_GET['request_type'];
        }
        
        // Filter by date range
        if (!empty($_GET['date_from'])) {
            $where[] = 'sr.requested_at >= ?';
            $params[] = $_GET['date_from'] . ' 00:00:00';
        }
        if (!empty($_GET['date_to'])) {
            $where[] = 'sr.requested_at <= ?';
            $params[] = $_GET['date_to'] . ' 23:59:59';
        }
        
        $whereClause = implode(' AND ', $where);
        
        $stmt = $pdo->prepare("
            SELECT 
                sr.*,
                p.sku AS product_sku,
                p.name AS product_name,
                CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
                u.email AS agent_email,
                CONCAT(approver.first_name, ' ', approver.last_name) AS approved_by_name,
                l.company_name AS lead_company_name,
                wa.business_name AS account_business_name
            FROM sample_requests sr
            JOIN products p ON p.id = sr.sample_product_id
            JOIN users u ON u.id = sr.agent_id
            LEFT JOIN users approver ON approver.id = sr.approved_by
            LEFT JOIN crm_leads l ON l.id = sr.lead_id
            LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
            WHERE $whereClause
            ORDER BY sr.requested_at DESC
            LIMIT 100
        ");
        
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'ok' => true,
            'requests' => $requests,
            'count' => count($requests)
        ]);
        
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// ACTION: Get Single Request
// ============================================

if ($action === 'get') {
    try {
        $requestId = intval($_GET['id'] ?? 0);
        
        if (!$requestId) {
            throw new Exception('Missing request ID');
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                sr.*,
                p.sku AS product_sku,
                p.name AS product_name,
                p.total_production_cost,
                CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
                u.email AS agent_email,
                CONCAT(approver.first_name, ' ', approver.last_name) AS approved_by_name,
                l.company_name AS lead_company_name,
                l.email AS lead_email,
                wa.business_name AS account_business_name
            FROM sample_requests sr
            JOIN products p ON p.id = sr.sample_product_id
            JOIN users u ON u.id = sr.agent_id
            LEFT JOIN users approver ON approver.id = sr.approved_by
            LEFT JOIN crm_leads l ON l.id = sr.lead_id
            LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
            WHERE sr.id = ?
            LIMIT 1
        ");
        
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        // Check permissions: admins or request owner
        $isAdmin = is_admin_user($pdo, $userId);
        if (!$isAdmin && (int)$request['agent_id'] !== $userId) {
            throw new Exception('Access denied');
        }
        
        // Get shipping quotes if available
        $stmt = $pdo->prepare('
            SELECT * FROM sample_shipping_quotes
            WHERE sample_request_id = ?
            ORDER BY cost ASC
        ');
        $stmt->execute([$requestId]);
        $request['shipping_quotes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'ok' => true,
            'request' => $request
        ]);
        
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// ACTION: Approve Request (Admin Only)
// ============================================

if ($action === 'approve') {
    try {
        if (!is_admin_user($pdo, $userId)) {
            throw new Exception('Admin access required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $requestId = intval($input['request_id'] ?? 0);
        $adminNotes = trim($input['admin_notes'] ?? '');
        
        if (!$requestId) {
            throw new Exception('Missing request ID');
        }
        
        // Get request details
        $stmt = $pdo->prepare('SELECT * FROM sample_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        if ($request['status'] !== 'pending') {
            throw new Exception('Only pending requests can be approved');
        }
        
        // Update request status
        $stmt = $pdo->prepare('
            UPDATE sample_requests
            SET status = "approved",
                approved_at = NOW(),
                approved_by = ?,
                admin_notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$userId, $adminNotes ?: null, $requestId]);
        
        // Trigger email workflow: sample_approved
        trigger_sample_email_workflow($pdo, 'sample_approved', $requestId);
        
        // Create sample shipment record (ready for operations to ship)
        create_sample_shipment_placeholder($pdo, $requestId, $userId);
        
        echo json_encode([
            'ok' => true,
            'message' => 'Sample request approved successfully. Customer has been notified.'
        ]);
        
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// ACTION: Reject Request (Admin Only)
// ============================================

if ($action === 'reject') {
    try {
        if (!is_admin_user($pdo, $userId)) {
            throw new Exception('Admin access required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $requestId = intval($input['request_id'] ?? 0);
        $adminNotes = trim($input['admin_notes'] ?? '');
        
        if (!$requestId) {
            throw new Exception('Missing request ID');
        }
        
        // Get request details
        $stmt = $pdo->prepare('SELECT * FROM sample_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        if (!in_array($request['status'], ['pending', 'approved'])) {
            throw new Exception('Cannot reject request with current status');
        }
        
        $pdo->beginTransaction();
        
        try {
            // Update request status
            $stmt = $pdo->prepare('
                UPDATE sample_requests
                SET status = "rejected",
                    rejected_at = NOW(),
                    admin_notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$adminNotes ?: null, $requestId]);
            
            // Release reserved inventory
            release_sample_inventory(
                $pdo,
                (int)$request['sample_product_id'],
                (int)$request['quantity'],
                $requestId,
                $userId
            );
            
            $pdo->commit();
            
            echo json_encode([
                'ok' => true,
                'message' => 'Sample request rejected and inventory released'
            ]);
            
        } catch (Exception $e) {
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
    exit;
}

// ============================================
// ACTION: Mark as Shipped (Admin Only)
// ============================================

if ($action === 'mark_shipped') {
    try {
        if (!is_admin_user($pdo, $userId)) {
            throw new Exception('Admin access required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $requestId = intval($input['request_id'] ?? 0);
        $trackingNumber = trim($input['tracking_number'] ?? '');
        $shippingCost = !empty($input['shipping_cost']) ? floatval($input['shipping_cost']) : null;
        $carrier = trim($input['carrier'] ?? '');
        $service = trim($input['service'] ?? '');
        
        if (!$requestId) {
            throw new Exception('Missing request ID');
        }
        
        // Get request details
        $stmt = $pdo->prepare('SELECT * FROM sample_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        if ($request['status'] !== 'approved') {
            throw new Exception('Only approved requests can be marked as shipped');
        }
        
        $pdo->beginTransaction();
        
        try {
            // Update request
            $stmt = $pdo->prepare('
                UPDATE sample_requests
                SET status = "shipped",
                    shipped_at = NOW(),
                    tracking_number = ?,
                    shipping_cost = ?,
                    shipping_carrier = ?,
                    shipping_service = ?,
                    updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([
                $trackingNumber ?: null,
                $shippingCost,
                $carrier ?: null,
                $service ?: null,
                $requestId
            ]);
            
            // Deduct from reserved inventory
            deduct_sample_inventory(
                $pdo,
                (int)$request['sample_product_id'],
                (int)$request['quantity'],
                $requestId,
                $userId
            );
            
            // Create expense and journal entry for shipping cost (if applicable)
            if ($request['request_type'] === 'shipping' && $shippingCost > 0) {
                // Load expense automation helpers
                if (is_file(__DIR__ . '/../required/expense-automation.php')) {
                    require_once __DIR__ . '/../required/expense-automation.php';
                    
                    if (function_exists('create_sample_shipping_expense')) {
                        $expenseId = create_sample_shipping_expense(
                            $requestId,
                            $shippingCost,
                            $carrier ?: 'Carrier',
                            $trackingNumber ?: ''
                        );
                        
                        // Update request with expense reference
                        if ($expenseId) {
                            $stmt = $pdo->prepare('UPDATE sample_requests SET expense_id = ? WHERE id = ?');
                            $stmt->execute([$expenseId, $requestId]);
                        }
                    }
                }
            }
            
            // Create/update sample shipment record with tracking
            create_or_update_sample_shipment(
                $pdo, 
                $requestId, 
                $carrier, 
                $service,
                $trackingNumber, 
                $shippingCost, 
                $userId
            );
            
            // Send shipped email notifications to customer and agent
            send_sample_email_notification($pdo, 'shipped', $requestId);
            
            // Log CRM activity
            if ($request['lead_id']) {
                log_sample_crm_activity(
                    $pdo,
                    $request['lead_id'],
                    'sample_shipped',
                    "Sample shipped via {$carrier} - Tracking: {$trackingNumber}",
                    [
                        'sample_request_id' => $requestId,
                        'tracking_number' => $trackingNumber,
                        'carrier' => $carrier
                    ]
                );
            }
            
            $pdo->commit();
            
            sample_suite_handle_event($pdo, 'shipped', $requestId);
            
            echo json_encode([
                'ok' => true,
                'message' => 'Sample marked as shipped successfully. Emails sent to customer and agent.'
            ]);
            
        } catch (Exception $e) {
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
    exit;
}

// ============================================
// ACTION: Get Inventory Levels
// ============================================

if ($action === 'get_inventory') {
    try {
        $inventory = get_all_sample_inventory($pdo);
        
        echo json_encode([
            'ok' => true,
            'inventory' => $inventory
        ]);
        
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// ACTION: Restock Inventory (Admin Only)
// ============================================

if ($action === 'restock') {
    try {
        if (!is_admin_user($pdo, $userId)) {
            throw new Exception('Admin access required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $productId = intval($input['product_id'] ?? 0);
        $quantity = intval($input['quantity'] ?? 0);
        $reason = trim($input['reason'] ?? '');
        
        if (!$productId || $quantity < 1) {
            throw new Exception('Invalid product ID or quantity');
        }
        
        if (!$reason) {
            throw new Exception('Reason is required for restocking');
        }
        
        // Verify product is a sample product
        $stmt = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_sample = 1 LIMIT 1');
        $stmt->execute([$productId]);
        if (!$stmt->fetch()) {
            throw new Exception('Invalid sample product');
        }
        
        // Restock inventory
        restock_sample_inventory($pdo, $productId, $quantity, $reason, $userId);
        
        echo json_encode([
            'ok' => true,
            'message' => 'Inventory restocked successfully'
        ]);
        
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// ACTION: Mark as Delivered
// ============================================

if ($action === 'mark_delivered') {
    try {
        if (!is_admin_user($pdo, $userId)) {
            throw new Exception('Admin access required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $requestId = intval($input['request_id'] ?? 0);
        
        if (!$requestId) {
            throw new Exception('Missing request ID');
        }
        
        // Get request details
        $stmt = $pdo->prepare('SELECT * FROM sample_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        if ($request['status'] !== 'shipped') {
            throw new Exception('Only shipped requests can be marked as delivered');
        }
        
        $pdo->beginTransaction();
        
        try {
            // Update request
            $stmt = $pdo->prepare('
                UPDATE sample_requests
                SET status = "delivered",
                    delivered_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$requestId]);
            
            // Update shipment record
            $stmt = $pdo->prepare('
                UPDATE sample_shipments
                SET actual_delivery_date = NOW(),
                    delivery_status = "delivered"
                WHERE sample_request_id = ?
            ');
            $stmt->execute([$requestId]);
            
            // Send delivered email notification
            send_sample_email_notification($pdo, 'delivered', $requestId);
            
            // Log CRM activity
            if ($request['lead_id']) {
                log_sample_crm_activity(
                    $pdo,
                    $request['lead_id'],
                    'sample_delivered',
                    'Sample delivered to customer',
                    ['sample_request_id' => $requestId]
                );
            }
            
            // Create CRM task for agent follow-up (3 days from now)
            create_sample_followup_task($pdo, $requestId);
            
            $pdo->commit();
            
            sample_suite_handle_event($pdo, 'delivered', $requestId, [
                'delivered_at' => date('Y-m-d H:i:s')
            ]);
            
            echo json_encode([
                'ok' => true,
                'message' => 'Sample marked as delivered. Customer notified and agent task created.'
            ]);
            
        } catch (Exception $e) {
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
    exit;
}

// ============================================
// ACTION: Resend Tracking Info (Admin Only)
// ============================================

if ($action === 'resend_tracking') {
    try {
        if (!is_admin_user($pdo, $userId)) {
            throw new Exception('Admin access required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $requestId = intval($input['request_id'] ?? 0);
        
        if (!$requestId) {
            throw new Exception('Missing request ID');
        }
        
        // Get request details
        $stmt = $pdo->prepare('
            SELECT sr.*, p.name as product_name,
                   CONCAT(u.first_name, " ", u.last_name) as agent_name,
                   u.email as agent_email, u.phone as agent_phone,
                   l.email as lead_email, l.company_name as lead_company_name
            FROM sample_requests sr
            JOIN products p ON p.id = sr.sample_product_id
            JOIN users u ON u.id = sr.agent_id
            LEFT JOIN crm_leads l ON l.id = sr.lead_id
            WHERE sr.id = ? LIMIT 1
        ');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        if ($request['status'] !== 'shipped' && $request['status'] !== 'delivered') {
            throw new Exception('Can only resend tracking for shipped/delivered samples');
        }
        
        if (empty($request['tracking_number'])) {
            throw new Exception('No tracking number available for this shipment');
        }
        
        // Get recipient email
        $recipientEmail = $request['lead_email'] ?: null;
        if (!$recipientEmail || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('No valid email address found for recipient');
        }
        
        // Get shipment details for delivery estimation
        $stmt = $pdo->prepare('SELECT * FROM sample_shipments WHERE sample_request_id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Prepare tracking variables
        $trackingUrl = generate_tracking_url($request['shipping_carrier'], $request['tracking_number']);
        
        // Build tracking info HTML block
        $trackingInfoHtml = '<p style="margin: 0 0 8px; color: #065f46;"><strong>Tracking Number:</strong> ' . htmlspecialchars($request['tracking_number']) . '</p>';
        $trackingInfoHtml .= '<p style="margin: 0; color: #065f46;"><a href="' . htmlspecialchars($trackingUrl) . '" style="color: #059669; text-decoration: underline;">Track Your Package</a></p>';
        
        // Calculate delivery days if we have shipment info
        $deliveryDays = '';
        $deliveryInfo = 'Your premium Product sample is on its way. ';
        
        if ($shipment && !empty($request['shipping_carrier']) && !empty($request['shipping_service'])) {
            $days = calculate_delivery_days($request['shipping_carrier'], $request['shipping_service']);
            $deliveryDays = $days;
            $deliveryInfo = 'Your premium Product sample will arrive within ' . $days . ' business days. ';
        }
        
        $variables = [
            'recipient_name' => $request['recipient_name'],
            'product_name' => $request['product_name'],
            'agent_name' => $request['agent_name'],
            'agent_email' => $request['agent_email'],
            'agent_phone' => $request['agent_phone'] ?: 'N/A',
            'company_name' => $request['lead_company_name'] ?: $request['recipient_name'],
            'tracking_number' => $request['tracking_number'],
            'carrier' => $request['shipping_carrier'] ?: 'USPS',
            'service_type' => $request['shipping_service'] ?: 'Ground',
            'tracking_url' => $trackingUrl,
            'tracking_info' => $trackingInfoHtml,
            'delivery_days' => $deliveryDays,
            'delivery_info' => $deliveryInfo,
            'base_url' => rtrim(site_base_url(), '/'),
            'tracking_pixel' => ''
        ];
        
        // Load SendGrid functions
        require_once __DIR__ . '/../api/sendgrid.php';
        
        // Get template
        $stmt = $pdo->prepare('SELECT subject, html_body, text_body FROM email_templates WHERE slug = "sample_approved_customer" AND is_active = 1 LIMIT 1');
        $stmt->execute();
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            throw new Exception('Email template not found');
        }
        
        // Replace template variables
        $subject = $template['subject'];
        $htmlBody = $template['html_body'];
        $textBody = $template['text_body'] ?? strip_tags($htmlBody);
        
        foreach ($variables as $key => $value) {
            $subject = str_replace('{' . $key . '}', $value, $subject);
            $htmlBody = str_replace('{' . $key . '}', $value, $htmlBody);
            $textBody = str_replace('{' . $key . '}', $value, $textBody);
        }
        
        // Prepend "RESENT:" to subject
        $subject = 'RESENT: ' . $subject;
        
        // Send email
        $sendResult = sendgrid_send([
            'to' => $recipientEmail,
            'to_name' => $variables['recipient_name'],
            'from' => 'sample-006@example.com',
            'from_name' => 'Product',
            'subject' => $subject,
            'html' => $htmlBody,
            'text' => $textBody
        ]);
        
        if (!$sendResult['ok']) {
            throw new Exception('Failed to send email: ' . ($sendResult['error'] ?? 'Unknown error'));
        }
        
        // Log resend event
        $messageId = 'resend-sample-' . $requestId . '-' . time();
        $stmt = $pdo->prepare('
            INSERT INTO sample_email_events (
                sample_request_id, event_type, template_slug, recipient_email,
                recipient_type, email_sent, sent_at, sendgrid_message_id, metadata
            ) VALUES (?, "tracking_resent", "sample_approved_customer", ?, "customer", 1, NOW(), ?, ?)
        ');
        $stmt->execute([
            $requestId,
            $recipientEmail,
            $messageId,
            json_encode(['resent_by_admin' => $userId, 'tracking_number' => $variables['tracking_number']])
        ]);
        
        echo json_encode([
            'ok' => true,
            'message' => 'Tracking information resent successfully to ' . $recipientEmail
        ]);
        
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Trigger email workflow for sample request events
 */
function trigger_sample_email_workflow(PDO $pdo, string $event, int $requestId): void {
    try {
        // Get full request details with agent and product info
        $stmt = $pdo->prepare('
            SELECT 
                sr.*,
                p.name as product_name,
                p.sku as product_sku,
                CONCAT(agent.first_name, " ", agent.last_name) as agent_name,
                agent.email as agent_email,
                agent.phone as agent_phone,
                l.company_name as lead_company_name,
                l.email as lead_email,
                wa.business_name as account_business_name
            FROM sample_requests sr
            JOIN products p ON p.id = sr.sample_product_id
            JOIN users agent ON agent.id = sr.agent_id
            LEFT JOIN crm_leads l ON l.id = sr.lead_id
            LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
            WHERE sr.id = ?
        ');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            return;
        }
        
        // Get shipment details if available
        $shipment = null;
        if (in_array($event, ['sample_shipped', 'sample_delivered'])) {
            $stmt = $pdo->prepare('SELECT * FROM sample_shipments WHERE sample_request_id = ? LIMIT 1');
            $stmt->execute([$requestId]);
            $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Build tracking info HTML if available
        $trackingInfoHtml = '';
        $trackingUrl = '';
        if ($shipment && !empty($shipment['tracking_number']) && $shipment['tracking_number'] !== 'N/A') {
            $trackingUrl = generate_tracking_url($shipment['carrier'] ?? 'USPS', $shipment['tracking_number']);
            $trackingInfoHtml = '<p style="margin: 0 0 8px; color: #065f46;"><strong>Tracking Number:</strong> ' . htmlspecialchars($shipment['tracking_number']) . '</p>';
            $trackingInfoHtml .= '<p style="margin: 0; color: #065f46;"><a href="' . htmlspecialchars($trackingUrl) . '" style="color: #059669; text-decoration: underline;">Track Your Package</a></p>';
        }
        
        // Calculate delivery info conditionally
        $deliveryDays = '';
        $deliveryInfo = 'Your premium Product sample is on its way. ';
        
        if ($shipment && !empty($shipment['carrier']) && !empty($shipment['service_type'])) {
            $days = calculate_delivery_days($shipment['carrier'], $shipment['service_type']);
            $deliveryDays = $days;
            $deliveryInfo = 'Your premium Product sample will arrive within ' . $days . ' business days. ';
        }
        
        // Prepare variables for email templates
        $variables = [
            'recipient_name' => $request['recipient_name'],
            'product_name' => $request['product_name'],
            'agent_name' => $request['agent_name'],
            'agent_email' => $request['agent_email'],
            'agent_phone' => $request['agent_phone'] ?: 'N/A',
            'company_name' => $request['lead_company_name'] ?: $request['account_business_name'] ?: $request['recipient_name'],
            'tracking_number' => $shipment['tracking_number'] ?? '',
            'carrier' => $shipment['carrier'] ?? 'USPS',
            'service_type' => $shipment['service_type'] ?? 'Ground',
            'expected_delivery_date' => isset($shipment['estimated_delivery_date']) ? date('F j, Y', strtotime($shipment['estimated_delivery_date'])) : '',
            'delivery_days' => $deliveryDays,
            'delivery_info' => $deliveryInfo,
            'tracking_info' => $trackingInfoHtml,
            'tracking_url' => $trackingUrl,
            'base_url' => rtrim(site_base_url(), '/'),
            'tracking_pixel' => ''
        ];
        
        // Get recipient email (either lead email or from request)
        $recipientEmail = $request['lead_email'] ?: $request['recipient_name'];
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $recipientEmail = 'sample-017@example.com';
        }
        
        // Determine template slug for this event
        $templateMap = [
            'sample_approved' => 'sample_approved_customer',
            'sample_shipped' => 'sample_approved_customer',
            'sample_delivered' => 'sample_delivered',
            'sample_request_created' => 'sample_request_received'
        ];
        
        $templateSlug = $templateMap[$event] ?? 'sample_approved_customer';
        
        // Load SendGrid functions
        require_once __DIR__ . '/../api/sendgrid.php';
        
        // Get template from database
        $stmt = $pdo->prepare('SELECT subject, html_body, text_body FROM email_templates WHERE slug = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$templateSlug]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($template) {
            // Replace template variables
            $subject = $template['subject'];
            $htmlBody = $template['html_body'];
            $textBody = $template['text_body'] ?? strip_tags($htmlBody);
            
            foreach ($variables as $key => $value) {
                $subject = str_replace('{' . $key . '}', $value, $subject);
                $htmlBody = str_replace('{' . $key . '}', $value, $htmlBody);
                $textBody = str_replace('{' . $key . '}', $value, $textBody);
            }
            
            // Send email to customer
            $sendResult = sendgrid_send([
                'to' => $recipientEmail,
                'to_name' => $variables['recipient_name'],
                'from' => 'sample-006@example.com',
                'from_name' => 'Product',
                'subject' => $subject,
                'html' => $htmlBody,
                'text' => $textBody
            ]);
            
            // Extract SendGrid message ID
            $messageId = null;
            if (isset($sendResult['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $sendResult['headers'], $matches)) {
                $messageId = trim($matches[1]);
            }
            if (!$messageId) {
                $messageId = 'sample-' . $requestId . '-' . time();
            }
            
            // Log event for customer
            $stmt = $pdo->prepare('
                INSERT INTO sample_email_events (
                    sample_request_id, event_type, template_slug, recipient_email,
                    recipient_type, email_sent, sent_at, sendgrid_message_id, metadata
                ) VALUES (?, ?, ?, ?, "customer", ?, NOW(), ?, ?)
            ');
            $stmt->execute([
                $requestId,
                str_replace('sample_', '', $event),
                $templateSlug,
                $recipientEmail,
                $sendResult['ok'] ? 1 : 0,
                $messageId,
                json_encode($variables)
            ]);
            
            // If shipped event, also send to agent
            if ($event === 'sample_shipped') {
                $agentTemplate = $pdo->prepare('SELECT subject, html_body, text_body FROM email_templates WHERE slug = "sample_shipped_agent" AND is_active = 1 LIMIT 1');
                $agentTemplate->execute();
                $agentTpl = $agentTemplate->fetch(PDO::FETCH_ASSOC);
                
                if ($agentTpl) {
                    $agentSubject = $agentTpl['subject'];
                    $agentHtml = $agentTpl['html_body'];
                    $agentText = $agentTpl['text_body'] ?? strip_tags($agentHtml);
                    
                    foreach ($variables as $key => $value) {
                        $agentSubject = str_replace('{' . $key . '}', $value, $agentSubject);
                        $agentHtml = str_replace('{' . $key . '}', $value, $agentHtml);
                        $agentText = str_replace('{' . $key . '}', $value, $agentText);
                    }
                    
                    $agentSendResult = sendgrid_send([
                        'to' => $request['agent_email'],
                        'to_name' => $variables['agent_name'],
                        'from' => 'sample-006@example.com',
                        'from_name' => 'Product Operations',
                        'subject' => $agentSubject,
                        'html' => $agentHtml,
                        'text' => $agentText
                    ]);
                    
                    $agentMessageId = null;
                    if (isset($agentSendResult['headers']) && preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $agentSendResult['headers'], $matches)) {
                        $agentMessageId = trim($matches[1]);
                    }
                    if (!$agentMessageId) {
                        $agentMessageId = 'sample-agent-' . $requestId . '-' . time();
                    }
                    
                    $stmt = $pdo->prepare('
                        INSERT INTO sample_email_events (
                            sample_request_id, event_type, template_slug, recipient_email,
                            recipient_type, email_sent, sent_at, sendgrid_message_id, metadata
                        ) VALUES (?, "shipped", "sample_shipped_agent", ?, "agent", ?, NOW(), ?, ?)
                    ');
                    $stmt->execute([
                        $requestId,
                        $request['agent_email'],
                        $agentSendResult['ok'] ? 1 : 0,
                        $agentMessageId,
                        json_encode($variables)
                    ]);
                }
            }
            
            error_log("Sample email sent via SendGrid: {$event} for request #{$requestId}, status: " . ($sendResult['ok'] ? 'SUCCESS' : 'FAILED'));
        } else {
            error_log("Sample email template not found: {$templateSlug} for event: {$event}");
        }
        
    } catch (Throwable $e) {
        error_log("Error triggering sample email workflow: " . $e->getMessage());
    }
}

/**
 * Calculate estimated delivery days based on carrier and service
 */
function calculate_delivery_days(string $carrier, string $service): int {
    $deliveryMap = [
        'USPS_Ground' => 5,
        'USPS_Priority' => 3,
        'USPS_Express' => 2,
        'UPS_Ground' => 5,
        'UPS_2Day' => 2,
        'UPS_NextDay' => 1,
        'FedEx_Ground' => 5,
        'FedEx_2Day' => 2,
        'FedEx_Overnight' => 1
    ];
    
    $key = $carrier . '_' . str_replace(' ', '', $service);
    return $deliveryMap[$key] ?? 5;
}

/**
 * Generate tracking URL for carrier
 */
function generate_tracking_url(string $carrier, string $trackingNumber): string {
    $urls = [
        'USPS' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . urlencode($trackingNumber),
        'UPS' => 'https://www.ups.com/track?tracknum=' . urlencode($trackingNumber),
        'FedEx' => 'https://www.fedex.com/fedextrack/?trknbr=' . urlencode($trackingNumber),
        'DHL' => 'https://www.dhl.com/en/express/tracking.html?AWB=' . urlencode($trackingNumber)
    ];
    
    return $urls[$carrier] ?? '#';
}

/**
 * Create placeholder shipment record when request is approved
 */
function create_sample_shipment_placeholder(PDO $pdo, int $requestId, int $createdBy): void {
    try {
        // Check if shipment already exists
        $stmt = $pdo->prepare('SELECT id FROM sample_shipments WHERE sample_request_id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        if ($stmt->fetch()) {
            return; // Already exists
        }
        
        // Create placeholder
        $stmt = $pdo->prepare('
            INSERT INTO sample_shipments (
                sample_request_id, shipment_date, carrier, shipping_cost,
                delivery_status, created_by
            ) VALUES (?, NOW(), "TBD", 0, "pending", ?)
        ');
        $stmt->execute([$requestId, $createdBy]);
    } catch (Throwable $e) {
        error_log("Error creating shipment placeholder: " . $e->getMessage());
    }
}

/**
 * Create or update sample shipment record with tracking details
 */
function create_or_update_sample_shipment(
    PDO $pdo, 
    int $requestId, 
    string $carrier, 
    string $service,
    string $trackingNumber, 
    float $shippingCost, 
    int $userId
): void {
    try {
        // Calculate estimated delivery date
        $deliveryDays = calculate_delivery_days($carrier, $service);
        $estimatedDelivery = date('Y-m-d', strtotime("+{$deliveryDays} weekdays"));
        
        // Check if shipment exists
        $stmt = $pdo->prepare('SELECT id FROM sample_shipments WHERE sample_request_id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing
            $stmt = $pdo->prepare('
                UPDATE sample_shipments
                SET carrier = ?, service_type = ?, tracking_number = ?,
                    shipping_cost = ?, estimated_delivery_date = ?,
                    delivery_status = "in_transit", updated_at = NOW()
                WHERE sample_request_id = ?
            ');
            $stmt->execute([$carrier, $service, $trackingNumber, $shippingCost, $estimatedDelivery, $requestId]);
        } else {
            // Create new
            $stmt = $pdo->prepare('
                INSERT INTO sample_shipments (
                    sample_request_id, shipment_date, carrier, service_type,
                    tracking_number, shipping_cost, estimated_delivery_date,
                    delivery_status, created_by
                ) VALUES (?, NOW(), ?, ?, ?, ?, ?, "in_transit", ?)
            ');
            $stmt->execute([
                $requestId, $carrier, $service, $trackingNumber,
                $shippingCost, $estimatedDelivery, $userId
            ]);
        }
    } catch (Throwable $e) {
        error_log("Error creating/updating sample shipment: " . $e->getMessage());
    }
}

/**
 * Create CRM follow-up task for agent
 */
function create_sample_followup_task(PDO $pdo, int $requestId): void {
    try {
        // Get request and agent details
        $stmt = $pdo->prepare('
            SELECT sr.agent_id, sr.recipient_name, sr.lead_id, sr.account_id,
                   l.company_name as lead_company_name,
                   wa.business_name as account_business_name
            FROM sample_requests sr
            LEFT JOIN crm_leads l ON l.id = sr.lead_id
            LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
            WHERE sr.id = ?
        ');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            return;
        }
        
        $companyName = $request['lead_company_name'] ?: $request['account_business_name'] ?: $request['recipient_name'];
        
        // Create task due 3 days from now
        $stmt = $pdo->prepare('
            INSERT INTO crm_tasks (
                agent_id, lead_id, account_id, task_type, title,
                description, due_date, priority, status, created_at
            ) VALUES (?, ?, ?, "follow_up", ?, ?, DATE_ADD(NOW(), INTERVAL 3 DAY), "normal", "pending", NOW())
        ');
        $stmt->execute([
            $request['agent_id'],
            $request['lead_id'],
            $request['account_id'],
            'Follow up on sample delivery - ' . $companyName,
            'Check in with customer about their sample experience. Discuss their feedback and explore wholesale pricing options.'
        ]);
        
    } catch (Throwable $e) {
        error_log("Error creating follow-up task: " . $e->getMessage());
    }
}

// ============================================
// ACTION: Update Sample Request Address
// ============================================

if ($action === 'update_address') {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $requestId = intval($input['request_id'] ?? 0);
        
        if (!$requestId) {
            throw new Exception('Missing request_id');
        }
        
        // Verify access (admin or agent who created the request)
        $stmt = $pdo->prepare('SELECT agent_id FROM sample_requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        if (!$request) {
            throw new Exception('Sample request not found');
        }
        
        $isAdmin = is_admin_user($pdo, $userId);
        if (!$isAdmin && intval($request['agent_id']) !== $userId) {
            throw new Exception('Access denied');
        }
        
        // Update address fields
        $stmt = $pdo->prepare('
            UPDATE sample_requests
            SET recipient_name = ?,
                recipient_address_line1 = ?,
                recipient_address_line2 = ?,
                recipient_city = ?,
                recipient_state = ?,
                recipient_zip = ?,
                recipient_country = ?,
                recipient_phone = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        
        $stmt->execute([
            trim($input['recipient_name'] ?? ''),
            trim($input['recipient_address_line1'] ?? ''),
            trim($input['recipient_address_line2'] ?? ''),
            trim($input['recipient_city'] ?? ''),
            trim($input['recipient_state'] ?? ''),
            trim($input['recipient_zip'] ?? ''),
            trim($input['recipient_country'] ?? 'USA'),
            trim($input['recipient_phone'] ?? ''),
            $requestId
        ]);
        
        echo json_encode(['ok' => true]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ============================================
// ACTION: Get Lead Address (for syncing)
// ============================================

if ($action === 'get_lead_address') {
    try {
        $leadId = intval($_GET['lead_id'] ?? 0);
        
        if (!$leadId) {
            throw new Exception('Missing lead_id');
        }
        
        // Fetch lead data
        $stmt = $pdo->prepare('
            SELECT 
                first_name, last_name, company_name, phone,
                address_line1, address_line2, city, state, zip_code, country
            FROM crm_leads
            WHERE id = ?
        ');
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();
        
        if (!$lead) {
            throw new Exception('Lead not found');
        }
        
        echo json_encode([
            'ok' => true,
            'recipient_name' => trim(($lead['first_name'] ?: '') . ' ' . ($lead['last_name'] ?: '')) ?: ($lead['company_name'] ?: ''),
            'address_line1' => $lead['address_line1'],
            'address_line2' => $lead['address_line2'],
            'city' => $lead['city'],
            'state' => $lead['state'],
            'zip' => $lead['zip_code'],
            'country' => $lead['country'] ?: 'USA',
            'phone' => $lead['phone']
        ]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ============================================
// ACTION: Get Account Address (for syncing)
// ============================================

if ($action === 'get_account_address') {
    try {
        $accountId = intval($_GET['account_id'] ?? 0);
        
        if (!$accountId) {
            throw new Exception('Missing account_id');
        }
        
        // Fetch account data with primary contact
        $stmt = $pdo->prepare('
            SELECT 
                wa.business_name, wa.phone,
                wa.address_line1, wa.address_line2, wa.city, wa.state_region, wa.postal_code, wa.country,
                u.first_name, u.last_name, u.phone AS user_phone
            FROM wholesale_accounts wa
            JOIN users u ON u.id = wa.user_id
            WHERE wa.id = ?
        ');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        
        if (!$account) {
            throw new Exception('Account not found');
        }
        
        echo json_encode([
            'ok' => true,
            'recipient_name' => $account['business_name'] ?: trim(($account['first_name'] ?: '') . ' ' . ($account['last_name'] ?: '')),
            'address_line1' => $account['address_line1'],
            'address_line2' => $account['address_line2'],
            'city' => $account['city'],
            'state' => $account['state_region'],
            'zip' => $account['postal_code'],
            'country' => $account['country'] ?: 'USA',
            'phone' => $account['phone'] ?: $account['user_phone']
        ]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ============================================
// ACTION: Bulk Sync Sample Addresses from Leads/Accounts
// ============================================

if ($action === 'bulk_sync_addresses') {
    try {
        if (!is_admin_user($pdo, $userId)) {
            throw new Exception('Admin access required');
        }
        
        // Get all sample requests with linked leads or accounts but incomplete addresses
        $stmt = $pdo->query('
            SELECT 
                sr.id, sr.lead_id, sr.account_id,
                sr.recipient_address_line1, sr.recipient_city, sr.recipient_zip
            FROM sample_requests sr
            WHERE sr.request_type = "shipping"
            AND (sr.lead_id IS NOT NULL OR sr.account_id IS NOT NULL)
            AND (
                sr.recipient_address_line1 IS NULL OR sr.recipient_address_line1 = ""
                OR sr.recipient_city IS NULL OR sr.recipient_city = ""
                OR sr.recipient_zip IS NULL OR sr.recipient_zip = ""
            )
        ');
        $requests = $stmt->fetchAll();
        
        $syncedFromLeads = 0;
        $syncedFromAccounts = 0;
        $failed = 0;
        $skipped = 0;
        
        foreach ($requests as $req) {
            try {
                // Try lead first if available
                if ($req['lead_id']) {
                    $leadStmt = $pdo->prepare('
                        SELECT 
                            first_name, last_name, company_name, phone,
                            address_line1, address_line2, city, state, zip_code, country
                        FROM crm_leads
                        WHERE id = ?
                    ');
                    $leadStmt->execute([intval($req['lead_id'])]);
                    $lead = $leadStmt->fetch();
                    
                    if ($lead && $lead['address_line1'] && $lead['city'] && $lead['zip_code']) {
                        // Update sample request with lead address
                        $updateStmt = $pdo->prepare('
                            UPDATE sample_requests
                            SET recipient_name = COALESCE(NULLIF(recipient_name, ""), ?),
                                recipient_address_line1 = COALESCE(NULLIF(recipient_address_line1, ""), ?),
                                recipient_address_line2 = COALESCE(NULLIF(recipient_address_line2, ""), ?),
                                recipient_city = COALESCE(NULLIF(recipient_city, ""), ?),
                                recipient_state = COALESCE(NULLIF(recipient_state, ""), ?),
                                recipient_zip = COALESCE(NULLIF(recipient_zip, ""), ?),
                                recipient_country = COALESCE(NULLIF(recipient_country, ""), ?),
                                recipient_phone = COALESCE(NULLIF(recipient_phone, ""), ?),
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ');
                        
                        $recipientName = trim(($lead['first_name'] ?: '') . ' ' . ($lead['last_name'] ?: '')) ?: ($lead['company_name'] ?: '');
                        
                        $updateStmt->execute([
                            $recipientName,
                            $lead['address_line1'],
                            $lead['address_line2'],
                            $lead['city'],
                            $lead['state'],
                            $lead['zip_code'],
                            $lead['country'] ?: 'USA',
                            $lead['phone'],
                            $req['id']
                        ]);
                        
                        $syncedFromLeads++;
                        continue;
                    }
                }
                
                // Try account if lead didn't work or not available
                if ($req['account_id']) {
                    $accountStmt = $pdo->prepare('
                        SELECT 
                            wa.business_name, wa.phone,
                            wa.address_line1, wa.address_line2, wa.city, wa.state_region, wa.postal_code, wa.country,
                            u.first_name, u.last_name, u.phone AS user_phone
                        FROM wholesale_accounts wa
                        JOIN users u ON u.id = wa.user_id
                        WHERE wa.id = ?
                    ');
                    $accountStmt->execute([intval($req['account_id'])]);
                    $account = $accountStmt->fetch();
                    
                    if ($account && $account['address_line1'] && $account['city'] && $account['postal_code']) {
                        // Update sample request with account address
                        $updateStmt = $pdo->prepare('
                            UPDATE sample_requests
                            SET recipient_name = COALESCE(NULLIF(recipient_name, ""), ?),
                                recipient_address_line1 = COALESCE(NULLIF(recipient_address_line1, ""), ?),
                                recipient_address_line2 = COALESCE(NULLIF(recipient_address_line2, ""), ?),
                                recipient_city = COALESCE(NULLIF(recipient_city, ""), ?),
                                recipient_state = COALESCE(NULLIF(recipient_state, ""), ?),
                                recipient_zip = COALESCE(NULLIF(recipient_zip, ""), ?),
                                recipient_country = COALESCE(NULLIF(recipient_country, ""), ?),
                                recipient_phone = COALESCE(NULLIF(recipient_phone, ""), ?),
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ');
                        
                        $recipientName = $account['business_name'] ?: trim(($account['first_name'] ?: '') . ' ' . ($account['last_name'] ?: ''));
                        
                        $updateStmt->execute([
                            $recipientName,
                            $account['address_line1'],
                            $account['address_line2'],
                            $account['city'],
                            $account['state_region'],
                            $account['postal_code'],
                            $account['country'] ?: 'USA',
                            $account['phone'] ?: $account['user_phone'],
                            $req['id']
                        ]);
                        
                        $syncedFromAccounts++;
                        continue;
                    }
                }
                
                // No usable data found
                $skipped++;
                
            } catch (Throwable $e) {
                $failed++;
            }
        }
        
        echo json_encode([
            'ok' => true,
            'synced_from_leads' => $syncedFromLeads,
            'synced_from_accounts' => $syncedFromAccounts,
            'skipped' => $skipped,
            'failed' => $failed,
            'total' => count($requests)
        ]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ============================================
// ACTION: Delete Sample Request
// ============================================

if ($action === 'delete_request') {
    try {
        if (!is_admin_user($pdo, $userId)) {
            throw new Exception('Admin access required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $requestId = intval($input['request_id'] ?? 0);
        
        if (!$requestId) {
            throw new Exception('Missing request_id');
        }
        
        // Get request to check status
        $stmt = $pdo->prepare('SELECT id, status, sample_product_id, quantity FROM sample_requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Sample request not found');
        }
        
        // Only allow deletion of pending requests
        if ($request['status'] !== 'pending') {
            throw new Exception('Only pending requests can be deleted');
        }
        
        $pdo->beginTransaction();
        
        try {
            // Release any reserved inventory
            try {
                release_sample_inventory(
                    $pdo,
                    (int)$request['sample_product_id'],
                    (int)$request['quantity'],
                    $requestId,
                    $userId
                );
            } catch (Throwable $e) {
                // Ignore if inventory already released
            }
            
            // Delete the request
            $stmt = $pdo->prepare('DELETE FROM sample_requests WHERE id = ?');
            $stmt->execute([$requestId]);
            
            $pdo->commit();
            
            echo json_encode(['ok' => true]);
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ============================================
// ACTION: Bulk Delete Sample Requests
// ============================================

if ($action === 'bulk_delete_requests') {
    try {
        if (!is_admin_user($pdo, $userId)) {
            throw new Exception('Admin access required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $requestIds = $input['request_ids'] ?? [];
        
        if (!is_array($requestIds) || empty($requestIds)) {
            throw new Exception('No request IDs provided');
        }
        
        $deleted = 0;
        $failed = 0;
        
        foreach ($requestIds as $requestId) {
            $requestId = intval($requestId);
            if ($requestId <= 0) continue;
            
            try {
                // Get request
                $stmt = $pdo->prepare('SELECT id, status, sample_product_id, quantity FROM sample_requests WHERE id = ?');
                $stmt->execute([$requestId]);
                $request = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$request || $request['status'] !== 'pending') {
                    $failed++;
                    continue;
                }
                
                $pdo->beginTransaction();
                
                // Release inventory
                try {
                    release_sample_inventory(
                        $pdo,
                        (int)$request['sample_product_id'],
                        (int)$request['quantity'],
                        $requestId,
                        $userId
                    );
                } catch (Throwable $e) {
                    // Ignore
                }
                
                // Delete request
                $stmt = $pdo->prepare('DELETE FROM sample_requests WHERE id = ?');
                $stmt->execute([$requestId]);
                
                $pdo->commit();
                $deleted++;
                
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $failed++;
            }
        }
        
        echo json_encode([
            'ok' => true,
            'deleted' => $deleted,
            'failed' => $failed,
            'total' => count($requestIds)
        ]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ============================================
// Invalid Action
// ============================================

http_response_code(400);
echo json_encode([
    'ok' => false,
    'error' => 'Invalid action. Supported: create, list, get, approve, reject, mark_shipped, mark_delivered, resend_tracking, get_inventory, restock, update_address, get_lead_address, get_account_address, bulk_sync_addresses, delete_request, bulk_delete_requests'
]);

