<?php
$SEO = [
    'title' => 'Labor Tracking — Operations',
    'description' => 'Track labor hours and costs for work orders.',
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
$message = '';
$error = '';

$selectedWoId = intval($_GET['wo'] ?? 0);

// Handle labor logging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_labor'])) {
    try {
        $woId = intval($_POST['work_order_id'] ?? 0);
        $groupId = intval($_POST['product_group_id'] ?? 0);
        $manufacturerId = intval($_POST['manufacturer_id'] ?? 0);
        $laborDate = $_POST['labor_date'] ?? date('Y-m-d');
        $hoursWorked = floatval($_POST['hours_worked'] ?? 0);
        $hourlyRate = floatval($_POST['hourly_rate'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($woId <= 0 || $manufacturerId <= 0 || $hoursWorked <= 0) {
            throw new Exception('Work order, manufacturer, and hours are required');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO product_group_labor_logs
            (work_order_id, product_group_id, manufacturer_id, labor_date, hours_worked, hourly_rate, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$woId, $groupId, $manufacturerId, $laborDate, $hoursWorked, $hourlyRate, $notes]);
        
        // Update work order labor totals
        $stmt = $pdo->prepare("
            UPDATE work_orders 
            SET labor_hours = (SELECT SUM(hours_worked) FROM product_group_labor_logs WHERE work_order_id = ?),
                labor_cost = (SELECT SUM(hours_worked * hourly_rate) FROM product_group_labor_logs WHERE work_order_id = ?),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$woId, $woId, $woId]);
        
        $message = 'Labor hours logged successfully';
        $selectedWoId = $woId;
        
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch work orders
$workOrders = $pdo->query("
    SELECT wo.id, wo.wo_number, pg.name as group_name, wo.status
    FROM work_orders wo
    JOIN product_groups pg ON pg.id = wo.product_group_id
    WHERE wo.status IN ('scheduled', 'in_progress', 'completed')
    ORDER BY wo.production_date DESC, wo.wo_number DESC
")->fetchAll();

// Fetch manufacturers
$manufacturers = $pdo->query("
    SELECT DISTINCT u.id, u.first_name, u.last_name
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.name = 'operations'
    ORDER BY u.first_name, u.last_name
")->fetchAll();

// Fetch labor logs for selected work order
$laborLogs = [];
$workOrder = null;
if ($selectedWoId > 0) {
    $stmt = $pdo->prepare("
        SELECT wo.*, pg.name as group_name, pg.id as group_id
        FROM work_orders wo
        JOIN product_groups pg ON pg.id = wo.product_group_id
        WHERE wo.id = ?
    ");
    $stmt->execute([$selectedWoId]);
    $workOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($workOrder) {
        $stmt = $pdo->prepare("
            SELECT l.*, u.first_name, u.last_name
            FROM product_group_labor_logs l
            JOIN users u ON u.id = l.manufacturer_id
            WHERE l.work_order_id = ?
            ORDER BY l.labor_date DESC, l.created_at DESC
        ");
        $stmt->execute([$selectedWoId]);
        $laborLogs = $stmt->fetchAll();
    }
}
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>⏱️ Labor Tracking</h1>
                <p class="muted">Track labor hours and costs for work orders</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="<?php echo path_with_base('/operations/'); ?>" class="btn secondary">← Back to Operations</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
                ✅ <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="card" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-bottom:16px">
                ❌ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Work Order Selector -->
        <div class="card" style="margin-bottom: 24px;">
            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Select Work Order</label>
            <select onchange="window.location.href='?wo=' + this.value" 
                    style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                <option value="">Select a work order...</option>
                <?php foreach ($workOrders as $wo): ?>
                <option value="<?php echo $wo['id']; ?>" <?php echo $selectedWoId == $wo['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($wo['wo_number']); ?> - <?php echo htmlspecialchars($wo['group_name']); ?> (<?php echo $wo['status']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($workOrder): ?>
        <!-- Work Order Summary -->
        <div class="card" style="margin-bottom: 24px; background: linear-gradient(135deg, #f0fdf4, #dcfce7);">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                <div>
                    <div style="font-size: 24px; font-weight: 700; color: #059669;">
                        <?php echo number_format($workOrder['labor_hours'], 2); ?> hrs
                    </div>
                    <div style="font-size: 12px; color: #14532d;">Total Labor Hours</div>
                </div>
                <div>
                    <div style="font-size: 24px; font-weight: 700; color: #dc2626;">
                        $<?php echo number_format($workOrder['labor_cost'], 2); ?>
                    </div>
                    <div style="font-size: 12px; color: #14532d;">Total Labor Cost</div>
                </div>
                <div>
                    <div style="font-size: 24px; font-weight: 700; color: #3b82f6;">
                        $<?php echo $workOrder['labor_hours'] > 0 ? number_format($workOrder['labor_cost'] / $workOrder['labor_hours'], 2) : '0.00'; ?>/hr
                    </div>
                    <div style="font-size: 12px; color: #14532d;">Average Hourly Rate</div>
                </div>
            </div>
        </div>
        
        <!-- Log Labor Form -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>Log Labor Hours</h2>
            <form method="post">
                <input type="hidden" name="log_labor" value="1">
                <input type="hidden" name="work_order_id" value="<?php echo $workOrder['id']; ?>">
                <input type="hidden" name="product_group_id" value="<?php echo $workOrder['group_id']; ?>">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Manufacturer/Worker *</label>
                        <select name="manufacturer_id" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="">Select...</option>
                            <?php foreach ($manufacturers as $mfg): ?>
                            <option value="<?php echo $mfg['id']; ?>">
                                <?php echo htmlspecialchars($mfg['first_name'] . ' ' . $mfg['last_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Labor Date *</label>
                        <input type="date" name="labor_date" required value="<?php echo date('Y-m-d'); ?>"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Hours Worked *</label>
                        <input type="number" name="hours_worked" step="0.25" min="0" required
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Hourly Rate *</label>
                        <input type="number" name="hourly_rate" step="0.01" min="0" required value="15.00"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Notes</label>
                    <textarea name="notes" rows="2" placeholder="Optional notes..."
                              style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"></textarea>
                </div>
                
                <div style="text-align: right;">
                    <button type="submit" class="btn" style="background: var(--green); color: white;">Log Labor Hours</button>
                </div>
            </form>
        </div>

        <!-- Labor History -->
        <div class="card">
            <h2>Labor History (<?php echo count($laborLogs); ?>)</h2>
            <?php if (empty($laborLogs)): ?>
                <p style="color: #6b7280; text-align: center; padding: 40px 20px;">No labor logged yet.</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc;">
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Date</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Worker</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Hours</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Rate</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Total Cost</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($laborLogs as $log): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px;">
                                <?php echo date('M d, Y', strtotime($log['labor_date'])); ?>
                            </td>
                            <td style="padding: 12px;">
                                <?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?>
                            </td>
                            <td style="padding: 12px; text-align: center; font-weight: 600;">
                                <?php echo number_format($log['hours_worked'], 2); ?> hrs
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                $<?php echo number_format($log['hourly_rate'], 2); ?>/hr
                            </td>
                            <td style="padding: 12px; text-align: center; font-weight: 700; color: #059669;">
                                $<?php echo number_format($log['total_cost'], 2); ?>
                            </td>
                            <td style="padding: 12px;">
                                <?php echo htmlspecialchars($log['notes'] ?? '—'); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../required/footer.php'; ?>






