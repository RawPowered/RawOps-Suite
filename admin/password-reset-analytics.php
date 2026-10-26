<?php
/**
 * Password Reset & Profile Update Analytics Dashboard
 * For admin review of user authentication and profile activity
 */

$SEO = [
    'title' => 'Password Reset Analytics — Admin',
    'description' => 'View password reset requests and profile update activity',
    'type' => 'website'
];

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/admin-menu.php';

start_session();
$adminId = current_admin_id();
if (!$adminId) {
    redirect('/admin/');
}

$pdo = db();

// Date range filter
$dateRange = $_GET['range'] ?? '30';
$allowedRanges = ['7', '30', '90', 'all'];
if (!in_array($dateRange, $allowedRanges, true)) {
    $dateRange = '30';
}

$dateFilter = '';
if ($dateRange !== 'all') {
    $dateFilter = "AND created_at >= DATE_SUB(NOW(), INTERVAL {$dateRange} DAY)";
}

// ============================================================================
// Password Reset Statistics
// ============================================================================

// Overall stats
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_requests,
        COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as completed,
        COUNT(CASE WHEN used_at IS NULL AND expires_at < NOW() THEN 1 END) as expired,
        COUNT(CASE WHEN used_at IS NULL AND expires_at >= NOW() THEN 1 END) as pending,
        ROUND(COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0), 2) as completion_rate
    FROM password_reset_tokens
    WHERE 1=1 {$dateFilter}
");
$resetStats = $stmt->fetch();

