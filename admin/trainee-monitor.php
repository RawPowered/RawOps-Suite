<?php
$SEO = [
    'title' => 'Trainee Progress Monitor — Admin',
    'description' => 'Monitor trainee progress and certification status.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userIds = $_POST['user_ids'] ?? [];
    
    try {
        if ($action === 'approve_trainees' && !empty($userIds)) {
            $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id IN ($placeholders)");
            $stmt->execute($userIds);
            flash_set('success', count($userIds) . ' trainee(s) approved successfully.');
        }
        
        elseif ($action === 'suspend_trainees' && !empty($userIds)) {
            $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id IN ($placeholders)");
            $stmt->execute($userIds);
            flash_set('success', count($userIds) . ' trainee(s) suspended successfully.');
        }
        
        elseif ($action === 'reset_training' && !empty($userIds)) {
            $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
            // Reset training progress
            $stmt = $pdo->prepare("DELETE FROM training_progress WHERE user_id IN ($placeholders)");
            $stmt->execute($userIds);
            // Clear quiz results
            $stmt = $pdo->prepare("DELETE FROM training_quiz_results WHERE user_id IN ($placeholders)");
            $stmt->execute($userIds);
            flash_set('success', 'Training reset for ' . count($userIds) . ' trainee(s).');
        }
        
        elseif ($action === 'promote_to_agent' && !empty($userIds)) {
            $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
            // Remove trainee role and add sales_agent role
            $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id IN ($placeholders) AND role_id = 1");
            $stmt->execute($userIds);
            
            foreach ($userIds as $userId) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, 2)");
                $stmt->execute([$userId]);
            }
            flash_set('success', count($userIds) . ' trainee(s) promoted to Sales Agent.');
        }
        
        elseif ($action === 'send_message' && !empty($userIds)) {
            $message = trim($_POST['message'] ?? '');
            if (!empty($message)) {
                // Create alerts for selected users
                foreach ($userIds as $userId) {
                    $stmt = $pdo->prepare("INSERT INTO training_admin_alerts (user_id, alert_type, alert_message, severity) VALUES (?, 'admin_message', ?, 'medium')");
                    $stmt->execute([$userId, $message]);
                }
                flash_set('success', 'Message sent to ' . count($userIds) . ' trainee(s).');
            }
        }
        
        elseif ($action === 'require_retest') {
            $userId = intval($_POST['user_id']);
            $moduleId = intval($_POST['module_id']);
            $reason = trim($_POST['reason']);
            
            $stmt = $pdo->prepare("INSERT INTO training_retest_requirements (user_id, module_id, admin_id, reason) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $moduleId, current_user_id(), $reason]);
            flash_set('success', 'Retest requirement added successfully.');
        }
        
    } catch (Exception $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
    }
    
    redirect($_SERVER['REQUEST_URI']);
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'active';
$module_filter = $_GET['module'] ?? 'all';

// Get all training modules
$stmt = $pdo->query('SELECT * FROM training_modules ORDER BY sort_order');
$modules = $stmt->fetchAll();

// Build trainee query
$query = '
    SELECT 
        u.id,
        u.email,
        u.first_name,
        u.last_name,
        u.created_at as joined_date,
        u.status,
        COUNT(DISTINCT CASE 
            WHEN tp.status = "completed" 
             AND tp.module_id IN (SELECT id FROM training_modules WHERE required = 1)
            THEN tp.module_id END) as modules_completed,
        (SELECT COUNT(*) FROM training_modules WHERE required = 1) as total_modules,
        MAX(COALESCE(tp.completed_at, tp.started_at)) as last_activity,
        MIN(tp.started_at) as training_started,
        CASE 
            WHEN COUNT(DISTINCT CASE 
                    WHEN tp.status = "completed" 
                     AND tp.module_id IN (SELECT id FROM training_modules WHERE required = 1)
                    THEN tp.module_id END) 
                 >= (SELECT COUNT(*) FROM training_modules WHERE required = 1) 
            THEN 1
            ELSE 0
        END as is_complete,
        GROUP_CONCAT(
            CONCAT(tp.module_id, ":", tp.status)
            ORDER BY tp.module_id
        ) as module_statuses,
        (
            SELECT ROUND((tqr.score / NULLIF(tqr.total_questions,0)) * 100)
            FROM training_quiz_results tqr
            WHERE tqr.user_id = u.id 
              AND tqr.module_id = (SELECT id FROM training_modules WHERE slug = "intro-product" LIMIT 1)
            ORDER BY tqr.completed_at DESC, tqr.attempt_number DESC
            LIMIT 1
        ) AS module1_quiz_percent
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    LEFT JOIN training_progress tp ON tp.user_id = u.id
    WHERE r.name = "trainee"
