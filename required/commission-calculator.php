<?php
/**
 * Line-Item Commission Calculator
 * Handles per-line-item commission calculations based on quantity tiers
 */

/**
 * Calculate commission rate based on line item quantity
 * @param int $quantity Number of items in this line
 * @return float Commission rate percentage
 */
function getCommissionRateByQuantity($quantity) {
    if ($quantity >= 20) return 10.00;      // 20+ items: 10%
    if ($quantity >= 15) return 12.00;      // 15-19 items: 12%
    if ($quantity >= 10) return 15.00;      // 10-14 items: 15%
    if ($quantity >= 5) return 20.00;       // 5-9 items: 20%
    return 20.00;                           // 1-4 items: 20%
}

/**
 * Calculate commission for a single order line item
 * @param array $orderItem Order item data
 * @param int $salesAgentId Sales agent ID
 * @return array Commission calculation results
 */
function calculateLineItemCommission($orderItem, $salesAgentId = null) {
    $quantity = intval($orderItem['quantity']);
    $unitPrice = floatval($orderItem['unit_price']);
    $lineTotal = floatval($orderItem['line_total']);
    
    // Get commission rate based on quantity
    $commissionRate = getCommissionRateByQuantity($quantity);
    
    // Calculate sales agent commission
    $salesCommissionAmount = ($lineTotal * $commissionRate) / 100;
    
    // Developer commission is always 5% of line total
    $developerCommissionAmount = ($lineTotal * 5.00) / 100;
    
    return [
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'line_total' => $lineTotal,
        'sales_commission_rate' => $commissionRate,
        'sales_commission_amount' => round($salesCommissionAmount, 2),
        'developer_commission_rate' => 5.00,
        'developer_commission_amount' => round($developerCommissionAmount, 2),
        'sales_agent_id' => $salesAgentId
    ];
}

/**
 * Process all line item commissions for an order
 * @param int $orderId Order ID
 * @param int $salesAgentId Sales agent ID (optional)
 * @return array Summary of all commission calculations
 */
function processOrderLineItemCommissions($orderId, $salesAgentId = null) {
    $pdo = db();
    
    // Get all order items for this order
    $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $orderItems = $stmt->fetchAll();
    
    $totalSalesCommission = 0;
    $totalDeveloperCommission = 0;
    $lineItemResults = [];
    
    foreach ($orderItems as $item) {
        // Calculate commission for this line item
        $commission = calculateLineItemCommission($item, $salesAgentId);
        $lineItemResults[] = $commission;
        
        // Update order_items table with commission data
        $stmt = $pdo->prepare('
            UPDATE order_items 
            SET commission_rate = ?, commission_amount = ?, developer_commission_amount = ? 
            WHERE id = ?
        ');
        $stmt->execute([
            $commission['sales_commission_rate'],
            $commission['sales_commission_amount'],
            $commission['developer_commission_amount'],
            $item['id']
        ]);
        
        // Insert into line_item_commissions table
        if ($salesAgentId) {
            // Sales agent commission
            $stmt = $pdo->prepare('
                INSERT INTO line_item_commissions 
                (order_id, order_item_id, sales_agent_id, commission_type, quantity, unit_price, line_total, commission_rate, commission_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $orderId,
                $item['id'],
                $salesAgentId,
                'sales_agent',
                $commission['quantity'],
                $commission['unit_price'],
                $commission['line_total'],
                $commission['sales_commission_rate'],
                $commission['sales_commission_amount']
            ]);
        }
        
        // Developer commission (always created)
        $stmt = $pdo->prepare('
            INSERT INTO line_item_commissions 
            (order_id, order_item_id, sales_agent_id, commission_type, quantity, unit_price, line_total, commission_rate, commission_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $orderId,
            $item['id'],
            null, // Developer commission doesn't need sales_agent_id
            'developer',
            $commission['quantity'],
            $commission['unit_price'],
            $commission['line_total'],
            $commission['developer_commission_rate'],
            $commission['developer_commission_amount']
        ]);
        
        $totalSalesCommission += $commission['sales_commission_amount'];
        $totalDeveloperCommission += $commission['developer_commission_amount'];
    }
    
    return [
        'order_id' => $orderId,
        'sales_agent_id' => $salesAgentId,
        'line_items' => $lineItemResults,
        'total_sales_commission' => round($totalSalesCommission, 2),
        'total_developer_commission' => round($totalDeveloperCommission, 2),
        'line_item_count' => count($orderItems)
    ];
}

/**
 * Update existing commissions table with line-item totals
 * @param int $orderId Order ID
 * @param array $commissionSummary Result from processOrderLineItemCommissions
 */
function updateCommissionsTable($orderId, $commissionSummary) {
    $pdo = db();
    
    // Delete existing commission records for this order
    $stmt = $pdo->prepare('DELETE FROM commissions WHERE order_id = ?');
    $stmt->execute([$orderId]);
    
    // Insert sales agent commission (if exists)
    if ($commissionSummary['sales_agent_id'] && $commissionSummary['total_sales_commission'] > 0) {
        $stmt = $pdo->prepare('
            INSERT INTO commissions (order_id, user_id, commission_type, amount, percentage, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $orderId,
            $commissionSummary['sales_agent_id'],
            'sales_agent',
            $commissionSummary['total_sales_commission'],
            0, // Percentage is calculated per line item, not overall
            'pending'
        ]);
    }
    
    // Insert developer commission (always exists)
    if ($commissionSummary['total_developer_commission'] > 0) {
        // Get developer user ID (Portfolio Developer)
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute(['sample-027@example.com']);
        $developerId = $stmt->fetchColumn();
        
        if ($developerId) {
            $stmt = $pdo->prepare('
                INSERT INTO commissions (order_id, user_id, commission_type, amount, percentage, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $orderId,
                $developerId,
                'developer',
                $commissionSummary['total_developer_commission'],
                5.00, // Always 5% for developer
                'pending'
            ]);
        }
    }
}

/**
 * Complete commission processing for an order
 * @param int $orderId Order ID
 * @param int $salesAgentId Sales agent ID (optional)
 * @return array Complete commission summary
 */
function processOrderCommissions($orderId, $salesAgentId = null) {
    try {
        // Process line item commissions
        $commissionSummary = processOrderLineItemCommissions($orderId, $salesAgentId);
        
        // Update main commissions table
        updateCommissionsTable($orderId, $commissionSummary);
        
        return [
            'success' => true,
            'summary' => $commissionSummary
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'order_id' => $orderId
        ];
    }
}
?>
