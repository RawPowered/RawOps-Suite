<?php
/**
 * Lead Stages Management
 * Admin interface for managing dynamic pipeline stages
 * 
 * @version 1.0.0
 * @prefix sacp1015_
 */

$SEO = [
    'title' => 'Lead Stages Management — Admin',
    'description' => 'Manage dynamic lead pipeline stages',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
start_session();

$adminId = current_admin_id();
if (!$adminId) redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$messageType = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create') {
            $stageKey = strtolower(trim($_POST['stage_key'] ?? ''));
            $stageKey = preg_replace('/[^a-z0-9_]/', '_', $stageKey);
            $stageLabel = trim($_POST['stage_label'] ?? '');
            $stageColor = trim($_POST['stage_color'] ?? '#6b7280');
            $stageIcon = trim($_POST['stage_icon'] ?? '📋');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $description = trim($_POST['description'] ?? '');
            
            $stmt = $pdo->prepare('
                INSERT INTO crm_lead_stages (stage_key, stage_label, stage_color, stage_icon, sort_order, description)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$stageKey, $stageLabel, $stageColor, $stageIcon, $sortOrder, $description]);
            
            $message = "Stage '$stageLabel' created successfully!";
            $messageType = 'success';
            
        } elseif ($action === 'update') {
            $stageId = (int)$_POST['stage_id'];
            $stageLabel = trim($_POST['stage_label'] ?? '');
            $stageColor = trim($_POST['stage_color'] ?? '#6b7280');
            $stageIcon = trim($_POST['stage_icon'] ?? '📋');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $isDefault = isset($_POST['is_default']) ? 1 : 0;
            $isClosedWon = isset($_POST['is_closed_won']) ? 1 : 0;
            $isClosedLost = isset($_POST['is_closed_lost']) ? 1 : 0;
            $description = trim($_POST['description'] ?? '');
            
            // If setting as default, unset other defaults
            if ($isDefault) {
                $pdo->exec('UPDATE crm_lead_stages SET is_default = 0');
            }
            
            $stmt = $pdo->prepare('
                UPDATE crm_lead_stages 
                SET stage_label = ?, stage_color = ?, stage_icon = ?, sort_order = ?, is_active = ?, 
                    is_default = ?, is_closed_won = ?, is_closed_lost = ?, description = ?
                WHERE id = ?
            ');
            $stmt->execute([$stageLabel, $stageColor, $stageIcon, $sortOrder, $isActive, $isDefault, $isClosedWon, $isClosedLost, $description, $stageId]);
            
            $message = "Stage updated successfully!";
            $messageType = 'success';
            
        } elseif ($action === 'delete') {
            $stageId = (int)$_POST['stage_id'];
            
            // Check if stage is in use
            $stmt = $pdo->prepare('SELECT COUNT(*), stage_key FROM crm_lead_stages WHERE id = ?');
            $stmt->execute([$stageId]);
            $stage = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stage) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE lead_status = ?');
                $stmt->execute([$stage['stage_key']]);
                $useCount = (int)$stmt->fetchColumn();
                
                if ($useCount > 0) {
                    $message = "Cannot delete stage: it is assigned to $useCount lead(s). Please reassign leads first.";
                    $messageType = 'error';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM crm_lead_stages WHERE id = ?');
                    $stmt->execute([$stageId]);
                    $message = "Stage deleted successfully!";
                    $messageType = 'success';
                }
            }
            
        } elseif ($action === 'reorder') {
            $orders = $_POST['order'] ?? [];
            foreach ($orders as $stageId => $sortOrder) {
                $stmt = $pdo->prepare('UPDATE crm_lead_stages SET sort_order = ? WHERE id = ?');
                $stmt->execute([(int)$sortOrder, (int)$stageId]);
            }
            $message = "Stage order updated successfully!";
            $messageType = 'success';
        }
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch stages
$stages = $pdo->query('
    SELECT s.*, 
           (SELECT COUNT(*) FROM crm_leads WHERE lead_status = s.stage_key) as usage_count
    FROM crm_lead_stages s
    ORDER BY s.sort_order ASC, s.stage_label ASC
')->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <div class="card" style="margin-top: 24px;">
        <h1>📊 Lead Stages Management</h1>
        <p style="color: #6b7280;">Manage dynamic pipeline stages. Sales agents will see these stages in their Kanban views.</p>
        
        <?php if ($message): ?>
        <div style="padding: 12px; border-radius: 8px; margin: 16px 0; background: <?php echo $messageType === 'success' ? '#d1fae5' : '#fee2e2'; ?>; border: 1px solid <?php echo $messageType === 'success' ? '#10b981' : '#ef4444'; ?>;">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Create New Stage -->
        <div style="background: #f9fafb; padding: 20px; border-radius: 8px; margin-bottom: 24px;">
            <h3>Create New Stage</h3>
            <form method="post">
                <input type="hidden" name="action" value="create">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 4px;">Stage Key * (code)</label>
                        <input type="text" name="stage_key" required placeholder="custom_stage" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <small style="color: #6b7280;">Lowercase, underscores only</small>
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 4px;">Stage Label *</label>
                        <input type="text" name="stage_label" required placeholder="Custom Stage" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 4px;">Color</label>
                        <input type="color" name="stage_color" value="#6b7280" style="width: 100%; height: 40px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 4px;">Icon</label>
                        <input type="text" name="stage_icon" value="📋" maxlength="10" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 4px;">Sort Order</label>
                        <input type="number" name="sort_order" value="<?php echo count($stages) + 1; ?>" min="0" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 4px;">Description</label>
                    <textarea name="description" rows="2" placeholder="Describe when leads should be in this stage..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;"></textarea>
                </div>
                <button type="submit" class="btn" style="background: #10b981;">Create Stage</button>
            </form>
        </div>
        
        <!-- Existing Stages -->
        <h3>Existing Stages (Drag to Reorder)</h3>
        <p style="color: #6b7280; font-size: 14px; margin-bottom: 16px;">
            The order shown here determines the order in Kanban views and pipeline visualizations.
        </p>
        
        <form method="post" id="reorder-form">
            <input type="hidden" name="action" value="reorder">
            <div id="stages-list" style="display: grid; gap: 16px;">
                <?php foreach ($stages as $idx => $stage): ?>
                <div class="stage-item" data-stage-id="<?php echo $stage['id']; ?>" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; background: white; cursor: move;">
                    <div style="display: flex; gap: 16px; align-items: start; flex-wrap: wrap;">
                        <div style="font-size: 24px; cursor: grab;">⋮⋮</div>
                        
                        <div style="flex: 1; min-width: 200px;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <span style="font-size: 24px;"><?php echo htmlspecialchars($stage['stage_icon']); ?></span>
                                <strong style="font-size: 16px;"><?php echo htmlspecialchars($stage['stage_label']); ?></strong>
                                <code style="background: #f3f4f6; padding: 2px 8px; border-radius: 4px; font-size: 12px;"><?php echo htmlspecialchars($stage['stage_key']); ?></code>
                                <span style="display: inline-block; width: 20px; height: 20px; border-radius: 4px; background: <?php echo htmlspecialchars($stage['stage_color']); ?>;"></span>
                            </div>
                            
                            <?php if ($stage['description']): ?>
                            <p style="color: #6b7280; font-size: 13px; margin: 0;"><?php echo htmlspecialchars($stage['description']); ?></p>
                            <?php endif; ?>
                            
                            <div style="margin-top: 8px; display: flex; gap: 12px; flex-wrap: wrap; font-size: 12px;">
                                <?php if ($stage['is_active']): ?>
                                <span style="color: #10b981;">✓ Active</span>
                                <?php else: ?>
                                <span style="color: #ef4444;">⊗ Inactive</span>
                                <?php endif; ?>
                                
                                <?php if ($stage['is_default']): ?>
                                <span style="background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 4px;">DEFAULT</span>
                                <?php endif; ?>
                                
                                <?php if ($stage['is_closed_won']): ?>
                                <span style="background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 4px;">CLOSED WON</span>
                                <?php endif; ?>
                                
                                <?php if ($stage['is_closed_lost']): ?>
                                <span style="background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 4px;">CLOSED LOST</span>
                                <?php endif; ?>
                                
                                <span style="color: #6b7280;">Used by <?php echo $stage['usage_count']; ?> leads</span>
                            </div>
                        </div>
                        
                        <input type="hidden" name="order[<?php echo $stage['id']; ?>]" value="<?php echo $idx + 1; ?>" class="sort-order-input">
                        
                        <div>
                            <a href="#" onclick="openEditModal(<?php echo $stage['id']; ?>); return false;" class="btn secondary" style="padding: 8px 16px;">Edit</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn" style="margin-top: 16px; background: #3b82f6;">Save Order</button>
        </form>
    </div>
</div>

<!-- Edit Stage Modal -->
<div id="edit-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h3 style="margin-top: 0;">Edit Stage</h3>
        <form method="post" id="edit-form">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="stage_id" id="edit-stage-id">
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 4px;">Stage Key (Read-only)</label>
                <input type="text" id="edit-stage-key" readonly style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; background: #f3f4f6;">
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 4px;">Stage Label *</label>
                <input type="text" name="stage_label" id="edit-stage-label" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 4px;">Color</label>
                    <input type="color" name="stage_color" id="edit-stage-color" style="width: 100%; height: 40px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 4px;">Icon</label>
                    <input type="text" name="stage_icon" id="edit-stage-icon" maxlength="10" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 4px;">Sort Order</label>
                <input type="number" name="sort_order" id="edit-sort-order" min="0" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 4px;">Description</label>
                <textarea name="description" id="edit-description" rows="3" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="is_active" id="edit-is-active">
                    <span>Active</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="is_default" id="edit-is-default">
                    <span>Default for new leads</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="is_closed_won" id="edit-is-closed-won">
                    <span>Marks as Closed Won</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="is_closed_lost" id="edit-is-closed-lost">
                    <span>Marks as Closed Lost</span>
                </label>
            </div>
            
            <div style="display: flex; justify-content: space-between; gap: 8px;">
                <button type="button" onclick="closeEditModal()" class="btn secondary">Cancel</button>
                <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn" style="background: #10b981;">Save Changes</button>
                    <button type="submit" name="action" value="delete" onclick="return confirm('Delete this stage? Only possible if no leads are using it.')" class="btn" style="background: #ef4444;" id="delete-stage-btn">Delete</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Stage data for editing
const stagesData = <?php echo json_encode($stages); ?>;

function openEditModal(stageId) {
    const stage = stagesData.find(s => s.id == stageId);
    if (!stage) return;
    
    document.getElementById('edit-stage-id').value = stage.id;
    document.getElementById('edit-stage-key').value = stage.stage_key;
    document.getElementById('edit-stage-label').value = stage.stage_label;
    document.getElementById('edit-stage-color').value = stage.stage_color;
    document.getElementById('edit-stage-icon').value = stage.stage_icon;
    document.getElementById('edit-sort-order').value = stage.sort_order;
    document.getElementById('edit-description').value = stage.description || '';
    document.getElementById('edit-is-active').checked = stage.is_active == 1;
    document.getElementById('edit-is-default').checked = stage.is_default == 1;
    document.getElementById('edit-is-closed-won').checked = stage.is_closed_won == 1;
    document.getElementById('edit-is-closed-lost').checked = stage.is_closed_lost == 1;
    
    // Disable delete if stage is in use
    const deleteBtn = document.getElementById('delete-stage-btn');
    if (stage.usage_count > 0) {
        deleteBtn.disabled = true;
        deleteBtn.title = 'Cannot delete: stage is in use by ' + stage.usage_count + ' leads';
    } else {
        deleteBtn.disabled = false;
        deleteBtn.title = '';
    }
    
    document.getElementById('edit-modal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
}

// Simple drag and drop reordering
let draggedItem = null;

document.addEventListener('DOMContentLoaded', function() {
    const stagesList = document.getElementById('stages-list');
    const stageItems = stagesList.querySelectorAll('.stage-item');
    
    stageItems.forEach(item => {
        item.setAttribute('draggable', true);
        
        item.addEventListener('dragstart', function(e) {
            draggedItem = this;
            this.style.opacity = '0.5';
        });
        
        item.addEventListener('dragend', function(e) {
            this.style.opacity = '1';
            updateSortOrders();
        });
        
        item.addEventListener('dragover', function(e) {
            e.preventDefault();
        });
        
        item.addEventListener('drop', function(e) {
            e.preventDefault();
            if (draggedItem !== this) {
                const allItems = Array.from(stagesList.querySelectorAll('.stage-item'));
                const draggedIdx = allItems.indexOf(draggedItem);
                const targetIdx = allItems.indexOf(this);
                
                if (draggedIdx < targetIdx) {
                    this.parentNode.insertBefore(draggedItem, this.nextSibling);
                } else {
                    this.parentNode.insertBefore(draggedItem, this);
                }
            }
        });
    });
});

function updateSortOrders() {
    const items = document.querySelectorAll('.stage-item');
    items.forEach((item, idx) => {
        const input = item.querySelector('.sort-order-input');
        if (input) {
            input.value = idx + 1;
        }
    });
}

// Close modal on background click
document.getElementById('edit-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>




