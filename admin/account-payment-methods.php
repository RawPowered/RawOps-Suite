<?php
$SEO = [
    'title' => 'Account Payment Methods — Admin',
    'description' => 'View and manage payment methods for wholesale accounts.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$accountId = intval($_GET['account_id'] ?? 0);

if ($accountId <= 0) {
    flash_set('error', 'Invalid account ID.');
    redirect(path_with_base('/admin/wholesale-accounts.php'));
}

// Handle actions (delete, set default)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $paymentMethodId = intval($_POST['payment_method_id'] ?? 0);
    
    try {
        if ($action === 'delete' && $paymentMethodId > 0) {
            $stmt = $pdo->prepare("DELETE FROM payment_methods WHERE id = ? AND wholesale_account_id = ?");
            $stmt->execute([$paymentMethodId, $accountId]);
            flash_set('success', 'Payment method deleted successfully.');
        } elseif ($action === 'set_default' && $paymentMethodId > 0) {
            // Clear all defaults
            $pdo->prepare("UPDATE payment_methods SET is_default = 0 WHERE wholesale_account_id = ?")->execute([$accountId]);
            // Set new default
            $pdo->prepare("UPDATE payment_methods SET is_default = 1 WHERE id = ? AND wholesale_account_id = ?")->execute([$paymentMethodId, $accountId]);
            flash_set('success', 'Default payment method updated.');
        }
        redirect(path_with_base('/admin/account-payment-methods.php?account_id=' . $accountId));
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch account details
try {
    $stmt = $pdo->prepare("
        SELECT wa.*, u.first_name, u.last_name, u.email
        FROM wholesale_accounts wa
        JOIN users u ON u.id = wa.user_id
        WHERE wa.id = ?
    ");
    $stmt->execute([$accountId]);
    $account = $stmt->fetch();
    
    if (!$account) {
        flash_set('error', 'Account not found.');
        redirect(path_with_base('/admin/wholesale-accounts.php'));
    }
    
    // Fetch all payment methods for this account
    $stmt = $pdo->prepare("
        SELECT pm.*, 
               COUNT(DISTINCT o.id) as order_count,
               MAX(o.created_at) as last_used
        FROM payment_methods pm
        LEFT JOIN orders o ON o.payment_method_id = pm.id
        WHERE pm.wholesale_account_id = ?
        GROUP BY pm.id
        ORDER BY pm.is_default DESC, pm.created_at DESC
    ");
    $stmt->execute([$accountId]);
    $paymentMethods = $stmt->fetchAll();
    
} catch (Exception $e) {
    flash_set('error', 'Database error: ' . $e->getMessage());
    redirect(path_with_base('/admin/wholesale-accounts.php'));
}
?>

<style>
.page-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.payment-card {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 16px;
    position: relative;
    transition: all 0.2s;
}
.payment-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.payment-card.default {
    border-color: #059669;
    background: #f0fdf4;
}
.payment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.payment-type {
    font-size: 24px;
    margin-right: 8px;
}
.payment-actions {
    display: flex;
    gap: 8px;
}
.token-section {
    background: #fef3c7;
    border: 2px solid #f59e0b;
    border-radius: 8px;
    padding: 16px;
    margin-top: 16px;
}
.token-details {
    font-family: monospace;
    font-size: 14px;
    background: white;
    padding: 12px;
    border-radius: 6px;
    margin-top: 8px;
}
.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.badge-default {
    background: #dcfce7;
    color: #14532d;
}
.badge-usage {
    background: #dbeafe;
    color: #1e40af;
}
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: #f9fafb;
    border-radius: 12px;
    color: #6b7280;
}
</style>

<div class="page-header">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="font-size: 36px; margin-bottom: 8px;">Payment Methods</h1>
                <p style="font-size: 18px; opacity: 0.9;">
                    <?php echo htmlspecialchars($account['business_name']); ?>
                </p>
            </div>
            <div>
                <a href="<?php echo path_with_base('/admin/wholesale-accounts.php'); ?>" class="btn secondary">← Back to Accounts</a>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <?php if (isset($error)): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Account Info Card -->
    <div class="card" style="margin-bottom: 32px; background: linear-gradient(135deg, #f0f9ff, #e0f2fe); border: 2px solid #3b82f6;">
        <h3 style="margin-bottom: 12px; color: #1e40af;">Account Information</h3>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
            <div>
                <p style="font-size: 13px; color: #6b7280; margin-bottom: 4px;">Business Name</p>
                <p style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($account['business_name']); ?></p>
            </div>
            <div>
                <p style="font-size: 13px; color: #6b7280; margin-bottom: 4px;">Contact</p>
                <p style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?></p>
            </div>
            <div>
                <p style="font-size: 13px; color: #6b7280; margin-bottom: 4px;">Email</p>
                <p style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($account['email']); ?></p>
            </div>
        </div>
    </div>
    
    <!-- Payment Methods -->
    <h2 style="margin-bottom: 24px; font-size: 24px;">Saved Payment Methods (<?php echo count($paymentMethods); ?>)</h2>
    
    <?php if (empty($paymentMethods)): ?>
    <div class="empty-state">
        <div style="font-size: 48px; margin-bottom: 16px;">💳</div>
        <h3 style="color: #374151; margin-bottom: 8px;">No Payment Methods</h3>
        <p>This account has not added any payment methods yet.</p>
    </div>
    <?php else: ?>
    
    <?php foreach ($paymentMethods as $pm): ?>
    <div class="payment-card <?php echo $pm['is_default'] ? 'default' : ''; ?>">
        <div class="payment-header">
            <div style="display: flex; align-items: center;">
                <span class="payment-type">
                    <?php echo $pm['type'] === 'credit_card' ? '💳' : '🏦'; ?>
                </span>
                <div>
                    <h3 style="margin: 0; font-size: 18px;">
                        <?php if ($pm['type'] === 'credit_card'): ?>
                            <?php echo htmlspecialchars($pm['card_brand'] ?: 'Credit Card'); ?> 
                            ••••<?php echo htmlspecialchars($pm['last_four']); ?>
                        <?php else: ?>
                            <?php echo htmlspecialchars($pm['bank_name'] ?: 'Bank Account'); ?> 
                            ••••<?php echo htmlspecialchars($pm['last_four']); ?>
                        <?php endif; ?>
                    </h3>
                    <p style="margin: 4px 0 0; font-size: 13px; color: #6b7280;">
                        <?php echo htmlspecialchars($pm['card_holder_name'] ?: 'N/A'); ?>
                        <?php if ($pm['type'] === 'credit_card' && $pm['exp_month']): ?>
                            · Exp: <?php echo htmlspecialchars($pm['exp_month'] . '/' . $pm['exp_year']); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="payment-actions">
                <?php if (!$pm['is_default']): ?>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="set_default">
                    <input type="hidden" name="payment_method_id" value="<?php echo $pm['id']; ?>">
                    <button type="submit" class="btn secondary" style="padding: 6px 12px; font-size: 13px;">
                        Set as Default
                    </button>
                </form>
                <?php endif; ?>
                <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this payment method?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="payment_method_id" value="<?php echo $pm['id']; ?>">
                    <button type="submit" class="btn" style="padding: 6px 12px; font-size: 13px; background: #dc2626;">
                        🗑️ Delete
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Badges -->
        <div style="margin-bottom: 12px;">
            <?php if ($pm['is_default']): ?>
                <span class="badge badge-default">✓ Default</span>
            <?php endif; ?>
            <?php if ($pm['order_count'] > 0): ?>
                <span class="badge badge-usage"><?php echo $pm['order_count']; ?> Order<?php echo $pm['order_count'] > 1 ? 's' : ''; ?></span>
            <?php endif; ?>
        </div>
        
        <!-- Usage Info -->
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
            <div>
                <p style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">Added</p>
                <p style="font-size: 14px; font-weight: 600;"><?php echo date('M j, Y g:i A', strtotime($pm['created_at'])); ?></p>
            </div>
            <div>
                <p style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">Last Used</p>
                <p style="font-size: 14px; font-weight: 600;">
                    <?php echo $pm['last_used'] ? date('M j, Y g:i A', strtotime($pm['last_used'])) : 'Never'; ?>
                </p>
            </div>
        </div>
        
        <!-- Admin Only: Full Payment Details -->
        <?php if ($pm['token']): ?>
        <div class="token-section">
            <p style="font-size: 13px; font-weight: 600; color: #92400e; margin-bottom: 8px;">
                ⚠️ ADMIN ONLY - Full Payment Details
            </p>
            <?php 
            $tokenData = json_decode($pm['token'], true);
            if ($tokenData): 
            ?>
            <div class="token-details">
                <?php if ($pm['type'] === 'credit_card'): ?>
                    <?php if (isset($tokenData['card_number'])): ?>
                        <p><strong>Card Number:</strong> <?php echo htmlspecialchars($tokenData['card_number']); ?></p>
                        <p><strong>CVV:</strong> <?php echo htmlspecialchars($tokenData['cvv'] ?? 'N/A'); ?></p>
                    <?php endif; ?>
                <?php elseif ($pm['type'] === 'ach'): ?>
                    <?php if (isset($tokenData['routing_number'])): ?>
                        <p><strong>Routing Number:</strong> <?php echo htmlspecialchars($tokenData['routing_number']); ?></p>
                        <p><strong>Account Number:</strong> <?php echo htmlspecialchars($tokenData['account_number']); ?></p>
                        <p><strong>Account Type:</strong> <?php echo htmlspecialchars(ucfirst($tokenData['account_type'] ?? 'checking')); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <p style="font-size: 11px; color: #78350f; margin-top: 8px;">
                💡 Use this information for manual payment processing. Keep confidential.
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    
    <?php endif; ?>
    
    <!-- Security Notice -->
    <div class="card" style="margin-top: 32px; background: #fee2e2; border: 2px solid #dc2626;">
        <h4 style="color: #991b1b; margin-bottom: 12px;">🔒 Security Notice</h4>
        <p style="color: #991b1b; font-size: 14px; line-height: 1.6;">
            Payment details are stored securely for manual processing only. All full payment information (card numbers, CVV, routing numbers) 
            should be handled with extreme care and only accessed when necessary for payment processing. Never share these details 
            via email or unsecured channels.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>














