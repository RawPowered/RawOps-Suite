<?php
/**
 * Invoice API - Invoice Management
 * Handles invoice CRUD, payment recording, and aging reports
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../required/config.php';

start_session();

// Authentication check
$userId = current_user_id();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Role check
$roles = get_user_roles($userId);
$canManageInvoices = in_array('accountant', $roles) || in_array('admin', $roles) || in_array('sales_agent', $roles);

if (!$canManageInvoices) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        
        case 'create_invoice':
            $input = json_decode(file_get_contents('php://input'), true);
            
            $required = ['invoice_date', 'due_date', 'customer_name', 'subtotal', 'total_amount'];
            foreach ($required as $field) {
                if (!isset($input[$field]) || $input[$field] === '') {
                    throw new Exception("Field {$field} is required");
                }
            }
            
            // Generate invoice number
            $invoiceNumber = $input['invoice_number'] ?? ('INV-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT));
            
            // Check for duplicate
            $checkStmt = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ?");
            $checkStmt->execute([$invoiceNumber]);
            if ($checkStmt->fetch()) {
                $invoiceNumber = 'INV-' . date('Y') . '-' . uniqid();
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO invoices 
                (invoice_number, invoice_date, due_date, customer_type, customer_id, customer_name, customer_email,
                 subtotal, tax_amount, shipping_amount, total_amount, payment_status, notes, invoice_type, order_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $invoiceNumber,
                $input['invoice_date'],
                $input['due_date'],
                $input['customer_type'] ?? 'other',
                $input['customer_id'] ?? null,
                $input['customer_name'],
                $input['customer_email'] ?? null,
                $input['subtotal'],
                $input['tax_amount'] ?? 0,
                $input['shipping_amount'] ?? 0,
                $input['total_amount'],
                $input['payment_status'] ?? 'unpaid',
                $input['notes'] ?? null,
                $input['invoice_type'] ?? 'product_sale',
                $input['order_id'] ?? null,
                $userId
            ]);
            
            $invoiceId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Invoice created successfully',
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber
            ]);
            break;
            
        case 'update_invoice':
            $input = json_decode(file_get_contents('php://input'), true);
            $invoiceId = $input['invoice_id'] ?? 0;
            
            if (!$invoiceId) {
                throw new Exception('Invoice ID required');
            }
            
            $updates = [];
            $params = [];
            
            $allowedFields = [
                'invoice_date', 'due_date', 'customer_name', 'customer_email', 'subtotal',
                'tax_amount', 'shipping_amount', 'total_amount', 'payment_status',
                'payment_date', 'payment_method', 'notes'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "{$field} = ?";
                    $params[] = $input[$field];
                }
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
            }
            
            $params[] = $invoiceId;
            $sql = "UPDATE invoices SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode([
                'success' => true,
                'message' => 'Invoice updated successfully'
            ]);
            break;
            
        case 'get_invoices':
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-t');
            $paymentStatus = $_GET['payment_status'] ?? null;
            $customerType = $_GET['customer_type'] ?? null;
            $limit = min(intval($_GET['limit'] ?? 100), 1000);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "
                SELECT i.*,
                       DATEDIFF(i.due_date, CURDATE()) as days_until_due,
                       CASE 
                           WHEN i.payment_status = 'paid' THEN 'paid'
                           WHEN i.due_date < CURDATE() AND i.payment_status != 'paid' THEN 'overdue'
                           ELSE i.payment_status
                       END as computed_status
                FROM invoices i
                WHERE i.invoice_date BETWEEN ? AND ?
            ";
            $params = [$startDate, $endDate];
            
            if ($paymentStatus) {
                $sql .= " AND i.payment_status = ?";
                $params[] = $paymentStatus;
            }
            
            if ($customerType) {
                $sql .= " AND i.customer_type = ?";
                $params[] = $customerType;
            }
            
            $sql .= " ORDER BY i.invoice_date DESC, i.id DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get totals
            $totalStmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_count,
                    SUM(total_amount) as total_amount,
                    SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount ELSE 0 END) as unpaid_amount
                FROM invoices
                WHERE invoice_date BETWEEN ? AND ?
            ");
            $totalStmt->execute([$startDate, $endDate]);
            $totals = $totalStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'invoices' => $invoices,
                'totals' => $totals
            ]);
            break;
            
        case 'record_payment':
            if (!in_array('accountant', $roles) && !in_array('admin', $roles)) {
                throw new Exception('Only accountants and admins can record payments');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['invoice_id']) || empty($input['payment_date']) || empty($input['payment_method'])) {
                throw new Exception('Invoice ID, payment date, and payment method are required');
            }
            
            $stmt = $pdo->prepare("
                UPDATE invoices 
                SET payment_status = 'paid',
                    payment_date = ?,
                    payment_method = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $input['payment_date'],
                $input['payment_method'],
                $input['invoice_id']
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Payment recorded successfully'
            ]);
            break;
            
        case 'get_aging_report':
            // Aging report: Current, 1-30 days, 31-60 days, 61-90 days, 90+ days overdue
            $stmt = $pdo->query("
                SELECT 
                    customer_name,
                    customer_type,
                    SUM(CASE WHEN due_date >= CURDATE() THEN total_amount ELSE 0 END) as current,
                    SUM(CASE WHEN due_date < CURDATE() AND due_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN total_amount ELSE 0 END) as days_1_30,
                    SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN total_amount ELSE 0 END) as days_31_60,
                    SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN total_amount ELSE 0 END) as days_61_90,
                    SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN total_amount ELSE 0 END) as days_90_plus,
                    SUM(total_amount) as total_outstanding
                FROM invoices
                WHERE payment_status != 'paid'
                GROUP BY customer_name, customer_type
                ORDER BY total_outstanding DESC
            ");
            
            $agingData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate totals
            $totals = [
                'current' => 0,
                'days_1_30' => 0,
                'days_31_60' => 0,
                'days_61_90' => 0,
                'days_90_plus' => 0,
                'total_outstanding' => 0
            ];
            
            foreach ($agingData as $row) {
                $totals['current'] += $row['current'];
                $totals['days_1_30'] += $row['days_1_30'];
                $totals['days_31_60'] += $row['days_31_60'];
                $totals['days_61_90'] += $row['days_61_90'];
                $totals['days_90_plus'] += $row['days_90_plus'];
                $totals['total_outstanding'] += $row['total_outstanding'];
            }
            
            echo json_encode([
                'success' => true,
                'aging_data' => $agingData,
                'totals' => $totals
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}





