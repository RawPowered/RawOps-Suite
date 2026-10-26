<?php
$SEO = [
    'title' => 'My Commissions — Sales Agent',
    'description' => 'View your personal commission earnings and performance.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_login_or_redirect('/login.php');

$uid = current_user_id();
$pdo = db();

// Verify user is sales agent
$userRoles = user_roles($uid);
if (!in_array('sales_agent', $userRoles ?? []) && !in_array('admin', $userRoles ?? [])) {
    flash_set('error', 'Sales agent access required.');
    redirect('/');
}

// Get user info
$stmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

// Get commission data for THIS USER ONLY
try {
    // Personal commission summary
    $stmt = $pdo->prepare('
        SELECT 
            COUNT(*) as total_commissions,
            SUM(CASE WHEN status = "pending" THEN amount ELSE 0 END) as pending_amount,
            SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END) as paid_amount,
            SUM(amount) as total_earned
        FROM commissions 
        WHERE user_id = ? AND commission_type = "sales_agent"
    ');
    $stmt->execute([$uid]);
    $commissionSummary = $stmt->fetch();
    
    // Recent commissions for THIS USER ONLY
    $stmt = $pdo->prepare('
        SELECT 
            c.*,
            o.order_number,
            o.total as order_total,
            o.created_at as order_date,
            wa.business_name
        FROM commissions c
        JOIN orders o ON o.id = c.order_id
        JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
        WHERE c.user_id = ? AND c.commission_type = "sales_agent"
        ORDER BY c.created_at DESC
        LIMIT 20
    ');
    $stmt->execute([$uid]);
    $recentCommissions = $stmt->fetchAll();
    
    // Monthly performance for THIS USER ONLY
    $stmt = $pdo->prepare('
        SELECT 
            DATE_FORMAT(c.created_at, "%Y-%m") as month,
            DATE_FORMAT(c.created_at, "%M %Y") as month_name,
            SUM(c.amount) as commission_earned,
            COUNT(DISTINCT c.order_id) as orders_count,
            AVG(c.percentage) as avg_commission_rate
        FROM commissions c
        WHERE c.user_id = ? AND c.commission_type = "sales_agent"
        AND c.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(c.created_at, "%Y-%m")
        ORDER BY month DESC
    ');
    $stmt->execute([$uid]);
    $monthlyPerformance = $stmt->fetchAll();
    
} catch (Exception $e) {
    $commissionSummary = ['total_commissions' => 0, 'pending_amount' => 0, 'paid_amount' => 0, 'total_earned' => 0];
    $recentCommissions = [];
    $monthlyPerformance = [];
}
?>

<style>
.commission-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.commission-header {
    background: linear-gradient(135deg, #059669, #10b981);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    text-align: center;
}

.stat-value {
    font-size: 28px;
    font-weight: bold;
    margin-bottom: 8px;
}

.stat-label {
    color: #6b7280;
    font-size: 14px;
    text-transform: uppercase;
}

.commission-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 24px;
}

.commission-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
}

.commission-table th,
.commission-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.commission-table th {
    background: #f9fafb;
    font-weight: 600;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.status-pending { background: #fef3c7; color: #92400e; }
.status-paid { background: #dcfce7; color: #14532d; }
.status-cancelled { background: #fee2e2; color: #991b1b; }

.privacy-notice {
    background: #f0f9ff;
    border: 2px solid #3b82f6;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
}
</style>

<div class="commission-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">💰 My Commission Dashboard</h1>
        <p style="font-size: 18px; opacity: 0.9;">
            Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>! 
            Track your personal earnings and performance.
        </p>
    </div>
</div>

<div class="commission-container">
    <?php if (in_array('admin', $userRoles ?? [])): ?>
        <?php include __DIR__ . '/admin-menu.php'; ?>
    <?php endif; ?>
    
    <!-- Privacy Notice -->
    <div class="privacy-notice">
        <h3 style="color: #1e40af; margin: 0 0 12px 0;">🔒 Commission Privacy Policy</h3>
        <p style="margin: 0; color: #374151;">
            This dashboard shows <strong>only your personal commission data</strong>. 
            Commission information for other sales agents is completely private and not accessible to you.
            All commission calculations are based on order volume and your individual performance.
        </p>
    </div>
    
    <!-- Commission Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value" style="color: #f59e0b;">${<?php echo number_format($commissionSummary['pending_amount'] ?? 0, 2); ?></div>
            <div class="stat-label">Pending Payout</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #059669;">${<?php echo number_format($commissionSummary['paid_amount'] ?? 0, 2); ?></div>
            <div class="stat-label">Total Paid</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #3b82f6;">${<?php echo number_format($commissionSummary['total_earned'] ?? 0, 2); ?></div>
            <div class="stat-label">Total Earned</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $commissionSummary['total_commissions'] ?? 0; ?></div>
            <div class="stat-label">Total Orders</div>
        </div>
    </div>
    
    <!-- Recent Commissions -->
    <div class="commission-card">
        <h2 style="color: #1f2937; margin-bottom: 20px;">📋 Recent Commission History</h2>
        
        <?php if (!empty($recentCommissions)): ?>
            <table class="commission-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Order Total</th>
                        <th>Commission Rate</th>
                        <th>Commission Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentCommissions as $commission): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($commission['order_number']); ?></td>
                            <td><?php echo htmlspecialchars($commission['business_name']); ?></td>
                            <td>$<?php echo number_format($commission['order_total'], 2); ?></td>
                            <td><?php echo $commission['percentage']; ?>%</td>
                            <td style="font-weight: 600; color: #059669;">
                                $<?php echo number_format($commission['amount'], 2); ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $commission['status']; ?>">
                                    <?php echo ucfirst($commission['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($commission['order_date'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #6b7280;">
                <div style="font-size: 48px; margin-bottom: 16px;">💰</div>
                <h3>No commissions yet</h3>
                <p>Start making sales to see your commission history here!</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Monthly Performance -->
    <?php if (!empty($monthlyPerformance)): ?>
    <div class="commission-card">
        <h2 style="color: #1f2937; margin-bottom: 20px;">📈 Monthly Performance</h2>
        
        <table class="commission-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Orders</th>
                    <th>Avg Commission Rate</th>
                    <th>Total Commissions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyPerformance as $month): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($month['month_name']); ?></td>
                        <td><?php echo $month['orders_count']; ?></td>
                        <td><?php echo number_format($month['avg_commission_rate'], 1); ?>%</td>
                        <td style="font-weight: 600; color: #059669;">
                            $<?php echo number_format($month['commission_earned'], 2); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Commission Tier Information -->
    <div class="commission-card" style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe);">
        <h2 style="color: #1e40af; margin-bottom: 20px;">🎯 Commission Structure</h2>
        
        <?php
        // Get all product groups with custom commission structures
        try {
            $stmt = $pdo->query("
                SELECT 
                    pg.id, pg.name, pg.description,
                    COUNT(p.id) as product_count
                FROM product_groups pg
                LEFT JOIN products p ON p.product_group_id = pg.id AND p.active = 1
                WHERE pg.active = 1 AND pg.use_custom_commission = 1
                GROUP BY pg.id
                ORDER BY pg.display_order, pg.name
            ");
            $productGroups = $stmt->fetchAll();
            
            // Get default tiers
            $defaultTiers = commission_get_agent_tiers($pdo, $uid);
            
            if (!empty($productGroups)):
        ?>
            <p style="color: #374151; margin-bottom: 16px;">
                Your commission rate is based on order volume and product type. Each product group has its own commission structure:
            </p>
            
            <?php foreach ($productGroups as $group): ?>
                <?php
                // Get commission tiers for this group
                $stmt = $pdo->prepare("
                    SELECT tier_name, quantity_min, quantity_max, commission_percent, bulk_discount_percent
                    FROM product_group_commissions 
                    WHERE product_group_id = ? AND active = 1
                    ORDER BY quantity_min ASC
                ");
                $stmt->execute([$group['id']]);
                $groupTiers = $stmt->fetchAll();
                ?>
                
                <div style="background: white; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                    <h3 style="margin: 0 0 8px 0; color: #1e40af;"><?php echo htmlspecialchars($group['name']); ?></h3>
                    <?php if ($group['product_count'] > 0): ?>
                        <p style="margin: 0 0 12px 0; font-size: 13px; color: #6b7280;">
                            <?php echo $group['product_count']; ?> active product<?php echo $group['product_count'] != 1 ? 's' : ''; ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($groupTiers)): ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
                            <?php foreach ($groupTiers as $tier): ?>
                            <div style="background: #f9fafb; padding: 12px; border-radius: 6px; text-align: center; border: 2px solid #e5e7eb;">
                                <div style="font-size: 18px; font-weight: bold; color: #059669;"><?php echo number_format($tier['commission_percent'], 1); ?>%</div>
                                <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                    <?php echo htmlspecialchars($tier['tier_name']); ?> qty
                                </div>
                                <?php if ($tier['bulk_discount_percent'] > 0): ?>
                                <div style="font-size: 11px; color: #dc2626; margin-top: 2px;">
                                    <?php echo number_format($tier['bulk_discount_percent'], 0); ?>% discount
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <div style="background: white; padding: 16px; border-radius: 8px; margin-top: 16px;">
                <h3 style="margin: 0 0 8px 0; color: #6b7280;">Default Commission (Ungrouped Products)</h3>
                <p style="margin: 0 0 12px 0; font-size: 13px; color: #6b7280;">
                    Products not in a specific group use these rates:
                </p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
                    <?php foreach ($defaultTiers as $tier): ?>
                    <div style="background: #f9fafb; padding: 12px; border-radius: 6px; text-align: center; border: 2px solid #e5e7eb;">
                        <div style="font-size: 18px; font-weight: bold; color: #6b7280;"><?php echo number_format($tier['pct'], 1); ?>%</div>
                        <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                            <?php echo $tier['min']; ?><?php echo $tier['max'] ? '-' . $tier['max'] : '+'; ?> qty
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
        <?php else: ?>
            <p style="color: #374151; margin-bottom: 16px;">
                Your commission rate is based on order volume. Higher volume orders earn higher commission percentages:
            </p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">
                <?php foreach ($defaultTiers as $tier): ?>
                <div style="background: white; padding: 16px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 20px; font-weight: bold; color: #059669;"><?php echo number_format($tier['pct'], 1); ?>%</div>
                    <div style="font-size: 14px; color: #6b7280;">
                        <?php echo $tier['min']; ?><?php echo $tier['max'] ? '-' . $tier['max'] : '+'; ?> bottles
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php
        } catch (Throwable $e) {
            // Fallback to showing default tiers only
            $defaultTiers = commission_get_agent_tiers($pdo, $uid);
        ?>
            <p style="color: #374151; margin-bottom: 16px;">
                Your commission rate is based on order volume:
            </p>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">
                <?php foreach ($defaultTiers as $tier): ?>
                <div style="background: white; padding: 16px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 20px; font-weight: bold; color: #059669;"><?php echo number_format($tier['pct'], 1); ?>%</div>
                    <div style="font-size: 14px; color: #6b7280;">
                        <?php echo $tier['min']; ?><?php echo $tier['max'] ? '-' . $tier['max'] : '+'; ?> bottles
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php } ?>
        
        <div style="margin-top: 16px; padding: 16px; background: rgba(255,255,255,0.7); border-radius: 8px;">
            <p style="margin: 0; font-size: 14px; color: #1e40af;">
                💡 <strong>Strategy Tip:</strong> Encourage customers to order in bulk to maximize both their savings and your commission earnings!
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
