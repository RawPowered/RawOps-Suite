<?php
$SEO = [
	'title' => 'Demo Drop-Off Records — Sales Agent — Product',
	'description' => 'View and manage your store demo drop-offs and follow-ups.',
	'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/demo-functions.php';

start_session();
$pdo = db();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

// Access control unified
$isAdminSimulation = isset($_SESSION['admin_simulation']);
$isRealAdmin = current_admin_id() !== null;
// Toggle admin focused mode (admins only)
if (isset($_GET['toggle_admin_mode']) && $isRealAdmin) {
    $_SESSION['sales_acp_admin_mode'] = $_GET['toggle_admin_mode'] === '1' ? 1 : 0;
    header('Location: dropoffs.php');
    exit;
}
if (!$isAdminSimulation && !$isRealAdmin) {
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    try { $stmt->execute([$uid]); $hasRole = (bool)$stmt->fetch(); } catch (Throwable $e) { $hasRole = false; }
    if (!$hasRole && !is_training_complete($uid)) {
        redirect(path_with_base('/onboarding/trainee/index.php'));
    }
}

// Handle follow-up completion (unified for both sample_requests and legacy crm_demo_dropoffs)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_followup') {
	try {
		$dropoffId = intval($_POST['dropoff_id']);
		$taskId = intval($_POST['task_id'] ?? 0);
		$status = $_POST['status'] ?? 'completed';
		$notes = trim($_POST['notes'] ?? '');
		$source = $_POST['source'] ?? 'sample_system';
		
		if ($source === 'sample_system') {
			// Update sample_request status
			$newStatus = ($status === 'completed') ? 'delivered' : 'cancelled';
			$stmt = $pdo->prepare('UPDATE sample_requests SET status = ?, updated_at = NOW() WHERE id = ? AND agent_id = ? AND request_type = "drop_off"');
			$stmt->execute([$newStatus, $dropoffId, $uid]);
			
			// Get lead_id from sample_request
			$stmt = $pdo->prepare('SELECT lead_id FROM sample_requests WHERE id = ?');
			$stmt->execute([$dropoffId]);
			$leadId = $stmt->fetchColumn();
		} else {
			// Legacy: Update crm_demo_dropoffs
			$stmt = $pdo->prepare('UPDATE crm_demo_dropoffs SET follow_up_status = ?, updated_at = NOW() WHERE id = ? AND agent_id = ?');
			$stmt->execute([$status, $dropoffId, $uid]);
			
			// Get lead_id from crm_demo_dropoffs
			$stmt = $pdo->prepare('SELECT lead_id FROM crm_demo_dropoffs WHERE id = ?');
			$stmt->execute([$dropoffId]);
			$leadId = $stmt->fetchColumn();
		}
		
		// Update task if exists
		if ($taskId > 0) {
			$stmt = $pdo->prepare('UPDATE crm_tasks SET status = "completed", completed_at = NOW() WHERE id = ? AND agent_id = ?');
			$stmt->execute([$taskId, $uid]);
		}
		
		// Update lead status based on outcome
		if ($status === 'completed' && $leadId) {
			$stmt = $pdo->prepare('UPDATE crm_leads SET lead_status = "customer_follow_up" WHERE id = ?');
			$stmt->execute([$leadId]);
		}

		flash_set('success', 'Follow-up completed.');
		header('Location: dropoffs.php');
		exit;
	} catch (Throwable $e) {
		flash_set('error', 'Error: '.$e->getMessage());
		header('Location: dropoffs.php');
		exit;
	}
}

// Scoping rules
$adminMode = !empty($_SESSION['sales_acp_admin_mode']); // when true, admins focus on their own
$hasFullAccess = $isAdminSimulation || $isRealAdmin;
$applyAgentScope = (!$hasFullAccess) || ($hasFullAccess && $adminMode);

// Query drop-offs via shared helper (robust if promo tables missing)
$agentScopeId = $applyAgentScope ? $uid : null;
$dropoffs = get_demo_dropoffs_unified($pdo, $agentScopeId, true);
?>

