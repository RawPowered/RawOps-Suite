<?php
$SEO = [
    'title' => 'CRM Overview — Sales ACP (Admin Only) — Product',
    'description' => 'Admin-only CRM overview inside Sales ACP for convenience.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
start_session();
if (!current_admin_id()) redirect(path_with_base('/onboarding/sales-agent/index.php'));
$pdo = db();

// Stats (exclude archived)
$stats = $pdo->query('SELECT 
    COUNT(CASE WHEN is_archived = 0 THEN 1 END) as total_leads,
    COUNT(CASE WHEN assigned_agent_id IS NULL AND is_archived = 0 THEN 1 END) as unassigned,
    COUNT(CASE WHEN lead_status = "new" AND is_archived = 0 THEN 1 END) as new_leads,
    COUNT(CASE WHEN lead_status IN ("contacted","qualified") AND is_archived = 0 THEN 1 END) as active_leads,
    COUNT(CASE WHEN lead_status = "won" AND is_archived = 0 THEN 1 END) as won_leads
FROM crm_leads')->fetch();

// Recent assignments
$assignments = $pdo->query('SELECT la.*, CONCAT(l.first_name, " ", l.last_name) as lead_name,
    CONCAT(fa.first_name, " ", fa.last_name) as from_agent_name,
    CONCAT(ta.first_name, " ", ta.last_name) as to_agent_name
FROM crm_lead_assignments la
JOIN crm_leads l ON l.id = la.lead_id
LEFT JOIN users fa ON fa.id = la.from_agent_id
LEFT JOIN users ta ON ta.id = la.to_agent_id
ORDER BY la.assigned_at DESC LIMIT 10')->fetchAll();
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

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:16px; margin-bottom:24px;">
        <div class="card" style="text-align:center; background:linear-gradient(135deg,#dbeafe,#bfdbfe);">
            <div style="font-size:24px; font-weight:700; color:#1e40af;"><?php echo intval($stats['total_leads']); ?></div>
            <div style="color:#1e40af;">Total Leads</div>
        </div>
        <div class="card" style="text-align:center; background:linear-gradient(135deg,#fecaca,#fee2e2);">
            <div style="font-size:24px; font-weight:700; color:#b91c1c;"><?php echo intval($stats['unassigned']); ?></div>
            <div style="color:#b91c1c;">Unassigned</div>
        </div>
        <div class="card" style="text-align:center; background:linear-gradient(135deg,#dcfce7,#bbf7d0);">
            <div style="font-size:24px; font-weight:700; color:#166534;"><?php echo intval($stats['new_leads']); ?></div>
            <div style="color:#166534;">New</div>
        </div>
        <div class="card" style="text-align:center; background:linear-gradient(135deg,#fef3c7,#fde68a);">
            <div style="font-size:24px; font-weight:700; color:#b45309;"><?php echo intval($stats['active_leads']); ?></div>
            <div style="color:#b45309;">Active</div>
        </div>
        <div class="card" style="text-align:center; background:linear-gradient(135deg,#d1fae5,#a7f3d0);">
            <div style="font-size:24px; font-weight:700; color:#047857;"><?php echo intval($stats['won_leads']); ?></div>
            <div style="color:#047857;">Won</div>
        </div>
    </div>

    <div class="card">
        <h3 style="margin:0 0 12px 0;">Recent Assignments</h3>
        <?php if (empty($assignments)): ?>
            <div style="text-align:center; color:#6b7280; padding:16px;">No recent assignments.</div>
        <?php else: ?>
            <div style="max-height:300px; overflow-y:auto;">
                <?php foreach ($assignments as $a): ?>
                <div style="padding:8px; border-bottom:1px solid #e5e7eb; font-size:13px;">
                    <div style="font-weight:600; color:#1f2937; margin-bottom:2px;">
                        <?php echo htmlspecialchars($a['lead_name']); ?>
                    </div>
                    <div style="color:#6b7280;">
                        <?php if ($a['from_agent_name'] && $a['to_agent_name']): ?>
                            <?php echo htmlspecialchars($a['from_agent_name']); ?> → <?php echo htmlspecialchars($a['to_agent_name']); ?>
                        <?php elseif ($a['to_agent_name']): ?>
                            Assigned to <?php echo htmlspecialchars($a['to_agent_name']); ?>
                        <?php elseif ($a['from_agent_name']): ?>
                            Unassigned from <?php echo htmlspecialchars($a['from_agent_name']); ?>
                        <?php endif; ?>
                        • <?php echo date('M j, g:i A', strtotime($a['assigned_at'])); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('click',function(e){
    var wrap=document.querySelector('.acp-nav .overflow');
    if(!wrap) return;
    if(e.target.closest('.overflow-btn')){wrap.classList.toggle('open');return;}
    if(!e.target.closest('.overflow')){wrap.classList.remove('open');}
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>


