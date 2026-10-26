<?php
$SEO = [
    'title' => 'Commission Management — Admin',
    'description' => 'Manage sales commissions, tiers, and payouts.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

require_once __DIR__ . '/../required/commission-utils.php';

$pdo = db();
$message = '';
$error = '';

// Get date range parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Default to today
$agent_filter = $_GET['agent'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

// Handle commission actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_settings':
                $developer_commission = floatval($_POST['developer_commission'] ?? 5);
                $default_agent_commission = floatval($_POST['default_agent_commission'] ?? 10);
                $ai_commission = floatval($_POST['ai_assist_commission'] ?? 0);
                $tier19 = floatval($_POST['tier_1_9'] ?? 20.0);
                $tier1014 = floatval($_POST['tier_10_14'] ?? 15.0);
                $tier1519 = floatval($_POST['tier_15_19'] ?? 12.0);
                $tier20p = floatval($_POST['tier_20_plus'] ?? 10.0);
                
                // Check if settings exist first
                $stmt = $pdo->query("SELECT COUNT(*) FROM commission_settings WHERE setting_key = 'developer_commission'");
                if ($stmt->fetchColumn() == 0) {
                    $stmt = $pdo->prepare("INSERT INTO commission_settings (setting_key, setting_value, description) VALUES ('developer_commission', ?, 'Portfolio Developer developer commission percentage')");
                    $stmt->execute([$developer_commission]);
                } else {
                    $stmt = $pdo->prepare('UPDATE commission_settings SET setting_value = ? WHERE setting_key = ?');
                    $stmt->execute([$developer_commission, 'developer_commission']);
                }
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM commission_settings WHERE setting_key = 'default_agent_commission'");
                if ($stmt->fetchColumn() == 0) {
                    $stmt = $pdo->prepare("INSERT INTO commission_settings (setting_key, setting_value, description) VALUES ('default_agent_commission', ?, 'Default sales agent commission percentage')");
                    $stmt->execute([$default_agent_commission]);
                } else {
                    $stmt = $pdo->prepare('UPDATE commission_settings SET setting_value = ? WHERE setting_key = ?');
                    $stmt->execute([$default_agent_commission, 'default_agent_commission']);
                }
                
                // Upsert helper
                $upsert = function($key, $val, $desc) use ($pdo) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM commission_settings WHERE setting_key = ?");
                    $stmt->execute([$key]);
                    if ($stmt->fetchColumn() == 0) {
                        $ins = $pdo->prepare("INSERT INTO commission_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
                        $ins->execute([$key, $val, $desc]);
                    } else {
                        $upd = $pdo->prepare('UPDATE commission_settings SET setting_value = ? WHERE setting_key = ?');
                        $upd->execute([$val, $key]);
                    }
                };

                $upsert('ai_assist_commission', $ai_commission, 'Global AI assisted commission percent');
                $upsert('tier_1_9', $tier19, 'Default agent commission tier 1-9');
                $upsert('tier_10_14', $tier1014, 'Default agent commission tier 10-14');
                $upsert('tier_15_19', $tier1519, 'Default agent commission tier 15-19');
                $upsert('tier_20_plus', $tier20p, 'Default agent commission tier 20+');

                $message = "Commission settings updated successfully";
                break;
                
            case 'add_commission_recipient':
                $user_id = intval($_POST['user_id'] ?? 0);
                $commission_type = $_POST['commission_type'] ?? 'sales_agent';
                $base_rate = floatval($_POST['base_rate'] ?? 10);
                
                // Add user to commission recipients (we'll track this in user_roles or a custom table)
                // For now, ensure they have the sales_agent role
                if ($commission_type === 'sales_agent') {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = 'sales_agent'");
                    $stmt->execute([$user_id]);
                }
                
                $message = "Commission recipient added successfully";
                break;
                
            case 'approve_commission':
                $commission_id = intval($_POST['commission_id'] ?? 0);
                $stmt = $pdo->prepare('UPDATE commissions SET status = "approved" WHERE id = ?');
                $stmt->execute([$commission_id]);
                $message = "Commission approved";
                break;
                
            case 'mark_paid':
                $commission_ids = $_POST['commission_ids'] ?? [];
                if (!empty($commission_ids)) {
                    $placeholders = str_repeat('?,', count($commission_ids) - 1) . '?';
                    $stmt = $pdo->prepare("UPDATE commissions SET status = 'paid', paid_date = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($commission_ids);
                    $message = count($commission_ids) . " commissions marked as paid";
                }
                break;

            case 'toggle_ai_commission':
                $orderId = intval($_POST['order_id'] ?? 0);
                if ($orderId > 0) {
                    // Get global AI percent
                    $aiPct = 0.0;
                    try {
                        $st = $pdo->prepare("SELECT setting_value FROM commission_settings WHERE setting_key = 'ai_assist_commission'");
                        $st->execute();
                        $aiPct = floatval($st->fetchColumn());
                    } catch (Throwable $e) {}
                    // Check if AI line exists
                    $st = $pdo->prepare("SELECT id FROM commissions WHERE order_id = ? AND commission_type = 'ai_assist' LIMIT 1");
                    $st->execute([$orderId]);
                    $existingId = intval($st->fetchColumn());
                    if ($existingId) {
                        // Remove AI line
                        $pdo->prepare('DELETE FROM commissions WHERE id = ?')->execute([$existingId]);
                        $message = 'AI commission removed for order #' . $orderId;
                    } else {
                        // Compute amount from order subtotal
                        $st = $pdo->prepare('SELECT subtotal FROM orders WHERE id = ?');
                        $st->execute([$orderId]);
                        $subtotal = floatval($st->fetchColumn());
                        $amount = ($subtotal * ($aiPct / 100));
                        $ins = $pdo->prepare("INSERT INTO commissions (order_id, user_id, commission_type, amount, percentage, status) VALUES (?, NULL, 'ai_assist', ?, ?, 'pending')");
                        $ins->execute([$orderId, $amount, $aiPct]);
                        $message = 'AI commission added for order #' . $orderId;
                    }
                }
                break;

            case 'persist_commission_snapshot':
                $commissionId = intval($_POST['commission_id'] ?? 0);
                if ($commissionId <= 0) {
                    throw new Exception('Missing commission id for snapshot.');
                }
                if (!commission_snapshot_for_commission($pdo, $commissionId)) {
                    throw new Exception('Unable to capture commission snapshot.');
                }
                $message = 'Commission snapshot captured for record #' . $commissionId . '.';
                break;
            
            case 'restore_order_from_snapshot':
                $restoreId = intval($_POST['snapshot_order_id'] ?? 0);
                if ($restoreId <= 0) {
                    throw new Exception('Missing order id for restoration.');
                }
                if (commission_restore_order_from_snapshot($pdo, $restoreId)) {
                    $message = 'Order #' . $restoreId . ' restored from snapshot.';
                } else {
                    throw new Exception('Unable to restore invoice from snapshot.');
                }
                break;

            case 'save_agent_tiers':
                $userId = intval($_POST['user_id'] ?? 0);
                if ($userId > 0) {
                    // Optional reset
                    if (!empty($_POST['reset_defaults'])) {
                        $pdo->prepare('DELETE FROM commission_agent_tiers WHERE user_id = ?')->execute([$userId]);
                        $message = 'Tiers reset to defaults for agent ID ' . $userId;
                    } else {
                        // Upsert each provided tier if not empty
                        $tiers = [
                            ['min'=>1,  'max'=>9,  'key'=>'tier_1_9'],
                            ['min'=>10, 'max'=>14, 'key'=>'tier_10_14'],
                            ['min'=>15, 'max'=>19, 'key'=>'tier_15_19'],
                            ['min'=>20, 'max'=>null, 'key'=>'tier_20_plus']
                        ];
                        foreach ($tiers as $t) {
                            $val = trim((string)($_POST[$t['key']] ?? ''));
                            if ($val === '') continue; // inherit default
                            $pct = floatval($val);
                            // delete existing overlap
                            $del = $pdo->prepare('DELETE FROM commission_agent_tiers WHERE user_id = ? AND tier_min = ? AND (tier_max <=> ?)');
                            $del->execute([$userId, $t['min'], $t['max']]);
                            // insert new
                            $ins = $pdo->prepare('INSERT INTO commission_agent_tiers (user_id, tier_min, tier_max, percentage) VALUES (?, ?, ?, ?)');
                            $ins->execute([$userId, $t['min'], $t['max'], $pct]);
                        }
                        $message = 'Tiers saved for agent ID ' . $userId;
                    }
                }
                break;
        }
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
    
    // Redirect to prevent form resubmission (PRG pattern)
    if ($message || $error) {
        if ($message) flash_set('success', $message);
        if ($error) flash_set('error', $error);
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Get all commission recipients (including Portfolio Developer as developer)
// First, ensure Portfolio Developer exists in the system
$developer_id = 1; // Default ID for Portfolio Developer
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR (first_name = ? AND last_name = ?)");
$stmt->execute(['sample-005@example.com', 'Portfolio', 'Developer']);
$developer = $stmt->fetch();
if ($developer) {
    $developer_id = $developer['id'];
} else {
    // Create Portfolio Developer if not exists
    try {
        $stmt = $pdo->prepare("INSERT INTO users (email, first_name, last_name, password_hash, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->execute(['sample-005@example.com', 'Portfolio', 'Developer', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        $developer_id = $pdo->lastInsertId();
        
        // Add admin role
        $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = 'admin'");
        $stmt->execute([$developer_id]);
    } catch (Exception $e) {
        // Developer might already exist with different email
    }
}

// Get all commission recipients
$query = "
    SELECT DISTINCT 
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        CASE 
            WHEN u.id = ? THEN 'developer'
            WHEN r.name = 'sales_agent' THEN 'sales_agent'
            ELSE 'other'
        END as commission_type,
        COUNT(DISTINCT c.id) as total_commissions,
        COALESCE(SUM(CASE WHEN c.created_at BETWEEN ? AND ? THEN c.amount ELSE 0 END), 0) as period_earnings,
        COALESCE(SUM(c.amount), 0) as lifetime_earnings,
        COALESCE(SUM(CASE WHEN c.status = 'paid' THEN c.amount ELSE 0 END), 0) as paid_total,
        COALESCE(SUM(CASE WHEN c.status = 'pending' THEN c.amount ELSE 0 END), 0) as pending_total,
        COUNT(DISTINCT wa.id) as active_accounts,
        COUNT(DISTINCT CASE WHEN o.created_at BETWEEN ? AND ? THEN o.id END) as period_orders
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    LEFT JOIN commissions c ON c.user_id = u.id
    LEFT JOIN wholesale_accounts wa ON wa.sales_agent_id = u.id
    LEFT JOIN orders o ON o.sales_agent_id = u.id
    WHERE u.id = ? OR r.name = 'sales_agent' OR u.id IN (SELECT DISTINCT user_id FROM commissions)
    GROUP BY u.id
    ORDER BY period_earnings DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$developer_id, $start_date, $end_date, $start_date, $end_date, $developer_id]);
$recipients = $stmt->fetchAll();

// Get detailed commission history
$history_query = "
    SELECT 
        c.*,
        u.first_name,
        u.last_name,
        u.email,
        o.order_number,
        o.total as order_total,
        wa.business_name,
        s.snapshot_data,
        s.created_at as snapshot_created_at,
        s.entity_type,
        s.source_table,
        s.source_id
    FROM commissions c
    JOIN users u ON u.id = c.user_id
    LEFT JOIN orders o ON o.id = c.order_id
    LEFT JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
    LEFT JOIN incinerator_snapshots s ON s.id = c.snapshot_id
    WHERE c.created_at BETWEEN ? AND ?
";

$params = [$start_date, $end_date];

if ($agent_filter !== 'all') {
            $history_query .= " AND c.user_id = ?";
            $params[] = $agent_filter;
}

if ($status_filter !== 'all') {
            $history_query .= " AND c.status = ?";
            $params[] = $status_filter;
}

// Optional filter by commission type
$type_filter = $_GET['type'] ?? 'all';
if ($type_filter !== 'all') {
    $history_query .= " AND c.commission_type = ?";
    $params[] = $type_filter;
}

$history_query .= " ORDER BY c.created_at DESC";

try {
    $stmt = $pdo->prepare($history_query);
    $stmt->execute($params);
    $commission_history = $stmt->fetchAll();
} catch (PDOException $snapshotErr) {
    // Gracefully degrade when incinerator snapshots table is absent (initial deploy)
    if ((int)$snapshotErr->errorInfo[1] === 1146 && strpos($snapshotErr->getMessage(), 'incinerator_snapshots') !== false) {
        $history_query = str_replace(
            [
                ',
        s.snapshot_data,
        s.created_at as snapshot_created_at,
        s.entity_type,
        s.source_table,
        s.source_id',
                ' LEFT JOIN incinerator_snapshots s ON s.id = c.snapshot_id'
            ],
            ['', ''],
            $history_query
        );
        $stmt = $pdo->prepare($history_query);
        $stmt->execute($params);
        $commission_history = $stmt->fetchAll();
        $error = $error ?: '';
        $error .= ($error ? ' ' : '') . 'Snapshot archive unavailable — showing commission history without snapshots.';
    } else {
        throw $snapshotErr;
    }
}

// Get performance metrics for selected period
$metrics = [
    'total_sales' => 0,
    'total_commissions' => 0,
    'total_orders' => 0,
    'avg_order_value' => 0,
    'top_performer' => null,
    'growth_rate' => 0
];

try {
    // Total sales for period
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $metrics['total_sales'] = $stmt->fetchColumn();
    
    // Total commissions for period
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $metrics['total_commissions'] = $stmt->fetchColumn();
    
    // Total orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $metrics['total_orders'] = $stmt->fetchColumn();
    
    // Average order value
    if ($metrics['total_orders'] > 0) {
        $metrics['avg_order_value'] = $metrics['total_sales'] / $metrics['total_orders'];
    }
    
    // Calculate growth rate (compare to previous period)
    $period_days = (strtotime($end_date) - strtotime($start_date)) / 86400;
    $prev_start = date('Y-m-d', strtotime($start_date) - ($period_days * 86400));
    $prev_end = date('Y-m-d', strtotime($start_date) - 86400);
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$prev_start, $prev_end]);
    $prev_sales = $stmt->fetchColumn();
    
    if ($prev_sales > 0) {
        $metrics['growth_rate'] = (($metrics['total_sales'] - $prev_sales) / $prev_sales) * 100;
    }
} catch (Exception $e) {
    // Tables might not exist yet
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
    // Table might not exist
}

