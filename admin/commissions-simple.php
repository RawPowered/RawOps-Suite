<?php
$SEO = [
    'title' => 'Commission Management — Admin',
    'description' => 'Manage sales commissions, tiers, and payouts.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_settings') {
            $developer_commission = floatval($_POST['developer_commission'] ?? 5);
            $default_agent_commission = floatval($_POST['default_agent_commission'] ?? 10);
            
            // Update or insert developer commission
            $stmt = $pdo->prepare("INSERT INTO commission_settings (setting_key, setting_value, description) VALUES ('developer_commission', ?, 'Portfolio Developer developer commission percentage') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$developer_commission]);
            
            // Update or insert default agent commission
            $stmt = $pdo->prepare("INSERT INTO commission_settings (setting_key, setting_value, description) VALUES ('default_agent_commission', ?, 'Default sales agent commission percentage') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$default_agent_commission]);
            
            $message = "Commission settings updated successfully!";
        }
    } catch (Exception $e) {
        $error = "Error updating settings: " . $e->getMessage();
    }
}

// Get commission settings
$settings = [
    'developer_commission' => '5.00',
    'default_agent_commission' => '10.00'
];

try {
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM commission_settings');
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $error = "Error loading settings: " . $e->getMessage();
}

// Get Portfolio Developer info
$developer = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = 1 OR email LIKE '%portfolio%' OR (first_name = 'Portfolio' AND last_name = 'Developer') LIMIT 1");
    $stmt->execute();
    $developer = $stmt->fetch();
} catch (Exception $e) {
    $error = "Error loading Portfolio Developer: " . $e->getMessage();
}

// Get commission data
$commission_stats = [
    'total_commissions' => 0,
    'pending_amount' => 0,
    'paid_amount' => 0,
    'total_recipients' => 0
];

