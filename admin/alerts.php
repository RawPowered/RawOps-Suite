<?php
$SEO = [
    'title' => 'Admin Alerts — Product Admin',
    'description' => 'Manage training alerts and system notifications.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Handle alert actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'mark_read') {
            $alertIds = $_POST['alert_ids'] ?? [];
            if (!empty($alertIds)) {
                $placeholders = str_repeat('?,', count($alertIds) - 1) . '?';
                $stmt = $pdo->prepare("UPDATE training_admin_alerts SET is_read = 1 WHERE id IN ($placeholders)");
                $stmt->execute($alertIds);
                flash_set('success', count($alertIds) . ' alert(s) marked as read.');
            }
        }
        
        elseif ($action === 'mark_all_read') {
            $stmt = $pdo->query('UPDATE training_admin_alerts SET is_read = 1 WHERE is_read = 0');
            $affected = $stmt->rowCount();
            flash_set('success', "All {$affected} alerts marked as read.");
        }
        
        elseif ($action === 'delete_old') {
            $stmt = $pdo->query('DELETE FROM training_admin_alerts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
            $affected = $stmt->rowCount();
            flash_set('success', "Deleted {$affected} old alerts (30+ days).");
        }
        
        elseif ($action === 'create_alert') {
            $userId = intval($_POST['user_id']);
            $moduleId = !empty($_POST['module_id']) ? intval($_POST['module_id']) : null;
            $alertType = trim($_POST['alert_type']);
            $message = trim($_POST['alert_message']);
            $severity = $_POST['severity'];
            
            $stmt = $pdo->prepare('INSERT INTO training_admin_alerts (user_id, module_id, alert_type, alert_message, severity) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $moduleId, $alertType, $message, $severity]);
            
            flash_set('success', 'Manual alert created successfully.');
        }
        
    } catch (Exception $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
    }
    
    redirect($_SERVER['REQUEST_URI']);
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'unread';
$severity = $_GET['severity'] ?? 'all';

// Build query
$whereConditions = [];
$params = [];

if ($filter === 'unread') {
    $whereConditions[] = 'taa.is_read = 0';
} elseif ($filter === 'read') {
    $whereConditions[] = 'taa.is_read = 1';
}

if ($severity !== 'all') {
    $whereConditions[] = 'taa.severity = ?';
    $params[] = $severity;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get alerts
$stmt = $pdo->prepare("
    SELECT 
        taa.*,
        u.first_name, u.last_name, u.email,
        tm.title as module_title
    FROM training_admin_alerts taa
    JOIN users u ON u.id = taa.user_id
    LEFT JOIN training_modules tm ON tm.id = taa.module_id
    $whereClause
    ORDER BY taa.severity DESC, taa.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$alerts = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => $pdo->query('SELECT COUNT(*) FROM training_admin_alerts')->fetchColumn(),
    'unread' => $pdo->query('SELECT COUNT(*) FROM training_admin_alerts WHERE is_read = 0')->fetchColumn(),
    'critical' => $pdo->query('SELECT COUNT(*) FROM training_admin_alerts WHERE severity = "critical" AND is_read = 0')->fetchColumn(),
    'high' => $pdo->query('SELECT COUNT(*) FROM training_admin_alerts WHERE severity = "high" AND is_read = 0')->fetchColumn(),
    'today' => $pdo->query('SELECT COUNT(*) FROM training_admin_alerts WHERE DATE(created_at) = CURDATE()')->fetchColumn()
];

// Get users for manual alert creation
$users = $pdo->query('SELECT id, first_name, last_name, email FROM users ORDER BY first_name, last_name')->fetchAll();
$modules = $pdo->query('SELECT id, title FROM training_modules ORDER BY sort_order')->fetchAll();
?>

<style>
.alerts-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.alerts-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.filter-tabs {
    display: flex;
    gap: 8px;
    background: #f3f4f6;
    padding: 4px;
    border-radius: 8px;
}

.filter-tab {
    padding: 8px 16px;
    border: none;
    background: none;
    border-radius: 6px;
    cursor: pointer;
    text-decoration: none;
    color: #6b7280;
    font-weight: 500;
    transition: all 0.3s;
}

.filter-tab.active {
    background: #059669;
    color: white;
}

.filter-tab:hover:not(.active) {
    background: #e5e7eb;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    padding: 16px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    text-align: center;
}

.stat-value {
    font-size: 24px;
    font-weight: bold;
    margin-bottom: 4px;
}

.stat-label {
    color: #6b7280;
    font-size: 12px;
    text-transform: uppercase;
}

.alerts-list {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}

.alert-item {
    padding: 20px;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    gap: 16px;
    align-items: start;
}

.alert-item:last-child {
    border-bottom: none;
}

.alert-item.unread {
    background: #fefefe;
    border-left: 4px solid #059669;
}

.alert-checkbox {
    margin-top: 4px;
}

.alert-severity {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    min-width: 70px;
    text-align: center;
}

.severity-critical { background: #fef2f2; color: #dc2626; }
.severity-high { background: #fff7ed; color: #ea580c; }
.severity-medium { background: #fffbeb; color: #f59e0b; }
.severity-low { background: #f0fdf4; color: #059669; }

.alert-content {
    flex: 1;
}

.alert-type {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.alert-message {
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 8px;
}

.alert-meta {
    font-size: 12px;
    color: #6b7280;
}

.bulk-actions {
    background: #f9fafb;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 24px;
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.create-alert-form {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    margin-bottom: 24px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #374151;
}

.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
}
</style>

<div class="alerts-container">
    <div class="alerts-header">
        <div>
            <h1 style="color: #059669; margin: 0 0 8px 0;">🚨 Admin Alerts</h1>
            <p style="color: #6b7280; margin: 0;">Monitor and manage training system alerts</p>
        </div>
        
        <div class="filter-tabs">
            <a href="?filter=unread&severity=<?php echo $severity; ?>" class="filter-tab <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                Unread (<?php echo $stats['unread']; ?>)
            </a>
            <a href="?filter=all&severity=<?php echo $severity; ?>" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                All (<?php echo $stats['total']; ?>)
            </a>
            <a href="?filter=read&severity=<?php echo $severity; ?>" class="filter-tab <?php echo $filter === 'read' ? 'active' : ''; ?>">
                Read
            </a>
        </div>
    </div>
    
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value" style="color: #dc2626;"><?php echo $stats['critical']; ?></div>
            <div class="stat-label">Critical</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #ea580c;"><?php echo $stats['high']; ?></div>
            <div class="stat-label">High Priority</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #f59e0b;"><?php echo $stats['today']; ?></div>
            <div class="stat-label">Today</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #059669;"><?php echo $stats['unread']; ?></div>
            <div class="stat-label">Unread</div>
        </div>
    </div>
    
    <!-- Severity Filter -->
    <div style="margin-bottom: 16px;">
        <label style="font-weight: 600; margin-right: 12px;">Filter by Severity:</label>
        <select onchange="window.location.href='?filter=<?php echo $filter; ?>&severity=' + this.value" style="padding: 8px 12px; border-radius: 6px;">
            <option value="all" <?php echo $severity === 'all' ? 'selected' : ''; ?>>All Severities</option>
            <option value="critical" <?php echo $severity === 'critical' ? 'selected' : ''; ?>>Critical</option>
            <option value="high" <?php echo $severity === 'high' ? 'selected' : ''; ?>>High</option>
            <option value="medium" <?php echo $severity === 'medium' ? 'selected' : ''; ?>>Medium</option>
            <option value="low" <?php echo $severity === 'low' ? 'selected' : ''; ?>>Low</option>
        </select>
    </div>
    
    <?php if (!empty($alerts)): ?>
        <!-- Bulk Actions -->
        <form method="post" id="bulk-form">
            <div class="bulk-actions">
                <input type="checkbox" id="select-all" onchange="toggleAllAlerts(this)">
                <label for="select-all" style="margin-right: 16px;">Select All</label>
                
                <button type="submit" name="action" value="mark_read" class="btn secondary">Mark Selected as Read</button>
                <button type="submit" name="action" value="mark_all_read" class="btn secondary">Mark All as Read</button>
                <button type="submit" name="action" value="delete_old" class="btn" style="background: #dc2626;" onclick="return confirm('Delete all alerts older than 30 days?')">Clean Old Alerts</button>
            </div>
            
            <!-- Alerts List -->
            <div class="alerts-list">
                <?php foreach ($alerts as $alert): ?>
                    <div class="alert-item <?php echo $alert['is_read'] ? 'read' : 'unread'; ?>">
                        <input type="checkbox" name="alert_ids[]" value="<?php echo $alert['id']; ?>" class="alert-checkbox">
                        
                        <div class="alert-severity severity-<?php echo $alert['severity']; ?>">
                            <?php echo $alert['severity']; ?>
                        </div>
                        
                        <div class="alert-content">
                            <div class="alert-type"><?php echo htmlspecialchars($alert['alert_type']); ?></div>
                            <div class="alert-message"><?php echo htmlspecialchars($alert['alert_message']); ?></div>
                            <div class="alert-meta">
                                <strong><?php echo htmlspecialchars($alert['first_name'] . ' ' . $alert['last_name']); ?></strong>
                                (<?php echo htmlspecialchars($alert['email']); ?>)
                                <?php if ($alert['module_title']): ?>
                                    • Module: <?php echo htmlspecialchars($alert['module_title']); ?>
                                <?php endif; ?>
                                • <?php echo date('M j, Y g:i A', strtotime($alert['created_at'])); ?>
                            </div>
                        </div>
                        
                        <?php if (!$alert['is_read']): ?>
                            <div style="width: 8px; height: 8px; background: #059669; border-radius: 50%; margin-top: 8px;"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </form>
    <?php else: ?>
        <div style="text-align: center; padding: 60px; background: white; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1);">
            <div style="font-size: 48px; margin-bottom: 16px;">✅</div>
            <h3 style="color: #059669; margin-bottom: 8px;">No alerts found</h3>
            <p style="color: #6b7280;">All clear! No alerts match your current filters.</p>
        </div>
    <?php endif; ?>
    
    <!-- Create Manual Alert -->
    <div class="create-alert-form">
        <h3 style="color: #374151; margin-bottom: 16px;">📝 Create Manual Alert</h3>
        <form method="post">
            <input type="hidden" name="action" value="create_alert">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>User</label>
                    <select name="user_id" required>
                        <option value="">Select User</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' (' . $user['email'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Module (Optional)</label>
                    <select name="module_id">
                        <option value="">No specific module</option>
                        <?php foreach ($modules as $module): ?>
                            <option value="<?php echo $module['id']; ?>">
                                <?php echo htmlspecialchars($module['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Alert Type</label>
                    <select name="alert_type" required>
                        <option value="manual_review">Manual Review</option>
                        <option value="performance_concern">Performance Concern</option>
                        <option value="completion_milestone">Completion Milestone</option>
                        <option value="custom_alert">Custom Alert</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Severity</label>
                    <select name="severity" required>
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Alert Message</label>
                <textarea name="alert_message" rows="3" placeholder="Enter detailed alert message..." required></textarea>
            </div>
            
            <button type="submit" class="btn">Create Alert</button>
        </form>
    </div>
</div>

<script>
function toggleAllAlerts(checkbox) {
    const alertCheckboxes = document.querySelectorAll('input[name="alert_ids[]"]');
    alertCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
}

// Auto-refresh alerts every 60 seconds
setInterval(function() {
    if (document.visibilityState === 'visible') {
        window.location.reload();
    }
}, 60000);
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
