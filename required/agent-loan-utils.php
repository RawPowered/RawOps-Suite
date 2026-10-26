<?php
/**
 * Sales Agent Loan & Repayment Utilities
 * Functions for managing agent loans, calculating repayments, and processing deductions
 */

if (!function_exists('get_agent_repayment_settings')) {
    /**
     * Get repayment settings for an agent (either for specific loan or default)
     * 
     * @param PDO $pdo Database connection
     * @param int $agentId Agent user ID
     * @param int|null $loanId Specific loan ID (null for default settings)
     * @return array|null Repayment settings or null
     */
    function get_agent_repayment_settings(PDO $pdo, int $agentId, ?int $loanId = null): ?array {
        try {
            $stmt = $pdo->prepare('
                SELECT * FROM agent_repayment_settings 
                WHERE agent_id = ? AND loan_id <=> ? AND is_active = 1
                ORDER BY created_at DESC
                LIMIT 1
            ');
            $stmt->execute([$agentId, $loanId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $settings ?: null;
        } catch (Throwable $e) {
            error_log("Error fetching agent repayment settings: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('get_agent_active_loans')) {
    /**
     * Get all active loans for an agent
     * 
     * @param PDO $pdo Database connection
     * @param int $agentId Agent user ID
     * @return array Array of active loan records
     */
    function get_agent_active_loans(PDO $pdo, int $agentId): array {
        try {
            $stmt = $pdo->prepare('
                SELECT * FROM sales_agent_loans 
                WHERE agent_id = ? AND status = "active" AND balance_remaining > 0
                ORDER BY loan_date ASC
            ');
            $stmt->execute([$agentId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("Error fetching active loans: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('calculate_loan_deduction')) {
    /**
     * Calculate how much to deduct from commission based on repayment settings
     * 
     * @param PDO $pdo Database connection
     * @param int $agentId Agent user ID
     * @param float $commissionAmount Gross commission amount
     * @param int $orderId Order ID for context
     * @param int|null $leadId Lead ID if applicable
     * @return array ['deduction' => float, 'loan_allocations' => array]
     */
    function calculate_loan_deduction(PDO $pdo, int $agentId, float $commissionAmount, int $orderId, ?int $leadId = null): array {
        $totalDeduction = 0.0;
        $allocations = [];
        
        try {
            $activeLoans = get_agent_active_loans($pdo, $agentId);
            
            if (empty($activeLoans)) {
                return ['deduction' => 0.0, 'loan_allocations' => []];
            }
            
            foreach ($activeLoans as $loan) {
                $loanId = (int)$loan['id'];
                $balanceRemaining = (float)$loan['balance_remaining'];
                
                if ($balanceRemaining <= 0) {
                    continue;
                }
                
                // Get repayment settings for this specific loan
                $settings = get_agent_repayment_settings($pdo, $agentId, $loanId);
                
                // Fallback to default settings if none exist for this loan
                if (!$settings) {
                    $settings = get_agent_repayment_settings($pdo, $agentId, null);
                }
                
                if (!$settings) {
                    continue; // Skip if no settings found
                }
                
                $method = $settings['repayment_method'];
                $deductionAmount = 0.0;
                
                switch ($method) {
                    case 'percentage_per_commission':
                        $percentage = (float)($settings['percentage_rate'] ?? 20.0);
                        $deductionAmount = $commissionAmount * ($percentage / 100);
                        break;
                        
                    case 'first_sale_full':
                        // Check if this order is from a lead that received a sample linked to this loan
                        if ($leadId && $loan['loan_type'] === 'sample_expense') {
                            $stmt = $pdo->prepare('
                                SELECT sr.id, sr.agent_charged_amount
                                FROM sample_requests sr
                                LEFT JOIN agent_expense_charges aec ON aec.sample_request_id = sr.id
                                WHERE sr.lead_id = ? AND sr.agent_id = ? 
                                AND (aec.related_loan_id = ? OR sr.agent_charged_amount > 0)
                                AND NOT EXISTS (
                                    SELECT 1 FROM loan_repayment_transactions lrt
                                    WHERE lrt.loan_id = ? AND lrt.source_order_id = ?
                                )
                                LIMIT 1
                            ');
                            $stmt->execute([$leadId, $agentId, $loanId, $loanId, $orderId]);
                            $sampleRequest = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($sampleRequest) {
                                $deductionAmount = min((float)$sampleRequest['agent_charged_amount'], $balanceRemaining, $commissionAmount);
                            }
                        }
                        break;
                        
                    case 'upfront':
                        // No automatic deduction - agent pays upfront
                        $deductionAmount = 0.0;
                        break;
                        
                    case 'hybrid':
                        // Custom logic - for now treat as percentage
                        $percentage = (float)($settings['percentage_rate'] ?? 15.0);
                        $deductionAmount = $commissionAmount * ($percentage / 100);
                        break;
                }
                
                // Don't deduct more than balance remaining or commission available
                $deductionAmount = min($deductionAmount, $balanceRemaining, $commissionAmount - $totalDeduction);
                
                if ($deductionAmount > 0) {
                    $totalDeduction += $deductionAmount;
                    $allocations[] = [
                        'loan_id' => $loanId,
                        'amount' => $deductionAmount,
                        'balance_before' => $balanceRemaining,
                        'method' => $method
                    ];
                }
                
                // Stop if we've deducted all commission
                if ($totalDeduction >= $commissionAmount) {
                    break;
                }
            }
            
            return [
                'deduction' => $totalDeduction,
                'loan_allocations' => $allocations
            ];
            
        } catch (Throwable $e) {
            error_log("Error calculating loan deduction: " . $e->getMessage());
            return ['deduction' => 0.0, 'loan_allocations' => []];
        }
    }
}

if (!function_exists('apply_loan_repayment')) {
    /**
     * Apply a loan repayment transaction
     * 
     * @param PDO $pdo Database connection
     * @param int $loanId Loan ID
     * @param int $agentId Agent user ID
     * @param float $amount Repayment amount
     * @param string $type Repayment type
     * @param int|null $commissionId Related commission ID
     * @param int|null $orderId Related order ID
     * @param string|null $notes Additional notes
     * @return int|null Transaction ID on success, null on failure
     */
    function apply_loan_repayment(
        PDO $pdo, 
        int $loanId, 
        int $agentId, 
        float $amount, 
        string $type = 'commission_deduction',
        ?int $commissionId = null,
        ?int $orderId = null,
        ?string $notes = null
    ): ?int {
        try {
            $pdo->beginTransaction();
            
            // Get current loan balance
            $stmt = $pdo->prepare('SELECT balance_remaining FROM sales_agent_loans WHERE id = ? FOR UPDATE');
            $stmt->execute([$loanId]);
            $balance = (float)$stmt->fetchColumn();
            
            if ($balance <= 0) {
                $pdo->rollBack();
                return null; // Loan already paid
            }
            
            // Don't repay more than balance
            $amount = min($amount, $balance);
            
            // Create repayment transaction
            $stmt = $pdo->prepare('
                INSERT INTO loan_repayment_transactions (
                    loan_id, agent_id, amount, repayment_type,
                    source_commission_id, source_order_id, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $loanId,
                $agentId,
                $amount,
                $type,
                $commissionId,
                $orderId,
                $notes
            ]);
            
            $transactionId = (int)$pdo->lastInsertId();
            
            // Update loan balance
            $newBalance = $balance - $amount;
            $stmt = $pdo->prepare('
                UPDATE sales_agent_loans 
                SET balance_remaining = ?,
                    status = CASE WHEN ? <= 0 THEN "paid" ELSE status END,
                    paid_off_at = CASE WHEN ? <= 0 THEN NOW() ELSE paid_off_at END,
                    updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$newBalance, $newBalance, $newBalance, $loanId]);
            
            // Create journal entry for repayment
            // Debit: Cash (1110) - company receives payment
            // Credit: Agent Loan Receivable (1180) - reduce receivable
            $stmt = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE account_code = ? LIMIT 1');
            $stmt->execute(['1110']); // Cash
            $cashAccount = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE account_code = ? LIMIT 1');
            $stmt->execute(['1180']); // Agent Loan Receivable
            $loanReceivableAccount = $stmt->fetchColumn();
            
            if ($cashAccount && $loanReceivableAccount) {
                require_once __DIR__ . '/expense-automation.php';
                
                $entryNumber = 'LOAN-REPAY-' . str_pad($loanId, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd-His');
                
                $stmt = $pdo->prepare('
                    INSERT INTO journal_entries (
                        entry_number, entry_date, entry_type, description,
                        reference_type, reference_id, fiscal_period, status,
                        is_auto_generated, created_by
                    ) VALUES (?, NOW(), "standard", ?, "loan_repayment", ?, ?, "posted", 1, ?)
                ');
                $stmt->execute([
                    $entryNumber,
                    "Agent loan repayment - $" . number_format($amount, 2),
                    $transactionId,
                    date('Y-m'),
                    $agentId
                ]);
                
                $journalEntryId = (int)$pdo->lastInsertId();
                
                // Debit: Cash
                $stmt = $pdo->prepare('
                    INSERT INTO journal_entry_lines (
                        journal_entry_id, line_number, account_id, debit_amount, credit_amount, description
                    ) VALUES (?, 1, ?, ?, 0, ?)
                ');
                $stmt->execute([
                    $journalEntryId,
                    $cashAccount,
                    $amount,
                    'Cash received from agent loan repayment'
                ]);
                
                // Credit: Agent Loan Receivable
                $stmt = $pdo->prepare('
                    INSERT INTO journal_entry_lines (
                        journal_entry_id, line_number, account_id, debit_amount, credit_amount, description
                    ) VALUES (?, 2, ?, 0, ?, ?)
                ');
                $stmt->execute([
                    $journalEntryId,
                    $loanReceivableAccount,
                    $amount,
                    'Reduce agent loan receivable'
                ]);
                
                // Link journal entry to transaction
                $stmt = $pdo->prepare('UPDATE loan_repayment_transactions SET journal_entry_id = ? WHERE id = ?');
                $stmt->execute([$journalEntryId, $transactionId]);
            }
            
            $pdo->commit();
            return $transactionId;
            
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error applying loan repayment: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('create_agent_loan')) {
    /**
     * Create a new loan for an agent
     * 
     * @param PDO $pdo Database connection
     * @param int $agentId Agent user ID
     * @param float $amount Loan amount
     * @param string $type Loan type
     * @param int $approvedBy Admin user ID
     * @param array $repaymentTerms Repayment terms configuration
     * @param string|null $notes Additional notes
     * @return int|null Loan ID on success, null on failure
     */
    function create_agent_loan(
        PDO $pdo,
        int $agentId,
        float $amount,
        string $type,
        int $approvedBy,
        array $repaymentTerms = [],
        ?string $notes = null
    ): ?int {
        try {
            $pdo->beginTransaction();
            
            // Create loan record
            $stmt = $pdo->prepare('
                INSERT INTO sales_agent_loans (
                    agent_id, loan_amount, balance_remaining, loan_type,
                    loan_date, repayment_terms, status, approved_by, notes
                ) VALUES (?, ?, ?, ?, NOW(), ?, "active", ?, ?)
            ');
            $stmt->execute([
                $agentId,
                $amount,
                $amount, // Initial balance = full amount
                $type,
                json_encode($repaymentTerms),
                $approvedBy,
                $notes
            ]);
            
            $loanId = (int)$pdo->lastInsertId();
            
            // Create journal entry for loan disbursement
            // Debit: Agent Loan Receivable (1180) - company is owed money
            // Credit: Cash (1110) - company disburses cash
            $stmt = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE account_code = ? LIMIT 1');
            $stmt->execute(['1180']);
            $loanReceivableAccount = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE account_code = ? LIMIT 1');
            $stmt->execute(['1110']);
            $cashAccount = $stmt->fetchColumn();
            
            if ($loanReceivableAccount && $cashAccount) {
                $entryNumber = 'LOAN-' . str_pad($loanId, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd');
                
                $stmt = $pdo->prepare('
                    INSERT INTO journal_entries (
                        entry_number, entry_date, entry_type, description,
                        reference_type, reference_id, fiscal_period, status,
                        is_auto_generated, created_by
                    ) VALUES (?, NOW(), "standard", ?, "agent_loan", ?, ?, "posted", 1, ?)
                ');
                $stmt->execute([
                    $entryNumber,
                    "Agent loan disbursement - $type - $" . number_format($amount, 2),
                    $loanId,
                    date('Y-m'),
                    $approvedBy
                ]);
                
                $journalEntryId = (int)$pdo->lastInsertId();
                
                // Debit: Agent Loan Receivable
                $stmt = $pdo->prepare('
                    INSERT INTO journal_entry_lines (
                        journal_entry_id, line_number, account_id, debit_amount, credit_amount, description
                    ) VALUES (?, 1, ?, ?, 0, ?)
                ');
                $stmt->execute([
                    $journalEntryId,
                    $loanReceivableAccount,
                    $amount,
                    'Agent loan receivable created'
                ]);
                
                // Credit: Cash
                $stmt = $pdo->prepare('
                    INSERT INTO journal_entry_lines (
                        journal_entry_id, line_number, account_id, debit_amount, credit_amount, description
                    ) VALUES (?, 2, ?, 0, ?, ?)
                ');
                $stmt->execute([
                    $journalEntryId,
                    $cashAccount,
                    $amount,
                    'Cash disbursed to agent'
                ]);
            }
            
            $pdo->commit();
            return $loanId;
            
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error creating agent loan: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('charge_agent_for_sample_shipping')) {
    /**
     * Create an expense charge for agent sample shipping
     * 
     * @param PDO $pdo Database connection
     * @param int $sampleRequestId Sample request ID
     * @param int $agentId Agent user ID
     * @param float $shippingCost Shipping cost amount
     * @return int|null Charge ID on success, null on failure
     */
    function charge_agent_for_sample_shipping(PDO $pdo, int $sampleRequestId, int $agentId, float $shippingCost): ?int {
        try {
            // Get sample request details
            $stmt = $pdo->prepare('
                SELECT sr.*, p.name as product_name
                FROM sample_requests sr
                JOIN products p ON p.id = sr.sample_product_id
                WHERE sr.id = ?
            ');
            $stmt->execute([$sampleRequestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                return null;
            }
            
            // Get agent repayment settings
            $settings = get_agent_repayment_settings($pdo, $agentId, null);
            
            // Create charge record
            $stmt = $pdo->prepare('
                INSERT INTO agent_expense_charges (
                    agent_id, sample_request_id, charge_amount, charge_reason,
                    charge_status, created_by
                ) VALUES (?, ?, ?, ?, "pending", ?)
            ');
            $stmt->execute([
                $agentId,
                $sampleRequestId,
                $shippingCost,
                'Sample shipping cost - ' . $request['product_name'],
                1 // System user
            ]);
            
            $chargeId = (int)$pdo->lastInsertId();
            
            // Determine if we should create a loan or wait for commission deduction
            if ($settings && in_array($settings['repayment_method'], ['upfront', 'first_sale_full'])) {
                // Create a loan for this charge
                $loanId = create_agent_loan(
                    $pdo,
                    $agentId,
                    $shippingCost,
                    'sample_expense',
                    1, // System user
                    [
                        'method' => $settings['repayment_method'],
                        'percentage_rate' => $settings['percentage_rate'],
                        'grace_period_days' => $settings['grace_period_days'],
                        'sample_request_id' => $sampleRequestId
                    ],
                    'Sample shipping charge for request #' . $sampleRequestId
                );
                
                if ($loanId) {
                    // Link charge to loan
                    $stmt = $pdo->prepare('
                        UPDATE agent_expense_charges 
                        SET related_loan_id = ?, charge_status = "charged_to_loan", processed_at = NOW()
                        WHERE id = ?
                    ');
                    $stmt->execute([$loanId, $chargeId]);
                }
            }
            // Otherwise charge will be deducted from next commission automatically
            
            return $chargeId;
            
        } catch (Throwable $e) {
            error_log("Error charging agent for sample shipping: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('check_overdue_grace_periods')) {
    /**
     * Check for loans past grace period that should force deduction
     * Called by scheduled task
     * 
     * @param PDO $pdo Database connection
     * @return array Summary of actions taken
     */
    function check_overdue_grace_periods(PDO $pdo): array {
        $summary = ['checked' => 0, 'forced_deductions' => 0];
        
        try {
            // Find active loans with settings that have expired grace periods
            $stmt = $pdo->query('
                SELECT 
                    sal.id as loan_id,
                    sal.agent_id,
                    sal.balance_remaining,
                    ars.grace_period_days,
                    ars.auto_deduct_after_days,
                    DATEDIFF(NOW(), sal.loan_date) as days_since_loan
                FROM sales_agent_loans sal
                JOIN agent_repayment_settings ars ON ars.agent_id = sal.agent_id
                WHERE sal.status = "active"
                AND sal.balance_remaining > 0
                AND ars.is_active = 1
                AND DATEDIFF(NOW(), sal.loan_date) > ars.auto_deduct_after_days
            ');
            
            while ($loan = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $summary['checked']++;
                
                // Create a note that this loan is past due
                $note = "Loan past grace period ({$loan['days_since_loan']} days). Force deduction enabled on next commission.";
                
                $updateStmt = $pdo->prepare('UPDATE sales_agent_loans SET notes = CONCAT(COALESCE(notes, ""), "\n", ?) WHERE id = ?');
                $updateStmt->execute([$note, $loan['loan_id']]);
                
                $summary['forced_deductions']++;
            }
            
        } catch (Throwable $e) {
            error_log("Error checking overdue grace periods: " . $e->getMessage());
        }
        
        return $summary;
    }
}