function commission_get_order_snapshot(PDO $pdo, int $orderId): ?array {
    try {
        $st = $pdo->prepare('SELECT snapshot_data, created_at FROM incinerator_snapshots WHERE entity_type = ? AND source_table = ? AND source_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute(['order', 'orders', $orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $data = json_decode($row['snapshot_data'], true);
            return $data ?: null;
        }
    } catch (Throwable $e) {}
    return null;
}

function commission_restore_order_from_snapshot(PDO $pdo, int $orderId): bool {
    $snapshot = commission_get_order_snapshot($pdo, $orderId);
    if (!$snapshot || empty($snapshot['row'])) {
        return false;
    }
    $data = $snapshot['row'];
    try {
        $pdo->beginTransaction();
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnList = implode(', ', array_map(fn($c) => "`" . $c . "`", $columns));
        $stmt = $pdo->prepare("INSERT INTO orders ({$columnList}) VALUES ({$placeholders})");
        $stmt->execute(array_values($data));
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}
?>

<style>
.admin-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.date-filter {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 24px;
}
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.metric-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.metric-value {
    font-size: 32px;
    font-weight: bold;
    margin: 8px 0;
}
.metric-label {
    color: #6b7280;
    font-size: 13px;
    text-transform: uppercase;
}
.recipients-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.recipient-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    position: relative;
}
.recipient-type {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.type-developer {
    background: #fbbf24;
    color: #92400e;
}
.type-sales_agent {
    background: #3b82f6;
    color: white;
}
.recipient-name {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 4px;
}
.recipient-email {
    color: #6b7280;
    font-size: 14px;
    margin-bottom: 16px;
}
.recipient-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}
.stat-item {
    text-align: center;
}
.stat-value {
    font-size: 20px;
    font-weight: 600;
    color: #059669;
}
.stat-label {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
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
.commission-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
}
.commission-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 14px;
}
.commission-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
}
.commission-table tbody tr:hover {
    background: #f9fafb;
}
.commission-table td:nth-child(6),
.commission-table th:nth-child(6),
.commission-table td:nth-child(7),
.commission-table th:nth-child(7),
.commission-table td:nth-child(8),
.commission-table th:nth-child(8) {
    text-align: right;
    font-variant-numeric: tabular-nums;
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
.btn-small {
    background: #e5e7eb;
    color: #111827;
    padding: 4px 10px;
    border-radius: 6px;
    border: 1px solid #d1d5db;
    cursor: pointer;
}
.btn-small:hover { background: #f3f4f6; }
.btn-small.secondary { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
.btn-small.secondary:hover { background: #dbeafe; }
.btn-small.success { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
.btn-small.success:hover { background: #bbf7d0; }
.growth-indicator {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    margin-left: 8px;
}
.growth-positive { background: #dcfce7; color: #14532d; }
.growth-negative { background: #fee2e2; color: #991b1b; }
</style>

<div class="admin-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">Commission Management</h1>
        <p style="font-size: 18px; opacity: 0.9;">Track performance, manage payouts, and analyze commission data</p>
    </div>
</div>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <?php if ($message): ?>
        <div class="card" style="background: #dcfce7; color: #14532d; margin-bottom: 24px;">
            ✅ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Date Range Filter -->
    <div class="date-filter">
        <form method="get" style="display: flex; gap: 16px; align-items: end; flex-wrap: wrap;">
            <div>
                <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px;">Start Date</label>
                <input type="date" name="start_date" value="<?php echo $start_date; ?>" 
                       style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px;">End Date</label>
                <input type="date" name="end_date" value="<?php echo $end_date; ?>" 
                       style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px;">Agent</label>
                <select name="agent" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <option value="all">All Agents</option>
                    <?php foreach ($recipients as $recipient): ?>
                        <option value="<?php echo $recipient['id']; ?>" <?php echo $agent_filter == $recipient['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($recipient['first_name'] . ' ' . $recipient['last_name']); ?>
                            <?php if ($recipient['commission_type'] === 'developer'): ?>(Developer)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px;">Status</label>
                <select name="status" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <option value="all">All Status</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px;">Type</label>
                <select name="type" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <option value="all">All Types</option>
                    <option value="sales_agent" <?php echo ($type_filter ?? 'all') === 'sales_agent' ? 'selected' : ''; ?>>Sales Agent</option>
                    <option value="developer" <?php echo ($type_filter ?? 'all') === 'developer' ? 'selected' : ''; ?>>Developer</option>
                    <option value="ai_assist" <?php echo ($type_filter ?? 'all') === 'ai_assist' ? 'selected' : ''; ?>>AI Assisted</option>
                </select>
            </div>
            <button type="submit" class="btn">Apply Filters</button>
            <button type="button" onclick="window.print()" class="btn secondary">Print Report</button>
        </form>
    </div>
    
    <!-- Period Metrics -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-label">Total Sales</div>
            <div class="metric-value">$<?php echo number_format($metrics['total_sales'], 2); ?></div>
            <?php if ($metrics['growth_rate'] != 0): ?>
                <span class="growth-indicator <?php echo $metrics['growth_rate'] > 0 ? 'growth-positive' : 'growth-negative'; ?>">
                    <?php echo $metrics['growth_rate'] > 0 ? '↑' : '↓'; ?> <?php echo abs(round($metrics['growth_rate'], 1)); ?>%
                </span>
            <?php endif; ?>
        </div>
        <div class="metric-card">
            <div class="metric-label">Total Commissions</div>
            <div class="metric-value" style="color: #f59e0b;">$<?php echo number_format($metrics['total_commissions'], 2); ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Total Orders</div>
            <div class="metric-value"><?php echo $metrics['total_orders']; ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Avg Order Value</div>
            <div class="metric-value">$<?php echo number_format($metrics['avg_order_value'], 2); ?></div>
        </div>
    </div>
    
    <!-- Commission Recipients -->
    <h2 style="margin-bottom: 20px;">Commission Recipients</h2>
    <div class="recipients-grid">
        <?php foreach ($recipients as $recipient): ?>
        <div class="recipient-card">
            <span class="recipient-type type-<?php echo $recipient['commission_type']; ?>">
                <?php echo ucwords(str_replace('_', ' ', $recipient['commission_type'])); ?>
            </span>
            <div class="recipient-name"><?php echo htmlspecialchars($recipient['first_name'] . ' ' . $recipient['last_name']); ?></div>
            <div class="recipient-email"><?php echo htmlspecialchars($recipient['email']); ?></div>
            
            <div class="recipient-stats">
                <div class="stat-item">
                    <div class="stat-value">$<?php echo number_format($recipient['period_earnings'], 2); ?></div>
                    <div class="stat-label">Period Earnings</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">$<?php echo number_format($recipient['lifetime_earnings'], 2); ?></div>
                    <div class="stat-label">Lifetime</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $recipient['period_orders']; ?></div>
                    <div class="stat-label">Orders</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $recipient['active_accounts']; ?></div>
                    <div class="stat-label">Accounts</div>
                </div>
            </div>
            
            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5e7eb;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="color: #6b7280; font-size: 14px;">Pending:</span>
                    <span style="color: #f59e0b; font-weight: 600;">$<?php echo number_format($recipient['pending_total'], 2); ?></span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #6b7280; font-size: 14px;">Paid:</span>
                    <span style="color: #059669; font-weight: 600;">$<?php echo number_format($recipient['paid_total'], 2); ?></span>
                </div>
            </div>
            
            <details style="margin-top:16px;">
                <summary style="cursor:pointer; font-weight:600;">Edit Tiers</summary>
                <form method="post" style="margin-top:10px; display:grid; gap:8px;">
                    <input type="hidden" name="action" value="save_agent_tiers">
                    <input type="hidden" name="user_id" value="<?php echo $recipient['id']; ?>">
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:8px; align-items:center;">
                        <span>1–9</span>
                        <input type="number" name="tier_1_9" step="0.1" min="0" max="100" placeholder="inherit" style="padding:6px;">
                        <span>10–14</span>
                        <input type="number" name="tier_10_14" step="0.1" min="0" max="100" placeholder="inherit" style="padding:6px;">
                        <span>15–19</span>
                        <input type="number" name="tier_15_19" step="0.1" min="0" max="100" placeholder="inherit" style="padding:6px;">
                        <span>20+</span>
                        <input type="number" name="tier_20_plus" step="0.1" min="0" max="100" placeholder="inherit" style="padding:6px;">
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="submit" class="btn small">Save</button>
                        <button type="submit" name="reset_defaults" value="1" class="btn small secondary">Reset to Defaults</button>
                    </div>
                </form>
            </details>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Product Group Commission Overview -->
    <?php
    try {
        $stmt = $pdo->query("
            SELECT 
                pg.id, pg.name, pg.use_custom_commission,
                COUNT(DISTINCT p.id) as product_count,
                COUNT(DISTINCT pgc.id) as tier_count
            FROM product_groups pg
            LEFT JOIN products p ON p.product_group_id = pg.id AND p.active = 1
            LEFT JOIN product_group_commissions pgc ON pgc.product_group_id = pg.id AND pgc.active = 1
            WHERE pg.active = 1
            GROUP BY pg.id
            ORDER BY pg.display_order, pg.name
        ");
        $productGroups = $stmt->fetchAll();
        
        if (!empty($productGroups)):
    ?>
    <div class="card" style="margin-bottom: 32px; background: linear-gradient(135deg, #f0fdf4, #dcfce7);">
        <h2 style="margin-bottom: 20px; color: #14532d;">📦 Product Group Commission Structures</h2>
        <p style="color: #374151; margin-bottom: 16px;">
            Commission rates are configured per product group. Each group can have custom tiers based on order quantity.
        </p>
        
        <div style="display: grid; gap: 16px;">
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
                
                <div style="background: white; padding: 16px; border-radius: 8px; border: 2px solid #e5e7eb;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <div>
                            <h3 style="margin: 0; color: #059669;"><?php echo htmlspecialchars($group['name']); ?></h3>
                            <p style="margin: 4px 0 0 0; font-size: 13px; color: #6b7280;">
                                <?php echo $group['product_count']; ?> product<?php echo $group['product_count'] != 1 ? 's' : ''; ?>
                                <?php if ($group['use_custom_commission']): ?>
                                    • Custom commission structure
                                <?php else: ?>
                                    • Uses default tiers
                                <?php endif; ?>
                            </p>
                        </div>
                        <a href="<?php echo path_with_base('/admin/product-groups.php'); ?>" class="btn small secondary">
                            Manage
                        </a>
                    </div>
                    
                    <?php if ($group['use_custom_commission'] && !empty($groupTiers)): ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; margin-top: 12px;">
                            <?php foreach ($groupTiers as $tier): ?>
                            <div style="background: #f9fafb; padding: 10px; border-radius: 6px; text-align: center; border: 1px solid #e5e7eb;">
                                <div style="font-size: 16px; font-weight: bold; color: #059669;">
                                    <?php echo number_format($tier['commission_percent'], 1); ?>%
                                </div>
                                <div style="font-size: 11px; color: #6b7280; margin-top: 2px;">
                                    <?php echo htmlspecialchars($tier['tier_name']); ?>
                                </div>
                                <?php if ($tier['bulk_discount_percent'] > 0): ?>
                                <div style="font-size: 10px; color: #dc2626; margin-top: 2px;">
                                    <?php echo number_format($tier['bulk_discount_percent'], 0); ?>% discount
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif (!$group['use_custom_commission']): ?>
                        <div style="background: #fef3c7; padding: 8px 12px; border-radius: 6px; margin-top: 8px;">
                            <small style="color: #92400e;">Using default commission tiers (configured below)</small>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div style="margin-top: 16px; padding: 16px; background: rgba(255,255,255,0.7); border-radius: 8px; border: 1px dashed #10b981;">
            <p style="margin: 0; font-size: 13px; color: #14532d;">
                💡 <strong>Tip:</strong> Manage product groups, assign products, and configure commission tiers in the 
                <a href="<?php echo path_with_base('/admin/product-groups.php'); ?>" style="color: #14532d; font-weight: 600; text-decoration: underline;">Product Groups Management</a> panel.
            </p>
        </div>
    </div>
    <?php 
        endif;
    } catch (Throwable $e) {
        // Silently fail if product_groups table doesn't exist yet
    }
    ?>
    
    <!-- Cascading Commission Explanation -->
    <div class="card" style="margin-bottom: 32px; background: linear-gradient(135deg, #ecfdf5, #d1fae5); border-left: 4px solid #059669;">
        <h2 style="margin-bottom: 16px; color: #065f46;">💡 Cascading Commission Structure</h2>
        <div style="background: white; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
            <p style="margin: 0 0 12px 0; color: #374151; line-height: 1.6;">
                <strong>Effective October 15, 2025:</strong> Commissions now calculate using a cascading structure that ensures sales agents always receive their full commission percentage.
            </p>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin: 16px 0;">
                <div style="background: #f0fdf4; padding: 12px; border-radius: 8px; border: 2px solid #86efac;">
                    <div style="font-size: 12px; font-weight: 600; color: #166534; margin-bottom: 4px;">STEP 1: Agent</div>
                    <div style="font-size: 14px; color: #166534;">From <strong>Subtotal</strong></div>
                    <div style="font-size: 20px; font-weight: 700; color: #059669; margin-top: 8px;">Full Commission</div>
                </div>
                <div style="background: #fef3c7; padding: 12px; border-radius: 8px; border: 2px solid #fbbf24;">
                    <div style="font-size: 12px; font-weight: 600; color: #92400e; margin-bottom: 4px;">STEP 2: Developer</div>
                    <div style="font-size: 14px; color: #78350f;">From <strong>Remaining</strong></div>
                    <div style="font-size: 16px; color: #92400e; margin-top: 8px;">(After Agent)</div>
                </div>
                <div style="background: #dbeafe; padding: 12px; border-radius: 8px; border: 2px solid #60a5fa;">
                    <div style="font-size: 12px; font-weight: 600; color: #1e40af; margin-bottom: 4px;">STEP 3: AI</div>
                    <div style="font-size: 14px; color: #1e3a8a;">From <strong>Remaining</strong></div>
                    <div style="font-size: 16px; color: #1e40af; margin-top: 8px;">(After Dev)</div>
                </div>
            </div>
            <div style="background: #f8fafc; padding: 12px; border-radius: 8px; margin-top: 12px;">
                <div style="font-size: 13px; color: #334155; margin-bottom: 8px;"><strong>Example Calculation:</strong></div>
                <div style="font-family: monospace; font-size: 12px; color: #1e293b; line-height: 1.8;">
                    Order Subtotal: $1,000<br>
                    Agent (20%): $1,000 × 20% = <strong style="color: #059669;">$200</strong><br>
                    Developer (5%): ($1,000 - $200) × 5% = <strong style="color: #b45309;">$40</strong><br>
                    AI (2%): ($800 - $40) × 2% = <strong style="color: #0369a1;">$15.20</strong><br>
                    <span style="color: #059669; font-weight: 600;">✓ Agent always gets full $200, no perceived loss</span>
                </div>
            </div>
        </div>
        <div style="display: flex; justify-content: space-between; gap: 12px;">
            <a href="<?php echo path_with_base('/admin/ai-cost-management.php'); ?>" class="btn secondary" style="flex: 1;">
                🤖 AI Cost Management
            </a>
            <a href="<?php echo path_with_base('/admin/tools-agent-ai-settings.php'); ?>" class="btn secondary" style="flex: 1;">
                ⚙️ Agent AI Settings
            </a>
        </div>
    </div>
    
    <?php
    // Get AI usage statistics
    try {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_calls,
                SUM(estimated_cost) as total_cost,
                COUNT(DISTINCT agent_id) as agents_using_ai,
                MAX(usage_date) as last_usage
            FROM agent_ai_usage_log
            WHERE usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $aiStats = $stmt->fetch();
        
        $stmt = $pdo->query("
            SELECT COUNT(*) as agents_charged
            FROM agent_ai_charging_settings
            WHERE is_active = 1
        ");
        $chargedCount = $stmt->fetchColumn();
        
        if ($aiStats && $aiStats['total_calls'] > 0):
    ?>
    <!-- AI Usage Statistics -->
    <div class="card" style="margin-bottom: 32px; background: linear-gradient(135deg, #f0fdfa, #ccfbf1); border-left: 4px solid #0d9488;">
        <h2 style="margin-bottom: 16px; color: #115e59;">📊 AI Usage Statistics (Last 30 Days)</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
            <div style="background: white; padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 32px; font-weight: 700; color: #0d9488;">
                    <?php echo number_format($aiStats['total_calls']); ?>
                </div>
                <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">Total AI Calls</div>
            </div>
            <div style="background: white; padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 32px; font-weight: 700; color: #dc2626;">
                    $<?php echo number_format($aiStats['total_cost'], 2); ?>
                </div>
                <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">Total AI Cost</div>
            </div>
            <div style="background: white; padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 32px; font-weight: 700; color: #7c3aed;">
                    <?php echo $aiStats['agents_using_ai']; ?>
                </div>
                <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">Agents Using AI</div>
            </div>
            <div style="background: white; padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 32px; font-weight: 700; color: #f59e0b;">
                    <?php echo $chargedCount; ?>
                </div>
                <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">Agents Charged</div>
            </div>
        </div>
    </div>
    <?php 
        endif;
    } catch (Throwable $e) {
        // AI tracking tables may not exist yet
    }
    ?>
    
    <!-- Commission Settings -->
    <div class="card" style="margin-bottom: 32px;">
        <h2 style="margin-bottom: 20px;">Global Commission Settings</h2>
        <form method="post">
            <input type="hidden" name="action" value="update_settings">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <label style="display: block; margin-bottom: 8px;">
                        <strong>Developer Commission (Portfolio Developer)</strong>
                        <input type="number" name="developer_commission" 
                               value="<?php echo $settings['developer_commission']; ?>" 
                               step="0.1" min="0" max="100"
                               style="width: 100px; margin-left: 12px; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <span>%</span>
                    </label>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px;">
                        <strong>Default Agent Commission</strong>
                        <input type="number" name="default_agent_commission" 
                               value="<?php echo $settings['default_agent_commission']; ?>" 
                               step="0.1" min="0" max="100"
                               style="width: 100px; margin-left: 12px; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <span>%</span>
                    </label>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px;">
                        <strong>AI Assisted Commission (Global)</strong>
                        <input type="number" name="ai_assist_commission" 
                               value="<?php echo $settings['ai_assist_commission'] ?? '0.00'; ?>" 
                               step="0.1" min="0" max="100"
                               style="width: 100px; margin-left: 12px; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px;">
                        <span>%</span>
                    </label>
                </div>
                <div>
                    <label style="display:block; margin-bottom:8px;"><strong>Default Agent Tiers (Ungrouped Products)</strong></label>
                    <div style="background: #dbeafe; padding: 12px; border-radius: 8px; margin-bottom: 12px; border-left: 4px solid #3b82f6;">
                        <p style="margin: 0; font-size: 13px; color: #1e40af;">
                            ℹ️ <strong>Note:</strong> These tiers apply to products <strong>not assigned to a product group</strong>. Product group-specific commission rates are managed in:
                        </p>
                        <p style="margin: 8px 0 0 0; font-size: 12px; color: #1e40af;">
                            <a href="<?php echo path_with_base('/admin/product-groups.php'); ?>" style="color: #1e40af; font-weight: 600; text-decoration: underline;">📦 Product Groups Management</a>
                        </p>
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:8px; align-items:center;">
                        <span>1–9</span>
                        <input type="number" name="tier_1_9" value="<?php echo $settings['tier_1_9'] ?? '20.0'; ?>" step="0.1" min="0" max="100" style="padding:6px;">
                        <span>10–14</span>
                        <input type="number" name="tier_10_14" value="<?php echo $settings['tier_10_14'] ?? '15.0'; ?>" step="0.1" min="0" max="100" style="padding:6px;">
                        <span>15–19</span>
                        <input type="number" name="tier_15_19" value="<?php echo $settings['tier_15_19'] ?? '12.0'; ?>" step="0.1" min="0" max="100" style="padding:6px;">
                        <span>20+</span>
                        <input type="number" name="tier_20_plus" value="<?php echo $settings['tier_20_plus'] ?? '10.0'; ?>" step="0.1" min="0" max="100" style="padding:6px;">
                    </div>
                </div>
            </div>
            <button type="submit" class="btn" style="margin-top: 16px; background: var(--green); color: white;">
                Update Settings
            </button>
        </form>
    </div>
    
    <!-- Commission History -->
    <h2 style="margin-bottom: 20px;">Commission History</h2>
    <div class="commission-table">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Recipient</th>
                    <th>Type</th>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Order Total</th>
                    <th>Commission</th>
                        <th>Line Rate</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($commission_history)): ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 40px; color: #6b7280;">
                        No commissions found for the selected period
                    </td>
                </tr>
                <?php else: ?>
                    <tr>
                        <th colspan="10" style="background:#eef2ff;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <label style="display:flex; align-items:center; gap:6px; font-size:12px;">
                                    <input type="checkbox" id="commission-select-all">
                                    <span>Select all visible</span>
                                </label>
                                <button type="button" class="btn secondary" style="font-size:12px; padding:4px 8px;" onclick="return bulkIncinerateCommissions();">Incinerate Selected</button>
                            </div>
                        </th>
                    </tr>
                    <?php foreach ($commission_history as $commission): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($commission['created_at'])); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($commission['first_name'] . ' ' . $commission['last_name']); ?></strong>
                            <br><small style="color: #6b7280;"><?php echo htmlspecialchars($commission['email']); ?></small>
                        </td>
                        <td><?php echo ucwords(str_replace('_', ' ', $commission['commission_type'])); ?></td>
                        <td>
    <?php echo htmlspecialchars($commission['order_number'] ?? 'N/A'); ?>
    <?php if (!empty($commission['order_id'])): ?>
    <button type="button" class="btn-small" style="margin-left:6px;" onclick="return openOrderDetailModal(<?php echo intval($commission['order_id']); ?>)">View</button>
    <form method="post" style="display:inline-block; margin-left:6px;">
        <input type="hidden" name="order_id" value="<?php echo intval($commission['order_id']); ?>">
        <input type="hidden" name="action" value="toggle_ai_commission">
        <button type="submit" class="btn-small secondary">Toggle AI</button>
    </form>
    <?php elseif (!empty($commission['snapshot_payload'])): ?>
    <details style="margin-top:8px;">
        <summary style="cursor:pointer; font-size:12px;">View Snapshot</summary>
        <pre style="background:#f8fafc; padding:12px; border-radius:8px; max-height:240px; overflow:auto; text-align:left;">
<?php echo htmlspecialchars(json_encode($commission['snapshot_payload'], JSON_PRETTY_PRINT)); ?>
        </pre>
        <?php if (!empty($commission['snapshot_payload']['row']['id'])): ?>
        <form method="post" style="margin-top:8px;">
            <input type="hidden" name="action" value="restore_order_from_snapshot">
            <input type="hidden" name="snapshot_order_id" value="<?php echo intval($commission['snapshot_payload']['row']['id']); ?>">
            <button type="submit" class="btn-small secondary">Restore Invoice</button>
        </form>
        <?php endif; ?>
    </details>
    <?php endif; ?>
</td>
                        <td><?php echo htmlspecialchars($commission['business_name'] ?? 'N/A'); ?></td>
                        <td>$<?php echo number_format($commission['order_total'] ?? 0, 2); ?></td>
                        <td style="font-weight: 600; color: #059669;">
                            $<?php echo number_format($commission['amount'], 2); ?>
                        </td>
                        <td>
                            <?php 
                            // Display percentage only when present and meaningful per line.
                            echo is_null($commission['percentage']) || $commission['percentage'] === '' ? '—' : ($commission['percentage'] . '%');
                            ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $commission['status']; ?>">
                                <?php echo ucfirst($commission['status']); ?>
                            </span>
                            <?php 
                            // Show AI email indicator if this commission is linked to an AI-assisted email
                            if (!empty($commission['source_email_id'])) {
                                try {
                                    $emailCheck = $pdo->prepare('SELECT is_ai_assisted, subject FROM agent_emails WHERE id = ?');
                                    $emailCheck->execute([intval($commission['source_email_id'])]);
                                    $emailData = $emailCheck->fetch();
                                    if ($emailData && $emailData['is_ai_assisted']) {
                                        echo '<br><span class="badge" style="background:#dbeafe;color:#1e40af;margin-top:4px;" title="Originated from AI-assisted email: '.htmlspecialchars($emailData['subject']).'">🤖 AI Email</span>';
                                    }
                                } catch (Throwable $e) {}
                            }
                            ?>
                        </td>
                        <td>
                            <?php if (!empty($commission['order_id'])): ?>
                                <label style="display:flex; align-items:center; gap:6px; font-size:12px;">
                                    <input type="checkbox" class="commission-checkbox" value="<?php echo $commission['id']; ?>">
                                    <span>Select</span>
                                </label>
                            <?php endif; ?>
                            <?php if ($commission['status'] === 'pending'): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="approve_commission">
                                    <input type="hidden" name="commission_id" value="<?php echo $commission['id']; ?>">
                                    <button type="submit" class="btn-small" style="background: #059669; color: white; padding: 4px 8px; font-size: 12px; border: none; border-radius: 4px;">
                                        Approve
                                    </button>
                                </form>
                            <?php elseif ($commission['status'] === 'approved'): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="commission_ids[]" value="<?php echo $commission['id']; ?>">
                                <button type="submit" class="btn-small" style="background: #3b82f6; color: white; padding: 4px 8px; font-size: 12px; border: none; border-radius: 4px;">
                                    Mark Paid
                                </button>
                            </form>
                        <?php endif; ?>
                        <button type="button" class="btn-small" style="background:#111827; color:#fff; margin-top:6px;" onclick="incinerateSingleCommission(<?php echo $commission['id']; ?>)">Incinerate</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        </table>
    </div>
</div>

<script>
let commissionIncineratorPending = null;

function showCommissionIncineratorWarning(pendingFn) {
    commissionIncineratorPending = pendingFn;
    const overlay = document.getElementById('commission-incinerator-warning');
    if (overlay) {
        overlay.style.display = 'flex';
        const input = document.getElementById('commission-incinerate-confirm-input');
        if (input) {
            input.value = '';
            setTimeout(() => input.focus(), 30);
        }
    }
}

function hideCommissionIncineratorWarning(clearPending = false) {
    if (clearPending) commissionIncineratorPending = null;
    const overlay = document.getElementById('commission-incinerator-warning');
    if (overlay) overlay.style.display = 'none';
}

function confirmCommissionIncineration() {
    const input = document.getElementById('commission-incinerate-confirm-input');
    if (!input || input.value.trim().toUpperCase() !== 'INCINERATE COMMISSIONS') {
        alert('Please type INCINERATE COMMISSIONS to confirm.');
        return;
    }
    const pending = commissionIncineratorPending;
    commissionIncineratorPending = null;
    hideCommissionIncineratorWarning();
    if (typeof pending === 'function') pending();
}

const commissionIncineratorInput = document.getElementById('commission-incinerate-confirm-input');
if (commissionIncineratorInput) {
    commissionIncineratorInput.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmCommissionIncineration();
        }
    });
}

function incinerateSingleCommission(id) {
    showCommissionIncineratorWarning(() => {
        executeCommissionIncineration([id]);
    });
}

function bulkIncinerateCommissions() {
    const ids = Array.from(document.querySelectorAll('.commission-checkbox:checked')).map(cb => parseInt(cb.value, 10)).filter(Boolean);
    if (!ids.length) {
        alert('Select at least one commission to incinerate.');
        return false;
    }
    showCommissionIncineratorWarning(() => {
        executeCommissionIncineration(ids);
    });
    return false;
}

async function executeCommissionIncineration(ids) {
    try {
        const res = await fetch('<?=outbound_path('/api/admin-incinerator.php?action=incinerate_commission')?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({commission_ids: ids})
        });
        const json = await res.json();
        if (!json.ok) {
            throw new Error(json.error || 'Incineration failed');
        }
        alert(`Processed ${json.count || ids.length} commission(s). Paid or fulfilled commissions were skipped automatically.`);
        window.location.reload();
    } catch (err) {
        alert(err.message || 'Failed to incinerate commissions.');
    }
}

const commissionSelectAll = document.getElementById('commission-select-all');
if (commissionSelectAll) {
    commissionSelectAll.addEventListener('change', function(){
        const checked = this.checked;
        document.querySelectorAll('.commission-checkbox').forEach(cb => cb.checked = checked);
    });
}
function viewAgentDetails(agentId) {
    // In a real implementation, this would open a modal or navigate to a detailed view
    window.location.href = '/admin/agent-performance.php?id=' + agentId;
}

function openOrderDetailModal(orderId){
    var m = document.getElementById('orderDetailModal');
    var b = document.getElementById('orderDetailBody');
    if (!m || !b) return false;
    b.innerHTML = '<div style="padding:24px; color:#6b7280;">Loading…</div>';
    m.style.display = 'block';
    document.body.style.overflow = 'hidden';
    fetch('/onboarding/sales-agent/invoice-detail.php?partial=1&id=' + encodeURIComponent(orderId))
        .then(function(r){ return r.text(); })
        .then(function(html){
            try {
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                var main = tmp.querySelector('.container') || tmp;
                b.innerHTML = main.innerHTML || html;
            } catch(e){ b.innerHTML = html; }
        })
        .catch(function(){ b.innerHTML = '<div style="padding:24px; color:#991b1b;">Failed to load.</div>'; });
    return false;
}
function closeOrderDetailModal(){
    var m = document.getElementById('orderDetailModal');
    if (m) m.style.display = 'none';
    var b = document.getElementById('orderDetailBody');
    if (b) b.innerHTML = '';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeOrderDetailModal(); });
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

<!-- Order Detail Modal (Admin) -->
<div id="orderDetailModal" style="display:none; position:fixed; inset:0; background: rgba(0,0,0,0.5); z-index: 6000;">
    <div style="position:absolute; top:5%; left:50%; transform:translateX(-50%); width:min(1100px,95vw); height:90vh; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border-bottom:1px solid #e5e7eb; background:#f9fafb;">
            <div style="font-weight:700; color:#111827;">Invoice Detail</div>
            <button class="btn secondary small" onclick="closeOrderDetailModal()">Close</button>
        </div>
        <div id="orderDetailBody" style="height: calc(100% - 44px); overflow:auto;">
        </div>
    </div>
    <div onclick="closeOrderDetailModal()" style="position:absolute; inset:0;"></div>
</div>

<form id="commissionRestoreForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="restore_order_from_snapshot">
            <input type="hidden" name="snapshot_order_id" id="commission-restore-id">
</form>

<div id="commission-incinerator-warning" style="display:none; position:fixed; inset:0; background:rgba(17,24,39,0.88); z-index:3000; align-items:center; justify-content:center;">
    <div style="background:#ffffff; color:#111827; padding:32px; max-width:520px; width:92%; border-radius:16px; box-shadow:0 25px 50px -12px rgba(30,41,59,0.45);">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
            <span style="font-size:34px;">🔥</span>
            <div>
                <h2 style="margin:0; font-size:22px;">Commission Incinerator</h2>
                <p style="margin:4px 0 0; color:#6b7280; font-size:13px;">Permanently remove unpaid commissions and archived invoice snapshots. Paid commissions are skipped automatically.</p>
            </div>
        </div>
        <ul style="margin:0 0 16px 20px; color:#374151; font-size:13px;">
            <li>Incineration removes selected commission records and their invoice snapshots.</li>
            <li>Paid or fulfilled commissions are preserved to avoid accidental loss.</li>
            <li>Type <code style="background:#f3f4f6; padding:2px 6px; border-radius:4px;">INCINERATE COMMISSIONS</code> to confirm.</li>
        </ul>
        <input id="commission-incinerate-confirm-input" type="text" placeholder="Type INCINERATE COMMISSIONS" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; margin-bottom:16px;">
        <div style="display:flex; justify-content:flex-end; gap:12px;">
            <button type="button" class="btn secondary" onclick="hideCommissionIncineratorWarning(true)">Cancel</button>
            <button type="button" class="btn" style="background:#111827;" onclick="confirmCommissionIncineration()">Proceed</button>
        </div>
    </div>
</div>
