<?php
/**
 * Tax API - Tax Calculations and Management
 * Handles tax configuration, liability calculations, and filing tracking
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
if (!in_array('accountant', $roles) && !in_array('admin', $roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = db();

try {
    switch ($action) {
        
        case 'calculate_taxes':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['period_start']) || empty($input['period_end']) || empty($input['tax_type'])) {
                throw new Exception('Period start, period end, and tax type are required');
            }
            
            $periodStart = $input['period_start'];
            $periodEnd = $input['period_end'];
            $taxType = $input['tax_type'];
            
            // Get tax configuration
            $configStmt = $pdo->prepare("
                SELECT * FROM tax_configuration
                WHERE tax_type = ?
                AND is_active = 1
                AND effective_date <= ?
                AND (expiration_date IS NULL OR expiration_date >= ?)
                LIMIT 1
            ");
            $configStmt->execute([$taxType, $periodEnd, $periodStart]);
            $config = $configStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$config) {
                throw new Exception('No active tax configuration found for this tax type and period');
            }
            
            // Calculate taxable amount based on tax type
            $taxableAmount = 0;
            
            if ($taxType === 'income') {
                // Get revenue
                $revenueStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(total), 0) as total
                    FROM orders
                    WHERE created_at BETWEEN ? AND ?
                    AND status != 'cancelled'
                    AND payment_status = 'paid'
                ");
                $revenueStmt->execute([$periodStart, $periodEnd]);
                $revenue = $revenueStmt->fetch(PDO::FETCH_ASSOC);
                
                // Get expenses
                $expenseStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(amount), 0) as total
                    FROM expenses
                    WHERE expense_date BETWEEN ? AND ?
                    AND payment_status = 'paid'
                ");
                $expenseStmt->execute([$periodStart, $periodEnd]);
                $expenses = $expenseStmt->fetch(PDO::FETCH_ASSOC);
                
                $taxableAmount = $revenue['total'] - $expenses['total'];
                
            } elseif ($taxType === 'sales') {
                // Sales tax: sum of tax amounts collected
                $salesTaxStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(tax), 0) as total
                    FROM orders
                    WHERE created_at BETWEEN ? AND ?
                    AND status != 'cancelled'
                ");
                $salesTaxStmt->execute([$periodStart, $periodEnd]);
                $salesTax = $salesTaxStmt->fetch(PDO::FETCH_ASSOC);
                
                $taxableAmount = $salesTax['total'];
            }
            
            // Calculate tax owed
            $taxOwed = $taxableAmount * $config['tax_rate'];
            $balanceDue = $taxOwed; // No payments yet in this basic version
            
            // Save calculation
            $saveStmt = $pdo->prepare("
                INSERT INTO tax_calculations 
                (period_start, period_end, tax_type, jurisdiction, taxable_amount, tax_rate, tax_owed, payments_made, balance_due, filing_status, calculated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 'not_filed', ?)
            ");
            
            $saveStmt->execute([
                $periodStart,
                $periodEnd,
                $taxType,
                $config['jurisdiction'],
                $taxableAmount,
                $config['tax_rate'],
                $taxOwed,
                $balanceDue,
                $userId
            ]);
            
            $calcId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Tax calculated successfully',
                'calculation_id' => $calcId,
                'result' => [
                    'taxable_amount' => $taxableAmount,
                    'tax_rate' => $config['tax_rate'],
                    'tax_owed' => $taxOwed,
                    'balance_due' => $balanceDue
                ]
            ]);
            break;
            
        case 'get_tax_liability':
            $taxType = $_GET['tax_type'] ?? null;
            $filingStatus = $_GET['filing_status'] ?? null;
            
            $sql = "
                SELECT tc.*,
                       calculator.first_name as calc_first_name,
                       calculator.last_name as calc_last_name
                FROM tax_calculations tc
                LEFT JOIN users calculator ON calculator.id = tc.calculated_by
                WHERE 1=1
            ";
            $params = [];
            
            if ($taxType) {
                $sql .= " AND tc.tax_type = ?";
                $params[] = $taxType;
            }
            
            if ($filingStatus) {
                $sql .= " AND tc.filing_status = ?";
                $params[] = $filingStatus;
            }
            
            $sql .= " ORDER BY tc.period_end DESC LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $calculations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate totals
            $totals = [
                'total_owed' => array_sum(array_column($calculations, 'tax_owed')),
                'total_paid' => array_sum(array_column($calculations, 'payments_made')),
                'total_balance_due' => array_sum(array_column($calculations, 'balance_due'))
            ];
            
            echo json_encode([
                'success' => true,
                'calculations' => $calculations,
                'totals' => $totals
            ]);
            break;
            
        case 'file_taxes':
            if (!in_array('accountant', $roles) && !in_array('admin', $roles)) {
                throw new Exception('Only accountants and admins can file taxes');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $calcId = $input['calculation_id'] ?? 0;
            $filedDate = $input['filed_date'] ?? date('Y-m-d');
            $confirmationNumber = $input['confirmation_number'] ?? '';
            
            if (!$calcId) {
                throw new Exception('Calculation ID required');
            }
            
            $stmt = $pdo->prepare("
                UPDATE tax_calculations 
                SET filing_status = 'filed',
                    filed_date = ?,
                    confirmation_number = ?
                WHERE id = ?
            ");
            
            $stmt->execute([$filedDate, $confirmationNumber, $calcId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Tax filing recorded successfully'
            ]);
            break;
            
        case 'update_tax_config':
            if (!in_array('admin', $roles)) {
                throw new Exception('Only admins can update tax configuration');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['jurisdiction']) || empty($input['tax_type']) || !isset($input['tax_rate'])) {
                throw new Exception('Jurisdiction, tax type, and tax rate are required');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO tax_configuration 
                (jurisdiction, tax_type, tax_rate, applies_to, product_category_filter, effective_date, expiration_date, filing_frequency, notes, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            
            $stmt->execute([
                $input['jurisdiction'],
                $input['tax_type'],
                $input['tax_rate'],
                $input['applies_to'] ?? null,
                $input['product_category_filter'] ?? null,
                $input['effective_date'] ?? date('Y-m-d'),
                $input['expiration_date'] ?? null,
                $input['filing_frequency'] ?? null,
                $input['notes'] ?? null
            ]);
            
            $configId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Tax configuration created successfully',
                'config_id' => $configId
            ]);
            break;
            
        case 'get_filing_history':
            $taxType = $_GET['tax_type'] ?? null;
            $year = $_GET['year'] ?? date('Y');
            
            $sql = "
                SELECT tc.*
                FROM tax_calculations tc
                WHERE YEAR(tc.period_end) = ?
            ";
            $params = [$year];
            
            if ($taxType) {
                $sql .= " AND tc.tax_type = ?";
                $params[] = $taxType;
            }
            
            $sql .= " ORDER BY tc.period_end DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'filing_history' => $history,
                'year' => $year
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}





