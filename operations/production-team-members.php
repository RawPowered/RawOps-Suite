<?php
$SEO = [
    'title' => 'Production Team Members — Operations',
    'description' => 'Manage production team members for work order tracking.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';

// Ensure user has operations role
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'operations')) {
    flash_set('error', 'Access denied. Operations role required.');
    redirect('/login.php');
}

$pdo = db();

// Handle team member actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add_member') {
            $fullName = trim($_POST['full_name'] ?? '');
            $roleTitle = trim($_POST['role_title'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if ($fullName === '') {
                throw new Exception('Full name is required');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO production_team_members
                (full_name, role_title, notes, active, created_at)
                VALUES (?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$fullName, $roleTitle, $notes]);
            flash_set('success', 'Team member added successfully');
            header('Location: ' . path_with_base('/operations/production-team-members.php'));
            exit;
            
        } elseif ($action === 'update_member') {
            $memberId = intval($_POST['member_id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $roleTitle = trim($_POST['role_title'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $active = isset($_POST['active']) ? 1 : 0;
            
            if ($memberId <= 0 || $fullName === '') {
                throw new Exception('Member ID and full name are required');
            }
            
            $stmt = $pdo->prepare("
                UPDATE production_team_members
                SET full_name = ?, role_title = ?, notes = ?, active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$fullName, $roleTitle, $notes, $active, $memberId]);
            flash_set('success', 'Team member updated successfully');
            header('Location: ' . path_with_base('/operations/production-team-members.php'));
            exit;
            
        } elseif ($action === 'toggle_active') {
            $memberId = intval($_POST['member_id'] ?? 0);
            if ($memberId <= 0) throw new Exception('Invalid member ID');
            
            $stmt = $pdo->prepare("UPDATE production_team_members SET active = NOT active, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$memberId]);
            flash_set('success', 'Team member status updated');
            header('Location: ' . path_with_base('/operations/production-team-members.php'));
            exit;
            
        } elseif ($action === 'delete_member') {
            $memberId = intval($_POST['member_id'] ?? 0);
            if ($memberId <= 0) throw new Exception('Invalid member ID');
            
            // Check if member has any work order assignments
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_order_team_assignments WHERE team_member_id = ?");
            $stmt->execute([$memberId]);
            $assignmentCount = $stmt->fetchColumn();
            
            if ($assignmentCount > 0) {
                throw new Exception("Cannot delete team member with {$assignmentCount} work order assignment(s). Deactivate instead.");
            }
            
            $stmt = $pdo->prepare("DELETE FROM production_team_members WHERE id = ?");
            $stmt->execute([$memberId]);
            flash_set('success', 'Team member deleted successfully');
            header('Location: ' . path_with_base('/operations/production-team-members.php'));
            exit;
        }
    } catch (Throwable $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
        header('Location: ' . path_with_base('/operations/production-team-members.php'));
        exit;
    }
}

// Fetch all team members with work order counts
$teamMembers = $pdo->query("
    SELECT m.*, 
           COUNT(DISTINCT a.work_order_id) as work_order_count,
           MAX(wo.production_date) as last_work_date
    FROM production_team_members m
    LEFT JOIN work_order_team_assignments a ON a.team_member_id = m.id
    LEFT JOIN work_orders wo ON wo.id = a.work_order_id
    GROUP BY m.id
    ORDER BY m.active DESC, m.full_name
")->fetchAll();

// Get stats
$activeCount = count(array_filter($teamMembers, fn($m) => $m['active']));
$inactiveCount = count($teamMembers) - $activeCount;
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>👥 Production Team Members</h1>
                <p class="muted">Manage non-login team members for work order tracking</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="<?php echo path_with_base('/operations/'); ?>" class="btn secondary">← Back to Operations</a>
            </div>
        </div>

        <?php
        $successMsg = flash_get('success');
        $errorMsg = flash_get('error');
        ?>
        <?php if ($successMsg): ?>
            <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
                ✅ <?php echo htmlspecialchars($successMsg); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($errorMsg): ?>
            <div class="card" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-bottom:16px">
                ❌ <?php echo htmlspecialchars($errorMsg); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <div class="card" style="background: linear-gradient(135deg, #dcfce7, #f0fdf4);">
                <div style="color: #6b7280; font-size: 12px;">Active Members</div>
                <div style="font-size: 32px; font-weight: 700; color: #059669;"><?php echo $activeCount; ?></div>
            </div>
            <div class="card" style="background: linear-gradient(135deg, #fee2e2, #fef2f2);">
                <div style="color: #6b7280; font-size: 12px;">Inactive Members</div>
                <div style="font-size: 32px; font-weight: 700; color: #dc2626;"><?php echo $inactiveCount; ?></div>
            </div>
            <div class="card" style="background: linear-gradient(135deg, #dbeafe, #eff6ff);">
                <div style="color: #6b7280; font-size: 12px;">Total Members</div>
                <div style="font-size: 32px; font-weight: 700; color: #3b82f6;"><?php echo count($teamMembers); ?></div>
            </div>
        </div>

        <!-- Add New Member Form -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>Add New Team Member</h2>
            <form method="post">
                <input type="hidden" name="action" value="add_member">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Full Name *</label>
                        <input type="text" name="full_name" required 
                               placeholder="John Doe"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Role/Title</label>
                        <input type="text" name="role_title" 
                               placeholder="Production Lead, Mixer, Packager, QC Inspector, etc."
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Notes</label>
                    <textarea name="notes" rows="2" 
                              placeholder="Optional notes about this team member"
                              style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"></textarea>
                </div>
                
                <div style="text-align: right;">
                    <button type="submit" class="btn" style="background: var(--green); color: white;">Add Team Member</button>
                </div>
            </form>
        </div>

        <!-- Team Members List -->
        <div class="card">
            <h2>Team Members (<?php echo count($teamMembers); ?>)</h2>
            <?php if (empty($teamMembers)): ?>
                <p style="color: #6b7280; text-align: center; padding: 40px 20px;">No team members yet. Add your first team member above.</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc;">
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Name</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Role/Title</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Work Orders</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Last Work</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Status</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teamMembers as $member): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6; <?php echo !$member['active'] ? 'opacity: 0.5;' : ''; ?>">
                            <td style="padding: 12px;">
                                <strong><?php echo htmlspecialchars($member['full_name']); ?></strong>
                                <?php if ($member['notes']): ?>
                                <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                    <?php echo htmlspecialchars($member['notes']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px;">
                                <?php echo htmlspecialchars($member['role_title'] ?? '—'); ?>
                            </td>
                            <td style="padding: 12px; text-align: center; font-weight: 600;">
                                <?php echo number_format($member['work_order_count']); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php echo $member['last_work_date'] ? date('M d, Y', strtotime($member['last_work_date'])) : '—'; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php if ($member['active']): ?>
                                    <span style="background: #dcfce7; color: #14532d; padding: 4px 12px; border-radius: 4px; font-size: 12px;">Active</span>
                                <?php else: ?>
                                    <span style="background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 4px; font-size: 12px;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <button onclick="editMember(<?php echo htmlspecialchars(json_encode($member), ENT_QUOTES); ?>)" 
                                        class="btn-small" style="background: #3b82f6; color: white; margin-bottom: 4px;">Edit</button>
                                <form method="post" style="display: inline; margin-left: 4px;">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                    <button type="submit" class="btn-small secondary" style="margin-bottom: 4px;">
                                        <?php echo $member['active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <?php if ($member['work_order_count'] == 0): ?>
                                <form method="post" style="display: inline; margin-left: 4px;" onsubmit="return confirm('Delete this team member?');">
                                    <input type="hidden" name="action" value="delete_member">
                                    <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                    <button type="submit" class="btn-small" style="background: #dc2626; color: white;">Delete</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Edit Member Modal -->
<div id="edit-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 600px; width: 90%;">
        <h3 style="margin: 0 0 16px 0;">Edit Team Member</h3>
        <form method="post" id="edit-form">
            <input type="hidden" name="action" value="update_member">
            <input type="hidden" name="member_id" id="edit-member-id">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Full Name *</label>
                    <input type="text" name="full_name" id="edit-full-name" required
                           style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Role/Title</label>
                    <input type="text" name="role_title" id="edit-role-title"
                           style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                </div>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Notes</label>
                <textarea name="notes" id="edit-notes" rows="3"
                          style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"></textarea>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="active" id="edit-active">
                    <span>Active</span>
                </label>
            </div>
            
            <div style="text-align: right; gap: 8px; display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeEditModal()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: var(--green); color: white;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function editMember(member) {
    document.getElementById('edit-member-id').value = member.id;
    document.getElementById('edit-full-name').value = member.full_name || '';
    document.getElementById('edit-role-title').value = member.role_title || '';
    document.getElementById('edit-notes').value = member.notes || '';
    document.getElementById('edit-active').checked = member.active == 1;
    
    document.getElementById('edit-modal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
    document.getElementById('edit-form').reset();
}

// Close modal on outside click
document.getElementById('edit-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

// ESC key to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEditModal();
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

