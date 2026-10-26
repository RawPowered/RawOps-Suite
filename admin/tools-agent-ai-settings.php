<?php
$SEO = [
    'title' => 'Agent AI Settings — Admin Tools',
    'description' => 'Configure per-agent AI charging settings.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();
$message = '';
$error = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_settings') {
    try {
        $agentId = intval($_POST['agent_id'] ?? 0);
        if ($agentId <= 0) throw new Exception('Invalid agent ID');
        
        $chargeCalls = isset($_POST['charge_for_ai_calls']) ? 1 : 0;
        $chargeEmails = isset($_POST['charge_for_ai_emails']) ? 1 : 0;
        $chargeSMS = isset($_POST['charge_for_ai_sms']) ? 1 : 0;
        $startDate = !empty($_POST['enabled_start_date']) ? $_POST['enabled_start_date'] : null;
        $endDate = !empty($_POST['enabled_end_date']) ? $_POST['enabled_end_date'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $notes = trim($_POST['notes'] ?? '');
        
        // Upsert settings
        $stmt = $pdo->prepare('
            INSERT INTO agent_ai_charging_settings 
            (agent_id, charge_for_ai_calls, charge_for_ai_emails, charge_for_ai_sms, 
             enabled_start_date, enabled_end_date, is_active, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                charge_for_ai_calls = VALUES(charge_for_ai_calls),
                charge_for_ai_emails = VALUES(charge_for_ai_emails),
                charge_for_ai_sms = VALUES(charge_for_ai_sms),
                enabled_start_date = VALUES(enabled_start_date),
                enabled_end_date = VALUES(enabled_end_date),
                is_active = VALUES(is_active),
                notes = VALUES(notes),
                updated_at = NOW()
        ');
        $stmt->execute([
            $agentId, $chargeCalls, $chargeEmails, $chargeSMS,
            $startDate, $endDate, $isActive, $notes
        ]);
        
        $message = 'Agent AI charging settings updated successfully.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle bulk disable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_disable') {
    try {
        $agentIds = $_POST['agent_ids'] ?? [];
        if (empty($agentIds)) throw new Exception('No agents selected');
        
        $placeholders = implode(',', array_fill(0, count($agentIds), '?'));
        $stmt = $pdo->prepare("UPDATE agent_ai_charging_settings SET is_active = 0 WHERE agent_id IN ($placeholders)");
        $stmt->execute($agentIds);
        
        $message = count($agentIds) . ' agent(s) AI charging disabled.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get all sales agents with their current settings
$stmt = $pdo->query("
    SELECT 
        u.id, u.first_name, u.last_name, u.email,
        s.id AS settings_id,
        COALESCE(s.charge_for_ai_calls, 0) AS charge_calls,
        COALESCE(s.charge_for_ai_emails, 0) AS charge_emails,
        COALESCE(s.charge_for_ai_sms, 0) AS charge_sms,
        s.enabled_start_date,
        s.enabled_end_date,
        COALESCE(s.is_active, 0) AS is_active,
        s.notes,
        COUNT(DISTINCT l.id) AS usage_count_30d,
        SUM(l.estimated_cost) AS usage_cost_30d,
        COUNT(DISTINCT CASE WHEN o.charged_to_agent = 1 THEN o.id END) AS orders_charged
    FROM users u
    INNER JOIN user_roles ur ON ur.user_id = u.id
    INNER JOIN roles r ON r.id = ur.role_id
    LEFT JOIN agent_ai_charging_settings s ON s.agent_id = u.id
    LEFT JOIN agent_ai_usage_log l ON l.agent_id = u.id AND l.usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    LEFT JOIN order_ai_charges o ON o.agent_id = u.id
    WHERE r.name = 'sales_agent' AND u.status = 'active'
    GROUP BY u.id
    ORDER BY u.last_name, u.first_name
");
$agents = $stmt->fetchAll();
?>

<style>
.agent-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
}
.agent-card.charging-active {
    border-left: 4px solid #f59e0b;
}
.agent-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 16px;
}
.agent-name {
    font-size: 18px;
    font-weight: 700;
    color: #111827;
}
.checkbox-group {
    display: flex;
    gap: 20px;
    margin: 12px 0;
}
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
}
.date-range {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin: 12px 0;
}
</style>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <div style="background: linear-gradient(135deg, #1e293b, #334155); color: white; padding: 32px; border-radius: 12px; margin-bottom: 24px;">
        <h1 style="margin: 0 0 8px 0; font-size: 32px;">Agent AI Charging Settings</h1>
        <p style="margin: 0; opacity: 0.9;">Configure which agents are charged for AI usage</p>
        <div style="margin-top: 12px; padding: 12px; background: rgba(255,255,255,0.1); border-radius: 8px; font-size: 13px;">
            <strong>AI Calls</strong> = AI voice agents (phone calls) • <strong>AI SMS</strong> = AI text agents • <strong>AI Emails</strong> = Email generation
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="card" style="background: #dcfce7; color: #14532d; margin-bottom: 24px;">
            ✅ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Summary Stats -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px;">
        <?php
        $activeChargingCount = count(array_filter($agents, fn($a) => $a['is_active']));
        $totalAgents = count($agents);
        $total30dCost = array_sum(array_column($agents, 'usage_cost_30d'));
        ?>
        <div class="card" style="background: #fffbeb;">
            <div style="font-size: 36px; font-weight: 700; color: #92400e;">
                <?php echo $activeChargingCount; ?>
            </div>
            <div style="color: #6b7280; margin-top: 4px;">Agents Charged for AI</div>
        </div>
        <div class="card">
            <div style="font-size: 36px; font-weight: 700; color: #6b7280;">
                <?php echo $totalAgents; ?>
            </div>
            <div style="color: #6b7280; margin-top: 4px;">Total Sales Agents</div>
        </div>
        <div class="card">
            <div style="font-size: 36px; font-weight: 700; color: #dc2626;">
                $<?php echo number_format($total30dCost, 2); ?>
            </div>
            <div style="color: #6b7280; margin-top: 4px;">AI Usage (30d)</div>
        </div>
    </div>
    
    <!-- Bulk Actions -->
    <form method="post" id="bulkForm">
        <input type="hidden" name="action" value="bulk_disable">
        <div style="display: flex; gap: 12px; margin-bottom: 24px;">
            <button type="button" class="btn secondary" onclick="selectAll()">Select All</button>
            <button type="button" class="btn secondary" onclick="deselectAll()">Deselect All</button>
            <button type="submit" class="btn" style="background: #dc2626;">Disable Selected</button>
        </div>
    
        <!-- Agent Cards -->
        <?php foreach ($agents as $agent): ?>
        <div class="agent-card <?php echo $agent['is_active'] ? 'charging-active' : ''; ?>">
            <div class="agent-header">
                <div>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 4px;">
                        <input type="checkbox" name="agent_ids[]" value="<?php echo $agent['id']; ?>" 
                               class="agent-checkbox" style="width: 20px; height: 20px;">
                        <div class="agent-name">
                            <?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?>
                        </div>
                        <?php if ($agent['is_active']): ?>
                        <span style="background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                            CHARGING ENABLED
                        </span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size: 13px; color: #6b7280;">
                        <?php echo htmlspecialchars($agent['email']); ?>
                    </div>
                </div>
                <div style="text-align: right; font-size: 13px; color: #6b7280;">
                    <div>Usage (30d): <?php echo $agent['usage_count_30d']; ?> calls</div>
                    <div style="font-weight: 600; color: #dc2626;">
                        Cost: $<?php echo number_format($agent['usage_cost_30d'] ?? 0, 2); ?>
                    </div>
                    <?php if ($agent['orders_charged'] > 0): ?>
                    <div style="color: #92400e; margin-top: 4px;">
                        <?php echo $agent['orders_charged']; ?> order(s) charged
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <details>
                <summary style="cursor: pointer; font-weight: 600; color: #111827; margin-bottom: 12px;">
                    Configure AI Charging
                </summary>
                
                <form method="post">
                    <input type="hidden" name="action" value="update_settings">
                    <input type="hidden" name="agent_id" value="<?php echo $agent['id']; ?>">
                    
                    <div style="margin-bottom: 12px;">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_active" <?php echo $agent['is_active'] ? 'checked' : ''; ?>>
                            <strong>Enable AI Charging for this Agent</strong>
                        </label>
                    </div>
                    
                    <div style="padding: 12px; background: #f9fafb; border-radius: 8px; margin-bottom: 12px;">
                        <div style="font-weight: 600; margin-bottom: 8px;">Charge Agent For:</div>
                        <div class="checkbox-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="charge_for_ai_calls" <?php echo $agent['charge_calls'] ? 'checked' : ''; ?>>
                                📞 AI Calls (Voice Agents)
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="charge_for_ai_emails" <?php echo $agent['charge_emails'] ? 'checked' : ''; ?>>
                                📧 AI Emails
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="charge_for_ai_sms" <?php echo $agent['charge_sms'] ? 'checked' : ''; ?>>
                                💬 AI SMS (Text Agents)
                            </label>
                        </div>
                        <div style="margin-top: 8px; padding: 8px; background: white; border-radius: 6px; font-size: 11px; color: #6b7280;">
                            <strong>Note:</strong> AI Calls and SMS are coming soon features. Email generation is currently active.
                        </div>
                    </div>
                    
                    <div class="date-range">
                        <div>
                            <label style="display: block; font-size: 12px; margin-bottom: 4px; font-weight: 600;">
                                Start Date (Optional)
                            </label>
                            <input type="date" name="enabled_start_date" 
                                   value="<?php echo $agent['enabled_start_date'] ?? ''; ?>"
                                   style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <small style="color: #6b7280;">Leave empty for immediate</small>
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; margin-bottom: 4px; font-weight: 600;">
                                End Date (Optional)
                            </label>
                            <input type="date" name="enabled_end_date" 
                                   value="<?php echo $agent['enabled_end_date'] ?? ''; ?>"
                                   style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <small style="color: #6b7280;">Leave empty for indefinite</small>
                        </div>
                    </div>
                    
                    <div style="margin-top: 12px;">
                        <label style="display: block; font-size: 12px; margin-bottom: 4px; font-weight: 600;">
                            Notes
                        </label>
                        <textarea name="notes" rows="2" 
                                  style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"
                        ><?php echo htmlspecialchars($agent['notes'] ?? ''); ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn" style="margin-top: 12px; background: #059669; color: white;">
                        Save Settings
                    </button>
                </form>
            </details>
        </div>
        <?php endforeach; ?>
    </form>
</div>

<script>
function selectAll() {
    document.querySelectorAll('.agent-checkbox').forEach(cb => cb.checked = true);
}
function deselectAll() {
    document.querySelectorAll('.agent-checkbox').forEach(cb => cb.checked = false);
}

document.getElementById('bulkForm').addEventListener('submit', function(e) {
    const checked = document.querySelectorAll('.agent-checkbox:checked');
    if (checked.length === 0) {
        e.preventDefault();
        alert('Please select at least one agent.');
        return false;
    }
    if (!confirm(`Disable AI charging for ${checked.length} agent(s)?`)) {
        e.preventDefault();
        return false;
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

