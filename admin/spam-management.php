<?php
$SEO = [
    'title' => 'Spam Management — Admin',
    'description' => 'Manage spam prevention, blacklist, and review blocked registration attempts.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/admin_user_utils.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add_blacklist') {
            $entryType = $_POST['entry_type'] ?? '';
            $entryValue = trim($_POST['entry_value'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $adminId = current_user_id();
            
            if (empty($entryType) || empty($entryValue)) {
                throw new Exception('Entry type and value are required.');
            }
            
            $stmt = $pdo->prepare('
                INSERT INTO spam_blacklist (entry_type, entry_value, reason, added_by_admin_id, is_active)
                VALUES (?, ?, ?, ?, 1)
            ');
            $stmt->execute([$entryType, $entryValue, $reason, $adminId]);
            $message = "Blacklist entry added successfully.";
        }
        
        elseif ($action === 'remove_blacklist') {
            $entryId = intval($_POST['entry_id'] ?? 0);
            if ($entryId > 0) {
                $stmt = $pdo->prepare('UPDATE spam_blacklist SET is_active = 0 WHERE id = ?');
                $stmt->execute([$entryId]);
                $message = "Blacklist entry removed.";
            }
        }
        
        elseif ($action === 'delete_spam_attempt') {
            $attemptId = intval($_POST['attempt_id'] ?? 0);
            if ($attemptId > 0) {
                $stmt = $pdo->prepare('DELETE FROM spam_registration_attempts WHERE id = ?');
                $stmt->execute([$attemptId]);
                $message = "Spam attempt deleted.";
            }
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
    
    if ($message || $error) {
        if ($message) flash_set('success', $message);
        if ($error) flash_set('error', $error);
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Get statistics
$stats = [
    'total_attempts' => $pdo->query('SELECT COUNT(*) FROM spam_registration_attempts')->fetchColumn(),
    'today_attempts' => $pdo->query('SELECT COUNT(*) FROM spam_registration_attempts WHERE DATE(blocked_at) = CURDATE()')->fetchColumn(),
    'week_attempts' => $pdo->query('SELECT COUNT(*) FROM spam_registration_attempts WHERE blocked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn(),
    'unique_ips' => $pdo->query('SELECT COUNT(DISTINCT ip_address) FROM spam_registration_attempts')->fetchColumn(),
    'blacklist_count' => $pdo->query('SELECT COUNT(*) FROM spam_blacklist WHERE is_active = 1')->fetchColumn(),
    'blacklist_emails' => $pdo->query('SELECT COUNT(*) FROM spam_blacklist WHERE entry_type = "email" AND is_active = 1')->fetchColumn(),
    'blacklist_domains' => $pdo->query('SELECT COUNT(*) FROM spam_blacklist WHERE entry_type = "domain" AND is_active = 1')->fetchColumn(),
    'blacklist_ips' => $pdo->query('SELECT COUNT(*) FROM spam_blacklist WHERE entry_type = "ip_address" AND is_active = 1')->fetchColumn()
];

// Get recent spam attempts
$limit = 50;
$stmt = $pdo->prepare('
    SELECT * FROM spam_registration_attempts 
    ORDER BY blocked_at DESC 
    LIMIT ?
');
$stmt->execute([$limit]);
$recentAttempts = $stmt->fetchAll();

// Get blacklist entries
$stmt = $pdo->query('
    SELECT sb.*, u.email as admin_email 
    FROM spam_blacklist sb
    LEFT JOIN users u ON u.id = sb.added_by_admin_id
    WHERE sb.is_active = 1
    ORDER BY sb.created_at DESC
');
$blacklistEntries = $stmt->fetchAll();
?>

<style>
.admin-header {
    background: linear-gradient(135deg, #dc2626, #991b1b);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
}
.stat-label {
    color: #6b7280;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.data-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 32px;
}
.data-table table {
    width: 100%;
    border-collapse: collapse;
}
.data-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    font-size: 13px;
}
.data-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
}
.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.badge-danger { background: #fee2e2; color: #dc2626; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-info { background: #dbeafe; color: #1e40af; }
</style>

<div class="admin-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">🛡️ Spam Prevention System</h1>
        <p style="font-size: 18px; opacity: 0.9;">Monitor blocked attempts and manage blacklist</p>
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
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Blocked</div>
            <div class="stat-value" style="color: #dc2626;"><?php echo number_format($stats['total_attempts']); ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">All time</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Today</div>
            <div class="stat-value" style="color: #f59e0b;"><?php echo number_format($stats['today_attempts']); ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">Last 24 hours</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">This Week</div>
            <div class="stat-value" style="color: #3b82f6;"><?php echo number_format($stats['week_attempts']); ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">Last 7 days</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Unique IPs</div>
            <div class="stat-value" style="color: #8b5cf6;"><?php echo number_format($stats['unique_ips']); ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">Distinct sources</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Blacklist Size</div>
            <div class="stat-value" style="color: #059669;"><?php echo number_format($stats['blacklist_count']); ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">
                <?php echo $stats['blacklist_emails']; ?> emails, 
                <?php echo $stats['blacklist_domains']; ?> domains, 
                <?php echo $stats['blacklist_ips']; ?> IPs
            </div>
        </div>
    </div>
    
    <!-- Add to Blacklist Form -->
    <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 24px; margin-bottom: 32px;">
        <h2 style="margin: 0 0 20px 0; color: #dc2626;">➕ Add Blacklist Entry</h2>
        <form method="post">
            <input type="hidden" name="action" value="add_blacklist">
            <div style="display: grid; grid-template-columns: 200px 1fr 1fr 140px; gap: 12px; align-items: end;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">Type</label>
                    <select name="entry_type" required style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px;">
                        <option value="email">Email</option>
                        <option value="domain">Domain</option>
                        <option value="name_pattern">Name Pattern</option>
                        <option value="phone">Phone</option>
                        <option value="ip_address">IP Address</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">Value</label>
                    <input type="text" name="entry_value" required placeholder="sample-012@example.com or .xyz" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">Reason</label>
                    <input type="text" name="reason" placeholder="Why is this blacklisted?" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px;">
                </div>
                <button type="submit" class="btn" style="background: #dc2626;">Add Entry</button>
            </div>
        </form>
    </div>
    
    <!-- Blacklist Entries -->
    <div class="data-table">
        <h2 style="padding: 20px 20px 0; margin: 0; color: #dc2626;">📋 Active Blacklist</h2>
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Value</th>
                    <th>Reason</th>
                    <th>Added By</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($blacklistEntries as $entry): ?>
                <tr>
                    <td>
                        <span class="badge badge-info">
                            <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($entry['entry_type']))); ?>
                        </span>
                    </td>
                    <td><code style="background: #f3f4f6; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($entry['entry_value']); ?></code></td>
                    <td><?php echo htmlspecialchars($entry['reason'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($entry['admin_email'] ?: 'System'); ?></td>
                    <td><?php echo date('M d, Y', strtotime($entry['created_at'])); ?></td>
                    <td>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="action" value="remove_blacklist">
                            <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                            <button type="submit" style="background: none; border: none; color: #dc2626; cursor: pointer; font-size: 18px;" title="Remove" onclick="return confirm('Remove this blacklist entry?')">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($blacklistEntries)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;">
                        No blacklist entries found
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Recent Spam Attempts -->
    <div class="data-table">
        <h2 style="padding: 20px 20px 0; margin: 0; color: #dc2626;">🚫 Recent Blocked Attempts (Last <?php echo $limit; ?>)</h2>
        <table>
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Email</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>IP Address</th>
                    <th>Score</th>
                    <th>Violations</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentAttempts as $attempt): ?>
                <?php 
                    $violations = json_decode($attempt['detected_violations'], true) ?: [];
                    $formData = json_decode($attempt['form_data'], true) ?: [];
                ?>
                <tr>
                    <td style="font-size: 12px;"><?php echo date('M d, Y H:i', strtotime($attempt['blocked_at'])); ?></td>
                    <td><code style="font-size: 11px;"><?php echo htmlspecialchars($attempt['email'] ?: '—'); ?></code></td>
                    <td><?php echo htmlspecialchars(($attempt['first_name'] ?? '') . ' ' . ($attempt['last_name'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($attempt['phone_number'] ?: '—'); ?></td>
                    <td><code style="font-size: 11px;"><?php echo htmlspecialchars($attempt['ip_address'] ?: '—'); ?></code></td>
                    <td>
                        <span class="badge <?php echo $attempt['spam_score'] >= 100 ? 'badge-danger' : 'badge-warning'; ?>">
                            <?php echo intval($attempt['spam_score']); ?>
                        </span>
                    </td>
                    <td style="max-width: 300px;">
                        <details>
                            <summary style="cursor: pointer; color: #dc2626; font-size: 12px;">
                                <?php echo count($violations); ?> violation<?php echo count($violations) !== 1 ? 's' : ''; ?>
                            </summary>
                            <ul style="margin: 8px 0 0 0; padding-left: 20px; font-size: 11px; line-height: 1.6;">
                                <?php foreach ($violations as $violation): ?>
                                    <li><?php echo htmlspecialchars($violation); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    </td>
                    <td>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="action" value="delete_spam_attempt">
                            <input type="hidden" name="attempt_id" value="<?php echo $attempt['id']; ?>">
                            <button type="submit" style="background: none; border: none; color: #6b7280; cursor: pointer; font-size: 16px;" title="Delete" onclick="return confirm('Delete this spam attempt log?')">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recentAttempts)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 40px; color: #6b7280;">
                        No spam attempts recorded yet
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

