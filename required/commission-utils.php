<?php
/**
 * Unified commission utilities — line-by-line, tier-based, role aware.
 */

if (!function_exists('commission_get_default_tiers')) {
    function commission_get_default_tiers(PDO $pdo): array {
        $defaults = [
            ['min' => 1,  'max' => 9,  'pct' => 20.0],
            ['min' => 10, 'max' => 14, 'pct' => 15.0],
            ['min' => 15, 'max' => 19, 'pct' => 12.0],
            ['min' => 20, 'max' => null, 'pct' => 10.0],
        ];
        try {
            $map = [];
            $st = $pdo->query("SELECT setting_key, setting_value FROM commission_settings");
            while ($r = $st->fetch()) { $map[$r['setting_key']] = (float)$r['setting_value']; }
            if (!empty($map)) {
                $defaults[0]['pct'] = isset($map['tier_1_9']) ? (float)$map['tier_1_9'] : $defaults[0]['pct'];
                $defaults[1]['pct'] = isset($map['tier_10_14']) ? (float)$map['tier_10_14'] : $defaults[1]['pct'];
                $defaults[2]['pct'] = isset($map['tier_15_19']) ? (float)$map['tier_15_19'] : $defaults[2]['pct'];
                $defaults[3]['pct'] = isset($map['tier_20_plus']) ? (float)$map['tier_20_plus'] : $defaults[3]['pct'];
            }
        } catch (Throwable $e) {}
        return $defaults;
    }
}

if (!function_exists('commission_get_agent_tiers')) {
    function commission_get_agent_tiers(PDO $pdo, int $agentId): array {
        $tiers = [];
        try {
            $st = $pdo->prepare('SELECT tier_min, tier_max, percentage FROM commission_agent_tiers WHERE user_id = ? ORDER BY tier_min');
            $st->execute([$agentId]);
            while ($r = $st->fetch()) {
                $tiers[] = ['min' => (int)$r['tier_min'], 'max' => isset($r['tier_max']) ? (int)$r['tier_max'] : null, 'pct' => (float)$r['percentage']];
            }
        } catch (Throwable $e) {}
        if (empty($tiers)) return commission_get_default_tiers($pdo);
        return $tiers;
    }
}

if (!function_exists('commission_resolve_percent')) {
    function commission_resolve_percent(array $tiers, int $qty): float {
        foreach ($tiers as $t) {
            $min = $t['min']; $max = $t['max'];
            if ($qty >= $min && ($max === null || $qty <= $max)) return (float)$t['pct'];
        }
        return 0.0;
    }
}

if (!function_exists('commission_get_setting_float')) {
    function commission_get_setting_float(PDO $pdo, string $key, float $fallback = 0.0): float {
        try {
            $st = $pdo->prepare('SELECT setting_value FROM commission_settings WHERE setting_key = ?');
            $st->execute([$key]);
            $val = $st->fetchColumn();
            if ($val !== false && $val !== null) return (float)$val;
        } catch (Throwable $e) {}
        return $fallback;
    }
}

if (!function_exists('commission_find_developer_user_id')) {
    function commission_find_developer_user_id(PDO $pdo): int {
        try {
            $st = $pdo->prepare("SELECT id FROM users WHERE email = ? OR (first_name = ? AND last_name = ?) LIMIT 1");
            $st->execute(['sample-005@example.com', 'Portfolio', 'Developer']);
            $id = $st->fetchColumn();
            if ($id) return (int)$id;
        } catch (Throwable $e) {}
        return 1; // fallback
    }
}

