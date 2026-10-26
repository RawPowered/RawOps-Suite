<?php
$SEO = [
    'title' => 'Trainee Details — Admin',
    'description' => 'Detailed view and management of trainee progress.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/config.php';

start_session();
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$adminId = current_admin_id();
$userId = intval($_GET['id'] ?? 0);

if ($userId <= 0) {
    flash_set('error', 'Invalid user ID.');
    redirect(path_with_base('/admin/trainee-monitor.php'));
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'toggle_sales_agent':
                $currentRoles = [];
                $stmt = $pdo->prepare("SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?");
                $stmt->execute([$userId]);
                while ($row = $stmt->fetch()) {
                    $currentRoles[] = $row['name'];
                }
                
                if (in_array('sales_agent', $currentRoles)) {
                    // Remove sales_agent role, add trainee role
                    $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = (SELECT id FROM roles WHERE name = 'sales_agent')")->execute([$userId]);
                    $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, (SELECT id FROM roles WHERE name = 'trainee'))")->execute([$userId]);
                    
                    // Log admin action
                    $stmt = $pdo->prepare("INSERT INTO admin_history (admin_id, target_user_id, action_type, action_description, old_value, new_value, ip_address, user_agent) VALUES (?, ?, 'role_change', 'Revoked sales agent status', 'sales_agent', 'trainee', ?, ?)");
                    $stmt->execute([$adminId, $userId, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
                    
                    flash_set('success', 'Sales agent status revoked. User is now a trainee.');
                } else {
                    // Add sales_agent role, remove trainee role
                    $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = (SELECT id FROM roles WHERE name = 'trainee')")->execute([$userId]);
                    $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, (SELECT id FROM roles WHERE name = 'sales_agent'))")->execute([$userId]);
                    
                    // Log admin action
                    $stmt = $pdo->prepare("INSERT INTO admin_history (admin_id, target_user_id, action_type, action_description, old_value, new_value, ip_address, user_agent) VALUES (?, ?, 'role_change', 'Granted sales agent status', 'trainee', 'sales_agent', ?, ?)");
                    $stmt->execute([$adminId, $userId, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
                    
                    flash_set('success', 'Sales agent status granted successfully.');
                }
                break;
                
            case 'require_retest':
                $moduleId = intval($_POST['module_id'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');
                
                if ($moduleId > 0 && !empty($reason)) {
                    // Mark existing retest requirements as inactive
                    $pdo->prepare("UPDATE training_retest_requirements SET is_active = 0 WHERE user_id = ? AND module_id = ?")->execute([$userId, $moduleId]);
                    
                    // Create new retest requirement
                    $stmt = $pdo->prepare("INSERT INTO training_retest_requirements (user_id, module_id, admin_id, reason) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$userId, $moduleId, $adminId, $reason]);
                    
                    // Reset training progress for this module
                    $pdo->prepare("UPDATE training_progress SET status = 'not_started', started_at = NULL, completed_at = NULL WHERE user_id = ? AND module_id = ?")->execute([$userId, $moduleId]);
                    
                    // Log admin action
                    $stmt = $pdo->prepare("INSERT INTO admin_history (admin_id, target_user_id, action_type, action_description, old_value, new_value, ip_address, user_agent) VALUES (?, ?, 'retest_required', ?, 'completed', 'retest_required', ?, ?)");
                    $stmt->execute([$adminId, $userId, "Required retest for module {$moduleId}: {$reason}", $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
                    
                    flash_set('success', 'Retest requirement added successfully.');
                } else {
                    flash_set('error', 'Please provide a valid module and reason.');
                }
                break;
                
            case 'clear_retest':
                $retestId = intval($_POST['retest_id'] ?? 0);
                if ($retestId > 0) {
                    $stmt = $pdo->prepare("UPDATE training_retest_requirements SET is_active = 0, completed_at = NOW() WHERE id = ? AND user_id = ?");
                    $stmt->execute([$retestId, $userId]);
                    
                    // Log admin action
                    $stmt = $pdo->prepare("INSERT INTO admin_history (admin_id, target_user_id, action_type, action_description, ip_address, user_agent) VALUES (?, ?, 'retest_cleared', 'Cleared retest requirement', ?, ?)");
                    $stmt->execute([$adminId, $userId, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
                    
                    flash_set('success', 'Retest requirement cleared.');
                }
                break;
                
            case 'update_status':
                $newStatus = $_POST['status'] ?? '';
                if (in_array($newStatus, ['pending', 'active', 'suspended'])) {
                    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $oldStatus = $stmt->fetchColumn();
                    
                    $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$newStatus, $userId]);
                    
                    // Log admin action
                    $stmt = $pdo->prepare("INSERT INTO admin_history (admin_id, target_user_id, action_type, action_description, old_value, new_value, ip_address, user_agent) VALUES (?, ?, 'status_change', 'Changed user status', ?, ?, ?, ?)");
                    $stmt->execute([$adminId, $userId, $oldStatus, $newStatus, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
                    
                    flash_set('success', 'User status updated successfully.');
                }
                break;
        }
    } catch (Exception $e) {
        flash_set('error', 'Database error: ' . $e->getMessage());
    }
    
    // Redirect to prevent form resubmission
    redirect(path_with_base('/admin/trainee-detail.php') . "?id={$userId}");
}

// Fetch user details
try {
    $stmt = $pdo->prepare("
        SELECT u.*, 
               GROUP_CONCAT(r.name) as roles,
               wa.business_name, wa.approved as wholesale_approved
        FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        LEFT JOIN roles r ON r.id = ur.role_id
        LEFT JOIN wholesale_accounts wa ON wa.user_id = u.id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        flash_set('error', 'User not found.');
        redirect(path_with_base('/admin/trainee-monitor.php'));
    }
    
    // Get training progress
    $stmt = $pdo->prepare("
        SELECT tp.*, tm.title, tm.slug, tm.duration_minutes, tm.required,
               trr.id as retest_id, trr.reason as retest_reason, trr.required_at as retest_required_at
        FROM training_modules tm
        LEFT JOIN training_progress tp ON tp.module_id = tm.id AND tp.user_id = ?
        LEFT JOIN training_retest_requirements trr ON trr.module_id = tm.id AND trr.user_id = ? AND trr.is_active = 1
        ORDER BY tm.sort_order
    ");
    $stmt->execute([$userId, $userId]);
    $trainingProgress = $stmt->fetchAll();
    
    // Get admin history for this user
    $stmt = $pdo->prepare("
        SELECT ah.*, au.first_name as admin_first_name, au.last_name as admin_last_name
        FROM admin_history ah
        JOIN users au ON au.id = ah.admin_id
        WHERE ah.target_user_id = ?
        ORDER BY ah.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$userId]);
    $adminHistory = $stmt->fetchAll();
    
    // Get quiz results
    $stmt = $pdo->prepare("
        SELECT tqr.*, tm.title as module_title
        FROM training_quiz_results tqr
        JOIN training_modules tm ON tm.id = tqr.module_id
        WHERE tqr.user_id = ?
        ORDER BY tqr.completed_at DESC
    ");
    $stmt->execute([$userId]);
    $quizResults = $stmt->fetchAll();
    
} catch (Exception $e) {
    flash_set('error', 'Database error: ' . $e->getMessage());
    redirect(path_with_base('/admin/trainee-monitor.php'));
}

// Calculate statistics
$totalModules = count(array_filter($trainingProgress, fn($m) => $m['required']));
$completedModules = count(array_filter($trainingProgress, fn($m) => $m['required'] && $m['status'] === 'completed'));
$progressPercent = $totalModules > 0 ? round(($completedModules / $totalModules) * 100) : 0;
$userRoles = explode(',', $user['roles'] ?? '');
$isSalesAgent = in_array('sales_agent', $userRoles);
?>

<style>
.admin-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.user-info-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 32px;
    margin-bottom: 32px;
}
.user-card {
    background: white;
    border-radius: 12px;
    padding: 32px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.user-sidebar {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.status-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.progress-ring {
    width: 120px;
    height: 120px;
    margin: 0 auto 16px;
    position: relative;
}
.progress-ring svg {
    width: 100%;
    height: 100%;
    transform: rotate(-90deg);
}
.progress-ring .progress-circle {
    fill: none;
    stroke-width: 8;
    r: 52;
    cx: 60;
    cy: 60;
}
.progress-ring .progress-bg {
    stroke: #e5e7eb;
}
.progress-ring .progress-fill {
    stroke: #3b82f6;
    stroke-dasharray: 327;
    stroke-dashoffset: 327;
    transition: stroke-dashoffset 0.5s;
}
.progress-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 24px;
    font-weight: bold;
    color: #1f2937;
}
.training-module {
    background: #f9fafb;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    border-left: 4px solid #e5e7eb;
}
.training-module.completed {
    border-left-color: #10b981;
    background: #ecfdf5;
}
.training-module.in-progress {
    border-left-color: #f59e0b;
    background: #fffbeb;
}
.training-module.retest-required {
    border-left-color: #ef4444;
    background: #fef2f2;
}
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.status-pending { background: #fef3c7; color: #92400e; }
.status-active { background: #dcfce7; color: #14532d; }
.status-suspended { background: #fee2e2; color: #991b1b; }
.role-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 16px;
    font-size: 12px;
    font-weight: 600;
    margin-right: 8px;
}
.role-trainee { background: #dbeafe; color: #1e40af; }
.role-sales_agent { background: #dcfce7; color: #14532d; }
.role-admin { background: #f3e8ff; color: #7c3aed; }
.action-btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    margin-right: 8px;
    margin-bottom: 8px;
}
.btn-primary { background: #3b82f6; color: white; }
.btn-success { background: #10b981; color: white; }
.btn-warning { background: #f59e0b; color: white; }
.btn-danger { background: #ef4444; color: white; }
.btn-secondary { background: #6b7280; color: white; }
.action-btn:hover { opacity: 0.9; }
.history-item {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}
.history-item:last-child {
    border-bottom: none;
}
.quiz-result {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
    margin-bottom: 8px;
}
.quiz-score {
    font-weight: 600;
}
.quiz-passed { color: #10b981; }
.quiz-failed { color: #ef4444; }
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
}
.modal-content {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    border-radius: 12px;
    padding: 24px;
    width: 90%;
    max-width: 500px;
}
.tabs {
    display: flex;
    gap: 8px;
    background: #f3f4f6;
    border-radius: 8px;
    padding: 4px;
    margin-bottom: 24px;
}
.tab-btn {
    flex: 1;
    padding: 8px 16px;
    background: transparent;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
}
.tab-btn.active {
    background: white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}
</style>

<div class="admin-header">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="font-size: 36px; margin-bottom: 8px;">
                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                </h1>
                <p style="font-size: 18px; opacity: 0.9;">
                    Trainee Management & Progress Details
                </p>
            </div>
            <div>
                <a href="<?php echo path_with_base('/admin/trainee-monitor.php'); ?>" class="btn secondary">← Back to Monitor</a>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <?php if ($message = flash_get('success')): ?>
        <div class="alert success" style="margin-bottom: 24px;"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error = flash_get('error')): ?>
        <div class="alert error" style="margin-bottom: 24px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- User Information -->
    <div class="user-info-grid">
        <div class="user-card">
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn active" onclick="showTab('overview')">Overview</button>
                <button class="tab-btn" onclick="showTab('training')">Training Progress</button>
                <button class="tab-btn" onclick="showTab('history')">Admin History</button>
                <button class="tab-btn" onclick="showTab('quiz-results')">Quiz Results</button>
            </div>
            
            <!-- Overview Tab -->
            <div id="overview" class="tab-content active">
                <h3 style="margin-bottom: 20px;">User Information</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <div>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($user['phone'] ?: 'Not provided'); ?></p>
                        <p><strong>Status:</strong> 
                            <span class="status-badge status-<?php echo $user['status']; ?>">
                                <?php echo ucfirst($user['status']); ?>
                            </span>
                        </p>
                        <p><strong>Roles:</strong> 
                            <?php foreach ($userRoles as $role): ?>
                                <span class="role-badge role-<?php echo $role; ?>"><?php echo ucfirst(str_replace('_', ' ', $role)); ?></span>
                            <?php endforeach; ?>
                        </p>
                    </div>
                    <div>
                        <p><strong>Joined:</strong> <?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
                        <p><strong>Last Updated:</strong> <?php echo $user['updated_at'] ? date('F j, Y g:i A', strtotime($user['updated_at'])) : 'Never'; ?></p>
                        <?php if ($user['business_name']): ?>
                        <p><strong>Business:</strong> <?php echo htmlspecialchars($user['business_name']); ?></p>
                        <p><strong>Wholesale Status:</strong> 
                            <span class="status-badge <?php echo $user['wholesale_approved'] ? 'status-active' : 'status-pending'; ?>">
                                <?php echo $user['wholesale_approved'] ? 'Approved' : 'Pending'; ?>
                            </span>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div style="margin-top: 32px; padding-top: 24px; border-top: 1px solid #e5e7eb;">
                    <h4 style="margin-bottom: 16px;">Quick Actions</h4>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="toggle_sales_agent">
                        <button type="submit" class="action-btn <?php echo $isSalesAgent ? 'btn-warning' : 'btn-success'; ?>" 
                                onclick="return confirm('Are you sure you want to <?php echo $isSalesAgent ? 'revoke sales agent status' : 'grant sales agent status'; ?>?')">
                            <?php echo $isSalesAgent ? '⬇️ Demote to Trainee' : '⬆️ Promote to Sales Agent'; ?>
                        </button>
                    </form>
                    
                    <button onclick="showModal('status-modal')" class="action-btn btn-secondary">
                        👤 Change Status
                    </button>
                    
                    <button onclick="showModal('retest-modal')" class="action-btn btn-danger">
                        🔄 Require Retest
                    </button>
                </div>
            </div>
            
            <!-- Training Progress Tab -->
            <div id="training" class="tab-content">
                <h3 style="margin-bottom: 20px;">Training Modules</h3>
                <?php foreach ($trainingProgress as $module): ?>
                <div class="training-module <?php echo $module['status'] ?: 'not-started'; ?> <?php echo $module['retest_id'] ? 'retest-required' : ''; ?>">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div style="flex: 1;">
                            <h4 style="margin-bottom: 8px;"><?php echo htmlspecialchars($module['title']); ?></h4>
                            <div style="display: flex; gap: 16px; font-size: 14px; color: #6b7280;">
                                <span>📚 <?php echo $module['duration_minutes']; ?> min</span>
                                <span>⭐ <?php echo $module['required'] ? 'Required' : 'Optional'; ?></span>
                                <span class="status-badge status-<?php echo $module['status'] ?: 'pending'; ?>">
                                    <?php echo ucfirst($module['status'] ?: 'Not Started'); ?>
                                </span>
                            </div>
                            
                            <?php if ($module['retest_id']): ?>
                            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; margin-top: 12px;">
                                <p style="color: #dc2626; font-weight: 600; margin-bottom: 4px;">🔄 Retest Required</p>
                                <p style="color: #7f1d1d; font-size: 14px; margin-bottom: 8px;">
                                    <?php echo htmlspecialchars($module['retest_reason']); ?>
                                </p>
                                <small style="color: #991b1b;">
                                    Required on: <?php echo date('M j, Y g:i A', strtotime($module['retest_required_at'])); ?>
                                </small>
                                <form method="post" style="display: inline; margin-left: 12px;">
                                    <input type="hidden" name="action" value="clear_retest">
                                    <input type="hidden" name="retest_id" value="<?php echo $module['retest_id']; ?>">
                                    <button type="submit" class="action-btn btn-success" style="padding: 4px 8px; font-size: 12px;" 
                                            onclick="return confirm('Clear this retest requirement?')">
                                        ✅ Clear Requirement
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($module['started_at']): ?>
                            <div style="margin-top: 8px; font-size: 13px; color: #6b7280;">
                                Started: <?php echo date('M j, Y g:i A', strtotime($module['started_at'])); ?>
                                <?php if ($module['completed_at']): ?>
                                    | Completed: <?php echo date('M j, Y g:i A', strtotime($module['completed_at'])); ?>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Admin History Tab -->
            <div id="history" class="tab-content">
                <h3 style="margin-bottom: 20px;">Admin Action History</h3>
                <?php if (empty($adminHistory)): ?>
                    <p style="color: #6b7280; text-align: center; padding: 40px;">No admin actions recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($adminHistory as $history): ?>
                    <div class="history-item">
                        <div>
                            <p style="font-weight: 600; margin-bottom: 4px;">
                                <?php echo htmlspecialchars($history['action_description']); ?>
                            </p>
                            <p style="font-size: 13px; color: #6b7280;">
                                by <?php echo htmlspecialchars($history['admin_first_name'] . ' ' . $history['admin_last_name']); ?>
                                <?php if ($history['old_value'] && $history['new_value']): ?>
                                    | <?php echo htmlspecialchars($history['old_value']); ?> → <?php echo htmlspecialchars($history['new_value']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div style="text-align: right; font-size: 12px; color: #9ca3af;">
                            <?php echo date('M j, Y g:i A', strtotime($history['created_at'])); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Quiz Results Tab -->
            <div id="quiz-results" class="tab-content">
                <h3 style="margin-bottom: 20px;">Quiz Results</h3>
                <?php if (empty($quizResults)): ?>
                    <p style="color: #6b7280; text-align: center; padding: 40px;">No quiz results recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($quizResults as $result): ?>
                    <div class="quiz-result">
                        <div>
                            <p style="font-weight: 600;"><?php echo htmlspecialchars($result['module_title']); ?></p>
                            <p style="font-size: 13px; color: #6b7280;">
                                Attempt #<?php echo $result['attempt_number']; ?> | 
                                <?php echo date('M j, Y g:i A', strtotime($result['completed_at'])); ?>
                            </p>
                        </div>
                        <div class="quiz-score <?php echo $result['passed'] ? 'quiz-passed' : 'quiz-failed'; ?>">
                            <?php echo $result['score']; ?>/<?php echo $result['total_questions']; ?>
                            (<?php echo round(($result['score'] / $result['total_questions']) * 100); ?>%)
                            <?php echo $result['passed'] ? '✅' : '❌'; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Sidebar -->
        <div class="user-sidebar">
            <!-- Progress Overview -->
            <div class="status-card">
                <h3 style="text-align: center; margin-bottom: 16px;">Training Progress</h3>
                <div class="progress-ring">
                    <svg>
                        <circle class="progress-circle progress-bg"></circle>
                        <circle class="progress-circle progress-fill" 
                                style="stroke-dashoffset: <?php echo 327 - (327 * $progressPercent / 100); ?>"></circle>
                    </svg>
                    <div class="progress-text"><?php echo $progressPercent; ?>%</div>
                </div>
                <p style="text-align: center; color: #6b7280;">
                    <?php echo $completedModules; ?> of <?php echo $totalModules; ?> modules completed
                </p>
            </div>
            
            <!-- Current Status -->
            <div class="status-card">
                <h4 style="margin-bottom: 12px;">Current Status</h4>
                <p style="margin-bottom: 8px;">
                    <strong>Account:</strong> 
                    <span class="status-badge status-<?php echo $user['status']; ?>">
                        <?php echo ucfirst($user['status']); ?>
                    </span>
                </p>
                <p style="margin-bottom: 8px;">
                    <strong>Role:</strong> 
                    <span class="role-badge role-<?php echo $isSalesAgent ? 'sales_agent' : 'trainee'; ?>">
                        <?php echo $isSalesAgent ? 'Sales Agent' : 'Trainee'; ?>
                    </span>
                </p>
                <?php 
                $activeRetests = array_filter($trainingProgress, fn($m) => $m['retest_id']);
                if (!empty($activeRetests)): 
                ?>
                <p style="color: #dc2626; font-weight: 600;">
                    ⚠️ <?php echo count($activeRetests); ?> Retest<?php echo count($activeRetests) > 1 ? 's' : ''; ?> Required
                </p>
                <?php endif; ?>
            </div>
            
            <!-- Quick Stats -->
            <div class="status-card">
                <h4 style="margin-bottom: 12px;">Quick Stats</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 14px;">
                    <div>
                        <strong>Total Quizzes:</strong><br>
                        <span style="color: #6b7280;"><?php echo count($quizResults); ?></span>
                    </div>
                    <div>
                        <strong>Pass Rate:</strong><br>
                        <span style="color: #6b7280;">
                            <?php 
                            $passedQuizzes = count(array_filter($quizResults, fn($q) => $q['passed']));
                            echo count($quizResults) > 0 ? round(($passedQuizzes / count($quizResults)) * 100) . '%' : 'N/A';
                            ?>
                        </span>
                    </div>
                    <div>
                        <strong>Admin Actions:</strong><br>
                        <span style="color: #6b7280;"><?php echo count($adminHistory); ?></span>
                    </div>
                    <div>
                        <strong>Active Since:</strong><br>
                        <span style="color: #6b7280;"><?php echo date('M Y', strtotime($user['created_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Status Change Modal -->
<div id="status-modal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;">Change User Status</h3>
        <form method="post">
            <input type="hidden" name="action" value="update_status">
            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px;">New Status:</label>
                <select name="status" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                    <option value="pending" <?php echo $user['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="suspended" <?php echo $user['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                </select>
            </div>
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" onclick="hideModal('status-modal')" class="action-btn btn-secondary">Cancel</button>
                <button type="submit" class="action-btn btn-primary">Update Status</button>
            </div>
        </form>
    </div>
</div>

<!-- Retest Modal -->
<div id="retest-modal" class="modal">
    <div class="modal-content">
        <h3 style="margin-bottom: 16px;">Require Module Retest</h3>
        <form method="post">
            <input type="hidden" name="action" value="require_retest">
            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px;">Module:</label>
                <select name="module_id" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                    <option value="">Select a module...</option>
                    <?php foreach ($trainingProgress as $module): ?>
                        <?php if ($module['status'] === 'completed'): ?>
                        <option value="<?php echo $module['module_id']; ?>">
                            <?php echo htmlspecialchars($module['title']); ?>
                        </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px;">Reason for Retest:</label>
                <textarea name="reason" required rows="3" placeholder="Explain why this module needs to be retaken..."
                         style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"></textarea>
            </div>
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" onclick="hideModal('retest-modal')" class="action-btn btn-secondary">Cancel</button>
                <button type="submit" class="action-btn btn-danger">Require Retest</button>
            </div>
        </form>
    </div>
</div>

<script>
function showTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById(tabName).classList.add('active');
    
    // Add active class to clicked button
    event.target.classList.add('active');
}

function showModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function hideModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
