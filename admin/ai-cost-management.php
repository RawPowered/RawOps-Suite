<?php
$SEO = [
    'title' => 'AI Cost Management — Admin',
    'description' => 'Manage AI API costs and review schedules.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');

$pdo = db();
$message = '';
$error = '';

// Handle cost updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_cost') {
    try {
        $costId = intval($_POST['cost_id'] ?? 0);
        $newCost = floatval($_POST['cost_per_unit'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($costId <= 0) throw new Exception('Invalid cost ID');
        if ($newCost < 0) throw new Exception('Cost cannot be negative');
        
        $adminId = current_user_id();
        $nextReview = date('Y-m-d', strtotime('+30 days'));
        
        $stmt = $pdo->prepare('
            UPDATE ai_usage_costs 
            SET cost_per_unit = ?, 
                last_verified_at = NOW(), 
                next_review_due = ?,
                verified_by_admin_id = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$newCost, $nextReview, $adminId, $notes, $costId]);
        
        $message = 'AI cost updated and verification logged.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle confirming current prices (no changes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_review') {
    try {
        $costId = intval($_POST['cost_id'] ?? 0);
        if ($costId <= 0) throw new Exception('Invalid cost ID');
        
        $adminId = current_user_id();
        $nextReview = date('Y-m-d', strtotime('+30 days'));
        
        $stmt = $pdo->prepare('
            UPDATE ai_usage_costs 
            SET last_verified_at = NOW(),
                next_review_due = ?,
                verified_by_admin_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$nextReview, $adminId, $costId]);
        
        $message = 'Price confirmed - review scheduled for 30 days from now.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get all AI costs with review status
$stmt = $pdo->query("
    SELECT 
        c.*,
        CASE 
            WHEN c.next_review_due < CURDATE() THEN 'overdue'
            WHEN c.next_review_due <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'due_soon'
            ELSE 'current'
        END AS review_status,
        DATEDIFF(c.next_review_due, CURDATE()) AS days_until_review,
        u.first_name AS verified_by_first,
        u.last_name AS verified_by_last
    FROM ai_usage_costs c
    LEFT JOIN users u ON u.id = c.verified_by_admin_id
    WHERE c.is_active = 1
    ORDER BY 
        CASE 
            WHEN c.next_review_due < CURDATE() THEN 1
            WHEN c.next_review_due <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 2
            ELSE 3
        END,
        c.api_service, c.endpoint
");
$costs = $stmt->fetchAll();

// Get usage statistics (last 30 days)
$stmt = $pdo->query("
    SELECT 
        api_service,
        endpoint,
        COUNT(*) AS usage_count,
        SUM(estimated_cost) AS total_cost
    FROM agent_ai_usage_log
    WHERE usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY api_service, endpoint
");
$usageStats = [];
while ($row = $stmt->fetch()) {
    $key = $row['api_service'] . '|' . ($row['endpoint'] ?? '');
    $usageStats[$key] = $row;
}

// Get agents with AI charging enabled
$stmt = $pdo->query("
    SELECT 
        u.id, u.first_name, u.last_name, u.email,
        s.charge_for_ai_calls, s.charge_for_ai_emails, s.charge_for_ai_sms,
        s.enabled_start_date, s.enabled_end_date
    FROM agent_ai_charging_settings s
    JOIN users u ON u.id = s.agent_id
    WHERE s.is_active = 1
    ORDER BY u.last_name, u.first_name
");
$chargedAgents = $stmt->fetchAll();
?>

<style>
.cost-card {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
}
.cost-card.overdue {
    border-color: #dc2626;
    background: #fef2f2;
}
.cost-card.due_soon {
    border-color: #f59e0b;
    background: #fffbeb;
}
.cost-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.service-name {
    font-size: 18px;
    font-weight: 700;
    color: #111827;
}
.review-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.review-badge.overdue { background: #fecaca; color: #991b1b; }
.review-badge.due_soon { background: #fef3c7; color: #92400e; }
.review-badge.current { background: #d1fae5; color: #065f46; }
.cost-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}
.cost-stat {
    display: flex;
    flex-direction: column;
}
.cost-stat-label {
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 4px;
}
.cost-stat-value {
    font-size: 16px;
    font-weight: 600;
    color: #111827;
}
.update-form {
    display: grid;
    grid-template-columns: 1fr 2fr 120px 120px;
    gap: 12px;
    align-items: end;
}
</style>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <div style="background: linear-gradient(135deg, #1e293b, #334155); color: white; padding: 32px; border-radius: 12px; margin-bottom: 24px;">
        <h1 style="margin: 0 0 8px 0; font-size: 32px;">AI Cost Management</h1>
        <p style="margin: 0; opacity: 0.9;">Track and verify AI API costs • 30-day review system</p>
        <div style="margin-top: 12px; padding: 12px; background: rgba(255,255,255,0.1); border-radius: 8px; font-size: 13px;">
            <strong>AI Usage Types:</strong> Phone Calls (voice agents - coming soon) • SMS Messages (text agents - coming soon) • Email Generation (active)
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
    
    <!-- Overview Stats -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 32px;">
        <?php
        $overdueCount = count(array_filter($costs, fn($c) => $c['review_status'] === 'overdue'));
        $dueSoonCount = count(array_filter($costs, fn($c) => $c['review_status'] === 'due_soon'));
        $totalUsage30d = array_sum(array_column($usageStats, 'usage_count'));
        $totalCost30d = array_sum(array_column($usageStats, 'total_cost'));
        ?>
        <div class="card" style="background: <?php echo $overdueCount > 0 ? '#fee2e2' : '#f9fafb'; ?>;">
            <div style="font-size: 36px; font-weight: 700; color: <?php echo $overdueCount > 0 ? '#991b1b' : '#6b7280'; ?>;">
                <?php echo $overdueCount; ?>
            </div>
            <div style="color: #6b7280; margin-top: 4px;">Overdue Reviews</div>
        </div>
        <div class="card" style="background: <?php echo $dueSoonCount > 0 ? '#fffbeb' : '#f9fafb'; ?>;">
            <div style="font-size: 36px; font-weight: 700; color: <?php echo $dueSoonCount > 0 ? '#92400e' : '#6b7280'; ?>;">
                <?php echo $dueSoonCount; ?>
            </div>
            <div style="color: #6b7280; margin-top: 4px;">Due Soon (7 days)</div>
        </div>
        <div class="card">
            <div style="font-size: 36px; font-weight: 700; color: #059669;">
                <?php echo number_format($totalUsage30d); ?>
            </div>
            <div style="color: #6b7280; margin-top: 4px;">API Calls (30d)</div>
        </div>
        <div class="card">
            <div style="font-size: 36px; font-weight: 700; color: #dc2626;">
                $<?php echo number_format($totalCost30d, 2); ?>
            </div>
            <div style="color: #6b7280; margin-top: 4px;">Total Cost (30d)</div>
        </div>
    </div>
    
    <!-- AI Cost Cards -->
    <h2 style="margin-bottom: 20px;">AI API Costs</h2>
    <?php foreach ($costs as $cost): ?>
        <?php
        $key = $cost['api_service'] . '|' . ($cost['endpoint'] ?? '');
        $usage = $usageStats[$key] ?? null;
        ?>
        <div class="cost-card <?php echo $cost['review_status']; ?>">
            <div class="cost-header">
                <div>
                    <div class="service-name">
                        <?php echo htmlspecialchars($cost['api_service']); ?>
                        <?php if ($cost['endpoint']): ?>
                            <span style="font-weight: 400; color: #6b7280;"> / <?php echo htmlspecialchars($cost['endpoint']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">
                        <?php echo htmlspecialchars($cost['notes'] ?? 'No description'); ?>
                    </div>
                </div>
                <span class="review-badge <?php echo $cost['review_status']; ?>">
                    <?php 
                    if ($cost['review_status'] === 'overdue') {
                        echo 'OVERDUE ' . abs($cost['days_until_review']) . 'd';
                    } elseif ($cost['review_status'] === 'due_soon') {
                        echo 'DUE IN ' . $cost['days_until_review'] . 'd';
                    } else {
                        echo 'CURRENT';
                    }
                    ?>
                </span>
            </div>
            
            <div class="cost-details">
                <div class="cost-stat">
                    <div class="cost-stat-label">Current Cost</div>
                    <div class="cost-stat-value" style="color: #059669;">
                        $<?php echo number_format($cost['cost_per_unit'], 6); ?> / <?php echo htmlspecialchars($cost['unit_type']); ?>
                    </div>
                </div>
                <div class="cost-stat">
                    <div class="cost-stat-label">Usage (30d)</div>
                    <div class="cost-stat-value">
                        <?php echo $usage ? number_format($usage['usage_count']) : '0'; ?> calls
                        <span style="font-size: 13px; color: #6b7280;">
                            ($<?php echo $usage ? number_format($usage['total_cost'], 2) : '0.00'; ?>)
                        </span>
                    </div>
                </div>
                <div class="cost-stat">
                    <div class="cost-stat-label">Last Verified</div>
                    <div class="cost-stat-value">
                        <?php echo $cost['last_verified_at'] ? date('M j, Y', strtotime($cost['last_verified_at'])) : 'Never'; ?>
                    </div>
                    <?php if ($cost['verified_by_first']): ?>
                    <div style="font-size: 11px; color: #6b7280;">
                        by <?php echo htmlspecialchars($cost['verified_by_first'] . ' ' . $cost['verified_by_last']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="cost-stat">
                    <div class="cost-stat-label">Next Review</div>
                    <div class="cost-stat-value">
                        <?php echo date('M j, Y', strtotime($cost['next_review_due'])); ?>
                    </div>
                </div>
            </div>
            
            <details style="margin-top: 16px;">
                <summary style="cursor: pointer; font-weight: 600; color: #111827;">Update Cost & Verify</summary>
                <form method="post" class="update-form" style="margin-top: 12px;">
                    <input type="hidden" name="action" value="update_cost">
                    <input type="hidden" name="cost_id" value="<?php echo $cost['id']; ?>">
                    <div>
                        <label style="display: block; font-size: 12px; margin-bottom: 4px;">New Cost per <?php echo htmlspecialchars($cost['unit_type']); ?></label>
                        <input type="number" name="cost_per_unit" step="0.000001" min="0" 
                               value="<?php echo $cost['cost_per_unit']; ?>"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; margin-bottom: 4px;">Notes</label>
                        <input type="text" name="notes" 
                               value="<?php echo htmlspecialchars($cost['notes'] ?? ''); ?>"
                               style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    <button type="submit" class="btn" style="background: #059669; color: white;">
                        Update & Verify
                    </button>
                    <button type="submit" class="btn secondary" 
                            onclick="this.form.action.value='confirm_review'; return true;">
                        Confirm Current
                    </button>
                </form>
            </details>
        </div>
    <?php endforeach; ?>
    
    <!-- Agents with AI Charging Enabled -->
    <?php if (!empty($chargedAgents)): ?>
    <h2 style="margin: 40px 0 20px 0;">Agents with AI Charging Enabled</h2>
    <div class="card">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid #e5e7eb;">
                    <th style="text-align: left; padding: 12px;">Agent</th>
                    <th style="text-align: center; padding: 12px;">Calls</th>
                    <th style="text-align: center; padding: 12px;">Emails</th>
                    <th style="text-align: center; padding: 12px;">SMS</th>
                    <th style="text-align: center; padding: 12px;">Date Range</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($chargedAgents as $agent): ?>
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 12px;">
                        <strong><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></strong>
                        <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($agent['email']); ?></div>
                    </td>
                    <td style="text-align: center; padding: 12px;">
                        <?php echo $agent['charge_for_ai_calls'] ? '✅' : '—'; ?>
                    </td>
                    <td style="text-align: center; padding: 12px;">
                        <?php echo $agent['charge_for_ai_emails'] ? '✅' : '—'; ?>
                    </td>
                    <td style="text-align: center; padding: 12px;">
                        <?php echo $agent['charge_for_ai_sms'] ? '✅' : '—'; ?>
                    </td>
                    <td style="text-align: center; padding: 12px; font-size: 12px; color: #6b7280;">
                        <?php 
                        if ($agent['enabled_start_date'] && $agent['enabled_end_date']) {
                            echo date('M j', strtotime($agent['enabled_start_date'])) . ' - ' . date('M j, Y', strtotime($agent['enabled_end_date']));
                        } elseif ($agent['enabled_start_date']) {
                            echo 'From ' . date('M j, Y', strtotime($agent['enabled_start_date']));
                        } elseif ($agent['enabled_end_date']) {
                            echo 'Until ' . date('M j, Y', strtotime($agent['enabled_end_date']));
                        } else {
                            echo 'Always active';
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <div style="margin-top: 32px; text-align: center;">
        <a href="<?php echo path_with_base('/admin/tools-agent-ai-settings.php'); ?>" class="btn secondary">
            Manage Agent AI Charging Settings
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>

