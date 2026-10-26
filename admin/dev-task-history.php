<?php
$SEO = [
    'title' => 'Task History — Product Admin',
    'description' => 'Development task history and audit trail.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect();

$pdo = db();
$taskId = intval($_GET['task_id'] ?? 0);

if (!$taskId) {
    flash_set('error', 'Task ID required');
    redirect('/admin/dev-tasks.php');
}

try {
    // Get task details
    $stmt = $pdo->prepare('SELECT * FROM v_admin_dev_task_dashboard WHERE id = ? LIMIT 1');
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    
    if (!$task) {
        flash_set('error', 'Task not found');
        redirect('/admin/dev-tasks.php');
    }
    
    // Get task history
    $stmt = $pdo->prepare('
        SELECT 
            h.*,
            CONCAT(u.first_name, " ", u.last_name) as admin_name,
            u.email as admin_email
        FROM admin_dev_task_history h
        JOIN users u ON h.admin_id = u.id
        WHERE h.task_id = ?
        ORDER BY h.created_at DESC
    ');
    $stmt->execute([$taskId]);
    $history = $stmt->fetchAll();
    
} catch (Exception $e) {
    flash_set('error', 'Could not load task history');
    redirect('/admin/dev-tasks.php');
}

$priorityColors = [
    'urgent' => '#dc2626',
    'high' => '#f59e0b',
    'medium' => '#10b981',
    'low' => '#64748b'
];

$statusColors = [
    'pending' => '#6b7280',
    'in_progress' => '#3b82f6',
    'completed' => '#10b981',
    'cancelled' => '#ef4444',
    'on_hold' => '#f59e0b'
];
?>

<section class="section">
    <div class="container" style="max-width: 800px;">
        <div class="admin-header">
            <h1>📋 Task History</h1>
            <div class="admin-nav">
                <a href="<?php echo path_with_base('/admin/dev-tasks.php'); ?>" class="btn secondary">← Back to Tasks</a>
                <button onclick="window.print()" class="btn secondary">Print History</button>
            </div>
        </div>

        <!-- Task Summary -->
        <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
                <div>
                    <h3 style="margin: 0 0 8px; color: var(--blue);"><?php echo sanitize($task['title']); ?></h3>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span style="background: <?php echo $priorityColors[$task['priority']]; ?>; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                            <?php echo strtoupper($task['priority']); ?>
                        </span>
                        <span style="background: <?php echo $statusColors[$task['status']]; ?>; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px;">
                            <?php echo strtoupper(str_replace('_', ' ', $task['status'])); ?>
                        </span>
                    </div>
                </div>
                <div style="text-align: right; font-size: 14px; color: #6b7280;">
                    <div><strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($task['created_at'])); ?></div>
                    <div><strong>Updated:</strong> <?php echo date('M j, Y g:i A', strtotime($task['updated_at'])); ?></div>
                </div>
            </div>
            
            <div style="background: #f9fafb; padding: 12px; border-radius: 8px; margin-bottom: 12px;">
                <p style="margin: 0; line-height: 1.6;"><?php echo nl2br(sanitize($task['description'])); ?></p>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; font-size: 14px;">
                <div>
                    <strong>Created by:</strong> <?php echo sanitize($task['created_by_name']); ?><br>
                    <strong>Assigned to:</strong> <?php echo sanitize($task['assigned_to_name']); ?><br>
                    <?php if ($task['category']): ?>
                    <strong>Category:</strong> <?php echo sanitize($task['category']); ?><br>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($task['due_date']): ?>
                    <strong>Due Date:</strong> <?php echo date('M j, Y g:i A', strtotime($task['due_date'])); ?><br>
                    <?php endif; ?>
                    <?php if ($task['estimated_hours']): ?>
                    <strong>Est. Hours:</strong> <?php echo $task['estimated_hours']; ?><br>
                    <?php endif; ?>
                    <?php if ($task['actual_hours']): ?>
                    <strong>Actual Hours:</strong> <?php echo $task['actual_hours']; ?><br>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- History Timeline -->
        <div class="card">
            <h3 style="margin: 0 0 24px;">📝 Activity History (<?php echo count($history); ?> entries)</h3>
            
            <?php if (empty($history)): ?>
            <div style="text-align: center; padding: 48px; color: #6b7280;">
                <div style="font-size: 48px; margin-bottom: 16px;">📋</div>
                <h4>No History Available</h4>
                <p>No history entries found for this task.</p>
            </div>
            <?php else: ?>
            
            <div class="timeline">
                <?php foreach ($history as $entry): ?>
                <div class="timeline-entry" style="border-left: 3px solid #e5e7eb; padding-left: 20px; margin-bottom: 24px; position: relative;">
                    <!-- Timeline Dot -->
                    <div style="position: absolute; left: -8px; top: 8px; width: 12px; height: 12px; background: <?php 
                        echo match($entry['action_type']) {
                            'create' => '#10b981',
                            'status_change' => '#3b82f6',
                            'update' => '#f59e0b',
                            'system_install' => '#8b5cf6',
                            default => '#6b7280'
                        }; 
                    ?>; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"></div>
                    
                    <!-- Entry Content -->
                    <div style="background: #f9fafb; padding: 16px; border-radius: 8px;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                            <div>
                                <h5 style="margin: 0; color: var(--blue); font-size: 16px;">
                                    <?php 
                                    echo match($entry['action_type']) {
                                        'create' => '🆕 Task Created',
                                        'status_change' => '🔄 Status Changed',
                                        'update' => '✏️ Task Updated',
                                        'system_install' => '⚙️ System Event',
                                        default => '📝 ' . ucfirst(str_replace('_', ' ', $entry['action_type']))
                                    };
                                    ?>
                                </h5>
                                <p style="margin: 4px 0 0; color: #374151; line-height: 1.5;">
                                    <?php echo sanitize($entry['action_description']); ?>
                                </p>
                            </div>
                            <div style="text-align: right; font-size: 12px; color: #6b7280; flex-shrink: 0; margin-left: 16px;">
                                <div><?php echo date('M j, Y', strtotime($entry['created_at'])); ?></div>
                                <div><?php echo date('g:i A', strtotime($entry['created_at'])); ?></div>
                            </div>
                        </div>
                        
                        <div style="font-size: 13px; color: #6b7280; margin-bottom: 8px;">
                            <strong>By:</strong> <?php echo sanitize($entry['admin_name']); ?> (<?php echo sanitize($entry['admin_email']); ?>)
                        </div>
                        
                        <!-- Show old/new values if available -->
                        <?php if ($entry['old_values'] || $entry['new_values']): ?>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px; font-size: 12px;">
                            <?php if ($entry['old_values']): ?>
                            <?php $oldValues = json_decode($entry['old_values'], true) ?: []; ?>
                            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 8px;">
                                <strong style="color: #dc2626;">Previous Values:</strong>
                                <?php foreach ($oldValues as $key => $value): ?>
                                <div style="color: #7f1d1d;">
                                    <strong><?php echo ucfirst(str_replace('_', ' ', $key)); ?>:</strong> <?php echo sanitize($value); ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($entry['new_values']): ?>
                            <?php $newValues = json_decode($entry['new_values'], true) ?: []; ?>
                            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px;">
                                <strong style="color: #166534;">New Values:</strong>
                                <?php foreach ($newValues as $key => $value): ?>
                                <div style="color: #14532d;">
                                    <strong><?php echo ucfirst(str_replace('_', ' ', $key)); ?>:</strong> <?php echo sanitize($value); ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Show notes if available -->
                        <?php if ($entry['notes']): ?>
                        <div style="background: #fef3c7; border: 1px solid #fed7aa; border-radius: 6px; padding: 8px; margin-top: 8px;">
                            <strong style="color: #92400e;">Notes:</strong>
                            <p style="margin: 4px 0 0; color: #92400e; line-height: 1.4;"><?php echo nl2br(sanitize($entry['notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Show technical details -->
                        <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 11px; color: #9ca3af;">
                            <?php if ($entry['ip_address']): ?>
                            <span><strong>IP:</strong> <?php echo sanitize($entry['ip_address']); ?></span> • 
                            <?php endif; ?>
                            <span><strong>Action ID:</strong> <?php echo $entry['id']; ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php endif; ?>
        </div>
    </div>
</section>

<style>
@media print {
    .admin-header .admin-nav {
        display: none;
    }
    
    .timeline-entry {
        break-inside: avoid;
        page-break-inside: avoid;
    }
}

.timeline {
    position: relative;
}

.timeline-entry:last-child {
    margin-bottom: 0;
}

.timeline-entry:hover {
    transform: translateY(-2px);
    transition: transform 0.2s ease;
}
</style>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
