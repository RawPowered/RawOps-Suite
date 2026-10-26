<?php
/**
 * Sales ACP Unified Radial Menu
 * Replaces multiple overlapping widgets with a single elegant interface
 * 
 * Features:
 * - 8 radial positions for quick actions
 * - Lead completion stats integration
 * - Archived leads access
 * - AI chat integration
 * - Mobile & desktop responsive
 * 
 * @author Product Development Team
 * @date 2025-10-24
 */

// Ensure we have required data
$uid = current_user_id();
if (!$uid) {
    return;
}

$pdo = db();

// Get current lead ID if on detail page
$currentLeadId = intval($_GET['id'] ?? 0);

// Get notification counts and stats
$pendingTasks = 0;
$archivedCount = 0;
$completionRate = 0;
$detailedLeads = 0;
$totalLeads = 0;
$needsDemo = false;

try {
    // Get pending tasks count
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_tasks WHERE agent_id = ? AND status = "pending"');
    $stmt->execute([$uid]);
    $pendingTasks = (int)$stmt->fetchColumn();

    // Get archived leads count
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE assigned_agent_id = ? AND is_archived = 1');
    $stmt->execute([$uid]);
    $archivedCount = (int)$stmt->fetchColumn();

    // Get completion stats (from cache or view)
    $stmt = $pdo->prepare('SELECT * FROM crm_agent_performance_cache WHERE agent_id = ?');
    $stmt->execute([$uid]);
    $cacheStats = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cacheStats && isset($cacheStats['detail_completion_rate'])) {
        $totalLeads = (int)$cacheStats['total_leads'];
        $detailedLeads = (int)$cacheStats['detailed_leads'];
        $completionRate = (float)$cacheStats['detail_completion_rate'];
    } else {
        // Fallback to view
        $stmt = $pdo->prepare('SELECT * FROM agent_lead_detail_statistics WHERE agent_id = ?');
        $stmt->execute([$uid]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($stats) {
            $totalLeads = (int)$stats['total_leads'];
            $detailedLeads = (int)$stats['detailed_leads'];
            $completionRate = (float)$stats['detail_completion_rate'];
        }
    }

    // Check if current lead needs demo/sample
    if ($currentLeadId > 0) {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM crm_leads l
            WHERE l.id = ? 
            AND l.assigned_agent_id = ?
            AND NOT EXISTS (
                SELECT 1 FROM sample_requests sr 
                WHERE sr.lead_id = l.id
            )
            AND NOT EXISTS (
                SELECT 1 FROM crm_demo_dropoffs dd 
                WHERE dd.lead_id = l.id
            )
        ');
        $stmt->execute([$currentLeadId, $uid]);
        $needsDemo = (bool)$stmt->fetchColumn();
    }

} catch (Exception $e) {
    // Silently fail - use defaults
}

// Check if on lead detail page
$isLeadDetailPage = ($currentLeadId > 0);

// Calculate color for completion rate
$completionColor = '#6b7280'; // default gray
if ($completionRate >= 80) {
    $completionColor = '#059669'; // green
} elseif ($completionRate >= 50) {
    $completionColor = '#d97706'; // orange
} else {
    $completionColor = '#dc2626'; // red
}

// Total notification count for center badge
$totalNotifications = $pendingTasks;
?>

<!-- Backdrop (outside main container) -->
<div id="radialMenuBackdrop" class="radial-backdrop" aria-hidden="true"></div>