try {
    // Get basic commission stats
    $stmt = $pdo->query("SELECT COUNT(*) FROM commissions");
    $commission_stats['total_commissions'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE status = 'pending'");
    $commission_stats['pending_amount'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE status = 'paid'");
    $commission_stats['paid_amount'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM commissions");
    $commission_stats['total_recipients'] = $stmt->fetchColumn();
    
} catch (Exception $e) {
    $error = "Error loading commission stats: " . $e->getMessage();
}

// Get recent commissions
$recent_commissions = [];
try {
    $stmt = $pdo->query("
        SELECT 
            c.*,
            u.first_name,
            u.last_name,
            u.email
        FROM commissions c
        JOIN users u ON u.id = c.user_id
        ORDER BY c.created_at DESC
        LIMIT 10
    ");
    $recent_commissions = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error loading recent commissions: " . $e->getMessage();
}
?>

<style>
.commission-header {
    background: linear-gradient(135deg, #1e293b, #334155);
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
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    text-align: center;
}
.stat-value {
    font-size: 32px;
    font-weight: bold;
    margin: 8px 0;
    color: #059669;
}
.stat-label {
    color: #6b7280;
    font-size: 13px;
    text-transform: uppercase;
}
.settings-form {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 32px;
}
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
}
.form-group input {
    width: 100%;
    padding: 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}
.btn {
    background: #059669;
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}
.btn:hover {
    background: #047857;
}
.commission-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.commission-table table {
    width: 100%;
    border-collapse: collapse;
}
.commission-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
}
.commission-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
}
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.status-pending { background: #fef3c7; color: #92400e; }
.status-approved { background: #dbeafe; color: #1e40af; }
.status-paid { background: #dcfce7; color: #14532d; }
.alert {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 24px;
}
.alert-success {
    background: #dcfce7;
    color: #14532d;
}
.alert-error {
    background: #fee2e2;
    color: #991b1b;
}
</style>

<div class="commission-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">Commission Management</h1>
        <p style="font-size: 18px; opacity: 0.9;">Manage Portfolio Developer developer commission and sales agent rates</p>
    </div>
</div>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <?php if ($message): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Commission Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Commissions</div>
            <div class="stat-value"><?php echo $commission_stats['total_commissions']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pending Amount</div>
            <div class="stat-value">$<?php echo number_format($commission_stats['pending_amount'], 2); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Paid Amount</div>
            <div class="stat-value">$<?php echo number_format($commission_stats['paid_amount'], 2); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Recipients</div>
            <div class="stat-value"><?php echo $commission_stats['total_recipients']; ?></div>
        </div>
    </div>
    
    <!-- Portfolio Developer Info -->
    <?php if ($developer): ?>
    <div class="card" style="margin-bottom: 32px; background: #fef3c7; border: 1px solid #f59e0b;">
        <h2 style="color: #92400e;">👨‍💻 Developer Commission</h2>
        <p style="color: #92400e;">
            <strong><?php echo htmlspecialchars($developer['first_name'] . ' ' . $developer['last_name']); ?></strong> 
            (<?php echo htmlspecialchars($developer['email']); ?>)
            receives <strong><?php echo $settings['developer_commission']; ?>%</strong> commission on all sales.
        </p>
    </div>
    <?php endif; ?>
    
    <!-- Commission Settings -->
    <div class="settings-form">
        <h2 style="margin-bottom: 20px;">Commission Settings</h2>
        <form method="post">
            <input type="hidden" name="action" value="update_settings">
            <div class="form-grid">
                <div class="form-group">
                    <label>Developer Commission (Portfolio Developer)</label>
                    <div style="display: flex; align-items: center;">
                        <input type="number" name="developer_commission" 
                               value="<?php echo $settings['developer_commission']; ?>" 
                               step="0.1" min="0" max="100" style="width: 100px; margin-right: 8px;">
                        <span>%</span>
                    </div>
                </div>
                <div class="form-group">
                    <label>Default Sales Agent Commission</label>
                    <div style="display: flex; align-items: center;">
                        <input type="number" name="default_agent_commission" 
                               value="<?php echo $settings['default_agent_commission']; ?>" 
                               step="0.1" min="0" max="100" style="width: 100px; margin-right: 8px;">
                        <span>%</span>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn">Update Commission Settings</button>
        </form>
    </div>
    
    <!-- Recent Commissions -->
    <div class="commission-table">
        <h2 style="padding: 20px; margin: 0; background: #f9fafb; border-bottom: 1px solid #e5e7eb;">Recent Commissions</h2>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Recipient</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Rate</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_commissions)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;">
                        No commission records found. 
                        <br><br>
                        <a href="<?php echo path_with_base('/admin/commissions-debug.php'); ?>" style="color: #3b82f6;">Run Debug Test</a> to check system status.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($recent_commissions as $commission): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($commission['created_at'])); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($commission['first_name'] . ' ' . $commission['last_name']); ?></strong>
                            <br><small style="color: #6b7280;"><?php echo htmlspecialchars($commission['email']); ?></small>
                        </td>
                        <td><?php echo ucwords(str_replace('_', ' ', $commission['commission_type'])); ?></td>
                        <td style="font-weight: 600; color: #059669;">$<?php echo number_format($commission['amount'], 2); ?></td>
                        <td><?php echo $commission['percentage']; ?>%</td>
                        <td>
                            <span class="status-badge status-<?php echo $commission['status']; ?>">
                                <?php echo ucfirst($commission['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div style="margin-top: 32px; padding: 20px; background: #f0f9ff; border-radius: 8px;">
        <h3 style="margin: 0 0 12px 0; color: #0369a1;">🔧 Troubleshooting</h3>
        <p style="margin: 0; color: #0369a1;">
            If you're not seeing commission data, visit the 
            <a href="<?php echo path_with_base('/admin/commissions-debug.php'); ?>" style="color: #0369a1; font-weight: 600;">Debug Page</a> 
            to identify any issues with the commission system.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
