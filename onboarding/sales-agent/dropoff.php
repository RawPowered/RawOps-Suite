<?php
$SEO = [
	'title' => 'Drop-Off Lead — Sales Agent — Product',
	'description' => 'Record a store demo drop-off, search existing stores, and create a linked CRM lead.',
	'type' => 'website'
];

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/demo-functions.php';

start_session();
$pdo = db();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

// Access control unified with other ACP pages
$isAdminSimulation = isset($_SESSION['admin_simulation']);
if (!$isAdminSimulation) {
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    try { $stmt->execute([$uid]); $hasRole = (bool)$stmt->fetch(); } catch (Throwable $e) { $hasRole = false; }
    if (!$hasRole && !is_training_complete($uid)) {
        redirect(path_with_base('/onboarding/trainee/index.php'));
    }
}

// Get lead ID if provided
$leadId = intval($_GET['lead_id'] ?? 0);
$existingLead = null;
if ($leadId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ? AND assigned_agent_id = ?');
    $stmt->execute([$leadId, $uid]);
    $existingLead = $stmt->fetch();
}

// Get demo types
$demoTypes = get_demo_types($pdo);

// Modal/AJAX helpers
$isAjaxFetch = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && stripos($_SERVER['HTTP_X_REQUESTED_WITH'], 'fetch') !== false);
$isModal = isset($_GET['modal']) || isset($_POST['modal']);

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $selectedLeadId = intval($_POST['lead_id'] ?? 0);
        $demoTypeId = intval($_POST['demo_type_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if (!$selectedLeadId) {
            throw new Exception('Please select a lead from your list.');
        }
        
        if (!$demoTypeId) {
            throw new Exception('Please select a demo type.');
        }
        
        // Verify the lead belongs to this agent
        $stmt = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ? AND assigned_agent_id = ?');
        $stmt->execute([$selectedLeadId, $uid]);
        if (!$stmt->fetch()) {
            throw new Exception('Invalid lead selected.');
        }
        
        // Use new unified sample system to create drop-off
        $result = create_dropoff_sample_request($pdo, $selectedLeadId, $uid, $demoTypeId, $notes);
        
        if ($result['success']) {
            if ($isModal || $isAjaxFetch) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'message' => $result['message']]);
                exit;
            }
            flash_set('success', $result['message'] . ' Follow-up task created.');
            redirect('dropoffs.php');
        } else {
            throw new Exception($result['message']);
        }
        
    } catch (Throwable $e) {
        if ($isModal || $isAjaxFetch) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            exit;
        }
        flash_set('error', 'Error: ' . $e->getMessage());
        redirect('dropoffs.php');
    }
}

