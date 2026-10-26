<?php
/**
 * One-time script to backfill last_login_at timestamps from auth_events
 * This fixes the initial state where users have been logging in but the timestamp wasn't tracked
 */

require_once __DIR__ . '/../required/config.php';

$SEO = ['title' => 'Fix Login Timestamps', 'description' => 'Backfill login timestamps', 'type' => 'website'];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$updated = 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backfill'])) {
    try {
        // Update users.last_login_at from the most recent login event in auth_events
        $sql = "
            UPDATE users u
            JOIN (
                SELECT user_id, MAX(created_at) as last_login
                FROM auth_events
                WHERE event_type = 'login'
                GROUP BY user_id
            ) ae ON ae.user_id = u.id
            SET u.last_login_at = ae.last_login
            WHERE u.last_login_at IS NULL OR u.last_login_at < ae.last_login
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $updated = $stmt->rowCount();
        
        $success = "Successfully updated {$updated} user login timestamps from auth_events";
        
    } catch (Exception $e) {
        $errors[] = "Error updating timestamps: " . $e->getMessage();
    }
}

// Get current status
$stats = [];
try {
    $stats['total_users'] = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['users_with_login'] = $pdo->query('SELECT COUNT(*) FROM users WHERE last_login_at IS NOT NULL')->fetchColumn();
    $stats['users_without_login'] = $pdo->query('SELECT COUNT(*) FROM users WHERE last_login_at IS NULL')->fetchColumn();
    $stats['auth_events_available'] = $pdo->query('SELECT COUNT(DISTINCT user_id) FROM auth_events WHERE event_type = "login"')->fetchColumn();
} catch (Exception $e) {
    $errors[] = "Error fetching stats: " . $e->getMessage();
}

// Get sample users that would be updated
$sample_users = [];
try {
    $sample_users = $pdo->query("
        SELECT 
            u.id,
            u.email,
            u.first_name,
            u.last_name,
            u.last_login_at as old_timestamp,
            ae.last_login as new_timestamp,
            DATEDIFF(NOW(), COALESCE(u.last_login_at, u.created_at)) as days_inactive_current,
            DATEDIFF(NOW(), ae.last_login) as days_inactive_new
        FROM users u
        JOIN (
            SELECT user_id, MAX(created_at) as last_login
            FROM auth_events
            WHERE event_type = 'login'
            GROUP BY user_id
        ) ae ON ae.user_id = u.id
        WHERE u.last_login_at IS NULL OR u.last_login_at < ae.last_login
        ORDER BY ae.last_login DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = "Error fetching sample: " . $e->getMessage();
}
?>

<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; margin: 0; padding: 24px; }
.container { max-width: 1200px; margin: 0 auto; }
.card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; overflow: hidden; }
.card-header { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; padding: 24px; }
.card-body { padding: 24px; }
.btn { background: #2563eb; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; }
.btn:hover { background: #1d4ed8; }
.btn-danger { background: #dc2626; }
.btn-danger:hover { background: #b91c1c; }
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
.alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
.alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.alert-warning { background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #f1f5f9; }
th { background: #f8fafc; font-weight: 600; }
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 24px 0; }
.stat-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center; }
.stat-number { font-size: 32px; font-weight: 700; color: #1f2937; }
.stat-label { font-size: 12px; color: #6b7280; text-transform: uppercase; margin-top: 4px; }
</style>

<div class="container">
    <?php require __DIR__ . '/admin-menu.php'; ?>
    
    <div class="card">
        <div class="card-header">
            <h1 style="margin: 0;">🕐 Fix Login Timestamps</h1>
            <p style="margin: 8px 0 0; opacity: 0.9;">Backfill last_login_at from auth_events table</p>
        </div>
        
        <div class="card-body">
            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <h2>Current Status</h2>
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo intval($stats['total_users'] ?? 0); ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #16a34a;"><?php echo intval($stats['users_with_login'] ?? 0); ?></div>
                    <div class="stat-label">With Login Timestamp</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #dc2626;"><?php echo intval($stats['users_without_login'] ?? 0); ?></div>
                    <div class="stat-label">Without Login Timestamp</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #2563eb;"><?php echo intval($stats['auth_events_available'] ?? 0); ?></div>
                    <div class="stat-label">Auth Events Available</div>
                </div>
            </div>
            
            <?php if (!empty($sample_users)): ?>
                <h2>Users That Will Be Updated (Sample)</h2>
                <div class="alert alert-warning">
                    <strong>⚠️ Preview Mode:</strong> This shows what will be updated when you run the backfill.
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Current Timestamp</th>
                            <th>New Timestamp</th>
                            <th>Current Days Inactive</th>
                            <th>New Days Inactive</th>
                            <th>Improvement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sample_users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo $user['old_timestamp'] ? htmlspecialchars($user['old_timestamp']) : '<em>NULL</em>'; ?></td>
                                <td style="color: #16a34a; font-weight: 600;"><?php echo htmlspecialchars($user['new_timestamp']); ?></td>
                                <td><?php echo intval($user['days_inactive_current']); ?> days</td>
                                <td style="color: #16a34a; font-weight: 600;"><?php echo intval($user['days_inactive_new']); ?> days</td>
                                <td>
                                    <?php 
                                    $improvement = intval($user['days_inactive_current']) - intval($user['days_inactive_new']);
                                    if ($improvement > 0): ?>
                                        <span style="color: #16a34a; font-weight: 600;">↓ <?php echo $improvement; ?> days</span>
                                    <?php elseif ($improvement < 0): ?>
                                        <span style="color: #dc2626;">↑ <?php echo abs($improvement); ?> days</span>
                                    <?php else: ?>
                                        <span style="color: #6b7280;">No change</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="alert alert-success">
                    <strong>✅ All Good!</strong> All users already have accurate login timestamps.
                </div>
            <?php endif; ?>
            
            <?php if (!empty($sample_users)): ?>
                <div style="margin-top: 32px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 20px;">
                    <h3 style="margin: 0 0 12px 0; color: #92400e;">⚡ Run Backfill</h3>
                    <p style="margin: 0 0 16px 0;">This will update the <code>last_login_at</code> column for all users based on their most recent login event in the <code>auth_events</code> table.</p>
                    <form method="post">
                        <button type="submit" name="backfill" class="btn" style="font-size: 16px; padding: 14px 28px;">
                            🚀 Run Backfill Now
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 32px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px;">
                <h3 style="margin: 0 0 8px 0; color: #166534;">ℹ️ About This Tool</h3>
                <p style="margin: 0; color: #166534;">
                    This is a one-time fix to populate the <code>last_login_at</code> column in the users table. 
                    Going forward, this column will be automatically updated on every login through the 
                    <code>login_user()</code> and <code>login_admin()</code> functions.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
