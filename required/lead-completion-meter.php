<?php
/**
 * Lead Completion Rate Meter
 * Reusable component showing lead detail completion statistics
 * 
 * Displays:
 * - Circular progress indicator
 * - Detailed vs Total leads
 * - Percentage with color coding
 * - API access warnings
 * 
 * @author Product Development Team
 * @date 2025-10-20
 */

// Ensure we have user ID
$uid = current_user_id();
if (!$uid) {
    return;
}

// Get agent statistics using the same source as dashboard
try {
    $pdo = db();
    
    // Try to get from performance cache first (same as dashboard)
    $stmt = $pdo->prepare('SELECT * FROM crm_agent_performance_cache WHERE agent_id = ?');
    $stmt->execute([$uid]);
    $cacheStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cacheStats && isset($cacheStats['detail_completion_rate'])) {
        // Use cached stats for consistency with dashboard
        $totalLeads = (int)$cacheStats['total_leads'];
        $detailedLeads = (int)$cacheStats['detailed_leads'];
        $incompleteLeads = (int)$cacheStats['incomplete_leads'];
        $completionRate = (float)$cacheStats['detail_completion_rate'];
    } else {
        // Fallback to view if cache not available
        $stmt = $pdo->prepare('SELECT * FROM agent_lead_detail_statistics WHERE agent_id = ?');
        $stmt->execute([$uid]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$stats) {
            $stats = [
                'total_leads' => 0,
                'detailed_leads' => 0,
                'incomplete_leads' => 0,
                'detail_completion_rate' => 0
            ];
        }
        
        $totalLeads = (int)$stats['total_leads'];
        $detailedLeads = (int)$stats['detailed_leads'];
        $incompleteLeads = (int)$stats['incomplete_leads'];
        $completionRate = (float)$stats['detail_completion_rate'];
    }
    
    // Calculate exemption remaining
    $exemptRemaining = max(0, 10 - $totalLeads);
    
    // Check thresholds
    $meetsGoogleThreshold = ($totalLeads <= 10) || ($completionRate >= 80);
    $meetsAiThreshold = ($totalLeads <= 10) || ($completionRate >= 70);
    
    // Determine color based on completion rate
    $color = '#10b981'; // Green (80%+)
    if ($completionRate < 60) {
        $color = '#ef4444'; // Red
    } elseif ($completionRate < 70) {
        $color = '#f59e0b'; // Orange
    } elseif ($completionRate < 80) {
        $color = '#eab308'; // Yellow
    }
    
    // Calculate needs for Google API (80%)
    $needsForGoogle = 0;
    if (!$meetsGoogleThreshold && $totalLeads > 10) {
        $needsForGoogle = ceil($totalLeads * 0.8) - $detailedLeads;
    }
    
    // Calculate needs for AI Email (70%)
    $needsForAI = 0;
    if (!$meetsAiThreshold && $totalLeads > 10) {
        $needsForAI = ceil($totalLeads * 0.7) - $detailedLeads;
    }
    
} catch (Throwable $e) {
    error_log("Lead completion meter error: " . $e->getMessage());
    return; // Fail silently
}
?>

<style>
#lead-completion-meter {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: white;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1), 0 6px 10px rgba(0,0,0,0.08);
    z-index: 9999;
    max-width: 320px;
    border: 2px solid #e5e7eb;
    transition: all 0.3s ease;
}

#lead-completion-meter:hover {
    box-shadow: 0 15px 35px rgba(0,0,0,0.15), 0 8px 15px rgba(0,0,0,0.1);
}

#lead-completion-meter.collapsed {
    padding: 12px 16px;
    max-width: 200px;
}

.meter-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.meter-title {
    font-weight: 700;
    font-size: 14px;
    color: #1f2937;
}

.meter-toggle {
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px;
    color: #6b7280;
    font-size: 18px;
    line-height: 1;
}

.meter-toggle:hover {
    color: #111827;
}

.meter-content {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.meter-content.hidden {
    display: none;
}

.meter-circle-container {
    display: flex;
    justify-content: center;
    align-items: center;
    position: relative;
}

.meter-circle {
    width: 140px;
    height: 140px;
}

.meter-circle-bg {
    fill: none;
    stroke: #e5e7eb;
    stroke-width: 8;
}

.meter-circle-progress {
    fill: none;
    stroke-width: 8;
    stroke-linecap: round;
    transition: stroke-dashoffset 0.5s ease;
    transform: rotate(-90deg);
    transform-origin: 50% 50%;
}

.meter-text-overlay {
    position: absolute;
    text-align: center;
}

.meter-percentage {
    font-size: 32px;
    font-weight: 800;
    line-height: 1;
}

.meter-label {
    font-size: 11px;
    color: #6b7280;
    margin-top: 4px;
}

.meter-stats {
    text-align: center;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
}

.meter-stat-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 6px;
    font-size: 13px;
}

.meter-stat-row:last-child {
    margin-bottom: 0;
}

.meter-stat-label {
    color: #6b7280;
}

.meter-stat-value {
    font-weight: 600;
    color: #1f2937;
}

.meter-warnings {
    font-size: 12px;
}

.meter-warning {
    padding: 8px 12px;
    border-radius: 6px;
    margin-bottom: 8px;
}