if (!function_exists('commission_find_ai_user_id')) {
    function commission_find_ai_user_id(PDO $pdo): int {
        // Resolve a stable AI user for commission attribution; create if missing
        try {
            $st = $pdo->prepare("SELECT id FROM users WHERE email IN ('sample-028@example.com','sample-029@example.com') ORDER BY id LIMIT 1");
            $st->execute();
            $id = $st->fetchColumn();
            if ($id) return (int)$id;
        } catch (Throwable $e) {}
        try {
            $ins = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, status, created_at) VALUES (?, ?, ?, ?, "active", NOW())');
            $ins->execute(['sample-029@example.com', password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), 'AI', 'Assistant']);
            $newId = (int)$pdo->lastInsertId();
            // Mark as customer role to satisfy generic FK/use; safe no-op if roles table differs
            try { $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, (SELECT id FROM roles WHERE name = "customer")) ON DUPLICATE KEY UPDATE role_id = role_id')->execute([$newId]); } catch (Throwable $e2) {}
            return $newId;
        } catch (Throwable $e) {
            // As a last resort, reuse developer id
            return commission_find_developer_user_id($pdo);
        }
    }
}

if (!function_exists('commission_should_incinerate')) {
    function commission_should_incinerate(array $commission): bool
    {
        $commissionStatus = strtolower((string)($commission['status'] ?? ''));
        $orderStatus = strtolower((string)($commission['order_status'] ?? ''));
        $paymentStatus = strtolower((string)($commission['payment_status'] ?? ''));

        if ($commissionStatus === 'paid') {
            return false;
        }
        if (in_array($orderStatus, ['paid', 'fulfilled', 'delivered'], true)) {
            return false;
        }
        if ($paymentStatus === 'paid') {
            return false;
        }

        return true;
    }
}