';

$params = [];

// Apply filters
// initialize optional HAVING clause
$having = '';
if ($filter === 'active') {
    $query .= ' AND tp.started_at IS NOT NULL AND tp.completed_at IS NULL';
} elseif ($filter === 'completed') {
    // use HAVING after GROUP BY
    $having = ' HAVING is_complete = 1';
} elseif ($filter === 'inactive') {
    $query .= ' AND (tp.started_at IS NULL OR DATEDIFF(NOW(), COALESCE(tp.completed_at, tp.started_at)) > 7)';
}

$query .= ' GROUP BY u.id';
if (!empty($having)) { $query .= $having; }
$query .= ' ORDER BY last_activity DESC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$trainees = $stmt->fetchAll();

// Get statistics
$stats = [
    'total_trainees' => 0,
    'active_trainees' => 0,
    'completed' => 0,
    'avg_completion_time' => 0,
    'conversion_rate' => 0
];

try {
    // Total trainees
    $stmt = $pdo->query('SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name = "trainee"');
    $stats['total_trainees'] = $stmt->fetchColumn();
    
    // Active trainees (started but not completed)
    $stmt = $pdo->query('
        SELECT COUNT(DISTINCT u.id) 
        FROM users u 
        JOIN user_roles ur ON ur.user_id = u.id 
        JOIN roles r ON r.id = ur.role_id 
        JOIN training_progress tp ON tp.user_id = u.id
        WHERE r.name = "trainee" 
        AND tp.started_at IS NOT NULL 
        AND tp.status != "completed"
    ');
    $stats['active_trainees'] = $stmt->fetchColumn();
    
    // Completed training (now sales agents)
    $stmt = $pdo->query('SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name = "sales_agent"');
    $stats['completed'] = $stmt->fetchColumn();
    
    // Average completion time
    $stmt = $pdo->query('
        SELECT AVG(DATEDIFF(MAX(tp.completed_at), MIN(tp.started_at)))
        FROM training_progress tp
        WHERE tp.status = "completed"
        GROUP BY tp.user_id
        HAVING COUNT(DISTINCT tp.module_id) >= (SELECT COUNT(*) FROM training_modules WHERE required = 1)
    ');
    $stats['avg_completion_time'] = round(floatval($stmt->fetchColumn()), 1);
    
    // Conversion rate
    if ($stats['total_trainees'] > 0) {
        $stats['conversion_rate'] = round(($stats['completed'] / ($stats['total_trainees'] + $stats['completed'])) * 100, 1);
    }
    
} catch (Exception $e) {
    // Handle error
}

// Function to parse module statuses
function parseModuleStatuses($statusString) {
    if (empty($statusString)) return [];
    $statuses = [];
    $pairs = explode(',', $statusString);
    foreach ($pairs as $pair) {
        list($moduleId, $status) = explode(':', $pair);
        $statuses[$moduleId] = $status;
    }
    return $statuses;
}
?>

<style>
.admin-header {
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
}
.stat-value {
    font-size: 32px;
    font-weight: bold;
    margin: 8px 0;
}
.stat-label {
    color: #6b7280;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
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
}
.filter-tabs {
    display: flex;
    gap: 8px;
}
.filter-tab {
    padding: 8px 16px;
    background: #f3f4f6;
    border: none;
    border-radius: 8px;
    color: #6b7280;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}
.filter-tab:hover {
    background: #e5e7eb;
}
.filter-tab.active {
    background: #3b82f6;
    color: white;
}
.trainees-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.trainees-table table {
    width: 100%;
    border-collapse: collapse;
}
.trainees-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 14px;
}
.trainees-table td {
    padding: 16px 12px;
    border-bottom: 1px solid #f3f4f6;
}
.progress-bar {
    width: 200px;
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}
.progress-fill {
    height: 100%;
    background: linear-gradient(to right, #3b82f6, #10b981);
    transition: width 0.3s;
}
.module-indicators {
    display: flex;
    gap: 4px;
}
.module-dot {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: bold;
    color: white;
}
.module-not-started {
    background: #e5e7eb;
    color: #6b7280;
}
.module-in-progress {
    background: #fbbf24;
}
.module-completed {
    background: #10b981;
}
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.status-active {
    background: #dbeafe;
    color: #1e40af;
}
.status-inactive {
    background: #f3f4f6;
    color: #6b7280;
}
.status-complete {
    background: #dcfce7;
    color: #14532d;
}
.btn-small {
    padding: 6px 12px;
    font-size: 14px;
    border-radius: 6px;
    background: #3b82f6;
    color: white;
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}
.btn-small:hover {
    background: #2563eb;
}
</style>

<div class="admin-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">Trainee Progress Monitor</h1>
        <p style="font-size: 18px; opacity: 0.9;">Track training progress and certification status</p>
    </div>
</div>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Trainees</div>
            <div class="stat-value"><?php echo $stats['total_trainees']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Currently Active</div>
            <div class="stat-value" style="color: #3b82f6;"><?php echo $stats['active_trainees']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Certified Agents</div>
            <div class="stat-value" style="color: #10b981;"><?php echo $stats['completed']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Conversion Rate</div>
            <div class="stat-value" style="font-size: 24px;"><?php echo $stats['conversion_rate']; ?>%</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Avg. Completion</div>
            <div class="stat-value" style="font-size: 24px;"><?php echo $stats['avg_completion_time']; ?> days</div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="filter-tabs">
            <a href="?filter=active" class="filter-tab <?php echo $filter === 'active' ? 'active' : ''; ?>">
                Active Trainees
            </a>
            <a href="?filter=completed" class="filter-tab <?php echo $filter === 'completed' ? 'active' : ''; ?>">
                Completed
            </a>
            <a href="?filter=inactive" class="filter-tab <?php echo $filter === 'inactive' ? 'active' : ''; ?>">
                Inactive (7+ days)
            </a>
            <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                All Trainees
            </a>
        </div>
    </div>
    
    <!-- Bulk Actions -->
    <?php if (!empty($trainees)): ?>
    <form method="post" id="bulk-form">
        <div style="background: white; padding: 20px; border-radius: 12px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <h3 style="margin: 0 0 16px 0; color: #1f2937;">🔧 Bulk Actions</h3>
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <input type="checkbox" id="select-all" onchange="toggleAllTrainees(this)">
                <label for="select-all" style="margin-right: 16px;">Select All</label>
                
                <button type="submit" name="action" value="approve_trainees" class="btn secondary">✅ Approve Selected</button>
                <button type="submit" name="action" value="suspend_trainees" class="btn" style="background: #dc2626;" onclick="return confirm('Suspend selected trainees?')">⛔ Suspend</button>
                <button type="submit" name="action" value="promote_to_agent" class="btn" style="background: #059669;" onclick="return confirm('Promote selected trainees to Sales Agents?')">⬆️ Promote to Agent</button>
                <button type="submit" name="action" value="reset_training" class="btn" style="background: #f59e0b;" onclick="return confirm('Reset training progress for selected trainees?')">🔄 Reset Training</button>
                <button type="button" class="btn secondary" onclick="showMessageModal()">💬 Send Message</button>
            </div>
        </div>
        
        <!-- Message Modal -->
        <div id="message-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 32px; border-radius: 12px; max-width: 500px; width: 90%;">
                <h3 style="margin: 0 0 16px 0;">Send Message to Selected Trainees</h3>
                <textarea name="message" placeholder="Enter your message..." style="width: 100%; height: 120px; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; resize: vertical;" required></textarea>
                <div style="margin-top: 16px; display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" class="btn secondary" onclick="hideMessageModal()">Cancel</button>
                    <button type="submit" name="action" value="send_message" class="btn">Send Message</button>
                </div>
            </div>
        </div>
    
    <!-- Trainees Table -->
    <div class="trainees-table">
        <?php if (empty($trainees)): ?>
            <div style="padding: 60px; text-align: center;">
                <p style="color: #6b7280;">No trainees found matching your filters</p>
            </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;"><input type="checkbox" id="header-select-all" onchange="toggleAllTrainees(this)"></th>
                    <th>Trainee</th>
                    <th>Progress</th>
                    <th>Module Status</th>
                    <th>M1 Quiz</th>
                    <th>Started</th>
                    <th>Last Activity</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trainees as $trainee): 
                    $moduleStatuses = parseModuleStatuses($trainee['module_statuses']);
                    $progressPercent = $trainee['total_modules'] > 0 ? 
                        round(($trainee['modules_completed'] / $trainee['total_modules']) * 100) : 0;
                ?>
                <tr>
                    <td>
                        <input type="checkbox" name="user_ids[]" value="<?php echo $trainee['id']; ?>" class="trainee-checkbox">
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($trainee['first_name'] . ' ' . $trainee['last_name']); ?></strong>
                        <br><small style="color: #6b7280;"><?php echo htmlspecialchars($trainee['email']); ?></small>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $progressPercent; ?>%"></div>
                            </div>
                            <span style="font-size: 14px; color: #6b7280;">
                                <?php echo $progressPercent; ?>%
                            </span>
                        </div>
                        <small style="color: #6b7280;">
                            <?php echo $trainee['modules_completed']; ?>/<?php echo $trainee['total_modules']; ?> modules
                        </small>
                    </td>
                    <td>
                        <div class="module-indicators">
                            <?php foreach ($modules as $index => $module): 
                                $status = $moduleStatuses[$module['id']] ?? 'not_started';
                                $statusClass = 'module-not-started';
                                if ($status === 'completed') $statusClass = 'module-completed';
                                elseif ($status === 'in_progress') $statusClass = 'module-in-progress';
                            ?>
                            <div class="module-dot <?php echo $statusClass; ?>" 
                                 title="<?php echo htmlspecialchars($module['title']); ?>">
                                <?php echo $index + 1; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($trainee['training_started']): ?>
                            <?php echo date('M d, Y', strtotime($trainee['training_started'])); ?>
                        <?php else: ?>
                            <span style="color: #9ca3af;">Not started</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($trainee['last_activity']): ?>
                            <?php 
                            $daysAgo = floor((time() - strtotime($trainee['last_activity'])) / 86400);
                            if ($daysAgo == 0) echo 'Today';
                            elseif ($daysAgo == 1) echo 'Yesterday';
                            else echo $daysAgo . ' days ago';
                            ?>
                        <?php else: ?>
                            <span style="color: #9ca3af;">Never</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($trainee['is_complete']): ?>
                            <span class="status-badge status-complete">Complete</span>
                        <?php elseif ($trainee['last_activity'] && (time() - strtotime($trainee['last_activity'])) < 604800): ?>
                            <span class="status-badge status-active">Active</span>
                        <?php else: ?>
                            <span class="status-badge status-inactive">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                        $m1 = $trainee['module1_quiz_percent'] ?? null;
                        if ($m1 === null || $m1 === '') {
                            echo '<span style="color:#9ca3af;">—</span>';
                        } else {
                            $color = intval($m1) >= 80 ? '#059669' : '#dc2626';
                            echo '<strong style="color:' . $color . '">' . intval($m1) . '%</strong>';
                        }
                        ?>
                    </td>
                    <td>
                        <a href="<?php echo path_with_base('/admin/trainee-detail.php'); ?>?id=<?php echo $trainee['id']; ?>" class="btn-small">
                            View Details
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    </form>
    <?php endif; ?>
    
    <!-- Module Legend -->
    <div class="card" style="margin-top: 24px;">
        <h3>Module Legend</h3>
        <div style="display: flex; gap: 24px; margin-top: 16px;">
            <?php foreach ($modules as $index => $module): ?>
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="module-dot module-completed"><?php echo $index + 1; ?></div>
                <span style="font-size: 14px;"><?php echo htmlspecialchars($module['title']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function toggleAllTrainees(checkbox) {
    const traineeCheckboxes = document.querySelectorAll('.trainee-checkbox');
    const headerCheckbox = document.getElementById('header-select-all');
    const selectAllCheckbox = document.getElementById('select-all');
    
    traineeCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    
    // Sync both select all checkboxes
    if (checkbox.id === 'select-all') {
        headerCheckbox.checked = checkbox.checked;
    } else if (checkbox.id === 'header-select-all') {
        selectAllCheckbox.checked = checkbox.checked;
    }
}

function showMessageModal() {
    const selectedCheckboxes = document.querySelectorAll('.trainee-checkbox:checked');
    if (selectedCheckboxes.length === 0) {
        alert('Please select at least one trainee to send a message.');
        return;
    }
    document.getElementById('message-modal').style.display = 'block';
}

function hideMessageModal() {
    document.getElementById('message-modal').style.display = 'none';
    document.querySelector('textarea[name="message"]').value = '';
}

// Close modal when clicking outside
document.getElementById('message-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideMessageModal();
    }
});

// Validate bulk actions
document.getElementById('bulk-form').addEventListener('submit', function(e) {
    const selectedCheckboxes = document.querySelectorAll('.trainee-checkbox:checked');
    const action = e.submitter.value;
    
    if (selectedCheckboxes.length === 0 && action !== 'send_message') {
        e.preventDefault();
        alert('Please select at least one trainee for this action.');
        return;
    }
    
    // Additional confirmations for destructive actions
    if (action === 'reset_training') {
        if (!confirm(`Are you sure you want to reset training progress for ${selectedCheckboxes.length} trainee(s)? This action cannot be undone.`)) {
            e.preventDefault();
        }
    } else if (action === 'promote_to_agent') {
        if (!confirm(`Are you sure you want to promote ${selectedCheckboxes.length} trainee(s) to Sales Agent? They will gain access to sales tools.`)) {
            e.preventDefault();
        }
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