<style>
@keyframes fire {
    0% { transform: rotate(-2deg); }
    50% { transform: rotate(2deg); }
    100% { transform: rotate(-2deg); }
}
.sacp_admin-focus{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid var(--acp-platinum,#e5e7eb);border-radius:999px;background:#fff;color:#374151;text-decoration:none;font-weight:700}
.sacp_admin-focus.active{background:linear-gradient(135deg,var(--acp-gold,#d4af37),var(--acp-gold-light,#e8c85a));color:#fff}
.sacp_dropoff-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}
.sacp_page-title {
    margin: 0;
    font-size: 28px;
    color: var(--acp-carbon, #1f2937);
    display: flex;
    align-items: center;
    gap: 12px;
}
.sacp_page-subtitle {
    color: var(--acp-graphite, #6b7280);
    margin-top: 4px;
    font-size: 14px;
}
.sacp_metrics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.sacp_metric-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.sacp_metric-value{font-size:22px;font-weight:800;color:#1e40af}
.sacp_metric-label{font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700}
.chip-gold { 
    background: linear-gradient(135deg, var(--acp-gold-light, #ffed4e), var(--acp-gold, #FFD700)); 
    color: var(--acp-carbon, #1f2937); 
    font-weight: 600;
}
.chip-info {
    background: var(--acp-info-bg, #dbeafe);
    color: var(--acp-info, #3b82f6);
}
.sacp_dropoff-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px;transition:transform .15s ease, box-shadow .15s ease}
.sacp_dropoff-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.08)}
.sacp_hot-indicator{display:inline-block;animation:fire .6s ease-in-out infinite}
.sacp_source-badge{display:inline-flex;align-items:center;gap:6px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;border:1px solid #e5e7eb;color:#374151}
.sacp_source-badge.promo{background:linear-gradient(135deg,#e0e7ff,#dbeafe);color:#1e40af}
.sacp_source-badge.crm{background:#f9fafb;color:#374151}
@media(max-width:640px){
    .sacp_dropoff-header{margin-bottom:16px;gap:10px;align-items:flex-start}
    .sacp_page-title{font-size:22px}
    .sacp_metrics-grid{gap:10px}
    .sacp_dropoff-card{padding:12px;margin-bottom:12px}
}
</style>

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
    
    <!-- Enhanced Header with Admin Focus Toggle -->
    <div class="sacp_dropoff-header">
        <div>
            <h1 class="sacp_page-title">
                <span>🧪</span> Demo Drop-Off Records
            </h1>
            <div class="sacp_page-subtitle">Track demos across CRM and sample program • <?php echo count($dropoffs); ?> total records</div>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <?php if ($isRealAdmin): ?>
                <?php $focused = !empty($_SESSION['sales_acp_admin_mode']); ?>
                <a class="sacp_admin-focus <?php echo $focused ? 'active' : ''; ?>" 
                   href="?toggle_admin_mode=<?php echo $focused ? '0' : '1'; ?>" 
                   title="When enabled, admins focus on their own dropoffs">
                    <span><?php echo $focused ? '🎯' : '👁️'; ?></span>
                    Focus on My Dropoffs: <?php echo $focused ? 'ON' : 'OFF'; ?>
                </a>
            <?php endif; ?>
            <a href="index.php" class="btn secondary">← Back to CRM</a>
            <a href="dropoff.php" class="btn primary" style="background: var(--acp-gold, #FFD700);">
                <span>➕</span> Record New Drop-Off
            </a>
        </div>
    </div>
    
    <!-- Stats Overview -->
    <div class="sacp_metrics-grid" style="margin-bottom: 24px;">
        <?php 
        $overdueCount = 0;
        $reviewedCount = 0;
        $hotLeadCount = 0;
        $wholesaleCount = 0;
        foreach ($dropoffs as $d) {
            if ($d['follow_up_status'] === 'pending' && $d['days_until_followup'] < 0) $overdueCount++;
            if (!empty($d['rating'])) $reviewedCount++;
            if ($d['lead_status'] === 'customer_review') $hotLeadCount++;
            if (($d['has_wholesale_account'] ?? 0) > 0) $wholesaleCount++;
        }
        ?>
        <div class="sacp_metric-card">
            <div class="sacp_metric-value"><?php echo count($dropoffs); ?></div>
            <div class="sacp_metric-label">Total Dropoffs</div>
        </div>
        <div class="sacp_metric-card" style="background: linear-gradient(135deg, #fee2e2, #fecaca);">
            <div class="sacp_metric-value" style="color: var(--acp-danger, #dc2626);"><?php echo $overdueCount; ?></div>
            <div class="sacp_metric-label">Overdue Follow-ups</div>
        </div>
        <div class="sacp_metric-card" style="background: linear-gradient(135deg, #fef3c7, #fde68a);">
            <div class="sacp_metric-value" style="color: var(--acp-warning, #f59e0b);"><?php echo $hotLeadCount; ?></div>
            <div class="sacp_metric-label">🔥 Hot Leads</div>
        </div>
        <div class="sacp_metric-card" style="background: linear-gradient(135deg, #dcfce7, #bbf7d0);">
            <div class="sacp_metric-value" style="color: var(--acp-success, #059669);"><?php echo $reviewedCount; ?></div>
            <div class="sacp_metric-label">Reviews Received</div>
        </div>
        <div class="sacp_metric-card" style="background: linear-gradient(135deg, #dbeafe, #bfdbfe);">
            <div class="sacp_metric-value" style="color: var(--acp-info, #0369a1);"><?php echo $wholesaleCount; ?></div>
            <div class="sacp_metric-label">Wholesale Accounts</div>
        </div>
    </div>

	<?php if (empty($dropoffs)): ?>
    <div class="sacp_card" style="text-align:center; padding:48px;">
		<div style="font-size:64px; margin-bottom:16px;">🧪</div>
		<h2 style="color: var(--acp-carbon, #1f2937);">No Demo Drop-offs Yet</h2>
		<p style="color: var(--acp-graphite, #6b7280); margin-bottom: 24px;">Start tracking your demo samples to manage follow-ups effectively.</p>
        <a href="dropoff.php" class="btn primary" style="background: var(--acp-gold, #FFD700);">
            <span>➕</span> Record Your First Drop-Off
        </a>
	</div>
	<?php else: ?>
    <?php foreach ($dropoffs as $d): 
		$isHotLead = $d['lead_status'] === 'customer_review';
		$hasReview = !empty($d['rating']);
	?>
    <div class="sacp_dropoff-card <?php echo $isHotLead ? 'hot-lead' : ''; ?>">
		<div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px;">
			<div>
				<h3 style="margin: 0; display: flex; align-items: center; gap: 8px; color: var(--acp-carbon, #1f2937);">
					<?= htmlspecialchars($d['company_name'] ?: $d['first_name'] . ' ' . $d['last_name']) ?>
					<?php if ($isHotLead): ?>
						<span class="sacp_hot-indicator">🔥</span>
						<span class="chip" style="background: var(--acp-warning, #f59e0b); color: white;">HOT LEAD</span>
					<?php endif; ?>
                    <span class="sacp_source-badge <?= $d['source'] === 'promo' ? 'promo' : 'crm' ?>" title="Source">
                        <?= $d['source'] === 'promo' ? 'Sample Program' : 'CRM' ?>
                    </span>
					<?php if ($hasReview): ?>
						<span class="chip chip-success">✓ Reviewed</span>
					<?php endif; ?>
				</h3>
				<div style="color: #6b7280; font-size: 14px; margin-top: 4px;">
					Dropped: <?= date('M j, Y', strtotime($d['dropoff_date'])) ?>
					<?php if ($d['agent_first_name']): ?>
						• By: <?= htmlspecialchars($d['agent_first_name'] . ' ' . $d['agent_last_name']) ?>
					<?php endif; ?>
				</div>
			</div>
            <div style="text-align: right; display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end;">
                <?php $demoConfirmed = ($d['demo_status'] === 'confirmed'); ?>
                <span class="chip <?= $demoConfirmed ? 'chip-success' : 'chip-info' ?>">
                    Demo: <?= $demoConfirmed ? 'Confirmed' : 'Dropped' ?>
                </span>
                <?php if (!empty($d['rating'])): ?>
                    <span class="chip chip-success">Review: Left</span>
                <?php else: ?>
                    <span class="chip chip-gold">Review: Pending</span>
                <?php endif; ?>
                <?php if (($d['has_wholesale_account'] ?? 0) > 0): ?>
                    <span class="chip chip-success">Signup: Wholesale</span>
                <?php elseif (($d['has_user'] ?? 0) > 0): ?>
                    <span class="chip chip-status">Signup: User</span>
                <?php else: ?>
                    <span class="chip chip-gold">Signup: None</span>
                <?php endif; ?>
                <?php 
                    $fu = $d['follow_up_status'];
                    $days = intval($d['days_until_followup'] ?? 0);
                    if ($fu === 'completed') {
                        $fuChip = '<span class="chip chip-success">Follow-up: Done</span>';
                    } else if ($days < 0) {
                        $fuChip = '<span class="chip chip-danger">Follow-up: Overdue</span>';
                    } else if ($days === 0) {
                        $fuChip = '<span class="chip chip-gold">Follow-up: Today</span>';
                    } else {
                        $fuChip = '<span class="chip chip-status">Follow-up: ' . $days . 'd</span>';
                    }
                    echo $fuChip;
                ?>
            </div>
		</div>
		
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
			<div>
				<div style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Contact</div>
				<div style="font-weight: 600;"><?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?></div>
				<div style="font-size: 14px; color: #374151;">📧 <?= htmlspecialchars($d['email']) ?></div>
				<?php if ($d['phone']): ?>
				<div style="font-size: 14px; color: #374151;">📱 <?= htmlspecialchars($d['phone']) ?></div>
				<?php endif; ?>
			</div>
			
			<div>
				<div style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Product</div>
				<div style="font-weight: 600;"><?= htmlspecialchars($d['product_name']) ?></div>
				<div style="font-size: 14px; color: #374151;"><?= htmlspecialchars($d['blend_type']) ?> • <?= $d['size_oz'] ?>oz • <?= $d['total_mg'] ?>mg</div>
			</div>
			
			<div>
				<div style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Follow-up</div>
				<div style="font-weight: 600;"><?= date('M j, Y', strtotime($d['follow_up_date'])) ?></div>
				<?php if ($d['follow_up_status'] === 'pending'): ?>
					<?php if ($d['days_until_followup'] < 0): ?>
						<div style="color: #dc2626; font-weight: 600;">⚠️ Overdue by <?= abs($d['days_until_followup']) ?> days!</div>
					<?php elseif ($d['days_until_followup'] == 0): ?>
						<div style="color: #f59e0b; font-weight: 600;">📅 Due today!</div>
					<?php else: ?>
						<div style="color: #6b7280;">In <?= $d['days_until_followup'] ?> days</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			
			<div>
				<div style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">Status</div>
				<div style="font-weight: 600;"><?= ucwords(str_replace('_', ' ', $d['demo_status'])) ?></div>
                <?php if (($d['has_wholesale_account'] ?? 0) > 0): ?>
                    <div style="color: #059669; font-size: 14px;">✓ Wholesale Account</div>
                <?php elseif (($d['has_user'] ?? 0) > 0): ?>
                    <div class="muted" style="font-size: 14px;">User Registered</div>
				<?php endif; ?>
			</div>
		</div>
		
		<?php if ($hasReview): ?>
		<div class="sacp_review-section">
			<div style="font-weight: 600; color: var(--acp-success, #059669); margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 20px;">🌟</span> Customer Review
            </div>
			<div style="display: grid; grid-template-columns: auto 1fr; gap: 16px;">
				<div style="text-align: center;">
					<div class="sacp_review-rating">
						<?= $d['rating'] ?>/5
					</div>
					<div style="font-size: 12px; color: var(--acp-graphite, #6b7280);">
						<?= date('M j g:ia', strtotime($d['review_date'])) ?>
					</div>
				</div>
				<div>
					<?php if ($d['review_text']): ?>
						<div style="color: var(--acp-carbon, #374151); font-style: italic; margin-bottom: 8px; font-size: 15px;">
							"<?= htmlspecialchars($d['review_text']) ?>"
						</div>
					<?php endif; ?>
					<?php if ($d['preferred_strength'] && $d['preferred_strength'] !== 'undecided'): ?>
						<div style="font-size: 14px; color: var(--acp-graphite, #6b7280);">
							Preference: <strong style="color: var(--acp-carbon, #1f2937);">
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['preferred_strength']))) ?>
                            </strong>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php endif; ?>
		
		<?php if ($d['notes']): ?>
		<div style="background: #f9fafb; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
			<div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">📝 Notes</div>
			<div style="white-space: pre-line; color: #374151;"><?= htmlspecialchars($d['notes']) ?></div>
		</div>
		<?php endif; ?>
		
		<div style="border-top: 1px solid #e5e7eb; padding-top: 12px; margin-top: 12px;">
			<div style="font-size: 12px; color: #6b7280; margin-bottom: 8px;">📅 Timeline</div>
			<div style="display: flex; gap: 24px; font-size: 13px;">
				<div>
					<strong>Dropped:</strong> <?= date('M j', strtotime($d['dropoff_date'])) ?>
				</div>
                <?php if (($d['has_wholesale_account'] ?? 0) > 0): ?>
                    <div style="color: #059669;">
                        <strong>Wholesale Approved</strong> ✓
                    </div>
				<?php endif; ?>
				<?php if ($hasReview): ?>
				<div style="color: #0369a1;">
					<strong>Reviewed:</strong> <?= date('M j', strtotime($d['review_date'])) ?>
				</div>
				<?php endif; ?>
				<div>
					<strong>Follow-up:</strong> <?= date('M j', strtotime($d['follow_up_date'])) ?>
				</div>
			</div>
		</div>
		
		<div class="sacp_follow-up-actions">
			<?php if ($d['follow_up_status'] === 'pending'): ?>
				<button class="btn small" style="background: var(--acp-success, #059669);" onclick="showFU(<?= intval($d['id']) ?>, <?= intval($d['follow_up_task_id'] ?? 0) ?>, '<?= htmlspecialchars($d['source'] ?? 'sample_system', ENT_QUOTES) ?>')">
					<span>✅</span> Complete Follow-up
				</button>
			<?php endif; ?>
			<a href="leads.php?id=<?= $d['lead_id'] ?>" class="btn small secondary">
                <span>👁️</span> View Full Lead
            </a>
			<?php if ($isHotLead): ?>
				<a href="calls.php?lead_id=<?= $d['lead_id'] ?>&action=add&prefill=followup" class="btn small" style="background: var(--acp-danger, #dc2626);">
					<span>📞</span> Call Now!
				</a>
			<?php endif; ?>
            <button class="btn small" style="background: var(--acp-info, #0369a1);" 
                    data-rapid-action="quick-note" 
                    data-lead-id="<?= $d['lead_id'] ?>">
                <span>📝</span> Quick Note
            </button>
		</div>
	</div>
	<?php endforeach; ?>
	<?php endif; ?>

	<!-- Complete Follow-up Modal -->
	<div id="fuModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;">
        <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:12px; padding:24px; width:520px; max-width:92vw; max-height:90vh; overflow:auto;">
			<h3 style="margin-top:0;">Complete Follow-up</h3>
			<form method="post" style="display:grid; gap:12px;">
				<input type="hidden" name="action" value="complete_followup">
				<input type="hidden" name="dropoff_id" id="fu_dropoff_id">
				<input type="hidden" name="task_id" id="fu_task_id">
				<input type="hidden" name="source" id="fu_source">
				<div>
					<label>Follow-up Status</label>
					<select name="status" onchange="updateStatusOptions(this.value)">
						<option value="completed">Completed - Successful Follow-up</option>
						<option value="cancelled">Cancelled - Customer Not Interested</option>
					</select>
				</div>
				<div id="outcome_options" style="display:grid; gap:8px;">
					<label style="font-weight:600;">Customer Feedback:</label>
					<label style="display:flex; align-items:center; gap:8px; font-weight:500;">
						<input type="checkbox" name="interested_75mg" value="1"> Interested in trying 75mg blend
					</label>
					<label style="display:flex; align-items:center; gap:8px; font-weight:500;">
						<input type="checkbox" name="happy_current" value="1"> Happy with current blend (32/20)
					</label>
					<label style="display:flex; align-items:center; gap:8px; font-weight:500;">
						<input type="checkbox" name="ready_order" value="1"> Ready to place order
					</label>
				</div>
				<div>
					<label>Follow-up Notes</label>
					<textarea name="notes" rows="3" placeholder="Customer feedback, next steps, etc..."></textarea>
				</div>
				<div style="display:flex; gap:8px; justify-content:end;">
					<button type="button" class="btn secondary" onclick="hideFU()">Cancel</button>
					<button type="submit" class="btn" style="background:#059669;">Complete Follow-up</button>
				</div>
			</form>
		</div>
	</div>
</div>

<script>
function showFU(dropoffId, taskId, source){
	document.getElementById('fu_dropoff_id').value = dropoffId;
	document.getElementById('fu_task_id').value = taskId || 0;
	document.getElementById('fu_source').value = source || 'sample_system';
	document.getElementById('fuModal').style.display = 'block';
}

function hideFU(){
	document.getElementById('fuModal').style.display = 'none';
}

function updateStatusOptions(status){
	const outcomeDiv = document.getElementById('outcome_options');
	if (status === 'cancelled') {
		outcomeDiv.style.display = 'none';
	} else {
		outcomeDiv.style.display = 'grid';
	}
}

// Close modal on background click
document.addEventListener('click', function(e){
	if (e.target === document.getElementById('fuModal')) {
		hideFU();
	}
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>


