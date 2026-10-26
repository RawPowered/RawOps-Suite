<?php
$SEO = [
    'title' => 'Tasks — Sales Agent CRM — Product',
    'description' => 'Manage your follow-up tasks and reminders in the Product CRM system.',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';

start_session();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

// Check if this is admin simulation or real sales agent
$isAdminSimulation = isset($_SESSION['admin_simulation']);
if (!$isAdminSimulation) {
    // For real users, check role
    $pdo = db();
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    try { 
        $stmt->execute([$uid]); 
        $hasRole = (bool)$stmt->fetch(); 
    } catch (Throwable $e) { 
        $hasRole = false; 
    }
    if (!$hasRole && !is_training_complete($uid)) {
        redirect(path_with_base('/onboarding/trainee/index.php'));
    }
}

$pdo = db();

// Modal/AJAX helpers
$isAjaxFetch = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && stripos($_SERVER['HTTP_X_REQUESTED_WITH'], 'fetch') !== false);
$isModal = isset($_GET['modal']) || isset($_POST['modal']);

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'request_assignment') {
            // Sales agent requested assignment for nearby store already in DB
            $subject = trim($_POST['subject'] ?? 'Lead assignment request');
            $details = trim($_POST['details'] ?? '');
            $stmt = $pdo->prepare('
                INSERT INTO crm_tasks (
                    agent_id, lead_id, account_id, task_type, title, description,
                    priority, due_date, estimated_duration_minutes
                ) VALUES (?, NULL, NULL, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), ?)
            ');
            $stmt->execute([
                $uid,
                'follow_up',
                $subject,
                $details ?: 'Sales agent requests assignment to this lead/account.',
                'high',
                15
            ]);
            echo 'OK';
            exit;
        }
        if ($action === 'add_task') {
            $leadId = !empty($_POST['lead_id']) ? intval($_POST['lead_id']) : null;
            $accountId = !empty($_POST['account_id']) ? intval($_POST['account_id']) : null;
            
            // Verify access to lead/account if not admin simulation
            if (!$isAdminSimulation && $leadId) {
                $stmt = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? AND assigned_agent_id = ?');
                $stmt->execute([$leadId, $uid]);
                if (!$stmt->fetch()) {
                    flash_set('error', 'Access denied to this lead.');
                    header('Location: tasks.php');
                    exit;
                }
            }
            
            $stmt = $pdo->prepare('
                INSERT INTO crm_tasks (
                    agent_id, lead_id, account_id, task_type, title, description,
                    priority, due_date, estimated_duration_minutes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                $uid,
                $leadId,
                $accountId,
                $_POST['task_type'] ?: 'other',
                $_POST['title'],
                $_POST['description'] ?: null,
                $_POST['priority'] ?: 'medium',
                $_POST['due_date'],
                intval($_POST['estimated_duration_minutes'] ?: 30)
            ]);
            
            if ($isModal || $isAjaxFetch) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            } else {
                flash_set('success', 'Task created successfully!');
                header('Location: tasks.php');
                exit;
            }
            
        } elseif ($action === 'update_task') {
            $taskId = intval($_POST['task_id']);
            
            // Verify this task belongs to current agent (or admin simulation)
            if (!$isAdminSimulation) {
                $stmt = $pdo->prepare('SELECT 1 FROM crm_tasks WHERE id = ? AND agent_id = ?');
                $stmt->execute([$taskId, $uid]);
                if (!$stmt->fetch()) {
                    flash_set('error', 'Access denied to this task.');
                    header('Location: tasks.php');
                    exit;
                }
            }
            
            $stmt = $pdo->prepare('
                UPDATE crm_tasks SET 
                    task_type = ?, title = ?, description = ?, priority = ?, 
                    due_date = ?, estimated_duration_minutes = ?, updated_at = NOW()
                WHERE id = ?
            ');
            
            $stmt->execute([
                $_POST['task_type'] ?: 'other',
                $_POST['title'],
                $_POST['description'] ?: null,
                $_POST['priority'] ?: 'medium',
                $_POST['due_date'],
                intval($_POST['estimated_duration_minutes'] ?: 30),
                $taskId
            ]);
            
            if ($isModal || $isAjaxFetch) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            } else {
                flash_set('success', 'Task updated successfully!');
                header('Location: tasks.php?id=' . $taskId);
                exit;
            }
            
        } elseif ($action === 'complete') {
            $taskId = intval($_POST['task_id']);
            
            // Verify this task belongs to current agent (or admin simulation)
            if (!$isAdminSimulation) {
                $stmt = $pdo->prepare('SELECT 1 FROM crm_tasks WHERE id = ? AND agent_id = ?');
                $stmt->execute([$taskId, $uid]);
                if (!$stmt->fetch()) {
                    flash_set('error', 'Access denied to this task.');
                    header('Location: tasks.php');
                    exit;
                }
            }
            
            $actualDuration = intval($_POST['actual_duration_minutes'] ?: 0);
            
            $stmt = $pdo->prepare('
                UPDATE crm_tasks SET 
                    status = "completed", completed_at = NOW(), 
                    actual_duration_minutes = ?, updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$actualDuration, $taskId]);
            
            if ($isModal || $isAjaxFetch) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            } else {
                flash_set('success', 'Task marked as completed!');
                header('Location: tasks.php');
                exit;
            }
            
        } elseif ($action === 'update_status') {
            $taskId = intval($_POST['task_id']);
            $newStatus = $_POST['status'];
            
            if (!in_array($newStatus, ['pending', 'in_progress', 'completed', 'cancelled'])) {
                throw new Exception('Invalid status');
            }
            
            // Verify this task belongs to current agent (or admin simulation)
            if (!$isAdminSimulation) {
                $stmt = $pdo->prepare('SELECT 1 FROM crm_tasks WHERE id = ? AND agent_id = ?');
                $stmt->execute([$taskId, $uid]);
                if (!$stmt->fetch()) {
                    flash_set('error', 'Access denied to this task.');
                    header('Location: tasks.php');
                    exit;
                }
            }
            
            if ($newStatus === 'completed') {
                $stmt = $pdo->prepare('UPDATE crm_tasks SET status = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?');
            } else {
                $stmt = $pdo->prepare('UPDATE crm_tasks SET status = ?, completed_at = NULL, updated_at = NOW() WHERE id = ?');
            }
            $stmt->execute([$newStatus, $taskId]);
            
            if ($isModal || $isAjaxFetch) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            } else {
                flash_set('success', 'Task status updated!');
                header('Location: tasks.php');
                exit;
            }
        }
    } catch (Exception $e) {
        if ($isModal || $isAjaxFetch) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            exit;
        } else {
            flash_set('error', 'Error: ' . $e->getMessage());
        }
    }
}