.meter-warning:last-child {
    margin-bottom: 0;
}

.meter-warning.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.meter-warning.warning {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}

.meter-warning.success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.meter-action-btn {
    width: 100%;
    padding: 10px;
    background: #3b82f6;
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: background 0.2s;
}

.meter-action-btn:hover {
    background: #2563eb;
}

.meter-exempt-notice {
    text-align: center;
    padding: 10px;
    background: #dbeafe;
    border-radius: 8px;
    color: #1e40af;
    font-size: 12px;
    font-weight: 500;
}

@media (max-width: 768px) {
    #lead-completion-meter {
        bottom: 10px;
        right: 10px;
        left: 10px;
        max-width: none;
    }
}
</style>

<div id="lead-completion-meter">
    <div class="meter-header">
        <div class="meter-title"><i class="fas fa-chart-bar"></i> Lead Completion</div>
        <button class="meter-toggle" onclick="toggleMeter()" title="Collapse">
            <i class="fas fa-minus"></i>
        </button>
    </div>
    
    <div class="meter-content">
        <?php if ($exemptRemaining > 0): ?>
            <div class="meter-exempt-notice">
                <i class="fas fa-gift"></i> First 10 leads exempt<br>
                <strong><?php echo $exemptRemaining; ?></strong> remaining
            </div>
        <?php endif; ?>
        
        <div class="meter-circle-container">
            <svg class="meter-circle" viewBox="0 0 160 160">
                <circle class="meter-circle-bg" cx="80" cy="80" r="70"></circle>
                <circle 
                    class="meter-circle-progress" 
                    cx="80" 
                    cy="80" 
                    r="70"
                    style="stroke: <?php echo $color; ?>; stroke-dasharray: <?php echo 2 * M_PI * 70; ?>; stroke-dashoffset: <?php echo 2 * M_PI * 70 * (1 - $completionRate / 100); ?>;"
                ></circle>
            </svg>
            <div class="meter-text-overlay">
                <div class="meter-percentage" style="color: <?php echo $color; ?>;">
                    <?php echo number_format($completionRate, 0); ?>%
                </div>
                <div class="meter-label">Detailed</div>
            </div>
        </div>
        
        <div class="meter-stats">
            <div class="meter-stat-row">
                <span class="meter-stat-label">Total Leads:</span>
                <span class="meter-stat-value"><?php echo $totalLeads; ?></span>
            </div>
            <div class="meter-stat-row">
                <span class="meter-stat-label">Detailed:</span>
                <span class="meter-stat-value" style="color: #10b981;"><?php echo $detailedLeads; ?></span>
            </div>
            <div class="meter-stat-row">
                <span class="meter-stat-label">Incomplete:</span>
                <span class="meter-stat-value" style="color: #ef4444;"><?php echo $incompleteLeads; ?></span>
            </div>
        </div>
        
        <div class="meter-warnings">
            <?php if ($meetsGoogleThreshold && $meetsAiThreshold): ?>
                <div class="meter-warning success">
                    <i class="fas fa-check-circle"></i> All API tools unlocked
                </div>
            <?php else: ?>
                <?php if (!$meetsGoogleThreshold && $totalLeads > 10): ?>
                    <div class="meter-warning error">
                        <i class="fas fa-lock"></i> Google Places API locked<br>
                        <small>Complete <?php echo $needsForGoogle; ?> more lead<?php echo $needsForGoogle != 1 ? 's' : ''; ?> (need 80%)</small>
                    </div>
                <?php endif; ?>
                
                <?php if (!$meetsAiThreshold && $totalLeads > 10): ?>
                    <div class="meter-warning warning">
                        <i class="fas fa-robot"></i> AI Email locked<br>
                        <small>Complete <?php echo $needsForAI; ?> more lead<?php echo $needsForAI != 1 ? 's' : ''; ?> (need 70%)</small>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <?php if ($incompleteLeads > 0): ?>
            <button class="meter-action-btn" onclick="window.location.href='<?php echo path_with_base('/onboarding/sales-agent/leads.php?filter=incomplete'); ?>'">
                <i class="fas fa-edit"></i> Complete Leads
            </button>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleMeter() {
    const meter = document.getElementById('lead-completion-meter');
    const content = meter.querySelector('.meter-content');
    const toggle = meter.querySelector('.meter-toggle');
    
    if (content.classList.contains('hidden')) {
        content.classList.remove('hidden');
        toggle.innerHTML = '<i class="fas fa-minus"></i>';
        toggle.title = 'Collapse';
        meter.classList.remove('collapsed');
        localStorage.setItem('lead_meter_collapsed', '0');
    } else {
        content.classList.add('hidden');
        toggle.innerHTML = '<i class="fas fa-plus"></i>';
        toggle.title = 'Expand';
        meter.classList.add('collapsed');
        localStorage.setItem('lead_meter_collapsed', '1');
    }
}

// Restore collapsed state
document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('lead_meter_collapsed') === '1') {
        toggleMeter();
    }
});

// Refresh meter data every 30 seconds
setInterval(function() {
    // Check if page has been updated
    const meterElement = document.getElementById('lead-completion-meter');
    if (meterElement && document.body.contains(meterElement)) {
        // Could implement AJAX refresh here if needed
        // For now, meter updates on page reload
    }
}, 30000);
</script>