// Daily breakdown
$stmt = $pdo->query("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as total_requests,
        COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as completed,
        COUNT(CASE WHEN used_at IS NULL AND expires_at < NOW() THEN 1 END) as expired
    FROM password_reset_tokens
    WHERE 1=1 {$dateFilter}
    GROUP BY DATE(created_at)
    ORDER BY date DESC
    LIMIT 30
");
$dailyBreakdown = $stmt->fetchAll();

// Users with multiple reset requests (security concern)
$stmt = $pdo->query("
    SELECT 
        u.id,
        u.email,
        u.first_name,
        u.last_name,
        COUNT(*) as reset_count,
        MAX(prt.created_at) as last_request
    FROM password_reset_tokens prt
    JOIN users u ON u.id = prt.user_id
    WHERE prt.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    GROUP BY u.id, u.email, u.first_name, u.last_name
    HAVING reset_count > 3
    ORDER BY reset_count DESC
    LIMIT 20
");
$frequentResets = $stmt->fetchAll();

// ============================================================================
// Profile Update Statistics
// ============================================================================

$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_updates,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(CASE WHEN field_name = 'password_hash' THEN 1 END) as password_changes
    FROM user_profile_updates
    WHERE 1=1 {$dateFilter}
");
$profileStats = $stmt->fetch();

// Updates by field
$stmt = $pdo->query("
    SELECT 
        field_name,
        COUNT(*) as update_count,
        COUNT(DISTINCT user_id) as unique_users
    FROM user_profile_updates
    WHERE 1=1 {$dateFilter}
    GROUP BY field_name
    ORDER BY update_count DESC
");
$fieldUpdates = $stmt->fetchAll();

// Recent profile updates
$stmt = $pdo->query("
    SELECT 
        upu.*,
        u.email,
        u.first_name,
        u.last_name
    FROM user_profile_updates upu
    JOIN users u ON u.id = upu.user_id
    WHERE 1=1 {$dateFilter}
    ORDER BY upu.created_at DESC
    LIMIT 50
");
$recentUpdates = $stmt->fetchAll();

require_once __DIR__ . '/../required/header.php';
?>

<style>
.analytics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.stat-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.stat-card h3 {
    margin: 0 0 8px 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
}
.stat-card .value {
    font-size: 36px;
    font-weight: 700;
    color: var(--blue);
    margin: 0;
}
.stat-card .subtext {
    font-size: 13px;
    color: var(--muted);
    margin-top: 8px;
}
.filter-bar {
    background: white;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    gap: 16px;
    align-items: center;
    flex-wrap: wrap;
}
.data-table {
    width: 100%;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 32px;
}
.data-table table {
    width: 100%;
    border-collapse: collapse;
}
.data-table th {
    background: var(--blue);
    color: white;
    padding: 16px;
    text-align: left;
    font-weight: 600;
}
.data-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #eee;
}
.data-table tr:last-child td {
    border-bottom: none;
}
.data-table tr:hover {
    background: #f8f9fa;
}
.badge-success {
    background: #d1fae5;
    color: #065f46;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
.badge-warning {
    background: #fef3c7;
    color: #92400e;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
.badge-danger {
    background: #fee2e2;
    color: #991b1b;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
</style>

<div class="container" style="max-width:1400px;padding:40px 20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:32px;">
        <h1 style="margin:0;color:var(--blue);">🔐 Security & Profile Analytics</h1>
        <a href="<?php echo outbound_path('/admin/'); ?>" class="btn secondary">← Back to Admin</a>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <label style="margin:0;font-weight:600;">Date Range:</label>
        <select onchange="window.location.href='?range='+this.value" style="padding:8px 12px;border-radius:6px;border:1px solid #ddd;">
            <option value="7" <?php echo $dateRange === '7' ? 'selected' : ''; ?>>Last 7 Days</option>
            <option value="30" <?php echo $dateRange === '30' ? 'selected' : ''; ?>>Last 30 Days</option>
            <option value="90" <?php echo $dateRange === '90' ? 'selected' : ''; ?>>Last 90 Days</option>
            <option value="all" <?php echo $dateRange === 'all' ? 'selected' : ''; ?>>All Time</option>
        </select>
    </div>
    
    <!-- Password Reset Stats -->
    <h2 style="color:var(--blue);margin-bottom:20px;">Password Reset Overview</h2>
    <div class="analytics-grid">
        <div class="stat-card">
            <h3>Total Requests</h3>
            <div class="value"><?php echo number_format($resetStats['total_requests']); ?></div>
            <div class="subtext"><?php echo $dateRange === 'all' ? 'All time' : "Last {$dateRange} days"; ?></div>
        </div>
        <div class="stat-card">
            <h3>Completed</h3>
            <div class="value" style="color:#059669;"><?php echo number_format($resetStats['completed']); ?></div>
            <div class="subtext"><?php echo $resetStats['completion_rate']; ?>% completion rate</div>
        </div>
        <div class="stat-card">
            <h3>Expired</h3>
            <div class="value" style="color:#dc2626;"><?php echo number_format($resetStats['expired']); ?></div>
            <div class="subtext">Never used</div>
        </div>
        <div class="stat-card">
            <h3>Pending</h3>
            <div class="value" style="color:#f59e0b;"><?php echo number_format($resetStats['pending']); ?></div>
            <div class="subtext">Not yet expired</div>
        </div>
    </div>
    
    <!-- Daily Breakdown -->
    <?php if (!empty($dailyBreakdown)): ?>
    <div class="data-table">
        <h3 style="padding:20px 20px 0;margin:0;color:var(--blue);">Daily Breakdown</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Total Requests</th>
                    <th>Completed</th>
                    <th>Expired</th>
                    <th>Success Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailyBreakdown as $day): ?>
                <tr>
                    <td><?php echo date('M j, Y', strtotime($day['date'])); ?></td>
                    <td><?php echo $day['total_requests']; ?></td>
                    <td><?php echo $day['completed']; ?></td>
                    <td><?php echo $day['expired']; ?></td>
                    <td>
                        <?php 
                        $rate = $day['total_requests'] > 0 ? round(($day['completed'] / $day['total_requests']) * 100, 1) : 0;
                        $badgeClass = $rate >= 70 ? 'badge-success' : ($rate >= 40 ? 'badge-warning' : 'badge-danger');
                        ?>
                        <span class="<?php echo $badgeClass; ?>"><?php echo $rate; ?>%</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Frequent Reset Users (Security Concern) -->
    <?php if (!empty($frequentResets)): ?>
    <div class="data-table">
        <h3 style="padding:20px 20px 0;margin:0;color:#dc2626;">⚠️ Users with Frequent Password Resets (Last 90 Days)</h3>
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Reset Count</th>
                    <th>Last Request</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($frequentResets as $user): ?>
                <tr>
                    <td><?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?></td>
                    <td><?php echo sanitize($user['email']); ?></td>
                    <td><span class="badge-danger"><?php echo $user['reset_count']; ?> resets</span></td>
                    <td><?php echo date('M j, Y g:i A', strtotime($user['last_request'])); ?></td>
                    <td><a href="<?php echo outbound_path('/admin/user-detail.php?id=' . $user['id']); ?>" class="btn secondary" style="font-size:12px;padding:6px 12px;">View User</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Profile Update Stats -->
    <h2 style="color:var(--blue);margin:48px 0 20px;">Profile Update Overview</h2>
    <div class="analytics-grid">
        <div class="stat-card">
            <h3>Total Updates</h3>
            <div class="value"><?php echo number_format($profileStats['total_updates']); ?></div>
            <div class="subtext"><?php echo $dateRange === 'all' ? 'All time' : "Last {$dateRange} days"; ?></div>
        </div>
        <div class="stat-card">
            <h3>Unique Users</h3>
            <div class="value"><?php echo number_format($profileStats['unique_users']); ?></div>
            <div class="subtext">Made profile changes</div>
        </div>
        <div class="stat-card">
            <h3>Password Changes</h3>
            <div class="value" style="color:#f59e0b;"><?php echo number_format($profileStats['password_changes']); ?></div>
            <div class="subtext">Via profile page</div>
        </div>
    </div>
    
    <!-- Updates by Field -->
    <?php if (!empty($fieldUpdates)): ?>
    <div class="data-table">
        <h3 style="padding:20px 20px 0;margin:0;color:var(--blue);">Updates by Field</h3>
        <table>
            <thead>
                <tr>
                    <th>Field Name</th>
                    <th>Total Updates</th>
                    <th>Unique Users</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fieldUpdates as $field): ?>
                <tr>
                    <td><strong><?php echo sanitize(ucwords(str_replace('_', ' ', $field['field_name']))); ?></strong></td>
                    <td><?php echo number_format($field['update_count']); ?></td>
                    <td><?php echo number_format($field['unique_users']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Recent Profile Updates -->
    <?php if (!empty($recentUpdates)): ?>
    <div class="data-table">
        <h3 style="padding:20px 20px 0;margin:0;color:var(--blue);">Recent Profile Updates</h3>
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Field</th>
                    <th>Old Value</th>
                    <th>New Value</th>
                    <th>Date</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentUpdates as $update): ?>
                <tr>
                    <td><?php echo sanitize($update['first_name'] . ' ' . $update['last_name']); ?></td>
                    <td><?php echo sanitize(ucwords(str_replace('_', ' ', $update['field_name']))); ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo $update['field_name'] === 'password_hash' ? '[REDACTED]' : sanitize($update['old_value'] ?? '(empty)'); ?>
                    </td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo $update['field_name'] === 'password_hash' ? '[REDACTED]' : sanitize($update['new_value'] ?? '(empty)'); ?>
                    </td>
                    <td><?php echo date('M j, g:i A', strtotime($update['created_at'])); ?></td>
                    <td><?php echo sanitize($update['ip_address']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>