// Get data
$tasks = [];
$taskDetail = null;
$leads = [];
$accounts = [];

$taskId = intval($_GET['id'] ?? 0);
$preselectedLeadId = intval($_GET['lead_id'] ?? 0);
$filter = $_GET['filter'] ?? 'active'; // active, completed, overdue, all

try {
    // Get leads for dropdown
    $query = 'SELECT id, CONCAT(first_name, " ", last_name) as name, company_name FROM crm_leads';
    if (!$isAdminSimulation) {
        $query .= ' WHERE assigned_agent_id = ?';
        $stmt = $pdo->prepare($query . ' ORDER BY first_name, last_name');
        $stmt->execute([$uid]);
    } else {
        $stmt = $pdo->prepare($query . ' ORDER BY first_name, last_name');
        $stmt->execute();
    }
    $leads = $stmt->fetchAll();
    
    // Get wholesale accounts for dropdown
    $stmt = $pdo->prepare('SELECT wa.id, wa.business_name, u.first_name, u.last_name FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id WHERE wa.approved = 1 ORDER BY wa.business_name');
    $stmt->execute();
    $accounts = $stmt->fetchAll();
    
    if ($taskId > 0) {
        // Get specific task detail
        $query = '
            SELECT t.*, 
                   CONCAT(l.first_name, " ", l.last_name) as lead_name,
                   l.company_name as lead_company,
                   wa.business_name as account_name,
                   DATEDIFF(t.due_date, NOW()) as days_until_due
            FROM crm_tasks t 
            LEFT JOIN crm_leads l ON l.id = t.lead_id
            LEFT JOIN wholesale_accounts wa ON wa.id = t.account_id
            WHERE t.id = ?';
        
        if (!$isAdminSimulation) {
            $query .= ' AND t.agent_id = ?';
            $stmt = $pdo->prepare($query);
            $stmt->execute([$taskId, $uid]);
        } else {
            $stmt = $pdo->prepare($query);
            $stmt->execute([$taskId]);
        }
        
        $taskDetail = $stmt->fetch();
        
        if (!$taskDetail) {
            flash_set('error', 'Task not found or access denied.');
            header('Location: tasks.php');
            exit;
        }
    } else {
        // Get tasks based on filter
        $whereClause = '';
        $params = [];
        
        if (!$isAdminSimulation) {
            $whereClause = 'WHERE t.agent_id = ?';
            $params[] = $uid;
        }
        
        // Apply filters
        if ($filter === 'active') {
            $whereClause .= ($whereClause ? ' AND ' : 'WHERE ') . 't.status IN ("pending", "in_progress")';
        } elseif ($filter === 'completed') {
            $whereClause .= ($whereClause ? ' AND ' : 'WHERE ') . 't.status = "completed"';
        } elseif ($filter === 'overdue') {
            $whereClause .= ($whereClause ? ' AND ' : 'WHERE ') . 't.status IN ("pending", "in_progress") AND t.due_date < NOW()';
        }
        // 'all' filter shows everything
        
        $query = '
            SELECT t.*, 
                   CONCAT(l.first_name, " ", l.last_name) as lead_name,
                   l.company_name as lead_company,
                   wa.business_name as account_name,
                   DATEDIFF(t.due_date, NOW()) as days_until_due
            FROM crm_tasks t 
            LEFT JOIN crm_leads l ON l.id = t.lead_id
            LEFT JOIN wholesale_accounts wa ON wa.id = t.account_id
            ' . $whereClause . '
            ORDER BY 
                CASE WHEN t.due_date < NOW() AND t.status IN ("pending", "in_progress") THEN 1 ELSE 2 END,
                t.priority = "urgent" DESC,
                t.priority = "high" DESC,
                t.due_date ASC';
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
    }
} catch (Exception $e) {
    flash_set('error', 'Database error: ' . $e->getMessage());
}

