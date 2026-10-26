<?php
$SEO = [
    'title' => 'User Audit Log — Admin',
    'description' => 'View detailed user activity and admin actions.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();
$userId = intval($_GET['user_id'] ?? 0);

// Get user info if specified
$user = null;
if ($userId) {
    $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$days = intval($_GET['days'] ?? 30);

// Build query
$whereConditions = [];
$params = [];

if ($userId) {
    $whereConditions[] = '(ah.target_user_id = ? OR ah.admin_id = ?)';
    $params[] = $userId;
    $params[] = $userId;
}

if ($filter !== 'all') {
    $whereConditions[] = 'ah.action_type = ?';
    $params[] = $filter;
}

$whereConditions[] = 'ah.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
$params[] = $days;

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get audit log entries
$stmt = $pdo->prepare("
    SELECT 
        ah.*,
        admin_user.first_name as admin_first_name,
        admin_user.last_name as admin_last_name,
        admin_user.email as admin_email,
        target_user.first_name as target_first_name,
        target_user.last_name as target_last_name,
        target_user.email as target_email
    FROM admin_history ah
    JOIN users admin_user ON admin_user.id = ah.admin_id
    LEFT JOIN users target_user ON target_user.id = ah.target_user_id
    $whereClause
    ORDER BY ah.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$auditEntries = $stmt->fetchAll();

// Get statistics
$stats = [
    'total_actions' => $pdo->query("SELECT COUNT(*) FROM admin_history WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)")->fetchColumn(),
    'user_actions' => $userId ? $pdo->prepare("SELECT COUNT(*) FROM admin_history WHERE (target_user_id = ? OR admin_id = ?) AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)")->execute([$userId, $userId]) : 0,
    'admin_count' => $pdo->query("SELECT COUNT(DISTINCT admin_id) FROM admin_history WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)")->fetchColumn(),
    'impersonations' => $pdo->query("SELECT COUNT(*) FROM admin_history WHERE action_type IN ('login_as_user', 'return_from_impersonation') AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)")->fetchColumn()
];
?>

<style>
.audit-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.audit-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}

.filter-bar {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 24px;
    display: flex;
    gap: 16px;
    align-items: center;
    flex-wrap: wrap;
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
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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

.audit-list {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}

.audit-item {
    padding: 20px;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    gap: 16px;
    align-items: start;
}

.audit-item:last-child {
    border-bottom: none;
}

.action-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: bold;
    flex-shrink: 0;
}

.action-create { background: #dcfce7; color: #059669; }
.action-update { background: #dbeafe; color: #1e40af; }
.action-delete { background: #fee2e2; color: #dc2626; }
.action-login_as_user { background: #fef3c7; color: #92400e; }
.action-send_message { background: #f3e8ff; color: #7c3aed; }
.action-reset_training { background: #fff7ed; color: #ea580c; }

.audit-content {
    flex: 1;
}

.audit-action {
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 4px;
}

.audit-description {
    color: #6b7280;
    font-size: 14px;
    margin-bottom: 8px;
}

.audit-meta {
    font-size: 12px;
    color: #9ca3af;
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}
</style>

<div class="audit-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">
            📋 Admin Audit Log
            <?php if ($user): ?>
                - <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
            <?php endif; ?>
        </h1>
        <p style="font-size: 18px; opacity: 0.9;">
            Track all administrative actions and user management activities
        </p>
    </div>
</div>

<div class="audit-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['total_actions']; ?></div>
            <div class="stat-label">Total Actions</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['admin_count']; ?></div>
            <div class="stat-label">Active Admins</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['impersonations']; ?></div>
            <div class="stat-label">Impersonations</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $days; ?></div>
            <div class="stat-label">Days Shown</div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="get" style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
            <?php if ($userId): ?>
                <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
            <?php endif; ?>
            
            <select name="filter" style="padding: 8px 12px; border-radius: 6px;">
                <option value="all">All Actions</option>
                <option value="login_as_user" <?php echo $filter === 'login_as_user' ? 'selected' : ''; ?>>User Impersonation</option>
                <option value="update_user" <?php echo $filter === 'update_user' ? 'selected' : ''; ?>>User Updates</option>
                <option value="reset_training" <?php echo $filter === 'reset_training' ? 'selected' : ''; ?>>Training Resets</option>
                <option value="send_message" <?php echo $filter === 'send_message' ? 'selected' : ''; ?>>Messages Sent</option>
            </select>
            
            <select name="days" style="padding: 8px 12px; border-radius: 6px;">
                <option value="1" <?php echo $days === 1 ? 'selected' : ''; ?>>Last 24 Hours</option>
                <option value="7" <?php echo $days === 7 ? 'selected' : ''; ?>>Last 7 Days</option>
                <option value="30" <?php echo $days === 30 ? 'selected' : ''; ?>>Last 30 Days</option>
                <option value="90" <?php echo $days === 90 ? 'selected' : ''; ?>>Last 90 Days</option>
            </select>
            
            <button type="submit" class="btn">Filter</button>
            
            <?php if ($userId): ?>
                <a href="?filter=<?php echo $filter; ?>&days=<?php echo $days; ?>" class="btn secondary">View All Users</a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Audit Entries -->
    <div class="audit-list">
        <?php if (empty($auditEntries)): ?>
            <div style="padding: 60px; text-align: center; color: #6b7280;">
                <div style="font-size: 48px; margin-bottom: 16px;">📝</div>
                <h3>No audit entries found</h3>
                <p>No administrative actions match your current filters.</p>
            </div>
        <?php else: ?>
            <?php foreach ($auditEntries as $entry): ?>
                <div class="audit-item">
                    <div class="action-icon action-<?php echo $entry['action_type']; ?>">
                        <?php 
                        $icons = [
                            'create_user' => '➕',
                            'update_user' => '✏️',
                            'delete_user' => '🗑️',
                            'login_as_user' => '👥',
                            'return_from_impersonation' => '↩️',
                            'send_message' => '💬',
                            'reset_training' => '🔄',
                            'update_roles' => '👤',
                            'approve_wholesale' => '✅'
                        ];
                        echo $icons[$entry['action_type']] ?? '📋';
                        ?>
                    </div>
                    
                    <div class="audit-content">
                        <div class="audit-action">
                            <?php echo ucfirst(str_replace('_', ' ', $entry['action_type'])); ?>
                        </div>
                        <div class="audit-description">
                            <?php echo htmlspecialchars($entry['action_description']); ?>
                        </div>
                        <div class="audit-meta">
                            <span>
                                <strong>Admin:</strong> 
                                <?php echo htmlspecialchars($entry['admin_first_name'] . ' ' . $entry['admin_last_name']); ?>
                                (<?php echo htmlspecialchars($entry['admin_email']); ?>)
                            </span>
                            <?php if ($entry['target_user_id']): ?>
                                <span>
                                    <strong>Target:</strong> 
                                    <?php echo htmlspecialchars($entry['target_first_name'] . ' ' . $entry['target_last_name']); ?>
                                    (<?php echo htmlspecialchars($entry['target_email']); ?>)
                                </span>
                            <?php endif; ?>
                            <span>
                                <strong>Time:</strong> <?php echo date('M j, Y g:i A', strtotime($entry['created_at'])); ?>
                            </span>
                            <span>
                                <strong>IP:</strong> <?php echo htmlspecialchars($entry['ip_address']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
