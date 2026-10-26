<?php
// Secure commission processing - ADMIN ONLY
// This file calculates commissions based on spreadsheet data
// NO customer-facing exposure of commission data

require_once __DIR__ . '/../required/config.php';
start_session();

// Strict admin-only access
if (!current_admin_id()) {
    http_response_code(403);
    exit('Access denied');
}

// Commission calculation function based on your spreadsheet
function calculateCommissions($orderId, $orderSubtotal, $salesAgentId = null) {
    $pdo = db();
    
    try {
        // Commission rates from your spreadsheet
        $commissionRates = [
            'developer' => 0.2,  // 0.2% for system maintenance (Portfolio Developer)
            'base_agent' => [
                'X5' => 20,   // 20% for 5+ bottles
                'X10' => 15,  // 15% for 10+ bottles  
                'X15' => 12,  // 12% for 15+ bottles
                'X20' => 10   // 10% for 20+ bottles
            ]
        ];
        
        // Load per-invoice commission rate if explicitly set
        $stmt = $pdo->prepare('SELECT commission_rate FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $orderRow = $stmt->fetch();
        $explicitRate = isset($orderRow['commission_rate']) ? floatval($orderRow['commission_rate']) : null;

        // Get order quantity to determine agent commission tier (fallback)
        $stmt = $pdo->prepare('
            SELECT SUM(quantity) as total_quantity
            FROM order_items 
            WHERE order_id = ?
        ');
        $stmt->execute([$orderId]);
        $orderData = $stmt->fetch();
        $totalQuantity = intval($orderData['total_quantity'] ?? 0);
        
        // Determine agent commission rate
        if ($explicitRate !== null && $explicitRate >= 0) {
            $agentCommissionRate = $explicitRate;
        } else {
            // Fallback: derive from quantity tiers
            $agentCommissionRate = 10; // Default 10%
            if ($totalQuantity >= 20) {
                $agentCommissionRate = 10;
            } elseif ($totalQuantity >= 15) {
                $agentCommissionRate = 12;
            } elseif ($totalQuantity >= 10) {
                $agentCommissionRate = 15;
            } elseif ($totalQuantity >= 5) {
                $agentCommissionRate = 20;
            }
        }
        
        // Calculate developer commission (0.2%)
        $developerUserId = 1; // Portfolio Developer user ID
        $developerCommission = $orderSubtotal * ($commissionRates['developer'] / 100);
        
        // Insert developer commission
        $stmt = $pdo->prepare('
            INSERT INTO commissions (order_id, user_id, commission_type, amount, percentage, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $orderId, 
            $developerUserId, 
            'developer', 
            $developerCommission, 
            $commissionRates['developer'], 
            'pending'
        ]);
        
        // Calculate sales agent commission if assigned
        if ($salesAgentId) {
            $agentCommission = $orderSubtotal * ($agentCommissionRate / 100);
            
            $stmt = $pdo->prepare('
                INSERT INTO commissions (order_id, user_id, commission_type, amount, percentage, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $orderId, 
                $salesAgentId, 
                'sales_agent', 
                $agentCommission, 
                $agentCommissionRate, 
                'pending'
            ]);
        }
        
        return [
            'success' => true,
            'developer_commission' => $developerCommission,
            'agent_commission' => $salesAgentId ? $agentCommission : 0,
            'agent_rate' => $agentCommissionRate
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// If called directly via POST (from checkout process)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = intval($_POST['order_id'] ?? 0);
    $subtotal = floatval($_POST['subtotal'] ?? 0);
    $salesAgentId = intval($_POST['sales_agent_id'] ?? 0) ?: null;
    
    if (!$orderId || !$subtotal) {
        echo json_encode(['success' => false, 'error' => 'Invalid order data']);
        exit;
    }
    
    $result = calculateCommissions($orderId, $subtotal, $salesAgentId);
    echo json_encode($result);
    exit;
}

// If accessed directly, show admin commission overview
if (!isset($_POST['order_id'])) {
    echo json_encode([
        'info' => 'Commission processor ready',
        'rates' => [
            'developer' => '0.2%',
            'agent_tiers' => [
                '5-9 bottles' => '20%',
                '10-14 bottles' => '15%', 
                '15-19 bottles' => '12%',
                '20+ bottles' => '10%'
            ]
        ]
    ]);
}
?>
