<?php
// Handle POST requests BEFORE any output
require_once __DIR__ . '/../required/config.php';
start_session();
require_admin_or_redirect();

$pdo = db();
$currentAdminId = current_admin_id();

// Handle AJAX request for task data
if (isset($_GET['action']) && $_GET['action'] === 'get_task') {
    header('Content-Type: application/json');
    $taskId = intval($_GET['task_id'] ?? 0);
    if ($taskId) {
        $stmt = $pdo->prepare('SELECT * FROM admin_dev_tasks WHERE id = ? LIMIT 1');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($task ?: ['error' => 'Task not found']);
    } else {
        echo json_encode(['error' => 'Invalid task ID']);
    }
    exit;
}

// Handle POST requests before any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $taskId = intval($_POST['task_id'] ?? 0);
    
    // Get log data
    $logData = [
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ];
    
    try {
        switch ($action) {
            case 'create_task':
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $priority = $_POST['priority'] ?? 'medium';
                $assignedTo = intval($_POST['assigned_to'] ?? 0);
                $category = trim($_POST['category'] ?? 'General');
                $dueDate = $_POST['due_date'] ?: null;
                $estimatedHours = floatval($_POST['estimated_hours'] ?? 0) ?: null;
                
                if ($title && $description && $assignedTo) {
                    $stmt = $pdo->prepare('INSERT INTO admin_dev_tasks 
                        (title, description, priority, category, created_by_admin_id, assigned_to_admin_id, due_date, estimated_hours, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$title, $description, $priority, $category, $currentAdminId, $assignedTo, $dueDate, $estimatedHours, 'pending']);
                    $newTaskId = $pdo->lastInsertId();
                    
                    // Log task creation
                    $stmt = $pdo->prepare('INSERT INTO admin_dev_task_history 
                        (task_id, admin_id, action_type, action_description, new_values, ip_address, user_agent) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([
                        $newTaskId, $currentAdminId, 'task_created', 'New task created',
                        json_encode(['title' => $title, 'priority' => $priority, 'assigned_to' => $assignedTo]),
                        $logData['ip_address'], $logData['user_agent']
                    ]);
                    
                    flash_set('success', 'Task created successfully!');
                }
                break;
                
            case 'update_status':
                $newStatus = $_POST['status'] ?? '';
                $notes = trim($_POST['notes'] ?? '');
                $actualHours = floatval($_POST['actual_hours'] ?? 0) ?: null;
                
                if ($taskId && $newStatus) {
                    // Get current task for history
                    $stmt = $pdo->prepare('SELECT * FROM admin_dev_tasks WHERE id = ?');
                    $stmt->execute([$taskId]);
                    $currentTask = $stmt->fetch();
                    
                    if ($currentTask) {
                        $updateFields = ['status = ?', 'updated_at = NOW()'];
                        $updateValues = [$newStatus];
                        
                        if ($newStatus === 'in_progress' && !$currentTask['started_at']) {
                            $updateFields[] = 'started_at = NOW()';
                        } elseif ($newStatus === 'completed' && !$currentTask['completed_at']) {
                            $updateFields[] = 'completed_at = NOW()';
                        }
                        
                        if ($notes) {
                            $updateFields[] = 'completion_notes = ?';
                            $updateValues[] = $notes;
                        }
                        
                        if ($actualHours) {
                            $updateFields[] = 'actual_hours = ?';
                            $updateValues[] = $actualHours;
                        }
                        
                        $updateValues[] = $taskId;
                        $stmt = $pdo->prepare('UPDATE admin_dev_tasks SET ' . implode(', ', $updateFields) . ' WHERE id = ?');
                        $stmt->execute($updateValues);
                        
                        // Log status change
                        $stmt = $pdo->prepare('INSERT INTO admin_dev_task_history 
                            (task_id, admin_id, action_type, action_description, old_values, new_values, notes, ip_address, user_agent) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([
                            $taskId, $currentAdminId, 'status_change', "Status updated from '{$currentTask['status']}' to '{$newStatus}'",
                            json_encode(['status' => $currentTask['status']]),
                            json_encode(['status' => $newStatus, 'actual_hours' => $actualHours]),
                            $notes, $logData['ip_address'], $logData['user_agent']
                        ]);
                        
                        flash_set('success', 'Task status updated successfully!');
                    }
                }
                break;
                
            case 'update_task':
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $priority = $_POST['priority'] ?? 'medium';
                $assignedTo = intval($_POST['assigned_to'] ?? 0);
                $category = trim($_POST['category'] ?? '');
                $dueDate = $_POST['due_date'] ?: null;
                $estimatedHours = floatval($_POST['estimated_hours'] ?? 0) ?: null;
                
                if ($taskId && $title && $description) {
                    // Get current task for history
                    $stmt = $pdo->prepare('SELECT * FROM admin_dev_tasks WHERE id = ?');
                    $stmt->execute([$taskId]);
                    $currentTask = $stmt->fetch();
                    
                    if ($currentTask) {
                        $stmt = $pdo->prepare('UPDATE admin_dev_tasks SET 
                            title = ?, description = ?, priority = ?, category = ?, 
                            assigned_to_admin_id = ?, due_date = ?, estimated_hours = ?, updated_at = NOW() 
                            WHERE id = ?');
                        $stmt->execute([$title, $description, $priority, $category, $assignedTo, $dueDate, $estimatedHours, $taskId]);
                        
                        // Log task update
                        $oldValues = [
                            'title' => $currentTask['title'],
                            'priority' => $currentTask['priority'],
                            'assigned_to' => $currentTask['assigned_to_admin_id']
                        ];
                        $newValues = [
                            'title' => $title,
                            'priority' => $priority,
                            'assigned_to' => $assignedTo
                        ];
                        
                        $stmt = $pdo->prepare('INSERT INTO admin_dev_task_history 
                            (task_id, admin_id, action_type, action_description, old_values, new_values, ip_address, user_agent) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([
                            $taskId, $currentAdminId, 'task_updated', 'Task details updated',
                            json_encode($oldValues), json_encode($newValues),
                            $logData['ip_address'], $logData['user_agent']
                        ]);
                        
                        flash_set('success', 'Task updated successfully!');
                    }
                }
                break;
        }
    } catch (Exception $e) {
        flash_set('error', 'Action failed: ' . $e->getMessage());
        error_log("Dev Tasks error: " . $e->getMessage());
    }
    
    // Redirect to prevent form resubmission (PRG pattern)
    redirect($_SERVER['REQUEST_URI']);
}

// Now load the header for HTML output
$SEO = [
    'title' => 'Dev Tasks — Product Admin',
    'description' => 'Development task management and assignment system for administrators.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

// Get current admin user info
try {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$currentAdminId]);
    $currentUser = $stmt->fetch();
} catch (Exception $e) {
    $currentUser = null;
}


// Get filter parameters
$statusFilter = $_GET['status'] ?? '';
$assigneeFilter = $_GET['assignee'] ?? '';
$priorityFilter = $_GET['priority'] ?? '';
$viewMode = $_GET['view'] ?? 'dashboard';

// Initialize variables
$tasks = [];
$adminUsers = [];
$summary = ['total_tasks' => 0, 'pending_tasks' => 0, 'in_progress_tasks' => 0, 'completed_tasks' => 0, 'urgent_tasks' => 0, 'overdue_tasks' => 0];
$error = null;

try {
    // First, test if tables exist with a simple query
    $stmt = $pdo->query('SELECT COUNT(*) FROM admin_dev_tasks');
    $taskCount = $stmt->fetchColumn();
    
    // Get task summary using direct table query (more reliable than view)
    $stmt = $pdo->query('
        SELECT 
            COUNT(*) as total_tasks,
            COUNT(CASE WHEN status = "pending" THEN 1 END) as pending_tasks,
            COUNT(CASE WHEN status = "in_progress" THEN 1 END) as in_progress_tasks,
            COUNT(CASE WHEN status = "completed" THEN 1 END) as completed_tasks,
            COUNT(CASE WHEN status = "cancelled" THEN 1 END) as cancelled_tasks,
            COUNT(CASE WHEN priority = "urgent" THEN 1 END) as urgent_tasks,
            COUNT(CASE WHEN priority = "high" THEN 1 END) as high_priority_tasks,
            COUNT(CASE WHEN due_date < NOW() AND status NOT IN ("completed", "cancelled") THEN 1 END) as overdue_tasks
        FROM admin_dev_tasks
    ');
    $summary = $stmt->fetch() ?: $summary;
    
    // Build task query with filters - use direct table join instead of view
    $whereConditions = [];
    $params = [];
    
    if ($statusFilter) {
        $whereConditions[] = 't.status = ?';
        $params[] = $statusFilter;
    }
    
    if ($assigneeFilter) {
        $whereConditions[] = 't.assigned_to_admin_id = ?';
        $params[] = intval($assigneeFilter);
    }
    
    if ($priorityFilter) {
        $whereConditions[] = 't.priority = ?';
        $params[] = $priorityFilter;
    }
    
    $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get tasks using direct table query
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            CONCAT(creator.first_name, ' ', creator.last_name) as created_by_name,
            creator.email as created_by_email,
            CONCAT(assignee.first_name, ' ', assignee.last_name) as assigned_to_name,
            assignee.email as assigned_to_email,
            CASE 
                WHEN t.due_date IS NOT NULL AND t.due_date < NOW() AND t.status NOT IN ('completed', 'cancelled') 
                THEN 'overdue'
                WHEN t.due_date IS NOT NULL AND t.due_date <= DATE_ADD(NOW(), INTERVAL 2 DAY) AND t.status NOT IN ('completed', 'cancelled') 
                THEN 'due_soon'
                ELSE 'on_track'
            END as urgency_status,
            DATEDIFF(t.due_date, NOW()) as days_until_due,
            (SELECT COUNT(*) FROM admin_dev_task_history h WHERE h.task_id = t.id) as history_count
        FROM admin_dev_tasks t
        JOIN users creator ON t.created_by_admin_id = creator.id
        JOIN users assignee ON t.assigned_to_admin_id = assignee.id
        {$whereClause}
        ORDER BY 
            CASE t.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            t.due_date ASC,
            t.created_at DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
    
    // Get all admin users for assignment dropdown
    $stmt = $pdo->query('
        SELECT u.id, u.first_name, u.last_name, u.email 
        FROM users u 
        JOIN user_roles ur ON ur.user_id = u.id 
        JOIN roles r ON r.id = ur.role_id 
        WHERE r.name = "admin" 
        ORDER BY u.first_name, u.last_name
    ');
    $adminUsers = $stmt->fetchAll();
    
    // Debug information
    $debugInfo = [
        'task_count' => $taskCount,
        'tasks_found' => count($tasks),
        'admin_users_found' => count($adminUsers),
        'summary_total' => $summary['total_tasks'] ?? 0
    ];
    
} catch (Exception $e) {
    $error = "Task data not available: " . $e->getMessage();
    error_log("Dev Tasks error: " . $e->getMessage());
    
    // Additional debug info for troubleshooting
    $debugInfo = [
        'error_message' => $e->getMessage(),
        'error_file' => $e->getFile(),
        'error_line' => $e->getLine()
    ];
}
?>

<section class="section">
    <div class="container">
        <div class="admin-header">
            <h1>🛠️ Development Tasks</h1>
            <div class="admin-nav">
                <a href="<?php echo path_with_base('/admin/'); ?>" class="btn secondary">← Admin Dashboard</a>
                <button onclick="showCreateTaskModal()" class="btn">+ Create New Task</button>
            </div>
        </div>

        <?php
        // Display flash messages
        $successMsg = flash_get('success');
        $errorMsg = flash_get('error');
        $warningMsg = flash_get('warning');
        ?>
        
        <?php if ($successMsg): ?>
        <div class="card" style="border-color: #86efac; background: #f0fdf4; color: #166534; margin-bottom: 16px;">
            ✅ <?php echo sanitize($successMsg); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($errorMsg): ?>
        <div class="card" style="border-color: #fca5a5; background: #fef2f2; color: #991b1b; margin-bottom: 16px;">
            ❌ <?php echo sanitize($errorMsg); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($warningMsg): ?>
        <div class="card" style="border-color: #fbbf24; background: #fffbeb; color: #92400e; margin-bottom: 16px;">
            ⚠️ <?php echo sanitize($warningMsg); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
        <div class="card" style="border-color: #fca5a5; background: #fef2f2; color: #991b1b;">
            <p>⚠️ <?php echo sanitize($error); ?></p>
            <p style="font-size: 14px; margin-top: 8px;">
                The dev tasks system may not be installed yet. Install with:
            </p>
            <ol style="font-size: 14px; margin: 8px 0;">
                <li><strong>Go to phpMyAdmin</strong> → Select foggksfx_product database</li>
                <li><strong>Click "SQL" tab</strong></li>
                <li><strong>Copy/paste contents of:</strong> <code>admin/install-dev-tasks-direct.sql</code></li>
                <li><strong>Click "Go"</strong> to execute</li>
                <li><strong>Refresh this page</strong></li>
            </ol>
        </div>
        <?php else: ?>
        <!-- Debug Info (remove after testing) -->
        <div class="card" style="background: #f0fdf4; border-color: #16a34a; margin-bottom: 16px;">
            <p style="color: #166534; font-size: 14px; margin: 0;">
                ✅ Dev Tasks System Active | 
                Found <?php echo count($tasks); ?> tasks | 
                <?php echo count($adminUsers); ?> admin users available |
                Database connection: OK
                <?php if (isset($debugInfo)): ?>
                | Raw count: <?php echo $debugInfo['task_count'] ?? 'unknown'; ?>
                | Summary total: <?php echo $debugInfo['summary_total'] ?? 'unknown'; ?>
                <?php endif; ?>
            </p>
            <?php if (isset($debugInfo) && !empty($debugInfo)): ?>
            <details style="margin-top: 8px;">
                <summary style="color: #166534; cursor: pointer; font-size: 12px;">Show Debug Details</summary>
                <pre style="background: #f9fafb; padding: 8px; border-radius: 4px; font-size: 11px; margin: 8px 0 0; color: #374151;"><?php echo print_r($debugInfo, true); ?></pre>
            </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Task Summary Dashboard -->
        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; margin-bottom: 32px;">
            <div class="card" style="text-align: center; padding: 20px;">
                <div style="font-size: 28px; font-weight: 800; color: var(--blue);"><?php echo intval($summary['total_tasks']); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Total Tasks</div>
            </div>
            <div class="card" style="text-align: center; padding: 20px;">
                <div style="font-size: 28px; font-weight: 800; color: var(--gold);"><?php echo intval($summary['pending_tasks']); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Pending</div>
            </div>
            <div class="card" style="text-align: center; padding: 20px;">
                <div style="font-size: 28px; font-weight: 800; color: var(--blue);"><?php echo intval($summary['in_progress_tasks']); ?></div>
                <div style="color: #6b7280; font-size: 14px;">In Progress</div>
            </div>
            <div class="card" style="text-align: center; padding: 20px;">
                <div style="font-size: 28px; font-weight: 800; color: var(--green);"><?php echo intval($summary['completed_tasks']); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Completed</div>
            </div>
            <div class="card" style="text-align: center; padding: 20px;">
                <div style="font-size: 28px; font-weight: 800; color: var(--red);"><?php echo intval($summary['urgent_tasks']); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Urgent</div>
            </div>
            <div class="card" style="text-align: center; padding: 20px;">
                <div style="font-size: 28px; font-weight: 800; color: var(--red);"><?php echo intval($summary['overdue_tasks']); ?></div>
                <div style="color: #6b7280; font-size: 14px;">Overdue</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card" style="margin-bottom: 24px;">
            <h3>Filter Tasks</h3>
            <form method="get" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; align-items: end;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Status</label>
                    <select name="status" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="on_hold" <?php echo $statusFilter === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                    </select>
                </div>
                
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Priority</label>
                    <select name="priority" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">All Priorities</option>
                        <option value="urgent" <?php echo $priorityFilter === 'urgent' ? 'selected' : ''; ?>>🔴 Urgent</option>
                        <option value="high" <?php echo $priorityFilter === 'high' ? 'selected' : ''; ?>>🟡 High</option>
                        <option value="medium" <?php echo $priorityFilter === 'medium' ? 'selected' : ''; ?>>🟢 Medium</option>
                        <option value="low" <?php echo $priorityFilter === 'low' ? 'selected' : ''; ?>>⚪ Low</option>
                    </select>
                </div>
                
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Assignee</label>
                    <select name="assignee" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">All Assignees</option>
                        <?php foreach ($adminUsers as $admin): ?>
                        <option value="<?php echo $admin['id']; ?>" <?php echo $assigneeFilter == $admin['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($admin['first_name'] . ' ' . $admin['last_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <button type="submit" class="btn" style="width: 100%; padding: 8px;">Apply Filters</button>
                </div>
            </form>
        </div>

        <!-- Tasks List -->
        <div class="tasks-container">
            <?php if (empty($tasks)): ?>
            <div class="card" style="text-align: center; padding: 48px; color: #6b7280;">
                <div style="font-size: 48px; margin-bottom: 16px;">📋</div>
                <h3>No Tasks Found</h3>
                <p>No tasks match your current filters, or no tasks have been created yet.</p>
                <button onclick="showCreateTaskModal()" class="btn" style="margin-top: 16px;">Create First Task</button>
            </div>
            <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                <?php
                $priorityColors = [
                    'urgent' => 'background: #fee2e2; border-color: #dc2626; color: #7f1d1d;',
                    'high' => 'background: #fef3c7; border-color: #f59e0b; color: #92400e;',
                    'medium' => 'background: #ecfdf5; border-color: #10b981; color: #047857;',
                    'low' => 'background: #f8fafc; border-color: #64748b; color: #475569;'
                ];
                $statusColors = [
                    'pending' => '#6b7280',
                    'in_progress' => '#3b82f6',
                    'completed' => '#10b981',
                    'cancelled' => '#ef4444',
                    'on_hold' => '#f59e0b'
                ];
                $urgencyStyle = $task['urgency_status'] === 'overdue' ? 'border-left: 4px solid #dc2626;' : 
                               ($task['urgency_status'] === 'due_soon' ? 'border-left: 4px solid #f59e0b;' : '');
                ?>
                <div class="card task-card" style="margin-bottom: 24px; <?php echo $urgencyStyle; ?>" data-task-id="<?php echo $task['id']; ?>">
                    <!-- Task Header -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
                        <div style="flex: 1;">
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                                <h4 style="margin: 0; color: var(--blue);"><?php echo sanitize($task['title']); ?></h4>
                                <span style="<?php echo $priorityColors[$task['priority']]; ?> padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                    <?php echo strtoupper($task['priority']); ?>
                                </span>
                                <span style="background: <?php echo $statusColors[$task['status']]; ?>; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px;">
                                    <?php echo strtoupper(str_replace('_', ' ', $task['status'])); ?>
                                </span>
                            </div>
                            <div style="color: #6b7280; font-size: 14px; margin-bottom: 8px;">
                                <strong>Assigned to:</strong> <?php echo sanitize($task['assigned_to_name']); ?> • 
                                <strong>Created by:</strong> <?php echo sanitize($task['created_by_name']); ?> • 
                                <?php echo date('M j, Y g:i A', strtotime($task['created_at'])); ?>
                            </div>
                            <?php if ($task['due_date']): ?>
                            <div style="color: #6b7280; font-size: 14px;">
                                <strong>Due:</strong> 
                                <span style="color: <?php echo $task['urgency_status'] === 'overdue' ? '#dc2626' : ($task['urgency_status'] === 'due_soon' ? '#f59e0b' : '#6b7280'); ?>;">
                                    <?php echo date('M j, Y g:i A', strtotime($task['due_date'])); ?>
                                    <?php if ($task['days_until_due'] !== null): ?>
                                        (<?php echo $task['days_until_due'] > 0 ? $task['days_until_due'] . ' days' : 
                                                   ($task['days_until_due'] == 0 ? 'Due today' : abs($task['days_until_due']) . ' days overdue'); ?>)
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div style="display: flex; gap: 8px; flex-shrink: 0;">
                            <button onclick="showUpdateStatusModal(<?php echo $task['id']; ?>)" class="btn secondary" style="font-size: 12px; padding: 4px 8px;">
                                Update Status
                            </button>
                            <button onclick="showTaskHistory(<?php echo $task['id']; ?>)" class="btn secondary" style="font-size: 12px; padding: 4px 8px;">
                                History (<?php echo $task['history_count']; ?>)
                            </button>
                            <button onclick="showEditTaskModal(<?php echo $task['id']; ?>)" class="btn secondary" style="font-size: 12px; padding: 4px 8px;">
                                Edit
                            </button>
                        </div>
                    </div>
                    
                    <!-- Task Description -->
                    <div style="margin-bottom: 16px;">
                        <?php
                        $description = $task['description'];
                        $screenshots = [];
                        
                        // Extract screenshot URLs from description
                        if (preg_match_all('/\*\*Screenshot:\*\* \[(https?:\/\/[^\]]+)\]\([^\)]+\)/', $description, $matches)) {
                            $screenshots = $matches[1];
                            // Remove screenshot lines from description
                            $description = preg_replace('/\*\*Screenshot:\*\* \[https?:\/\/[^\]]+\]\([^\)]+\)\n?/', '', $description);
                        }
                        ?>
                        <p style="margin: 0; line-height: 1.6; color: #374151;"><?php echo nl2br(sanitize($description)); ?></p>
                        
                        <?php if (!empty($screenshots)): ?>
                        <div style="margin-top: 16px;">
                            <strong style="color: #374151;">📸 Screenshots:</strong>
                            <div style="display: flex; gap: 12px; margin-top: 8px; flex-wrap: wrap;">
                                <?php foreach ($screenshots as $screenshot): ?>
                                <a href="<?php echo sanitize($screenshot); ?>" target="_blank" style="display: block;">
                                    <img src="<?php echo sanitize($screenshot); ?>" 
                                         alt="Screenshot" 
                                         style="max-width: 200px; max-height: 200px; border-radius: 8px; border: 2px solid #e5e7eb; cursor: pointer; transition: all 0.2s;"
                                         onmouseover="this.style.borderColor='#3b82f6'; this.style.transform='scale(1.05)';"
                                         onmouseout="this.style.borderColor='#e5e7eb'; this.style.transform='scale(1)';">
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Task Metadata -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; font-size: 14px; color: #6b7280;">
                        <?php if ($task['category']): ?>
                        <div><strong>Category:</strong> <?php echo sanitize($task['category']); ?></div>
                        <?php endif; ?>
                        <?php if ($task['estimated_hours']): ?>
                        <div><strong>Est. Hours:</strong> <?php echo $task['estimated_hours']; ?></div>
                        <?php endif; ?>
                        <?php if ($task['actual_hours']): ?>
                        <div><strong>Actual Hours:</strong> <?php echo $task['actual_hours']; ?></div>
                        <?php endif; ?>
                        <?php if ($task['started_at']): ?>
                        <div><strong>Started:</strong> <?php echo date('M j', strtotime($task['started_at'])); ?></div>
                        <?php endif; ?>
                        <?php if ($task['completed_at']): ?>
                        <div><strong>Completed:</strong> <?php echo date('M j', strtotime($task['completed_at'])); ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($task['completion_notes']): ?>
                    <div style="background: #f0fdf4; border: 1px solid #16a34a; border-radius: 8px; padding: 12px; margin-top: 12px;">
                        <strong style="color: #166534;">Completion Notes:</strong>
                        <p style="margin: 4px 0 0; color: #166534; line-height: 1.5;"><?php echo nl2br(sanitize($task['completion_notes'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Create Task Modal -->
<div id="createTaskModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Create New Development Task</h3>
            <button onclick="hideCreateTaskModal()" class="btn secondary" style="padding: 4px 8px;">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="create_task">
            <div style="display: grid; gap: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Task Title *</label>
                    <input type="text" name="title" required maxlength="255" placeholder="Brief, descriptive task title..."
                           style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Description *</label>
                    <textarea name="description" required rows="4" placeholder="Detailed task description, requirements, and acceptance criteria..."
                              style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Priority</label>
                        <select name="priority" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                            <option value="low">⚪ Low</option>
                            <option value="medium" selected>🟢 Medium</option>
                            <option value="high">🟡 High</option>
                            <option value="urgent">🔴 Urgent</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Assign To *</label>
                        <select name="assigned_to" required style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                            <option value="">Select Admin...</option>
                            <?php foreach ($adminUsers as $admin): ?>
                            <option value="<?php echo $admin['id']; ?>">
                                <?php echo sanitize($admin['first_name'] . ' ' . $admin['last_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Category</label>
                        <input type="text" name="category" maxlength="100" placeholder="Bug Fix, Feature, etc."
                               style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Due Date</label>
                        <input type="datetime-local" name="due_date"
                               style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Est. Hours</label>
                        <input type="number" name="estimated_hours" step="0.5" min="0" max="999" placeholder="8.5"
                               style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                </div>
            </div>
            
            <div style="text-align: right; margin-top: 24px; padding-top: 16px; border-top: 1px solid #e5e7eb;">
                <button type="button" onclick="hideCreateTaskModal()" class="btn secondary" style="margin-right: 12px;">Cancel</button>
                <button type="submit" class="btn">Create Task</button>
            </div>
        </form>
    </div>
</div>

<!-- Update Status Modal -->
<div id="updateStatusModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Update Task Status</h3>
            <button onclick="hideUpdateStatusModal()" class="btn secondary" style="padding: 4px 8px;">×</button>
        </div>
        <form method="post" id="updateStatusForm">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="task_id" id="updateStatusTaskId">
            <div style="display: grid; gap: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">New Status</label>
                    <select name="status" required style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="on_hold">On Hold</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Actual Hours (if completed)</label>
                    <input type="number" name="actual_hours" step="0.5" min="0" max="999" placeholder="8.5"
                           style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Notes</label>
                    <textarea name="notes" rows="3" placeholder="Status update notes, completion summary, etc."
                              style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"></textarea>
                </div>
            </div>
            
            <div style="text-align: right; margin-top: 24px; padding-top: 16px; border-top: 1px solid #e5e7eb;">
                <button type="button" onclick="hideUpdateStatusModal()" class="btn secondary" style="margin-right: 12px;">Cancel</button>
                <button type="submit" class="btn">Update Status</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Task Modal -->
<div id="editTaskModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Edit Development Task</h3>
            <button onclick="hideEditTaskModal()" class="btn secondary" style="padding: 4px 8px;">×</button>
        </div>
        <form method="post" id="editTaskForm">
            <input type="hidden" name="action" value="update_task">
            <input type="hidden" name="task_id" id="editTaskId">
            <div style="display: grid; gap: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Task Title *</label>
                    <input type="text" name="title" id="editTaskTitle" required maxlength="255" 
                           style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Description *</label>
                    <textarea name="description" id="editTaskDescription" required rows="4"
                              style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Priority</label>
                        <select name="priority" id="editTaskPriority" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                            <option value="low">⚪ Low</option>
                            <option value="medium">🟢 Medium</option>
                            <option value="high">🟡 High</option>
                            <option value="urgent">🔴 Urgent</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Assign To *</label>
                        <select name="assigned_to" id="editTaskAssignedTo" required style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                            <?php foreach ($adminUsers as $admin): ?>
                            <option value="<?php echo $admin['id']; ?>">
                                <?php echo sanitize($admin['first_name'] . ' ' . $admin['last_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Category</label>
                        <input type="text" name="category" id="editTaskCategory" maxlength="100" 
                               style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Due Date</label>
                        <input type="datetime-local" name="due_date" id="editTaskDueDate"
                               style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Est. Hours</label>
                        <input type="number" name="estimated_hours" id="editTaskEstHours" step="0.5" min="0" max="999" 
                               style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                </div>
            </div>
            
            <div style="text-align: right; margin-top: 24px; padding-top: 16px; border-top: 1px solid #e5e7eb;">
                <button type="button" onclick="hideEditTaskModal()" class="btn secondary" style="margin-right: 12px;">Cancel</button>
                <button type="submit" class="btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<style>
.task-card {
    transition: all 0.2s ease;
}

.task-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.stats-grid .card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    padding: 24px;
    border-radius: 12px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header h3 {
    margin: 0;
}
</style>

<script>
function showCreateTaskModal() {
    document.getElementById('createTaskModal').style.display = 'flex';
}

function hideCreateTaskModal() {
    document.getElementById('createTaskModal').style.display = 'none';
}

function showUpdateStatusModal(taskId) {
    document.getElementById('updateStatusTaskId').value = taskId;
    document.getElementById('updateStatusModal').style.display = 'flex';
}

function hideUpdateStatusModal() {
    document.getElementById('updateStatusModal').style.display = 'none';
}

function showEditTaskModal(taskId) {
    // Fetch task data and populate modal
    const taskCard = document.querySelector(`[data-task-id="${taskId}"]`);
    if (!taskCard) return;
    
    // Get task data from PHP embedded in page
    fetch(window.location.pathname + '?action=get_task&task_id=' + taskId)
        .then(r => r.json())
        .then(task => {
            document.getElementById('editTaskId').value = task.id;
            document.getElementById('editTaskTitle').value = task.title;
            document.getElementById('editTaskDescription').value = task.description;
            document.getElementById('editTaskPriority').value = task.priority;
            document.getElementById('editTaskAssignedTo').value = task.assigned_to_admin_id;
            document.getElementById('editTaskCategory').value = task.category || '';
            if (task.due_date) {
                // Convert MySQL datetime to datetime-local format
                const date = new Date(task.due_date);
                const localDateTime = new Date(date.getTime() - date.getTimezoneOffset() * 60000)
                    .toISOString().slice(0, 16);
                document.getElementById('editTaskDueDate').value = localDateTime;
            }
            document.getElementById('editTaskEstHours').value = task.estimated_hours || '';
            
            document.getElementById('editTaskModal').style.display = 'flex';
        })
        .catch(e => {
            alert('Failed to load task data. Please try again.');
            console.error('Edit task error:', e);
        });
}

function hideEditTaskModal() {
    document.getElementById('editTaskModal').style.display = 'none';
}

function showTaskHistory(taskId) {
    // Open task history in a new window/modal
    window.open('/admin/dev-task-history.php?task_id=' + taskId, 'taskHistory', 'width=800,height=600,scrollbars=yes');
}

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
