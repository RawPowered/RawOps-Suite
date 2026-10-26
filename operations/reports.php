<?php
$SEO = [
    'title' => 'Operations Reports — Product',
    'description' => 'Operations and inventory reports for shipping department.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

// Ensure user has operations role
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'operations')) {
    flash_set('error', 'Access denied. Operations role required.');
    redirect('/login.php');
}

$pdo = db();
$reportType = $_GET['type'] ?? 'daily';
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>📊 Operations Reports</h1>
                <p class="muted">View inventory and shipping performance reports</p>
            </div>
            <a href="/operations/" class="btn secondary">← Back to Dashboard</a>
        </div>

        <!-- Report Type Selection -->
        <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="?type=daily" class="btn <?php echo $reportType === 'daily' ? '' : 'secondary'; ?>">📅 Daily Report</a>
                <a href="?type=weekly" class="btn <?php echo $reportType === 'weekly' ? '' : 'secondary'; ?>">📈 Weekly Report</a>
                <a href="?type=inventory" class="btn <?php echo $reportType === 'inventory' ? '' : 'secondary'; ?>">📦 Inventory Report</a>
                <a href="?type=stock_movements" class="btn <?php echo $reportType === 'stock_movements' ? '' : 'secondary'; ?>">🔄 Stock Movements</a>
            </div>
        </div>

        <?php if ($reportType === 'daily'): ?>
            <!-- Daily Report -->
            <div class="card">
                <h2>📅 Daily Operations Report - <?php echo date('M j, Y'); ?></h2>
                <?php
                try {
                    $dailyStats = [
                        'orders_processed' => $pdo->query('SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
                        'orders_shipped' => $pdo->query('SELECT COUNT(*) FROM orders WHERE shipping_status = "shipped" AND DATE(updated_at) = CURDATE()')->fetchColumn(),
                        'stock_movements' => $pdo->query('SELECT COUNT(*) FROM stock_movements WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
                        'low_stock_alerts' => $pdo->query('SELECT COUNT(*) FROM products WHERE active = 1 AND stock_quantity <= low_stock_threshold')->fetchColumn()
                    ];
                ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 28px; font-weight: bold; color: #2563eb;"><?php echo intval($dailyStats['orders_processed']); ?></div>
                        <div style="color: #6b7280; font-size: 12px;">Orders Received</div>
                    </div>
                    <div style="background: #f0fdf4; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 28px; font-weight: bold; color: #059669;"><?php echo intval($dailyStats['orders_shipped']); ?></div>
                        <div style="color: #6b7280; font-size: 12px;">Orders Shipped</div>
                    </div>
                    <div style="background: #fffbeb; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 28px; font-weight: bold; color: #d97706;"><?php echo intval($dailyStats['stock_movements']); ?></div>
                        <div style="color: #6b7280; font-size: 12px;">Stock Movements</div>
                    </div>
                    <div style="background: #fef2f2; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 28px; font-weight: bold; color: #dc2626;"><?php echo intval($dailyStats['low_stock_alerts']); ?></div>
                        <div style="color: #6b7280; font-size: 12px;">Low Stock Alerts</div>
                    </div>
                </div>
                <?php } catch (Throwable $e) { ?>
                    <p style="color: #dc2626;">Unable to load daily statistics.</p>
                <?php } ?>
            </div>

        <?php elseif ($reportType === 'inventory'): ?>
            <!-- Inventory Report -->
            <div class="card">
                <h2>📦 Current Inventory Status</h2>
                <?php
                try {
                    $inventoryItems = $pdo->query('
                        SELECT name, sku, stock_quantity, low_stock_threshold,
                               CASE WHEN stock_quantity <= low_stock_threshold THEN 1 ELSE 0 END as is_low_stock
                        FROM products 
                        WHERE active = 1 
                        ORDER BY is_low_stock DESC, name ASC
                    ')->fetchAll();
                ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Product</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">SKU</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Current Stock</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Threshold</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventoryItems as $item): ?>
                                <tr style="border-bottom: 1px solid #f3f4f6;">
                                    <td style="padding: 12px;"><?php echo htmlspecialchars($item['name']); ?></td>
                                    <td style="padding: 12px; text-align: center;"><?php echo htmlspecialchars($item['sku']); ?></td>
                                    <td style="padding: 12px; text-align: center; font-weight: bold; color: <?php echo $item['is_low_stock'] ? '#dc2626' : '#059669'; ?>;">
                                        <?php echo intval($item['stock_quantity']); ?>
                                    </td>
                                    <td style="padding: 12px; text-align: center;"><?php echo intval($item['low_stock_threshold']); ?></td>
                                    <td style="padding: 12px; text-align: center;">
                                        <?php if ($item['is_low_stock']): ?>
                                            <span style="background: #fef2f2; color: #dc2626; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                                🚨 LOW STOCK
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #f0fdf4; color: #059669; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                                ✅ OK
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php } catch (Throwable $e) { ?>
                    <p style="color: #dc2626;">Unable to load inventory data.</p>
                <?php } ?>
            </div>

        <?php else: ?>
            <!-- Default/Other Reports -->
            <div class="card">
                <h2>📊 Report Coming Soon</h2>
                <p>This report type is under development. Please check back later.</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
