<?php
$SEO = [
    'title' => 'COA Batch Manager — Operations',
    'description' => 'Manage batch codes and Certificate of Analysis attachments.',
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
$message = '';
$error = '';

// Handle batch actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'activate_batch') {
            $batchId = intval($_POST['batch_id'] ?? 0);
            if ($batchId <= 0) throw new Exception('Invalid batch ID');
            
            $stmt = $pdo->prepare("UPDATE batch_codes SET active = 1, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$batchId]);
            $message = 'Batch activated - products from this batch can now be sold';
            
        } elseif ($action === 'deactivate_batch') {
            $batchId = intval($_POST['batch_id'] ?? 0);
            if ($batchId <= 0) throw new Exception('Invalid batch ID');
            
            $stmt = $pdo->prepare("UPDATE batch_codes SET active = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$batchId]);
            $message = 'Batch deactivated - products from this batch cannot be sold';
            
        } elseif ($action === 'attach_coa') {
            $batchId = intval($_POST['batch_id'] ?? 0);
            $coaFilePath = trim($_POST['coa_file_path'] ?? '');
            
            if ($batchId <= 0 || $coaFilePath === '') {
                throw new Exception('Batch ID and COA file path are required');
            }
            
            $stmt = $pdo->prepare("
                UPDATE batch_codes 
                SET coa_attached = 1, coa_file_path = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$coaFilePath, $batchId]);
            $message = 'COA attached successfully';
            
        } elseif ($action === 'update_quantity') {
            $batchId = intval($_POST['batch_id'] ?? 0);
            $quantityProduced = intval($_POST['quantity_produced'] ?? 0);
            
            if ($batchId <= 0) throw new Exception('Invalid batch ID');
            
            $stmt = $pdo->prepare("UPDATE batch_codes SET quantity_produced = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$quantityProduced, $batchId]);
            
            // Also update work order if linked
            $stmt = $pdo->prepare("
                UPDATE work_orders wo
                JOIN batch_codes bc ON bc.work_order_id = wo.id
                SET wo.quantity_produced = ?, wo.updated_at = NOW()
                WHERE bc.id = ?
            ");
            $stmt->execute([$quantityProduced, $batchId]);
            
            $message = 'Batch quantity updated successfully';
        }
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch all batches
$filter = $_GET['filter'] ?? 'all';
$batchTypeFilter = $_GET['batch_type'] ?? '';

$sql = "
    SELECT bc.*, pg.name as group_name, wo.wo_number, wo.status as wo_status
    FROM batch_codes bc
    JOIN product_groups pg ON pg.id = bc.product_group_id
    LEFT JOIN work_orders wo ON wo.id = bc.work_order_id
    WHERE 1=1
";

if ($filter === 'active') {
    $sql .= " AND bc.active = 1";
} elseif ($filter === 'inactive') {
    $sql .= " AND bc.active = 0";
}

if ($batchTypeFilter) {
    $sql .= " AND bc.batch_type = " . $pdo->quote($batchTypeFilter);
}

$sql .= " ORDER BY bc.production_date DESC, bc.batch_code DESC";

$batches = $pdo->query($sql)->fetchAll();

// Batch statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_batches,
        SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_batches,
        SUM(CASE WHEN coa_attached = 1 THEN 1 ELSE 0 END) as batches_with_coa,
        SUM(quantity_produced) as total_units_produced
    FROM batch_codes
")->fetch(PDO::FETCH_ASSOC);
?>

<section class="section">
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h1>🔬 COA Batch Manager</h1>
                <p class="muted">Manage batch codes and Certificate of Analysis attachments</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="<?php echo path_with_base('/operations/'); ?>" class="btn secondary">← Back to Operations</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="card" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e;margin-bottom:16px">
                ✅ <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="card" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f;margin-bottom:16px">
                ❌ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
            <div class="card" style="text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: #3b82f6;"><?php echo number_format($stats['total_batches']); ?></div>
                <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Total Batches</div>
            </div>
            <div class="card" style="text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: #059669;"><?php echo number_format($stats['active_batches']); ?></div>
                <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Active Batches</div>
            </div>
            <div class="card" style="text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: #f59e0b;"><?php echo number_format($stats['batches_with_coa']); ?></div>
                <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Batches w/ COA</div>
            </div>
            <div class="card" style="text-align: center;">
                <div style="font-size: 28px; font-weight: 700; color: #8b5cf6;"><?php echo number_format($stats['total_units_produced']); ?></div>
                <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Units Produced</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <a href="?filter=all" class="btn <?php echo $filter === 'all' ? '' : 'secondary'; ?>">All Batches</a>
                <a href="?filter=active" class="btn <?php echo $filter === 'active' ? '' : 'secondary'; ?>">Active Only</a>
                <a href="?filter=inactive" class="btn <?php echo $filter === 'inactive' ? '' : 'secondary'; ?>">Inactive Only</a>
                <span style="margin: 0 8px;">|</span>
                <a href="?batch_type=production" class="btn <?php echo $batchTypeFilter === 'production' ? '' : 'secondary'; ?>">Production</a>
                <a href="?batch_type=testing" class="btn <?php echo $batchTypeFilter === 'testing' ? '' : 'secondary'; ?>">Testing</a>
                <a href="?batch_type=rnd" class="btn <?php echo $batchTypeFilter === 'rnd' ? '' : 'secondary'; ?>">R&D</a>
            </div>
        </div>

        <!-- Batch List -->
        <div class="card">
            <h2>Batch Codes (<?php echo count($batches); ?>)</h2>
            <?php if (empty($batches)): ?>
                <p style="color: #6b7280; text-align: center; padding: 40px 20px;">No batches found. Create a work order to generate batch codes.</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8fafc;">
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Batch Code</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #e5e7eb;">Product Group</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Type</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Quantity</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Date</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">COA</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Status</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 2px solid #e5e7eb;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $batch): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 12px;">
                                <code style="background: #f9fafb; padding: 4px 8px; border-radius: 4px; font-weight: 600;">
                                    <?php echo htmlspecialchars($batch['batch_code']); ?>
                                </code>
                                <?php if ($batch['wo_number']): ?>
                                <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">
                                    WO: <?php echo htmlspecialchars($batch['wo_number']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px;">
                                <?php echo htmlspecialchars($batch['group_name']); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <span style="padding: 4px 8px; border-radius: 4px; font-size: 11px; text-transform: uppercase; <?php
                                    echo $batch['batch_type'] === 'production' ? 'background: #dbeafe; color: #1e40af;' : 
                                        ($batch['batch_type'] === 'testing' ? 'background: #fef3c7; color: #92400e;' : 
                                        'background: #f3e8ff; color: #6b21a8;');
                                ?>">
                                    <?php echo strtoupper($batch['batch_type']); ?>
                                </span>
                            </td>
                            <td style="padding: 12px; text-align: center; font-weight: 600;">
                                <?php echo number_format($batch['quantity_produced']); ?> units
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php echo date('M d, Y', strtotime($batch['production_date'])); ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php if ($batch['coa_attached']): ?>
                                    <span style="color: #059669;">✅ Attached</span>
                                <?php else: ?>
                                    <span style="color: #dc2626;">❌ Missing</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php if ($batch['active']): ?>
                                    <span style="background: #dcfce7; color: #14532d; padding: 4px 12px; border-radius: 4px; font-size: 12px;">Active</span>
                                <?php else: ?>
                                    <span style="background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 4px; font-size: 12px;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <button onclick="openBatchModal(<?php echo $batch['id']; ?>, '<?php echo htmlspecialchars($batch['batch_code'], ENT_QUOTES); ?>', <?php echo $batch['quantity_produced']; ?>, <?php echo $batch['active']; ?>, <?php echo $batch['coa_attached']; ?>)" 
                                        class="btn-small secondary">Manage</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Batch Code Pattern Reference -->
        <div class="card" style="margin-top: 24px; background: #f0fdf4;">
            <h3 style="margin: 0 0 12px 0;">Batch Code Patterns</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                <div>
                    <strong>Production:</strong> <code style="background: #dcfce7; padding: 4px 8px; border-radius: 4px;">SKU-P-MMDDYYYY</code>
                    <div style="font-size: 12px; color: #14532d; margin-top: 4px;">Standard manufacturing batches</div>
                </div>
                <div>
                    <strong>Testing:</strong> <code style="background: #fef3c7; padding: 4px 8px; border-radius: 4px;">SKU-T-MMDDYYYY</code>
                    <div style="font-size: 12px; color: #92400e; margin-top: 4px;">Small test batches for lab testing</div>
                </div>
                <div>
                    <strong>R&D:</strong> <code style="background: #f3e8ff; padding: 4px 8px; border-radius: 4px;">SKU-RDT-MMDDYYYY</code>
                    <div style="font-size: 12px; color: #6b21a8; margin-top: 4px;">Research & development batches</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Batch Management Modal -->
<div id="batch-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 24px; max-width: 600px; width: 90%;">
        <h3 style="margin: 0 0 16px 0;">Manage Batch: <span id="modal-batch-code"></span></h3>
        
        <!-- Update Quantity -->
        <form method="post" style="margin-bottom: 24px;">
            <input type="hidden" name="action" value="update_quantity">
            <input type="hidden" name="batch_id" id="modal-batch-id">
            
            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Quantity Produced</label>
            <div style="display: flex; gap: 8px;">
                <input type="number" name="quantity_produced" id="modal-quantity" min="0" required
                       style="flex: 1; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                <button type="submit" class="btn secondary">Update Qty</button>
            </div>
        </form>
        
        <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
        
        <!-- Attach COA -->
        <form method="post" style="margin-bottom: 24px;">
            <input type="hidden" name="action" value="attach_coa">
            <input type="hidden" name="batch_id" id="modal-batch-id-coa">
            
            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Attach COA File Path</label>
            <div style="display: flex; gap: 8px;">
                <input type="text" name="coa_file_path" id="modal-coa-path" placeholder="/uploads/coa/batch-code.pdf"
                       style="flex: 1; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                <button type="submit" class="btn secondary">Attach COA</button>
            </div>
            <div id="coa-status" style="margin-top: 8px; font-size: 13px;"></div>
        </form>
        
        <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
        
        <!-- Activate/Deactivate -->
        <div style="display: flex; gap: 12px;">
            <form method="post" style="flex: 1;">
                <input type="hidden" name="action" value="activate_batch">
                <input type="hidden" name="batch_id" id="modal-batch-id-activate">
                <button type="submit" class="btn" style="background: #059669; color: white; width: 100%;">Activate Batch</button>
            </form>
            
            <form method="post" style="flex: 1;">
                <input type="hidden" name="action" value="deactivate_batch">
                <input type="hidden" name="batch_id" id="modal-batch-id-deactivate">
                <button type="submit" class="btn" style="background: #dc2626; color: white; width: 100%;">Deactivate Batch</button>
            </form>
        </div>
        
        <div style="margin-top: 16px; text-align: right;">
            <button type="button" onclick="closeBatchModal()" class="btn secondary">Close</button>
        </div>
    </div>
</div>

<script>
function openBatchModal(batchId, batchCode, quantity, active, coaAttached) {
    document.getElementById('modal-batch-code').textContent = batchCode;
    document.getElementById('modal-batch-id').value = batchId;
    document.getElementById('modal-batch-id-coa').value = batchId;
    document.getElementById('modal-batch-id-activate').value = batchId;
    document.getElementById('modal-batch-id-deactivate').value = batchId;
    document.getElementById('modal-quantity').value = quantity;
    
    const coaStatus = document.getElementById('coa-status');
    if (coaAttached) {
        coaStatus.innerHTML = '<span style="color: #059669;">✅ COA Already Attached</span>';
    } else {
        coaStatus.innerHTML = '<span style="color: #dc2626;">❌ No COA Attached</span>';
    }
    
    document.getElementById('batch-modal').style.display = 'flex';
}

function closeBatchModal() {
    document.getElementById('batch-modal').style.display = 'none';
}

document.getElementById('batch-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeBatchModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeBatchModal();
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>






