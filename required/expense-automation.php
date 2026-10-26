<?php
/**
 * Expense Automation Engine
 * Core functions for automated journal entry generation and expense tracking
 * Integrates accounting with orders, commissions, manufacturing, and API usage
 */

require_once __DIR__ . '/config.php';

/**
 * Track API usage and batch cost tracking
 * @param string $service API service name (gemini, google_places, google_maps, sendgrid)
 * @param string $endpoint Specific endpoint called
 * @param float $estimatedCost Estimated cost per call
 * @param int|null $userId User ID if applicable
 * @param string|null $sessionId Session ID if applicable
 * @param array|null $metadata Additional context
 * @return bool Success status
 */
function track_api_usage($service, $endpoint, $estimatedCost, $userId = null, $sessionId = null, $metadata = null) {
    try {
        $pdo = db();
        
        $stmt = $pdo->prepare("
            INSERT INTO api_usage_tracking 
            (api_service, usage_date, endpoint, request_count, estimated_cost_per_call, total_cost, user_id, session_id, metadata)
            VALUES (?, NOW(), ?, 1, ?, ?, ?, ?, ?)
        ");
        
        $metadataJson = $metadata ? json_encode($metadata) : null;
        
        $stmt->execute([
            $service,
            $endpoint,
            $estimatedCost,
            $estimatedCost,
            $userId,
            $sessionId,
            $metadataJson
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("API Usage Tracking Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Create expense and journal entry for sample shipping
 * Enhanced to support agent charging
 * 
 * @param int $sampleRequestId Sample request ID
 * @param float $shippingCost Shipping cost amount
 * @param string $carrier Shipping carrier (UPS, USPS, FedEx)
 * @param string $trackingNumber Tracking number
 * @return int|null Expense ID on success, null on failure
 */
function create_sample_shipping_expense($sampleRequestId, $shippingCost, $carrier, $trackingNumber) {
    try {
        $pdo = db();
        
        // Get sample request details to check if agent should be charged
        $stmt = $pdo->prepare("
            SELECT agent_id, charge_agent_shipping, invoice_customer
            FROM sample_requests
            WHERE id = ?
        ");
        $stmt->execute([$sampleRequestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Sample request not found');
        }
        
        $agentId = (int)$request['agent_id'];
        $chargeAgent = (bool)$request['charge_agent_shipping'];
        $invoiceCustomer = (bool)$request['invoice_customer'];
        
        // Get expense category ID for Sample Shipping
        $stmt = $pdo->prepare("
            SELECT id FROM expense_categories 
            WHERE category_name = 'Sample Shipping' 
            LIMIT 1
        ");
        $stmt->execute();
        $categoryId = $stmt->fetchColumn();
        
        if (!$categoryId) {
            // Fallback: get account directly
            $stmt = $pdo->prepare("
                SELECT id FROM chart_of_accounts 
                WHERE account_code = '6150' 
                LIMIT 1
            ");
            $stmt->execute();
            $accountId = $stmt->fetchColumn();
            
            if (!$accountId) {
                throw new Exception('Shipping expense account not found');
            }
            
            // Create category
            $stmt = $pdo->prepare("
                INSERT INTO expense_categories (category_name, account_id, is_system, allocation_method)
                VALUES ('Sample Shipping', ?, 1, 'direct')
            ");
            $stmt->execute([$accountId]);
            $categoryId = $pdo->lastInsertId();
        }
        
        // Create expense record
        $stmt = $pdo->prepare("
            INSERT INTO expenses (
                expense_date, category_id, amount, vendor_name, 
                description, reference_type, reference_id, 
                payment_status, payment_method, created_at
            ) VALUES (
                NOW(), ?, ?, ?, ?, 'sample_request', ?, 'paid', 'business_account', NOW()
            )
        ");
        
        $description = "Sample shipping via {$carrier}" . ($trackingNumber ? " - Tracking: {$trackingNumber}" : "");
        
        $stmt->execute([
            $categoryId,
            $shippingCost,
            $carrier,
            $description,
            $sampleRequestId
        ]);
        
        $expenseId = (int)$pdo->lastInsertId();
        
        // Create journal entry for the shipping expense
        // Debit: Shipping Expense (6150)
        // Credit: Cash (1000) or Accounts Payable (2000) - using Cash for now
        
        // Get account IDs
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '6150' LIMIT 1");
        $stmt->execute();
        $shippingExpenseAccount = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1");
        $stmt->execute();
        $cashAccount = $stmt->fetchColumn();
        
        if ($shippingExpenseAccount && $cashAccount) {
            // Generate unique entry number
            $entryNumber = 'SHIP-' . str_pad($sampleRequestId, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd');
            
            // Create journal entry header
            $stmt = $pdo->prepare("
                INSERT INTO journal_entries (
                    entry_number, entry_date, entry_type, description,
                    reference_type, reference_id, fiscal_period, status,
                    is_auto_generated, created_by, created_at
                ) VALUES (?, NOW(), 'standard', ?, 'sample_request', ?, ?, 'posted', 1, 1, NOW())
            ");
            
            $stmt->execute([
                $entryNumber,
                "Sample shipping expense - {$carrier}",
                $sampleRequestId,
                date('Y-m')
            ]);
            
            $journalEntryId = (int)$pdo->lastInsertId();
            
            // Create journal entry lines
            // Line 1: Debit Shipping Expense
            $stmt = $pdo->prepare("
                INSERT INTO journal_entry_lines (
                    journal_entry_id, line_number, account_id, 
                    debit_amount, credit_amount, description
                ) VALUES (?, 1, ?, ?, 0, ?)
            ");
            $stmt->execute([
                $journalEntryId,
                $shippingExpenseAccount,
                $shippingCost,
                "Shipping cost - {$carrier}"
            ]);
            
            // Line 2: Credit Cash
            $stmt = $pdo->prepare("
                INSERT INTO journal_entry_lines (
                    journal_entry_id, line_number, account_id, 
                    debit_amount, credit_amount, description
                ) VALUES (?, 2, ?, 0, ?, ?)
            ");
            $stmt->execute([
                $journalEntryId,
                $cashAccount,
                $shippingCost,
                "Payment for sample shipping"
            ]);
            
            // Update expense with journal entry reference
            $stmt = $pdo->prepare("UPDATE expenses SET journal_entry_id = ? WHERE id = ?");
            $stmt->execute([$journalEntryId, $expenseId]);
            
            // Update sample request with journal entry reference
            $stmt = $pdo->prepare("UPDATE sample_requests SET journal_entry_id = ?, agent_charged_amount = ? WHERE id = ?");
            $stmt->execute([$journalEntryId, $chargeAgent ? $shippingCost : 0, $sampleRequestId]);
        }
        
        // If agent should be charged, create expense charge
        if ($chargeAgent && $agentId > 0) {
            require_once __DIR__ . '/agent-loan-utils.php';
            charge_agent_for_sample_shipping($pdo, $sampleRequestId, $agentId, $shippingCost);
        }
        
        // If customer should be invoiced, create invoice
        if ($invoiceCustomer) {
            create_sample_customer_invoice($sampleRequestId, $shippingCost);
        }
        
        return $expenseId;
        
    } catch (Exception $e) {
        error_log("Sample Shipping Expense Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Create customer invoice for sample request
 * 
 * @param int $sampleRequestId Sample request ID
 * @param float $shippingCost Shipping cost
 * @return int|null Invoice ID on success, null on failure
 */
function create_sample_customer_invoice($sampleRequestId, $shippingCost) {
    try {
        $pdo = db();
        
        // Get sample request details
        $stmt = $pdo->prepare("
            SELECT sr.*, p.name as product_name, p.total_production_cost
            FROM sample_requests sr
            JOIN products p ON p.id = sr.sample_product_id
            WHERE sr.id = ?
        ");
        $stmt->execute([$sampleRequestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            return null;
        }
        
        // Generate invoice number
        $invoiceNumber = 'SAMPLE-' . date('Y') . '-' . str_pad($sampleRequestId, 6, '0', STR_PAD_LEFT);
        
        // Calculate totals (sample cost + shipping)
        $productCost = (float)($request['total_production_cost'] ?? 0);
        $subtotal = $productCost + $shippingCost;
        $taxAmount = 0; // TODO: Calculate tax based on jurisdiction
        $total = $subtotal + $taxAmount;
        
        // Create invoice
        $stmt = $pdo->prepare("
            INSERT INTO invoices (
                invoice_number, invoice_date, due_date, customer_type, customer_name,
                customer_email, subtotal, tax_amount, shipping_amount, total_amount,
                payment_status, invoice_type, sample_request_id, invoice_category,
                notes, created_by
            ) VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 'other', ?, ?, ?, ?, ?, ?, 'unpaid', 'product_sale', ?, 'sample', ?, 1)
        ");
        
        $stmt->execute([
            $invoiceNumber,
            $request['recipient_name'],
            $request['recipient_email'] ?? '',
            $subtotal,
            $taxAmount,
            $shippingCost,
            $total,
            $sampleRequestId,
            'Sample invoice for ' . $request['product_name']
        ]);
        
        $invoiceId = (int)$pdo->lastInsertId();
        
        // Update sample request with invoice reference
        $stmt = $pdo->prepare("UPDATE sample_requests SET customer_invoice_id = ? WHERE id = ?");
        $stmt->execute([$invoiceId, $sampleRequestId]);
        
        return $invoiceId;
        
    } catch (Exception $e) {
        error_log("Sample Customer Invoice Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Process commission loan repayment
 * Called when a commission is created to automatically deduct loan repayments
 * 
 * @param int $commissionId Commission ID
 * @return array Summary of repayments applied
 */
function process_commission_loan_repayment($commissionId) {
    $summary = ['total_deducted' => 0.0, 'repayments' => []];
    
    try {
        $pdo = db();
        
        // Get commission details
        $stmt = $pdo->prepare("
            SELECT c.*, o.sales_agent_id, l.id as lead_id
            FROM commissions c
            JOIN orders o ON o.id = c.order_id
            LEFT JOIN crm_leads l ON l.converted_to_account_id = o.wholesale_account_id
            WHERE c.id = ?
        ");
        $stmt->execute([$commissionId]);
        $commission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$commission || $commission['commission_type'] !== 'sales_agent') {
            return $summary; // Only process sales agent commissions
        }
        
        $agentId = (int)$commission['sales_agent_id'];
        $orderId = (int)$commission['order_id'];
        $leadId = isset($commission['lead_id']) ? (int)$commission['lead_id'] : null;
        $grossCommission = (float)$commission['amount'];
        
        // Calculate loan deductions
        require_once __DIR__ . '/agent-loan-utils.php';
        $deductionResult = calculate_loan_deduction($pdo, $agentId, $grossCommission, $orderId, $leadId);
        
        $totalDeduction = $deductionResult['deduction'];
        $allocations = $deductionResult['loan_allocations'];
        
        if ($totalDeduction > 0) {
            // Apply each loan repayment
            foreach ($allocations as $allocation) {
                $transactionId = apply_loan_repayment(
                    $pdo,
                    $allocation['loan_id'],
                    $agentId,
                    $allocation['amount'],
                    'commission_deduction',
                    $commissionId,
                    $orderId,
                    "Automatic deduction from commission #{$commissionId} using {$allocation['method']} method"
                );
                
                if ($transactionId) {
                    $summary['repayments'][] = [
                        'loan_id' => $allocation['loan_id'],
                        'amount' => $allocation['amount'],
                        'transaction_id' => $transactionId
                    ];
                }
            }
            
            // Update commission with deduction info
            $netCommission = $grossCommission - $totalDeduction;
            $stmt = $pdo->prepare("
                UPDATE commissions 
                SET loan_repayment_deduction = ?, net_commission_after_loans = ?
                WHERE id = ?
            ");
            $stmt->execute([$totalDeduction, $netCommission, $commissionId]);
            
            $summary['total_deducted'] = $totalDeduction;
        }
        
        return $summary;
        
    } catch (Exception $e) {
        error_log("Commission Loan Repayment Error: " . $e->getMessage());
        return $summary;
    }
}

/**
 * Create journal entry from order (revenue recognition)
 * @param int $orderId Order ID
 * @param string $method Accounting method: 'accrual' or 'cash'
 * @return int|false Journal entry ID or false on failure
 */
function create_journal_entry_from_order($orderId, $method = 'accrual') {
    try {
        $pdo = db();
        
        // Only start a transaction if not already in one (to avoid nested transaction errors)
        $startedTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }
        
        // Get order details
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        // Skip if already has journal entry
        if ($order['journal_entry_id']) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return $order['journal_entry_id'];
        }
        
        // For cash basis, only create entry if paid
        if ($method === 'cash' && $order['payment_status'] !== 'paid') {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return false;
        }
        
        // Generate entry number
        $entryNumber = 'JE-' . date('Y') . '-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
        
        // Get current user or use system
        $userId = current_user_id() ?: 1;
        
        // Create journal entry
        $stmt = $pdo->prepare("
            INSERT INTO journal_entries 
            (entry_number, entry_date, entry_type, description, reference_type, reference_id, fiscal_period, status, is_auto_generated, created_by)
            VALUES (?, ?, 'standard', ?, 'order', ?, ?, 'posted', 1, ?)
        ");
        
        $entryDate = $method === 'cash' && $order['payment_date'] ? $order['payment_date'] : $order['created_at'];
        $fiscalPeriod = date('Y-m', strtotime($entryDate));
        $description = "Revenue from Order {$order['order_number']}";
        
        $stmt->execute([$entryNumber, $entryDate, $description, $orderId, $fiscalPeriod, $userId]);
        $journalEntryId = $pdo->lastInsertId();
        
        // Get account IDs
        $arAccount = get_account_by_code('1120'); // Accounts Receivable
        $salesAccount = get_account_by_code('4110'); // Wholesale Sales (default)
        
        // Debit: Accounts Receivable (Asset increases)
        $stmt = $pdo->prepare("
            INSERT INTO journal_entry_lines 
            (journal_entry_id, line_number, account_id, debit_amount, credit_amount, description)
            VALUES (?, 1, ?, ?, 0, ?)
        ");
        $stmt->execute([$journalEntryId, $arAccount['id'], $order['total'], 'Accounts Receivable']);
        
        // Credit: Sales Revenue (Revenue increases)
        $stmt = $pdo->prepare("
            INSERT INTO journal_entry_lines 
            (journal_entry_id, line_number, account_id, debit_amount, credit_amount, description)
            VALUES (?, 2, ?, 0, ?, ?)
        ");
        $stmt->execute([$journalEntryId, $salesAccount['id'], $order['total'], 'Product Sales Revenue']);
        
        // Update order with journal entry ID
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET journal_entry_id = ?, revenue_recognition_date = ?, accounting_status = 'posted'
            WHERE id = ?
        ");
        $stmt->execute([$journalEntryId, $entryDate, $orderId]);
        
        if ($startedTransaction) {
            $pdo->commit();
        }
        return $journalEntryId;
        
    } catch (Exception $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Journal Entry from Order Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Create journal entry from commission
 * @param int $commissionId Commission ID
 * @return int|false Journal entry ID or false on failure
 */
function create_journal_entry_from_commission($commissionId) {
    try {
        $pdo = db();
        
        // Only start a transaction if not already in one (to avoid nested transaction errors)
        $startedTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }
        
        // Get commission details
        $stmt = $pdo->prepare("SELECT * FROM commissions WHERE id = ?");
        $stmt->execute([$commissionId]);
        $commission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$commission) {
            throw new Exception("Commission not found");
        }
        
        // Skip if already has journal entry
        if ($commission['journal_entry_id']) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return $commission['journal_entry_id'];
        }
        
        // Generate entry number
        $entryNumber = 'JE-COMM-' . date('Y') . '-' . str_pad($commissionId, 6, '0', STR_PAD_LEFT);
        
        $userId = current_user_id() ?: 1;
        
        // Create journal entry
        $stmt = $pdo->prepare("
            INSERT INTO journal_entries 
            (entry_number, entry_date, entry_type, description, reference_type, reference_id, fiscal_period, status, is_auto_generated, created_by)
            VALUES (?, ?, 'standard', ?, 'commission', ?, ?, 'posted', 1, ?)
        ");
        
        $entryDate = $commission['created_at'];
        $fiscalPeriod = date('Y-m', strtotime($entryDate));
        $description = "Commission Expense - {$commission['commission_type']}";
        
        $stmt->execute([$entryNumber, $entryDate, $description, $commissionId, $fiscalPeriod, $userId]);
        $journalEntryId = $pdo->lastInsertId();
        
        // Get account IDs based on commission type
        $expenseAccount = null;
        if ($commission['commission_type'] === 'sales_agent') {
            $expenseAccount = get_account_by_code('6110'); // Sales Agent Commissions
        } elseif ($commission['commission_type'] === 'developer') {
            $expenseAccount = get_account_by_code('6120'); // Developer Commissions
        } else {
            $expenseAccount = get_account_by_code('6100'); // General Commission Expense
        }
        
        $payableAccount = get_account_by_code('2120'); // Commission Payable
        
        // Debit: Commission Expense (Expense increases)
        $stmt = $pdo->prepare("
            INSERT INTO journal_entry_lines 
            (journal_entry_id, line_number, account_id, debit_amount, credit_amount, description)
            VALUES (?, 1, ?, ?, 0, ?)
        ");
        $stmt->execute([$journalEntryId, $expenseAccount['id'], $commission['amount'], 'Commission Expense']);
        
        // Credit: Commission Payable (Liability increases)
        $stmt = $pdo->prepare("
            INSERT INTO journal_entry_lines 
            (journal_entry_id, line_number, account_id, debit_amount, credit_amount, description)
            VALUES (?, 2, ?, 0, ?, ?)
        ");
        $stmt->execute([$journalEntryId, $payableAccount['id'], $commission['amount'], 'Commission Payable']);
        
        // Update commission with journal entry ID
        $stmt = $pdo->prepare("
            UPDATE commissions 
            SET journal_entry_id = ?, expense_recognition_date = ?, accounting_status = 'posted'
            WHERE id = ?
        ");
        $stmt->execute([$journalEntryId, $entryDate, $commissionId]);
        
        if ($startedTransaction) {
            $pdo->commit();
        }
        return $journalEntryId;
        
    } catch (Exception $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Journal Entry from Commission Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Create journal entry from work order (COGS recognition)
 * @param int $workOrderId Work Order ID
 * @return int|false Journal entry ID or false on failure
 */
function create_journal_entry_from_work_order($workOrderId) {
    try {
        $pdo = db();
        
        // Only start a transaction if not already in one (to avoid nested transaction errors)
        $startedTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }
        
        // Get work order details
        $stmt = $pdo->prepare("
            SELECT wo.*, 
                   COALESCE(wo.actual_ingredient_cost, wo.projected_ingredient_cost, 0) as ingredient_cost,
                   COALESCE(wo.actual_packaging_cost, wo.projected_packaging_cost, 0) as packaging_cost,
                   COALESCE(wo.actual_material_cost, wo.projected_material_cost, 0) as material_cost
            FROM work_orders wo
            WHERE wo.id = ?
        ");
        $stmt->execute([$workOrderId]);
        $workOrder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$workOrder) {
            throw new Exception("Work order not found");
        }
        
        // Only create for completed work orders
        if ($workOrder['status'] !== 'completed') {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return false;
        }
        
        // Generate entry number
        $entryNumber = 'JE-WO-' . date('Y') . '-' . str_pad($workOrderId, 6, '0', STR_PAD_LEFT);
        
        $userId = current_user_id() ?: 1;
        
        // Create journal entry
        $stmt = $pdo->prepare("
            INSERT INTO journal_entries 
            (entry_number, entry_date, entry_type, description, reference_type, reference_id, fiscal_period, status, is_auto_generated, created_by)
            VALUES (?, ?, 'standard', ?, 'work_order', ?, ?, 'posted', 1, ?)
        ");
        
        $entryDate = $workOrder['completion_date'] ?: $workOrder['created_at'];
        $fiscalPeriod = date('Y-m', strtotime($entryDate));
        $description = "COGS from Work Order {$workOrder['wo_number']}";
        
        $stmt->execute([$entryNumber, $entryDate, $description, $workOrderId, $fiscalPeriod, $userId]);
        $journalEntryId = $pdo->lastInsertId();
        
        // Get account IDs
        $inventoryAccount = get_account_by_code('1150'); // Finished Goods Inventory
        $ingredientAccount = get_account_by_code('5100'); // Ingredient Costs
        $packagingAccount = get_account_by_code('5200'); // Packaging Costs
        $materialAccount = get_account_by_code('5300'); // Materials Costs
        
        $totalCost = $workOrder['ingredient_cost'] + $workOrder['packaging_cost'] + $workOrder['material_cost'];
        $lineNumber = 1;
        
        // Debit: Finished Goods Inventory (Asset increases)
        $stmt = $pdo->prepare("
            INSERT INTO journal_entry_lines 
            (journal_entry_id, line_number, account_id, debit_amount, credit_amount, description)
            VALUES (?, ?, ?, ?, 0, ?)
        ");
        $stmt->execute([$journalEntryId, $lineNumber++, $inventoryAccount['id'], $totalCost, 'Finished Goods']);
        
        // Credit: Ingredient Costs (Reduce COGS/Inventory)
        if ($workOrder['ingredient_cost'] > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO journal_entry_lines 
                (journal_entry_id, line_number, account_id, debit_amount, credit_amount, description)
                VALUES (?, ?, ?, 0, ?, ?)
            ");
            $stmt->execute([$journalEntryId, $lineNumber++, $ingredientAccount['id'], $workOrder['ingredient_cost'], 'Ingredients Used']);
        }
        
        // Credit: Packaging Costs
        if ($workOrder['packaging_cost'] > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO journal_entry_lines 
                (journal_entry_id, line_number, account_id, debit_amount, credit_amount, description)
                VALUES (?, ?, ?, 0, ?, ?)
            ");
            $stmt->execute([$journalEntryId, $lineNumber++, $packagingAccount['id'], $workOrder['packaging_cost'], 'Packaging Used']);
        }
        
        // Credit: Materials Costs
        if ($workOrder['material_cost'] > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO journal_entry_lines 
                (journal_entry_id, line_number, account_id, debit_amount, credit_amount, description)
                VALUES (?, ?, ?, 0, ?, ?)
            ");
            $stmt->execute([$journalEntryId, $lineNumber++, $materialAccount['id'], $workOrder['material_cost'], 'Materials Used']);
        }
        
        // Update work order
        $stmt = $pdo->prepare("
            UPDATE work_orders 
            SET accounting_status = 'posted'
            WHERE id = ?
        ");
        $stmt->execute([$workOrderId]);
        
        if ($startedTransaction) {
            $pdo->commit();
        }
        return $journalEntryId;
        
    } catch (Exception $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Journal Entry from Work Order Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Process recurring subscriptions (called by cron)
 * @return array Results summary
 */
function process_recurring_subscriptions() {
    try {
        $pdo = db();
        $today = date('Y-m-d');
        $results = ['processed' => 0, 'errors' => 0];
        
        // Get subscriptions due today
        $stmt = $pdo->prepare("
            SELECT * FROM subscription_expenses
            WHERE status = 'active' 
            AND next_billing_date <= ?
            AND auto_renew = 1
        ");
        $stmt->execute([$today]);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($subscriptions as $sub) {
            try {
                // Create expense entry
                $stmt = $pdo->prepare("
                    INSERT INTO expenses 
                    (expense_date, expense_category_id, amount, vendor_name, description, reference_type, reference_id, is_recurring, payment_status, created_by)
                    VALUES (?, ?, ?, ?, ?, 'subscription', ?, 1, 'unpaid', 1)
                ");
                
                $description = "Subscription: {$sub['subscription_name']} - {$sub['billing_cycle']}";
                $stmt->execute([
                    $today,
                    $sub['category_id'],
                    $sub['amount'],
                    $sub['vendor_name'],
                    $description,
                    $sub['id']
                ]);
                
                // Update next billing date
                $nextDate = null;
                switch ($sub['billing_cycle']) {
                    case 'monthly':
                        $nextDate = date('Y-m-d', strtotime($sub['next_billing_date'] . ' +1 month'));
                        break;
                    case 'quarterly':
                        $nextDate = date('Y-m-d', strtotime($sub['next_billing_date'] . ' +3 months'));
                        break;
                    case 'annual':
                        $nextDate = date('Y-m-d', strtotime($sub['next_billing_date'] . ' +1 year'));
                        break;
                }
                
                if ($nextDate) {
                    $stmt = $pdo->prepare("UPDATE subscription_expenses SET next_billing_date = ? WHERE id = ?");
                    $stmt->execute([$nextDate, $sub['id']]);
                }
                
                $results['processed']++;
                
            } catch (Exception $e) {
                error_log("Subscription Processing Error (ID {$sub['id']}): " . $e->getMessage());
                $results['errors']++;
            }
        }
        
        return $results;
        
    } catch (Exception $e) {
        error_log("Process Recurring Subscriptions Error: " . $e->getMessage());
        return ['processed' => 0, 'errors' => 1, 'message' => $e->getMessage()];
    }
}

/**
 * Allocate lab test expense to batch code
 * @param int $expenseId Expense ID
 * @param string $batchCode Batch code
 * @param float $percentage Allocation percentage (default 100%)
 * @return bool Success status
 */
function allocate_lab_test_expense($expenseId, $batchCode, $percentage = 100.0) {
    try {
        $pdo = db();
        
        // Get expense amount
        $stmt = $pdo->prepare("SELECT amount FROM expenses WHERE id = ?");
        $stmt->execute([$expenseId]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$expense) {
            throw new Exception("Expense not found");
        }
        
        $allocatedAmount = $expense['amount'] * ($percentage / 100);
        
        // Create allocation
        $stmt = $pdo->prepare("
            INSERT INTO expense_allocations 
            (expense_id, allocation_type, target_id, allocated_amount, allocation_percentage, allocation_notes)
            VALUES (?, 'batch', ?, ?, ?, ?)
        ");
        
        $notes = "Lab test expense allocated to batch {$batchCode}";
        $stmt->execute([$expenseId, $batchCode, $allocatedAmount, $percentage, $notes]);
        
        // Link to work order if exists
        $stmt = $pdo->prepare("SELECT id FROM work_orders WHERE batch_code = ?");
        $stmt->execute([$batchCode]);
        $workOrder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($workOrder) {
            $stmt = $pdo->prepare("UPDATE work_orders SET lab_test_expense_id = ? WHERE id = ?");
            $stmt->execute([$expenseId, $workOrder['id']]);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Allocate Lab Test Expense Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Calculate overhead allocation across production
 * @param string $period Fiscal period (YYYY-MM)
 * @return array Allocation results
 */
function calculate_overhead_allocation($period) {
    // Placeholder for future enhancement
    // Will distribute overhead costs across all production for the period
    return ['status' => 'not_implemented', 'message' => 'Overhead allocation coming soon'];
}

/**
 * Get account by code helper function
 * @param string $code Account code
 * @return array|false Account data or false
 */
function get_account_by_code($code) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get Account by Code Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Aggregate API usage costs and create expense entries
 * @param string $date Date to aggregate (default: yesterday)
 * @return array Results summary
 */
function aggregate_api_usage_costs($date = null) {
    try {
        $pdo = db();
        $date = $date ?: date('Y-m-d', strtotime('-1 day'));
        $results = ['services' => [], 'total_cost' => 0, 'expenses_created' => 0];
        
        // Get API usage by service for the date
        $stmt = $pdo->prepare("
            SELECT api_service, 
                   SUM(request_count) as total_requests,
                   SUM(total_cost) as total_cost
            FROM api_usage_tracking
            WHERE DATE(usage_date) = ? 
            AND expense_created = 0
            GROUP BY api_service
            HAVING total_cost > 0.01
        ");
        $stmt->execute([$date]);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($services as $service) {
            // Get API Usage category
            $categoryStmt = $pdo->prepare("SELECT id FROM expense_categories WHERE category_name = 'API Usage'");
            $categoryStmt->execute();
            $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$category) continue;
            
            // Create expense
            $expenseStmt = $pdo->prepare("
                INSERT INTO expenses 
                (expense_date, expense_category_id, amount, vendor_name, description, reference_type, payment_status, created_by)
                VALUES (?, ?, ?, ?, ?, 'api_call', 'unpaid', 1)
            ");
            
            $vendor = ucfirst(str_replace('_', ' ', $service['api_service']));
            $description = "{$service['total_requests']} API calls - {$vendor}";
            
            $expenseStmt->execute([
                $date,
                $category['id'],
                $service['total_cost'],
                $vendor,
                $description
            ]);
            
            $expenseId = $pdo->lastInsertId();
            
            // Mark API usage as expense created
            $updateStmt = $pdo->prepare("
                UPDATE api_usage_tracking 
                SET expense_created = 1, expense_id = ?
                WHERE DATE(usage_date) = ? AND api_service = ? AND expense_created = 0
            ");
            $updateStmt->execute([$expenseId, $date, $service['api_service']]);
            
            $results['services'][] = $service;
            $results['total_cost'] += $service['total_cost'];
            $results['expenses_created']++;
        }
        
        return $results;
        
    } catch (Exception $e) {
        error_log("Aggregate API Usage Costs Error: " . $e->getMessage());
        return ['services' => [], 'total_cost' => 0, 'expenses_created' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Post AI usage charge to agent's books
 * Creates a debit entry in the agent's personal account
 * 
 * @param int $agentId Agent user ID
 * @param int $orderId Order ID that incurred the AI charges
 * @param float $amount Total AI charge amount
 * @return bool Success status
 */
function post_ai_charge_to_agent_books($agentId, $amount, $orderId = null) {
    try {
        if ($amount <= 0) return false;
        
        $pdo = db();
        
        // Get agent details
        $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$agent) {
            throw new Exception('Agent not found');
        }
        
        $agentName = trim($agent['first_name'] . ' ' . $agent['last_name']);
        
        // Get AI Usage Expense account (6180)
        $stmt = $pdo->prepare("
            SELECT id FROM chart_of_accounts 
            WHERE account_code = '6180' 
            LIMIT 1
        ");
        $stmt->execute();
        $aiExpenseAccountId = $stmt->fetchColumn();
        
        if (!$aiExpenseAccountId) {
            throw new Exception('AI Usage Expense account (6180) not found');
        }
        
        // Get Agent Accounts Receivable account (1200)
        $stmt = $pdo->prepare("
            SELECT id FROM chart_of_accounts 
            WHERE account_code = '1200' 
            LIMIT 1
        ");
        $stmt->execute();
        $agentReceivableAccountId = $stmt->fetchColumn();
        
        if (!$agentReceivableAccountId) {
            throw new Exception('Agent Accounts Receivable account (1200) not found');
        }
        
        // Create journal entry
        $orderRef = $orderId ? " - Order #$orderId" : '';
        $description = "AI Usage Charge - $agentName$orderRef";
        
        $stmt = $pdo->prepare("
            INSERT INTO journal_entries (entry_date, description, entry_type, reference_type, reference_id, created_by)
            VALUES (NOW(), ?, 'ai_charge', 'order', ?, 1)
        ");
        $stmt->execute([$description, $orderId]);
        $journalId = $pdo->lastInsertId();
        
        // Debit: AI Usage Expense (increase expense)
        $stmt = $pdo->prepare("
            INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, entity_type, entity_id)
            VALUES (?, ?, ?, 0.00, ?, 'agent', ?)
        ");
        $stmt->execute([$journalId, $aiExpenseAccountId, $amount, "AI usage charged to $agentName", $agentId]);
        
        // Credit: Agent Accounts Receivable (agent owes this amount)
        $stmt = $pdo->prepare("
            INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, entity_type, entity_id)
            VALUES (?, ?, 0.00, ?, ?, 'agent', ?)
        ");
        $stmt->execute([$journalId, $agentReceivableAccountId, $amount, "AI charges owed by $agentName", $agentId]);
        
        // Mark as posted to books in order_ai_charges
        if ($orderId) {
            $pdo->prepare("UPDATE order_ai_charges SET posted_to_books = 1 WHERE order_id = ?")->execute([$orderId]);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Post AI Charge to Agent Books Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update track_api_usage to use accurate costs from ai_usage_costs table
 * Enhanced version that links to cost management system
 * 
 * IMPORTANT: Usage types clarification:
 *   'call'  = AI voice agents making actual phone calls (future: api/ai-phone-agent.php)
 *   'email' = AI email composition (active: api/ai-email-composer.php)
 *   'sms'   = AI text messaging agents (future: api/ai-sms-agent.php)
 * 
 * NOTE: The AI chat support tool (api/ai-chat.php) uses track_api_usage() instead
 *       and does NOT track to agent_ai_usage_log - it's a free support tool
 */
function track_api_usage_enhanced($service, $endpoint, $userId = null, $sessionId = null, $metadata = null, $orderId = null, $leadId = null) {
    try {
        $pdo = db();
        
        // Get accurate cost from ai_usage_costs table
        $stmt = $pdo->prepare("
            SELECT cost_per_unit 
            FROM ai_usage_costs 
            WHERE api_service = ? AND (endpoint = ? OR endpoint IS NULL) AND is_active = 1
            ORDER BY endpoint IS NOT NULL DESC
            LIMIT 1
        ");
        $stmt->execute([$service, $endpoint]);
        $cost = $stmt->fetchColumn();
        
        // Fallback to default if not found
        if ($cost === false) {
            $cost = 0.001; // Default minimal cost
        }
        
        $cost = (float)$cost;
        
        // Determine usage type from endpoint
        // 'call' = AI phone voice agents, 'email' = email generation, 'sms' = AI text agents
        $usageType = 'call';
        if (stripos($endpoint, 'email') !== false || stripos($service, 'sendgrid') !== false) {
            $usageType = 'email';
        } elseif (stripos($endpoint, 'sms') !== false || stripos($service, 'twilio') !== false) {
            $usageType = 'sms';
        }
        
        // Log to agent_ai_usage_log if user is an agent
        if ($userId) {
            $stmt = $pdo->prepare("
                SELECT 1 FROM user_roles ur 
                JOIN roles r ON r.id = ur.role_id 
                WHERE ur.user_id = ? AND r.name = 'sales_agent'
            ");
            $stmt->execute([$userId]);
            $isAgent = (bool)$stmt->fetch();
            
            if ($isAgent) {
                $stmt = $pdo->prepare("
                    INSERT INTO agent_ai_usage_log 
                    (agent_id, usage_type, usage_date, api_service, endpoint, estimated_cost, order_id, lead_id, session_id, metadata)
                    VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId, $usageType, $service, $endpoint, $cost,
                    $orderId, $leadId, $sessionId, 
                    $metadata ? json_encode($metadata) : null
                ]);
            }
        }
        
        // Also log to api_usage_tracking (existing system)
        $stmt = $pdo->prepare("
            INSERT INTO api_usage_tracking 
            (api_service, usage_date, endpoint, request_count, estimated_cost_per_call, total_cost, user_id, session_id, metadata)
            VALUES (?, NOW(), ?, 1, ?, ?, ?, ?, ?)
        ");
        
        $metadataJson = $metadata ? json_encode($metadata) : null;
        
        $stmt->execute([
            $service, $endpoint, $cost, $cost, $userId, $sessionId, $metadataJson
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Enhanced API Usage Tracking Error: " . $e->getMessage());
        return false;
    }
}