if (!function_exists('commission_get_product_group_tiers')) {
    function commission_get_product_group_tiers(PDO $pdo, int $productId): ?array {
        try {
            // Get the product's group ID
            $st = $pdo->prepare('SELECT product_group_id FROM products WHERE id = ?');
            $st->execute([$productId]);
            $groupId = $st->fetchColumn();
            
            if (!$groupId) return null;
            
            // Check if the group uses custom commissions
            $st = $pdo->prepare('SELECT use_custom_commission FROM product_groups WHERE id = ?');
            $st->execute([$groupId]);
            $useCustom = $st->fetchColumn();
            
            if (!$useCustom) return null;
            
            // Get the group's commission tiers
            $st = $pdo->prepare('SELECT quantity_min, quantity_max, commission_percent FROM product_group_commissions WHERE product_group_id = ? AND active = 1 ORDER BY quantity_min ASC');
            $st->execute([$groupId]);
            $tiers = [];
            while ($r = $st->fetch()) {
                $tiers[] = [
                    'min' => (int)$r['quantity_min'], 
                    'max' => isset($r['quantity_max']) ? (int)$r['quantity_max'] : null, 
                    'pct' => (float)$r['commission_percent']
                ];
            }
            return !empty($tiers) ? $tiers : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('commission_check_agent_ai_charging')) {
    /**
     * Check if agent should be charged for AI usage on this order
     * @param PDO $pdo
     * @param int $agentId
     * @param int $orderId
     * @return array ['calls' => bool, 'emails' => bool, 'sms' => bool]
     */
    function commission_check_agent_ai_charging(PDO $pdo, int $agentId, int $orderId): array {
        $default = ['calls' => false, 'emails' => false, 'sms' => false];
        if ($agentId <= 0) return $default;
        
        try {
            $st = $pdo->prepare('
                SELECT charge_for_ai_calls, charge_for_ai_emails, charge_for_ai_sms, 
                       enabled_start_date, enabled_end_date 
                FROM agent_ai_charging_settings 
                WHERE agent_id = ? AND is_active = 1
            ');
            $st->execute([$agentId]);
            $settings = $st->fetch();
            if (!$settings) return $default;
            
            // Check date range
            $now = date('Y-m-d');
            $start = $settings['enabled_start_date'];
            $end = $settings['enabled_end_date'];
            
            $inRange = true;
            if ($start && $now < $start) $inRange = false;
            if ($end && $now > $end) $inRange = false;
            
            if (!$inRange) return $default;
            
            return [
                'calls' => (bool)$settings['charge_for_ai_calls'],
                'emails' => (bool)$settings['charge_for_ai_emails'],
                'sms' => (bool)$settings['charge_for_ai_sms']
            ];
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('commission_calculate_ai_charges')) {
    /**
     * Calculate AI charges for an order based on usage logs
     * @param PDO $pdo
     * @param int $orderId
     * @return array ['total_cost' => float, 'calls' => int, 'emails' => int, 'sms' => int]
     */
    function commission_calculate_ai_charges(PDO $pdo, int $orderId): array {
        $result = ['total_cost' => 0.0, 'calls' => 0, 'emails' => 0, 'sms' => 0];
        
        try {
            $st = $pdo->prepare('
                SELECT usage_type, COUNT(*) as count, SUM(estimated_cost) as cost
                FROM agent_ai_usage_log
                WHERE order_id = ?
                GROUP BY usage_type
            ');
            $st->execute([$orderId]);
            
            while ($row = $st->fetch()) {
                $type = $row['usage_type'];
                $count = (int)$row['count'];
                $cost = (float)$row['cost'];
                
                $result['total_cost'] += $cost;
                if ($type === 'call') $result['calls'] = $count;
                elseif ($type === 'email') $result['emails'] = $count;
                elseif ($type === 'sms') $result['sms'] = $count;
            }
        } catch (Throwable $e) {
            error_log("AI charge calculation failed: " . $e->getMessage());
        }
        
        return $result;
    }
}

if (!function_exists('commission_compute_for_order')) {
    /**
     * Compute commissions for an order using cascading calculation
     * 
     * CASCADING STRUCTURE (Effective 2025-10-15):
     *   - Agent gets full commission from subtotal (line items only)
     *   - Dev commission calculated from (subtotal - agent_commission)
     *   - AI commission calculated from (subtotal - agent_commission - dev_commission)
     * 
     * CRITICAL: Commissions calculated ONLY from line items (products)
     *   - Shipping costs are EXCLUDED from all commission calculations
     *   - Tax is EXCLUDED from all commission calculations
     *   - Agent commission = sum of (line_total × commission_pct) per item
     *   - Shipping is customer-paid and never reduces agent payout
     */
    function commission_compute_for_order(PDO $pdo, int $orderId): void {
        $st = $pdo->prepare('SELECT id, sales_agent_id FROM orders WHERE id = ?');
        $st->execute([$orderId]);
        $order = $st->fetch();
        if (!$order) {
            error_log("Commission calculation: Order {$orderId} not found");
            return;
        }
        $agentId = (int)($order['sales_agent_id'] ?? 0);

        try { $pdo->prepare('DELETE FROM commissions WHERE order_id = ?')->execute([$orderId]); } catch (Throwable $e) {}
        try {
            $pdo->prepare('UPDATE order_items SET agent_commission_pct = NULL, agent_commission_amount = NULL, developer_commission_amount = NULL, ai_commission_amount = NULL WHERE order_id = ?')->execute([$orderId]);
        } catch (Throwable $e) {}

        $tiers = commission_get_agent_tiers($pdo, $agentId);
        $devPct = commission_get_setting_float($pdo, 'developer_commission', 0.0);
        $aiPct  = commission_get_setting_float($pdo, 'ai_assist_commission', 0.0);
        $developerUserId = commission_find_developer_user_id($pdo);
        
        // Track total agent commission for cascading calculation
        $totalAgentCommission = 0.0;
        $totalDevCommission = 0.0;

        $it = $pdo->prepare('SELECT id, product_id, quantity, unit_price FROM order_items WHERE order_id = ?');
        $it->execute([$orderId]);
        $itemCount = 0;
        $itemsFound = [];
        while ($row = $it->fetch()) {
            $itemCount++;
            $itemId = (int)$row['id'];
            $itemsFound[] = $itemId;
            error_log("Commission calculation: Processing order {$orderId}, item {$itemId}, product {$row['product_id']}, qty {$row['quantity']}");
            $productId = (int)$row['product_id'];
            $qty = (int)$row['quantity'];
            $unit = (float)$row['unit_price'];
            $lineSubtotal = $qty * $unit;

            // Try to get product group-specific commission tiers first
            $productGroupTiers = commission_get_product_group_tiers($pdo, $productId);
            $tiersToUse = $productGroupTiers ?? $tiers;
            
            // STEP 1: Agent commission from subtotal (agent always gets full commission)
            $agentPct = commission_resolve_percent($tiersToUse, $qty);
            $agentAmt = round($lineSubtotal * ($agentPct / 100), 2);
            
            // STEP 2: Dev commission from remaining after agent commission (cascading)
            $remainingAfterAgent = $lineSubtotal - $agentAmt;
            $devAmt = round($remainingAfterAgent * ($devPct / 100), 2);
            
            // STEP 3: AI commission from remaining after agent and dev (cascading)
            $remainingAfterDev = $remainingAfterAgent - $devAmt;
            $aiAmt = round($remainingAfterDev * ($aiPct / 100), 2);

            $totalAgentCommission += $agentAmt;
            $totalDevCommission += $devAmt;

            $pdo->prepare('UPDATE order_items SET agent_commission_pct = ?, agent_commission_amount = ?, developer_commission_amount = ?, ai_commission_amount = ? WHERE id = ?')
                ->execute([$agentPct, $agentAmt, $devAmt, $aiAmt, $itemId]);

            if ($agentAmt > 0 && $agentId > 0) {
                try {
                    $pdo->prepare("INSERT INTO commissions (order_id, order_item_id, user_id, commission_type, amount, percentage, calculation_basis, status) VALUES (?, ?, ?, 'sales_agent', ?, ?, 'subtotal', 'pending')")
                        ->execute([$orderId, $itemId, $agentId, $agentAmt, $agentPct]);
                    $commissionId = $pdo->lastInsertId();
                } catch (Throwable $e) {
                    error_log("Commission insert failed for order {$orderId}, item {$itemId}, agent {$agentId}: " . $e->getMessage());
                    throw $e;
                }
                
                // Generate journal entry for commission expense
                try {
                    require_once __DIR__ . '/expense-automation.php';
                    create_journal_entry_from_commission($commissionId);
                    
                    // Process loan repayment deductions
                    process_commission_loan_repayment($commissionId);
                } catch (Exception $e) {
                    error_log("Commission journal entry/loan processing failed: " . $e->getMessage());
                }
            }
            if ($devAmt > 0) {
                $pdo->prepare("INSERT INTO commissions (order_id, order_item_id, user_id, commission_type, amount, percentage, calculation_basis, status) VALUES (?, ?, ?, 'developer', ?, ?, 'post_agent_commission', 'pending')")
                    ->execute([$orderId, $itemId, $developerUserId, $devAmt, $devPct]);
                $commissionId = $pdo->lastInsertId();
                
                // Generate journal entry for commission expense
                try {
                    require_once __DIR__ . '/expense-automation.php';
                    create_journal_entry_from_commission($commissionId);
                } catch (Exception $e) {
                    error_log("Commission journal entry failed: " . $e->getMessage());
                }
            }
            if ($aiAmt > 0) {
                $aiUserId = commission_find_ai_user_id($pdo);
                $pdo->prepare("INSERT INTO commissions (order_id, order_item_id, user_id, commission_type, amount, percentage, calculation_basis, status) VALUES (?, ?, ?, 'ai_assist', ?, ?, 'post_dev_commission', 'pending')")
                    ->execute([$orderId, $itemId, $aiUserId, $aiAmt, $aiPct]);
                $commissionId = $pdo->lastInsertId();
                
                // Generate journal entry for commission expense
                try {
                    require_once __DIR__ . '/expense-automation.php';
                    create_journal_entry_from_commission($commissionId);
                } catch (Exception $e) {
                    error_log("Commission journal entry failed: " . $e->getMessage());
                }
            }
        }
        
        // Calculate and record AI usage charges if applicable
        if ($agentId > 0) {
            $aiCharging = commission_check_agent_ai_charging($pdo, $agentId, $orderId);
            $aiUsage = commission_calculate_ai_charges($pdo, $orderId);
            
            if ($aiUsage['total_cost'] > 0) {
                try {
                    // Determine if agent should be charged based on settings
                    $chargeAgent = ($aiCharging['calls'] && $aiUsage['calls'] > 0) ||
                                   ($aiCharging['emails'] && $aiUsage['emails'] > 0) ||
                                   ($aiCharging['sms'] && $aiUsage['sms'] > 0);
                    
                    // Record AI charges for this order
                    $pdo->prepare('
                        INSERT INTO order_ai_charges (order_id, agent_id, ai_call_count, ai_email_count, ai_sms_count, total_ai_cost, charged_to_agent)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            ai_call_count = VALUES(ai_call_count),
                            ai_email_count = VALUES(ai_email_count),
                            ai_sms_count = VALUES(ai_sms_count),
                            total_ai_cost = VALUES(total_ai_cost),
                            charged_to_agent = VALUES(charged_to_agent)
                    ')->execute([
                        $orderId, $agentId, 
                        $aiUsage['calls'], $aiUsage['emails'], $aiUsage['sms'],
                        $aiUsage['total_cost'], $chargeAgent ? 1 : 0
                    ]);
                    
                    // Update order with AI usage data
                    $pdo->prepare('
                        UPDATE orders 
                        SET ai_usage_charged = ?, ai_usage_cost = ?, 
                            ai_calls_used = ?, ai_emails_used = ?, ai_sms_used = ?
                        WHERE id = ?
                    ')->execute([
                        $chargeAgent ? 1 : 0, $aiUsage['total_cost'],
                        $aiUsage['calls'], $aiUsage['emails'], $aiUsage['sms'],
                        $orderId
                    ]);
                    
                    // If agent is charged, post to their books
                    if ($chargeAgent) {
                        require_once __DIR__ . '/expense-automation.php';
                        post_ai_charge_to_agent_books($agentId, $orderId, $aiUsage['total_cost']);
                    }
                } catch (Throwable $e) {
                    error_log("AI charge recording failed: " . $e->getMessage());
                }
            }
        }
        
        // Log summary for debugging
        if ($itemCount === 0) {
            error_log("Commission calculation warning: No order_items found for order {$orderId}");
        } else {
            $itemsList = implode(',', $itemsFound ?? []);
            error_log("Commission calculation complete for order {$orderId}: {$itemCount} items (IDs: {$itemsList}), agent commission \${$totalAgentCommission}, dev commission \${$totalDevCommission}");
        }
    }
}

if (!function_exists('commission_snapshot_for_commission')) {
    function commission_snapshot_for_commission(PDO $pdo, int $commissionId): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT order_id FROM commissions WHERE id = ?');
            $stmt->execute([$commissionId]);
            $orderId = (int)$stmt->fetchColumn();
            if (!$orderId) {
                return false;
            }
            require_once __DIR__ . '/admin_incinerator.php';
            incinerator_snapshot_table($pdo, 'orders', 'id', $orderId, [
                'id','order_number','user_id','wholesale_account_id','sales_agent_id','status','shipping_status','payment_status','payment_method','subtotal','tax','shipping','total','commission_rate','notes','created_at','updated_at',
                'order_items' => ['id','order_id','product_id','product_name','product_sku','quantity','unit_price','line_total']
            ]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('commission_handle_order_cancellation')) {
    function commission_handle_order_cancellation(PDO $pdo, int $orderId): array
    {
        $summary = ['archived' => 0, 'retained' => 0];

        $stmt = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return $summary;
        }

        $stmt = $pdo->prepare('SELECT c.*, o.status AS order_status, o.payment_status FROM commissions c LEFT JOIN orders o ON o.id = c.order_id WHERE c.order_id = ?');
        $stmt->execute([$orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return $summary;
        }

        foreach ($rows as $row) {
            commission_snapshot_for_commission($pdo, (int)$row['id']);
            if (commission_should_incinerate($row)) {
                $pdo->prepare('UPDATE commissions SET status = "cancelled", updated_at = NOW() WHERE id = ?')->execute([$row['id']]);
                $summary['archived']++;
            } else {
                $summary['retained']++;
            }
        }

        return $summary;
    }
}