<!-- Radial Menu Component -->
<div id="salesACPRadialMenu" class="radial-menu" data-lead-id="<?php echo $currentLeadId; ?>" role="navigation" aria-label="Quick Actions Menu">
    <!-- Center Button -->
    <button class="radial-center" aria-label="Open Quick Actions Menu" aria-expanded="false" aria-controls="radial-items">
        <span class="center-icon">⚡</span>
        <?php if ($totalNotifications > 0): ?>
        <span class="badge" aria-label="<?php echo $totalNotifications; ?> pending notifications"><?php echo $totalNotifications; ?></span>
        <?php endif; ?>
    </button>

    <!-- Radial Items Container -->
    <div id="radial-items" class="radial-items" role="menu">
        
        <!-- 1. Lead Stats/Completion (12 o'clock - Top) -->
        <button class="radial-item item-stats" 
                data-action="stats" 
                data-position="0"
                role="menuitem"
                aria-label="Lead Completion Stats: <?php echo round($completionRate); ?>% complete"
                tabindex="-1">
            <div class="stats-circle">
                <svg width="52" height="52" viewBox="0 0 52 52" style="transform: rotate(-90deg);">
                    <circle cx="26" cy="26" r="22" fill="none" stroke="#e5e7eb" stroke-width="4"/>
                    <circle cx="26" cy="26" r="22" fill="none" 
                            stroke="<?php echo $completionColor; ?>" 
                            stroke-width="4" 
                            stroke-dasharray="<?php echo 2 * 3.14159 * 22; ?>" 
                            stroke-dashoffset="<?php echo 2 * 3.14159 * 22 * (1 - $completionRate / 100); ?>"
                            stroke-linecap="round"/>
                    <text x="26" y="30" text-anchor="middle" fill="<?php echo $completionColor; ?>" font-size="12" font-weight="700"><?php echo round($completionRate); ?>%</text>
                </svg>
            </div>
            <span class="item-label">Lead Stats</span>
        </button>

        <!-- 2. Request Sample/Demo (1:30 - Top-Right) -->
        <?php if ($isLeadDetailPage): ?>
        <button class="radial-item item-sample <?php echo $needsDemo ? 'item-highlight' : ''; ?>" 
                data-action="sample" 
                data-position="1"
                role="menuitem"
                aria-label="Request Sample or Demo"
                tabindex="-1">
            <span>🧪</span>
            <span class="item-label">Sample</span>
        </button>
        <?php else: ?>
        <button class="radial-item item-sample" 
                data-action="sample" 
                data-position="1"
                role="menuitem"
                aria-label="Request Sample"
                tabindex="-1">
            <span>🧪</span>
            <span class="item-label">Sample</span>
        </button>
        <?php endif; ?>

        <!-- 3. Log Call (3 o'clock - Right) -->
        <button class="radial-item item-call" 
                data-action="call" 
                data-position="2"
                role="menuitem"
                aria-label="Log Call"
                tabindex="-1">
            <span>📞</span>
            <span class="item-label">Log Call</span>
        </button>

        <!-- 4. Add Note / Social (4:30 - Bottom-Right) -->
        <button class="radial-item item-note" 
                data-action="note_or_social" 
                data-position="3"
                role="menuitem"
                aria-label="Add Note or Social Interaction"
                tabindex="-1">
            <span>📝</span>
            <span class="item-label">Note/Social</span>
        </button>

        <!-- 5. Create Task (6 o'clock - Bottom) -->
        <button class="radial-item item-task" 
                data-action="task" 
                data-position="4"
                role="menuitem"
                aria-label="Create Task"
                tabindex="-1">
            <span>✓</span>
            <?php if ($pendingTasks > 0): ?>
            <span class="item-badge" aria-label="<?php echo $pendingTasks; ?> pending"><?php echo $pendingTasks; ?></span>
            <?php endif; ?>
            <span class="item-label">Create Task</span>
        </button>

        <!-- 6. Create Invoice (7:30 - Bottom-Left) -->
        <button class="radial-item item-invoice" 
                data-action="invoice" 
                data-position="5"
                role="menuitem"
                aria-label="Create Invoice"
                tabindex="-1">
            <span>📄</span>
            <span class="item-label">Invoice</span>
        </button>

        <!-- 7. Archived Leads (9 o'clock - Left) -->
        <button class="radial-item item-archive" 
                data-action="archive" 
                data-position="6"
                role="menuitem"
                aria-label="View Archived Leads"
                tabindex="-1">
            <span>📂</span>
            <?php if ($archivedCount > 0): ?>
            <span class="item-badge" aria-label="<?php echo $archivedCount; ?> archived"><?php echo $archivedCount; ?></span>
            <?php endif; ?>
            <span class="item-label">Archive (<?php echo $archivedCount; ?>)</span>
        </button>

        <!-- 8. UI Chat/AI Assistant (10:30 - Top-Left) -->
        <button class="radial-item item-chat" 
                data-action="chat" 
                data-position="7"
                role="menuitem"
                aria-label="Open AI Chat Assistant"
                tabindex="-1">
            <span>💬</span>
            <span class="item-label">AI Chat</span>
        </button>
    </div>
</div>

<!-- Include Radial Menu Assets -->
<link rel="stylesheet" href="<?php echo asset_url('/assets/css/radial-menu.css'); ?>">
<script src="<?php echo asset_url('/assets/js/radial-menu.js'); ?>" defer></script>

<!-- Lead Stats Modal (for stats button) -->
<div id="leadStatsModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; padding:32px; max-width:500px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
            <h3 style="margin:0; color:#1f2937;">📊 Lead Completion Statistics</h3>
            <button onclick="closeLeadStatsModal()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#6b7280;">&times;</button>
        </div>
        
        <div style="text-align:center; margin-bottom:24px;">
            <svg width="120" height="120" viewBox="0 0 120 120" style="transform: rotate(-90deg);">
                <circle cx="60" cy="60" r="50" fill="none" stroke="#e5e7eb" stroke-width="10"/>
                <circle cx="60" cy="60" r="50" fill="none" 
                        stroke="<?php echo $completionColor; ?>" 
                        stroke-width="10" 
                        stroke-dasharray="<?php echo 2 * 3.14159 * 50; ?>" 
                        stroke-dashoffset="<?php echo 2 * 3.14159 * 50 * (1 - $completionRate / 100); ?>"
                        stroke-linecap="round"/>
                <text x="60" y="70" text-anchor="middle" fill="<?php echo $completionColor; ?>" font-size="32" font-weight="800" transform="rotate(90 60 60)"><?php echo round($completionRate); ?>%</text>
            </svg>
        </div>

        <div style="display:grid; gap:16px;">
            <div style="display:flex; justify-content:space-between; padding:12px; background:#f9fafb; border-radius:8px;">
                <span style="font-weight:600; color:#6b7280;">Total Leads:</span>
                <span style="font-weight:700; color:#1f2937;"><?php echo $totalLeads; ?></span>
            </div>
            <div style="display:flex; justify-content:space-between; padding:12px; background:#f9fafb; border-radius:8px;">
                <span style="font-weight:600; color:#6b7280;">Detailed Leads:</span>
                <span style="font-weight:700; color:<?php echo $completionColor; ?>;"><?php echo $detailedLeads; ?></span>
            </div>
            <div style="display:flex; justify-content:space-between; padding:12px; background:#f9fafb; border-radius:8px;">
                <span style="font-weight:600; color:#6b7280;">Incomplete:</span>
                <span style="font-weight:700; color:#dc2626;"><?php echo $totalLeads - $detailedLeads; ?></span>
            </div>
        </div>

        <div style="margin-top:24px; padding:16px; background:#fef3c7; border-radius:8px; border-left:4px solid #d97706;">
            <p style="margin:0; font-size:13px; color:#92400e;">
                <strong>💡 Tip:</strong> Complete lead details to improve your completion rate and unlock API access benefits.
            </p>
        </div>
    </div>
</div>

<script>
// Show/hide lead stats modal
function showLeadStatsModal() {
    const modal = document.getElementById('leadStatsModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeLeadStatsModal() {
    const modal = document.getElementById('leadStatsModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Close modal on backdrop click
document.getElementById('leadStatsModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeLeadStatsModal();
    }
});

// Escape key to close modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeLeadStatsModal();
    }
});
</script>