// Fetch my leads for selection
$myLeads = [];
$stmt = $pdo->prepare('
    SELECT l.*, 
           CONCAT(l.first_name, " ", l.last_name) as full_name,
           (SELECT COUNT(*) FROM crm_demo_dropoffs WHERE lead_id = l.id) as demo_count
    FROM crm_leads l 
    WHERE l.assigned_agent_id = ? 
    ORDER BY l.company_name, l.last_name, l.first_name
');
$stmt->execute([$uid]);
$myLeads = $stmt->fetchAll();

// Fetch recent drop-offs for this agent (unified fetch)
$myDropoffs = get_demo_dropoffs_unified($pdo, $uid, true);
if (count($myDropoffs) > 10) {
	$myDropoffs = array_slice($myDropoffs, 0, 10);
}
// If requested as modal fragment, render only the record form
if ($isModal) {
    ?>
    <div style="padding:20px">
        <h3 style="margin:0 0 16px 0">🧪 Record Demo Drop-Off</h3>
        <?php if (empty($myLeads)): ?>
            <div class="card" style="text-align:center; padding:24px;">You need a lead to record a demo.</div>
        <?php else: ?>
        <form method="post" data-ajax style="display:grid; gap:16px;">
            <input type="hidden" name="modal" value="1">
            <div>
                <label><strong>Select Lead *</strong></label>
                <select class="input" name="lead_id" required>
                    <option value="">-- Choose a lead --</option>
                    <?php foreach ($myLeads as $lead): ?>
                        <option value="<?= $lead['id'] ?>" data-demos="<?= $lead['demo_count'] ?>" <?= ($leadId == $lead['id'] ? 'selected' : '') ?> <?= ($lead['demo_count'] >= 2 ? 'disabled' : '') ?>>
                            <?= htmlspecialchars($lead['company_name'] ?: $lead['full_name']) ?> - <?= htmlspecialchars($lead['full_name']) ?>
                            <?= ($lead['demo_count'] >= 2 ? ' (Max demos reached)' : ' ('.$lead['demo_count'].' demo'.($lead['demo_count'] == 1 ? '' : 's').')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><strong>Demo Type *</strong></label>
                <select class="input" name="demo_type_id" required>
                    <option value="">-- Choose demo type --</option>
                    <?php foreach ($demoTypes as $demo): ?>
                        <option value="<?= $demo['id'] ?>"><?= htmlspecialchars($demo['product_name']) ?> (<?= htmlspecialchars($demo['blend_type']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Drop-off Notes (optional)</label>
                <textarea class="input" name="notes" rows="3" placeholder="Any special instructions or notes about this demo..."></textarea>
            </div>
            <div style="display:flex; gap:12px; justify-content:end;">
                <button type="button" class="btn secondary" onclick="window.SalesACPModals && SalesACPModals.close()">Cancel</button>
                <button class="btn" type="submit">Record Drop-Off</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <?php
    exit;
}

?>

<?php require_once __DIR__ . '/../../required/header.php'; ?>
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

<style>
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.input{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px}
.btn{appearance:none;border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;background:#0ea5e9;color:#fff}
.btn.secondary{background:#f3f4f6;color:#111827;border:1px solid #d1d5db}
.note{color:#64748b;font-size:12px}
.small{font-size:12px;padding:6px 10px}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid #f3f4f6;text-align:left}
thead th{background:#f9fafb;font-weight:600;color:#374151}
@media(max-width:700px){
  .grid{grid-template-columns:1fr}
  .card{padding:14px;margin-bottom:12px}
}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <div>
        <h2 style="margin:0 0 4px 0">🧪 Record Demo Drop-Off</h2>
        <div class="note">Select a lead and demo type to record drop-off</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
        <a href="index.php" class="btn secondary small">← Back to CRM</a>
        <a href="leads.php?action=add" class="btn small" style="background:#059669;">+ Add New Lead</a>
        <a href="dropoffs.php" class="btn small" style="background:#0369a1;">📑 View Drop-Off Records</a>
    </div>
</div>

<?php if (empty($myLeads)): ?>
<div class="card" style="text-align:center; padding:48px;">
    <div style="font-size:48px; margin-bottom:16px;">🎯</div>
    <h3 style="color:#6b7280;">No Leads Available</h3>
    <p style="color:#9ca3af; margin-bottom:24px;">You need to create a lead before recording a demo drop-off.</p>
    <a href="leads.php?action=add" class="btn" style="background:#059669;">Add Your First Lead</a>
</div>
<?php else: ?>
<div class="card">
    <form method="post">
        <div style="display:grid; gap:16px;">
            <div>
                <label><strong>Select Lead *</strong></label>
                <select class="input" name="lead_id" required onchange="updateLeadInfo(this.value)">
                    <option value="">-- Choose a lead --</option>
                    <?php foreach ($myLeads as $lead): ?>
                        <option value="<?= $lead['id'] ?>" 
                                data-demos="<?= $lead['demo_count'] ?>"
                                <?= ($leadId == $lead['id'] ? 'selected' : '') ?>
                                <?= ($lead['demo_count'] >= 2 ? 'disabled' : '') ?>>
                            <?= htmlspecialchars($lead['company_name'] ?: $lead['full_name']) ?> - 
                            <?= htmlspecialchars($lead['full_name']) ?>
                            <?= ($lead['demo_count'] >= 2 ? ' (Max demos reached)' : ' ('.$lead['demo_count'].' demo'.($lead['demo_count'] == 1 ? '' : 's').')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="lead_info" style="margin-top:8px; padding:12px; background:#f9fafb; border-radius:10px; border:1px solid #e5e7eb; display:none;">
                    <!-- Lead info will be populated by JavaScript -->
                </div>
            </div>
            
            <div>
                <label><strong>Demo Type *</strong></label>
                <select class="input" name="demo_type_id" required>
                    <option value="">-- Choose demo type --</option>
                    <?php foreach ($demoTypes as $demo): ?>
                        <option value="<?= $demo['id'] ?>">
                            <?= htmlspecialchars($demo['product_name']) ?> 
                            (<?= htmlspecialchars($demo['blend_type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label>Drop-off Notes (optional)</label>
                <textarea class="input" name="notes" rows="3" placeholder="Any special instructions or notes about this demo..."></textarea>
            </div>
            
            <div style="display:flex; gap:12px; align-items:center;">
                <button class="btn" type="submit">Record Drop-Off</button>
                <div class="note">A 3‑day follow‑up task will be created automatically.</div>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!empty($myDropoffs)): ?>
<div class="card">
    <h3 style="margin-top:0;">Recent Demo Drop-Offs</h3>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Store</th>
                    <th>Contact</th>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Follow-Up</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($myDropoffs as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['dropoff_date']) ?></td>
                    <td><?= htmlspecialchars($d['company_name'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?></td>
                    <td><?= htmlspecialchars($d['product_name']) ?></td>
                    <td>1</td>
                    <td><?= htmlspecialchars($d['follow_up_date']) ?></td>
                    <td>
                        <span style="color: <?= $d['follow_up_status'] === 'pending' && $d['follow_up_date'] < date('Y-m-d') ? '#dc2626' : '#059669' ?>">
                            <?= ucwords(str_replace('_',' ', $d['follow_up_status'])) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="text-align:right;margin-top:8px;">
        <a class="btn secondary small" href="dropoffs.php">View all →</a>
    </div>
    <div class="note">Track outcomes like 75mg interest vs happy with product-pack 20 and orders.</div>
</div>
<?php endif; ?>

<script>
// Store lead data for quick lookup
const leads = <?= json_encode($myLeads) ?>;

function updateLeadInfo(leadId) {
    const infoDiv = document.getElementById('lead_info');
    if (!leadId) {
        infoDiv.style.display = 'none';
        return;
    }
    
    const lead = leads.find(l => l.id == leadId);
    if (lead) {
        let html = '<div style="font-size:13px; color:#374151;"><strong>' + (lead.company_name || lead.full_name) + '</strong></div>';
        html += '<div style="font-size:12px; color:#6b7280; margin-top:4px;">';
        html += lead.email;
        if (lead.phone) html += ' • ' + lead.phone;
        html += '</div>';
        
        if (lead.demo_count > 0) {
            html += '<div style="font-size:12px; color:#0369a1; margin-top:4px;">This lead has ' + lead.demo_count + ' demo(s) already.</div>';
        }
        
        infoDiv.innerHTML = html;
        infoDiv.style.display = 'block';
    }
}

// Initialize if lead pre-selected
<?php if ($leadId && $existingLead): ?>
updateLeadInfo(<?= $leadId ?>);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>


