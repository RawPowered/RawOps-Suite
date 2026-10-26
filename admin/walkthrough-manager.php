<?php
/**
 * Walkthrough Manager
 * List, view, test, and manage walkthroughs
 * 
 * @author Product Development Team
 * @date 2025-10-20
 */

$SEO = [
    'title' => 'Walkthrough Manager — Admin',
    'description' => 'Manage system walkthroughs and tutorials',
    'type' => 'website'
];

require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$adminId = current_admin_id();

// Handle actions
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'toggle_active':
                $id = intval($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('UPDATE sales_walkthroughs SET is_active = NOT is_active WHERE id = ?');
                $stmt->execute([$id]);
                $success = 'Walkthrough status updated';
                break;
                
            case 'delete':
                $id = intval($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('DELETE FROM sales_walkthroughs WHERE id = ?');
                $stmt->execute([$id]);
                $success = 'Walkthrough deleted';
                break;
                
            case 'reset_progress':
                $walkthroughId = intval($_POST['walkthrough_id'] ?? 0);
                $userId = intval($_POST['user_id'] ?? 0);
                
                if ($userId) {
                    $stmt = $pdo->prepare('DELETE FROM user_walkthrough_progress WHERE walkthrough_id = ? AND user_id = ?');
                    $stmt->execute([$walkthroughId, $userId]);
                } else {
                    $stmt = $pdo->prepare('DELETE FROM user_walkthrough_progress WHERE walkthrough_id = ?');
                    $stmt->execute([$walkthroughId]);
                }
                $success = 'Progress reset successfully';
                break;
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Get all walkthroughs with statistics
$stmt = $pdo->query('
    SELECT 
        w.*,
        (SELECT COUNT(*) FROM walkthrough_steps WHERE walkthrough_id = w.id) as step_count,
        (SELECT COUNT(DISTINCT user_id) FROM user_walkthrough_progress WHERE walkthrough_id = w.id) as users_started,
        (SELECT COUNT(*) FROM user_walkthrough_progress WHERE walkthrough_id = w.id AND status = "completed") as completed_count,
        (SELECT COUNT(*) FROM user_walkthrough_progress WHERE walkthrough_id = w.id AND status = "skipped") as skipped_count,
        u.first_name as creator_first_name,
        u.last_name as creator_last_name
    FROM sales_walkthroughs w
    LEFT JOIN users u ON u.id = w.created_by
    ORDER BY w.sort_order ASC, w.id DESC
');
$walkthroughs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get detailed statistics
$totalWalkthroughs = count($walkthroughs);
$activeWalkthroughs = count(array_filter($walkthroughs, fn($w) => $w['is_active']));
$totalViews = array_sum(array_column($walkthroughs, 'users_started'));
$totalCompletions = array_sum(array_column($walkthroughs, 'completed_count'));
?>

<style>
.walkthrough-manager {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.manager-header {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: white;
    padding: 32px;
    border-radius: 12px;
    margin-bottom: 24px;
}

.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    text-align: center;
}

.stat-value {
    font-size: 32px;
    font-weight: 800;
    color: #1f2937;
}

.stat-label {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 4px;
}

.walkthrough-list {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    overflow: hidden;
}

.walkthrough-item {
    padding: 24px;
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.2s;
}

.walkthrough-item:hover {
    background: #f9fafb;
}

.walkthrough-item:last-child {
    border-bottom: none;
}

.walkthrough-header-row {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 12px;
}

.walkthrough-title {
    font-size: 18px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 4px 0;
}

.walkthrough-description {
    color: #6b7280;
    font-size: 14px;
    margin: 0 0 12px 0;
}

.walkthrough-meta {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    font-size: 13px;
    color: #6b7280;
}

.walkthrough-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-active {
    background: #d1fae5;
    color: #065f46;
}

.badge-inactive {
    background: #fee2e2;
    color: #991b1b;
}

.badge-multi {
    background: #dbeafe;
    color: #1e40af;
}

.badge-single {
    background: #f3f4f6;
    color: #4b5563;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-secondary {
    background: #f3f4f6;
    color: #374151;
}

.btn-secondary:hover {
    background: #e5e7eb;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

.btn-success {
    background: #10b981;
    color: white;
}

.btn-success:hover {
    background: #059669;
}

.alert {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.progress-stats {
    display: inline-flex;
    gap: 12px;
    padding: 8px 12px;
    background: #f9fafb;
    border-radius: 6px;
    font-size: 12px;
}

.progress-stat {
    display: flex;
    align-items: center;
    gap: 4px;
}

@media (max-width: 1024px) {
    .stat-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 640px) {
    .stat-grid {
        grid-template-columns: 1fr;
    }
    
    .walkthrough-header-row {
        flex-direction: column;
    }
    
    .walkthrough-actions {
        width: 100%;
    }
    
    .btn {
        flex: 1;
    }
}
</style>

<div class="walkthrough-manager">
    <div class="manager-header">
        <div>
            <h1 style="margin: 0 0 8px 0;">🎓 Walkthrough Manager</h1>
            <p style="margin: 0; opacity: 0.9;">Manage system tutorials and user onboarding experiences</p>
        </div>
        <a href="<?php echo path_with_base('/admin/walkthrough-editor.php'); ?>" class="btn btn-success">
            ➕ Create New Walkthrough
        </a>
    </div>
    
    <?php if ($success): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $totalWalkthroughs; ?></div>
            <div class="stat-label">Total Walkthroughs</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $activeWalkthroughs; ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $totalViews; ?></div>
            <div class="stat-label">Total Views</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $totalCompletions; ?></div>
            <div class="stat-label">Completions</div>
        </div>
    </div>
    
    <!-- Walkthroughs List -->
    <div class="walkthrough-list">
        <?php if (empty($walkthroughs)): ?>
            <div style="padding: 60px 20px; text-align: center; color: #6b7280;">
                <div style="font-size: 48px; margin-bottom: 16px;">📚</div>
                <p style="margin: 0 0 8px 0; font-weight: 600; color: #1f2937;">No walkthroughs yet</p>
                <p style="margin: 0;">Walkthroughs are created in the database. Sample walkthroughs were added during migration.</p>
            </div>
        <?php else: ?>
            <?php foreach ($walkthroughs as $walkthrough): 
                $completionRate = $walkthrough['users_started'] > 0 
                    ? round(($walkthrough['completed_count'] / $walkthrough['users_started']) * 100) 
                    : 0;
                $skipRate = $walkthrough['users_started'] > 0 
                    ? round(($walkthrough['skipped_count'] / $walkthrough['users_started']) * 100) 
                    : 0;
            ?>
                <div class="walkthrough-item">
                    <div class="walkthrough-header-row">
                        <div style="flex: 1;">
                            <h3 class="walkthrough-title">
                                <?php echo htmlspecialchars($walkthrough['title']); ?>
                                <?php if ($walkthrough['is_active']): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Inactive</span>
                                <?php endif; ?>
                                
                                <?php if ($walkthrough['is_multi_step']): ?>
                                    <span class="badge badge-multi"><?php echo $walkthrough['step_count']; ?> Steps</span>
                                <?php else: ?>
                                    <span class="badge badge-single">Single</span>
                                <?php endif; ?>
                            </h3>
                            
                            <?php if ($walkthrough['description']): ?>
                                <p class="walkthrough-description"><?php echo htmlspecialchars($walkthrough['description']); ?></p>
                            <?php endif; ?>
                            
                            <div class="walkthrough-meta">
                                <span>📄 Slug: <code><?php echo htmlspecialchars($walkthrough['slug']); ?></code></span>
                                <span>📍 Page: <code><?php echo $walkthrough['target_page'] ?: 'Global'; ?></code></span>
                                <span>📊 Order: <?php echo $walkthrough['sort_order']; ?></span>
                                <?php if ($walkthrough['creator_first_name']): ?>
                                    <span>👤 By: <?php echo htmlspecialchars($walkthrough['creator_first_name'] . ' ' . $walkthrough['creator_last_name']); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($walkthrough['users_started'] > 0): ?>
                                <div class="progress-stats" style="margin-top: 12px;">
                                    <div class="progress-stat">
                                        <span>👁️</span>
                                        <span><?php echo $walkthrough['users_started']; ?> views</span>
                                    </div>
                                    <div class="progress-stat">
                                        <span>✅</span>
                                        <span><?php echo $walkthrough['completed_count']; ?> completed (<?php echo $completionRate; ?>%)</span>
                                    </div>
                                    <div class="progress-stat">
                                        <span>⏭️</span>
                                        <span><?php echo $walkthrough['skipped_count']; ?> skipped (<?php echo $skipRate; ?>%)</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="walkthrough-actions">
                            <a href="<?php echo path_with_base('/admin/walkthrough-editor.php?id=' . $walkthrough['id']); ?>" 
                               class="btn btn-primary" 
                               title="Edit this walkthrough">
                                ✏️ Edit
                            </a>
                            
                            <a href="<?php echo path_with_base('/onboarding/sales-agent/index.php?test_walkthrough=' . $walkthrough['id']); ?>" 
                               class="btn btn-secondary" 
                               title="Test this walkthrough">
                                🎮 Test
                            </a>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?php echo $walkthrough['id']; ?>">
                                <button type="submit" class="btn <?php echo $walkthrough['is_active'] ? 'btn-secondary' : 'btn-success'; ?>">
                                    <?php echo $walkthrough['is_active'] ? '⏸️ Deactivate' : '▶️ Activate'; ?>
                                </button>
                            </form>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="reset_progress">
                                <input type="hidden" name="walkthrough_id" value="<?php echo $walkthrough['id']; ?>">
                                <button type="submit" class="btn btn-secondary" 
                                        onclick="return confirm('Reset all user progress for this walkthrough?')"
                                        title="Reset all user progress">
                                    🔄 Reset
                                </button>
                            </form>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $walkthrough['id']; ?>">
                                <button type="submit" class="btn btn-danger" 
                                        onclick="return confirm('Delete this walkthrough permanently?')">
                                    🗑️ Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div style="margin-top: 24px; padding: 20px; background: linear-gradient(135deg, #dbeafe, #bfdbfe); border-radius: 8px; border: 2px solid #3b82f6;">
        <h3 style="margin: 0 0 12px 0; font-size: 16px; color: #1e40af;">✨ WYSIWYG Editor Available!</h3>
        <p style="margin: 0 0 12px 0; font-size: 14px; color: #1e3a8a;">
            Create and edit walkthroughs visually with shortcode support, live preview, and multi-step management.
        </p>
        <a href="<?php echo path_with_base('/admin/walkthrough-editor.php'); ?>" class="btn btn-primary">
            ✏️ Open Walkthrough Editor
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>