// If requested as modal fragment for Add Task, render only the form
if ($isModal && ($_GET['action'] ?? '') === 'add') {
    ?>
    <div style="padding:20px">
        <h3 style="margin:0 0 16px 0;">Create New Task</h3>
        <form method="post" style="display:grid; gap:16px;" data-ajax>
            <input type="hidden" name="modal" value="1">
            <input type="hidden" name="action" value="add_task">
            <div>
                <label>Task Title *</label>
                <input type="text" name="title" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" placeholder="Follow up with prospect about...">
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div>
                    <label>Task Type</label>
                    <select name="task_type" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <?php foreach (['call','email','meeting','follow_up','proposal','demo','other'] as $type): ?>
                        <option value="<?php echo $type; ?>" <?php echo ($type==='follow_up'?'selected':''); ?>><?php echo ucwords(str_replace('_',' ', $type)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Priority</label>
                    <select name="priority" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <?php foreach (['low','medium','high','urgent'] as $p): ?>
                        <option value="<?php echo $p; ?>" <?php echo ($p==='medium'?'selected':''); ?>><?php echo ucfirst($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div>
                    <label>Related Lead</label>
                    <select name="lead_id" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Lead (Optional)</option>
                        <?php foreach ($leads as $lead): ?>
                            <option value="<?php echo $lead['id']; ?>" <?php echo $preselectedLeadId == $lead['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lead['name']); ?>
                                <?php if ($lead['company_name']): ?> - <?php echo htmlspecialchars($lead['company_name']); ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Related Account</label>
                    <select name="account_id" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Account (Optional)</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>"><?php echo htmlspecialchars($account['business_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label>Description</label>
                <textarea name="description" rows="3" placeholder="Detailed description of what needs to be done..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"></textarea>
            </div>
            <div style="display:grid; grid-template-columns: 1fr auto; gap: 12px;">
                <div>
                    <label>Due Date & Time *</label>
                    <input type="datetime-local" name="due_date" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" value="<?php echo date('Y-m-d\TH:i', strtotime('+1 day')); ?>">
                </div>
                <div>
                    <label>Est. Duration (min)</label>
                    <input type="number" name="estimated_duration_minutes" value="30" min="1" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
            <div style="display:flex; gap:12px; justify-content:end; margin-top: 16px;">
                <button type="button" class="btn secondary" onclick="window.SalesACPModals && SalesACPModals.close()">Cancel</button>
                <button type="submit" class="btn" style="background:#7c3aed;">Create Task</button>
            </div>
        </form>
    </div>
    <?php
    exit;
}

?>

<div class="container">
    <?php 
    // Include the enhanced Paladin navigation
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    ?>
    
    <?php
    // Include simple breadcrumb navigation
    require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php';
    ?>
    
    <?php if ($taskDetail): ?>
        <!-- Task Detail View -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1 style="margin: 0; color: #1f2937;">
                    ✓ <?php echo htmlspecialchars($taskDetail['title']); ?>
                </h1>
                <p style="margin: 4px 0 0 0; color: #6b7280;">
                    <?php echo ucwords(str_replace('_', ' ', $taskDetail['task_type'])); ?>
                    • <?php echo ucfirst($taskDetail['priority']); ?> Priority
                    • Due: <?php echo date('M j, Y g:i A', strtotime($taskDetail['due_date'])); ?>
                </p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="tasks.php" class="btn secondary">← Back to All Tasks</a>
                <?php if ($taskDetail['status'] !== 'completed'): ?>
                    <button onclick="showCompleteModal()" class="btn" style="background: #059669;">✓ Mark Complete</button>
                    <button onclick="showEditModal()" class="btn" style="background: #7c3aed;">✏️ Edit Task</button>
                <?php endif; ?>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
            <!-- Task Information -->
            <div class="card">
                <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 16px;">
                    <h3 style="margin: 0;">📋 Task Details</h3>
                    <span style="
                        background: <?php 
                        echo match($taskDetail['status']) {
                            'pending' => '#fef3c7',
                            'in_progress' => '#dbeafe',
                            'completed' => '#dcfce7',
                            'cancelled' => '#fee2e2',
                            default => '#f3f4f6'
                        }; 
                        ?>;
                        color: <?php 
                        echo match($taskDetail['status']) {
                            'pending' => '#d97706',
                            'in_progress' => '#1e40af',
                            'completed' => '#166534',
                            'cancelled' => '#dc2626',
                            default => '#6b7280'
                        }; 
                        ?>;
                        padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                        <?php echo ucwords(str_replace('_', ' ', $taskDetail['status'])); ?>
                    </span>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <strong>Related To:</strong><br>
                        <?php if ($taskDetail['lead_name']): ?>
                            🎯 Lead: <a href="leads.php?id=<?php echo $taskDetail['lead_id']; ?>" style="color: #3b82f6; text-decoration: none;"><?php echo htmlspecialchars($taskDetail['lead_name']); ?></a>
                            <?php if ($taskDetail['lead_company']): ?>
                                <br>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($taskDetail['lead_company']); ?>
                            <?php endif; ?>
                        <?php elseif ($taskDetail['account_name']): ?>
                            🏢 Account: <?php echo htmlspecialchars($taskDetail['account_name']); ?>
                        <?php else: ?>
                            📝 General Task
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong>Due Date:</strong><br>
                        <span style="
                            color: <?php 
                            if ($taskDetail['status'] === 'completed') {
                                echo '#166534';
                            } elseif ($taskDetail['days_until_due'] < 0) {
                                echo '#dc2626'; 
                            } elseif ($taskDetail['days_until_due'] <= 1) {
                                echo '#d97706'; 
                            } else {
                                echo '#6b7280'; 
                            }
                            ?>;
                            font-weight: 600;">
                            <?php 
                            if ($taskDetail['status'] === 'completed') {
                                echo '✓ Completed';
                            } elseif ($taskDetail['days_until_due'] < 0) {
                                echo abs($taskDetail['days_until_due']) . ' days overdue';
                            } elseif ($taskDetail['days_until_due'] == 0) {
                                echo 'Due today';
                            } elseif ($taskDetail['days_until_due'] == 1) {
                                echo 'Due tomorrow';
                            } else {
                                echo $taskDetail['days_until_due'] . ' days remaining';
                            }
                            ?>
                        </span><br>
                        <?php echo date('M j, Y g:i A', strtotime($taskDetail['due_date'])); ?>
                    </div>
                </div>

                <?php if ($taskDetail['description']): ?>
                <div style="margin-bottom: 16px;">
                    <strong>Description:</strong><br>
                    <div style="background: #f9fafb; padding: 12px; border-radius: 6px; white-space: pre-line; margin-top: 4px;">
                        <?php echo htmlspecialchars($taskDetail['description']); ?>
                    </div>
                </div>
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5e7eb;">
                    <div>
                        <strong>Priority:</strong><br>
                        <span style="
                            color: <?php 
                            echo match($taskDetail['priority']) {
                                'urgent' => '#dc2626', 
                                'high' => '#d97706', 
                                'medium' => '#1e40af', 
                                'low' => '#6b7280', 
                                default => '#6b7280'
                            }; 
                            ?>;
                            font-weight: 600;">
                            <?php echo ucfirst($taskDetail['priority']); ?>
                        </span>
                    </div>
                    <div>
                        <strong>Estimated:</strong><br>
                        <?php echo $taskDetail['estimated_duration_minutes']; ?> minutes
                    </div>
                    <div>
                        <strong>Actual:</strong><br>
                        <?php if ($taskDetail['actual_duration_minutes']): ?>
                            <?php echo $taskDetail['actual_duration_minutes']; ?> minutes
                        <?php elseif ($taskDetail['status'] === 'completed'): ?>
                            Not recorded
                        <?php else: ?>
                            In progress
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($taskDetail['status'] === 'completed' && $taskDetail['completed_at']): ?>
                <div style="background: #dcfce7; padding: 12px; border-radius: 6px; border-left: 4px solid #16a34a; margin-top: 16px;">
                    <strong>✅ Completed:</strong> <?php echo date('M j, Y g:i A', strtotime($taskDetail['completed_at'])); ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Actions & Status -->
            <div>
                <?php if ($taskDetail['status'] !== 'completed'): ?>
                <div class="card" style="margin-bottom: 16px;">
                    <h4 style="margin: 0 0 12px 0;">⚡ Quick Status</h4>
                    <form method="post" style="display: flex; flex-direction: column; gap: 8px;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="task_id" value="<?php echo $taskDetail['id']; ?>">
                        
                        <button type="submit" name="status" value="pending" class="btn <?php echo $taskDetail['status'] === 'pending' ? '' : 'secondary'; ?>" style="text-align: center;">
                            ⏳ Pending
                        </button>
                        <button type="submit" name="status" value="in_progress" class="btn <?php echo $taskDetail['status'] === 'in_progress' ? '' : 'secondary'; ?>" style="text-align: center; <?php echo $taskDetail['status'] === 'in_progress' ? 'background: #3b82f6;' : ''; ?>">
                            🚀 In Progress
                        </button>
                        <button type="submit" name="status" value="completed" class="btn" style="background: #059669; text-align: center;">
                            ✅ Complete
                        </button>
                        <button type="submit" name="status" value="cancelled" class="btn secondary" style="text-align: center;">
                            ❌ Cancel
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="card">
                    <h4 style="margin: 0 0 12px 0;">🚀 Quick Actions</h4>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <?php if ($taskDetail['lead_name'] && $taskDetail['lead_id']): ?>
                            <a href="leads.php?id=<?php echo $taskDetail['lead_id']; ?>" class="btn" style="background: #059669; text-align: center;">👁️ View Lead</a>
                            <a href="calls.php?lead_id=<?php echo $taskDetail['lead_id']; ?>&action=add" class="btn" style="background: #dc2626; text-align: center;">📞 Log Call</a>
                        <?php endif; ?>
                        <a href="tasks.php?action=add" class="btn" style="background: #7c3aed; text-align: center;">➕ New Task</a>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Tasks List View -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1 style="margin: 0; color: #1f2937;">✓ My Tasks</h1>
                <p style="margin: 4px 0 0 0; color: #6b7280;"><?php echo count($tasks); ?> tasks</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="index.php" class="btn secondary">← Back to CRM</a>
                <button onclick="showAddModal()" class="btn" style="background: #7c3aed;">+ Create Task</button>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div style="display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 2px solid #e5e7eb;">
            <a href="tasks.php?filter=active" class="filter-tab <?php echo $filter === 'active' ? 'active' : ''; ?>">
                🚀 Active (<?php 
                try {
                    $query = 'SELECT COUNT(*) FROM crm_tasks WHERE status IN ("pending", "in_progress")';
                    if (!$isAdminSimulation) {
                        $query .= ' AND agent_id = ?';
                        $stmt = $pdo->prepare($query);
                        $stmt->execute([$uid]);
                    } else {
                        $stmt = $pdo->prepare($query);
                        $stmt->execute();
                    }
                    echo $stmt->fetchColumn();
                } catch (Exception $e) { echo '0'; }
                ?>)
            </a>
            <a href="tasks.php?filter=overdue" class="filter-tab <?php echo $filter === 'overdue' ? 'active' : ''; ?>">
                ⚠️ Overdue (<?php 
                try {
                    $query = 'SELECT COUNT(*) FROM crm_tasks WHERE status IN ("pending", "in_progress") AND due_date < NOW()';
                    if (!$isAdminSimulation) {
                        $query .= ' AND agent_id = ?';
                        $stmt = $pdo->prepare($query);
                        $stmt->execute([$uid]);
                    } else {
                        $stmt = $pdo->prepare($query);
                        $stmt->execute();
                    }
                    echo $stmt->fetchColumn();
                } catch (Exception $e) { echo '0'; }
                ?>)
            </a>
            <a href="tasks.php?filter=completed" class="filter-tab <?php echo $filter === 'completed' ? 'active' : ''; ?>">
                ✅ Completed (<?php 
                try {
                    $query = 'SELECT COUNT(*) FROM crm_tasks WHERE status = "completed"';
                    if (!$isAdminSimulation) {
                        $query .= ' AND agent_id = ?';
                        $stmt = $pdo->prepare($query);
                        $stmt->execute([$uid]);
                    } else {
                        $stmt = $pdo->prepare($query);
                        $stmt->execute();
                    }
                    echo $stmt->fetchColumn();
                } catch (Exception $e) { echo '0'; }
                ?>)
            </a>
            <a href="tasks.php?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                📋 All Tasks
            </a>
        </div>

        <?php if (empty($tasks)): ?>
            <div class="card" style="text-align: center; padding: 48px;">
                <div style="font-size: 64px; margin-bottom: 16px;">✓</div>
                <h2 style="color: #6b7280;">
                    <?php 
                    echo match($filter) {
                        'active' => 'No Active Tasks',
                        'overdue' => 'No Overdue Tasks!', 
                        'completed' => 'No Completed Tasks',
                        default => 'No Tasks Yet'
                    };
                    ?>
                </h2>
                <p style="color: #9ca3af; margin-bottom: 24px;">
                    <?php 
                    echo match($filter) {
                        'active' => 'All caught up! Create a new task to stay productive.',
                        'overdue' => 'Great job staying on top of your tasks!',
                        'completed' => 'Complete some tasks to see them here.',
                        default => 'Create your first task to get started.'
                    };
                    ?>
                </p>
                <button onclick="showAddModal()" class="btn" style="background: #7c3aed;">Create Your First Task</button>
            </div>
        <?php else: ?>
            <div class="card">
                <div style="display: grid; gap: 16px;">
                    <?php foreach ($tasks as $task): ?>
                    <div style="
                        padding: 16px; 
                        border-radius: 8px; 
                        border-left: 4px solid <?php 
                        if ($task['status'] === 'completed') {
                            echo '#16a34a';
                        } elseif ($task['days_until_due'] < 0 && $task['status'] !== 'completed') {
                            echo '#dc2626';
                        } else {
                            echo match($task['priority']) {
                                'urgent' => '#dc2626',
                                'high' => '#f59e0b',
                                'medium' => '#3b82f6',
                                'low' => '#6b7280',
                                default => '#6b7280'
                            };
                        }
                        ?>;
                        background: <?php 
                        if ($task['days_until_due'] < 0 && $task['status'] !== 'completed') {
                            echo '#fef2f2';
                        } elseif ($task['status'] === 'completed') {
                            echo '#f0fdf4';
                        } else {
                            echo '#f9fafb';
                        }
                        ?>;
                    ">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                            <div style="flex: 1;">
                                <h4 style="margin: 0; color: #1f2937;">
                                    <a href="tasks.php?id=<?php echo $task['id']; ?>" style="text-decoration: none; color: inherit;">
                                        <?php echo htmlspecialchars($task['title']); ?>
                                    </a>
                                </h4>
                                <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">
                                    <?php echo ucwords(str_replace('_', ' ', $task['task_type'])); ?>
                                    <?php if ($task['lead_name'] || $task['account_name']): ?>
                                        • <?php echo htmlspecialchars($task['lead_name'] ?: $task['account_name']); ?>
                                        <?php if ($task['lead_company']): ?>
                                            (<?php echo htmlspecialchars($task['lead_company']); ?>)
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div style="display: flex; align-items: center; gap: 8px; margin-left: 16px;">
                                <span style="
                                    background: <?php 
                                    echo match($task['status']) {
                                        'pending' => '#fef3c7',
                                        'in_progress' => '#dbeafe',
                                        'completed' => '#dcfce7',
                                        'cancelled' => '#fee2e2',
                                        default => '#f3f4f6'
                                    }; 
                                    ?>;
                                    color: <?php 
                                    echo match($task['status']) {
                                        'pending' => '#d97706',
                                        'in_progress' => '#1e40af',
                                        'completed' => '#166534',
                                        'cancelled' => '#dc2626',
                                        default => '#6b7280'
                                    }; 
                                    ?>;
                                    padding: 2px 6px; border-radius: 8px; font-size: 11px; font-weight: 600;">
                                    <?php echo ucwords(str_replace('_', ' ', $task['status'])); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px;">
                            <div style="font-size: 12px; color: #6b7280;">
                                <span style="
                                    color: <?php 
                                    if ($task['status'] === 'completed') {
                                        echo '#166534';
                                    } elseif ($task['days_until_due'] < 0) {
                                        echo '#dc2626'; 
                                    } elseif ($task['days_until_due'] <= 1) {
                                        echo '#d97706'; 
                                    } else {
                                        echo '#6b7280'; 
                                    }
                                    ?>;
                                    font-weight: 600;">
                                    <?php 
                                    if ($task['status'] === 'completed') {
                                        echo '✅ Completed ' . date('M j', strtotime($task['completed_at']));
                                    } elseif ($task['days_until_due'] < 0) {
                                        echo '⚠️ ' . abs($task['days_until_due']) . ' days overdue';
                                    } elseif ($task['days_until_due'] == 0) {
                                        echo '🔥 Due today';
                                    } elseif ($task['days_until_due'] == 1) {
                                        echo '⏰ Due tomorrow';
                                    } else {
                                        echo '📅 Due in ' . $task['days_until_due'] . ' days';
                                    }
                                    ?>
                                </span>
                                • Est. <?php echo $task['estimated_duration_minutes']; ?>min
                            </div>
                            
                            <div style="display: flex; gap: 4px;">
                                <a href="tasks.php?id=<?php echo $task['id']; ?>" class="btn small">View</a>
                                <?php if ($task['status'] !== 'completed'): ?>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="action" value="complete">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <button type="submit" class="btn small" style="background: #059669;">Complete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($task['description']): ?>
                        <div style="margin-top: 8px; font-size: 13px; color: #4b5563; background: white; padding: 8px; border-radius: 4px;">
                            <?php echo htmlspecialchars(substr($task['description'], 0, 150) . (strlen($task['description']) > 150 ? '...' : '')); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Add Task Modal -->
<div id="addTaskModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 500px; max-width: 90vw; max-height: 80vh; overflow-y: auto;">
        <h3 style="margin: 0 0 16px 0;">Create New Task</h3>
        <form method="post" style="display: grid; gap: 16px;">
            <input type="hidden" name="action" value="add_task">
            
            <div>
                <label>Task Title *</label>
                <input type="text" name="title" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" placeholder="Follow up with prospect about...">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Task Type</label>
                    <select name="task_type" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="call">Call</option>
                        <option value="email">Email</option>
                        <option value="meeting">Meeting</option>
                        <option value="follow_up" selected>Follow-up</option>
                        <option value="proposal">Proposal</option>
                        <option value="demo">Demo</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label>Priority</label>
                    <select name="priority" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Related Lead</label>
                    <select name="lead_id" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Lead (Optional)</option>
                        <?php foreach ($leads as $lead): ?>
                            <option value="<?php echo $lead['id']; ?>" <?php echo $preselectedLeadId == $lead['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lead['name']); ?>
                                <?php if ($lead['company_name']): ?>
                                    - <?php echo htmlspecialchars($lead['company_name']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Related Account</label>
                    <select name="account_id" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Account (Optional)</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>">
                                <?php echo htmlspecialchars($account['business_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div>
                <label>Description</label>
                <textarea name="description" rows="3" placeholder="Detailed description of what needs to be done..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr auto; gap: 12px;">
                <div>
                    <label>Due Date & Time *</label>
                    <input type="datetime-local" name="due_date" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" value="<?php echo date('Y-m-d\TH:i', strtotime('+1 day')); ?>">
                </div>
                <div>
                    <label>Est. Duration (min)</label>
                    <input type="number" name="estimated_duration_minutes" value="30" min="1" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: end; margin-top: 16px;">
                <button type="button" onclick="hideAddModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #7c3aed;">Create Task</button>
            </div>
        </form>
    </div>
</div>

<?php if ($taskDetail && $taskDetail['status'] !== 'completed'): ?>
<!-- Complete Task Modal -->
<div id="completeTaskModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 400px; max-width: 90vw;">
        <h3 style="margin: 0 0 16px 0;">✅ Mark Task Complete</h3>
        <form method="post" style="display: grid; gap: 16px;">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="task_id" value="<?php echo $taskDetail['id']; ?>">
            
            <div>
                <label>How long did this actually take? (minutes)</label>
                <input type="number" name="actual_duration_minutes" min="0" value="<?php echo $taskDetail['estimated_duration_minutes']; ?>" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: end; margin-top: 16px;">
                <button type="button" onclick="hideCompleteModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #059669;">✅ Mark Complete</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Task Modal -->
<div id="editTaskModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; width: 500px; max-width: 90vw; max-height: 80vh; overflow-y: auto;">
        <h3 style="margin: 0 0 16px 0;">Edit Task</h3>
        <form method="post" style="display: grid; gap: 16px;">
            <input type="hidden" name="action" value="update_task">
            <input type="hidden" name="task_id" value="<?php echo $taskDetail['id']; ?>">
            
            <div>
                <label>Task Title *</label>
                <input type="text" name="title" value="<?php echo htmlspecialchars($taskDetail['title']); ?>" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Task Type</label>
                    <select name="task_type" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <?php foreach (['call', 'email', 'meeting', 'follow_up', 'proposal', 'demo', 'other'] as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $taskDetail['task_type'] === $type ? 'selected' : ''; ?>>
                                <?php echo ucwords(str_replace('_', ' ', $type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Priority</label>
                    <select name="priority" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <?php foreach (['low', 'medium', 'high', 'urgent'] as $priority): ?>
                            <option value="<?php echo $priority; ?>" <?php echo $taskDetail['priority'] === $priority ? 'selected' : ''; ?>>
                                <?php echo ucfirst($priority); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div>
                <label>Description</label>
                <textarea name="description" rows="3" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"><?php echo htmlspecialchars($taskDetail['description'] ?: ''); ?></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr auto; gap: 12px;">
                <div>
                    <label>Due Date & Time *</label>
                    <input type="datetime-local" name="due_date" value="<?php echo date('Y-m-d\TH:i', strtotime($taskDetail['due_date'])); ?>" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                <div>
                    <label>Est. Duration (min)</label>
                    <input type="number" name="estimated_duration_minutes" value="<?php echo $taskDetail['estimated_duration_minutes']; ?>" min="1" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: end; margin-top: 16px;">
                <button type="button" onclick="hideEditModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #7c3aed;">Update Task</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.btn.small {
    padding: 4px 8px;
    font-size: 12px;
}

label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
    color: #374151;
}

.filter-tab {
    padding: 8px 16px;
    text-decoration: none;
    color: #6b7280;
    border-bottom: 2px solid transparent;
    transition: all 0.2s;
}

.filter-tab:hover {
    color: #374151;
}

.filter-tab.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
}
</style>

<script>
function showAddModal() {
    document.getElementById('addTaskModal').style.display = 'block';
}

function hideAddModal() {
    document.getElementById('addTaskModal').style.display = 'none';
}

<?php if ($taskDetail && $taskDetail['status'] !== 'completed'): ?>
function showCompleteModal() {
    document.getElementById('completeTaskModal').style.display = 'block';
}

function hideCompleteModal() {
    document.getElementById('completeTaskModal').style.display = 'none';
}

function showEditModal() {
    document.getElementById('editTaskModal').style.display = 'block';
}

function hideEditModal() {
    document.getElementById('editTaskModal').style.display = 'none';
}
<?php endif; ?>

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.style.position === 'fixed' && e.target.style.background.includes('rgba')) {
        e.target.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
