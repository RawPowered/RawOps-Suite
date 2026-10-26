<?php
/**
 * API Restriction Manager
 * Admin interface for managing lead detail requirements and exemptions
 * 
 * Features:
 * - Global settings (default thresholds)
 * - Grant/revoke per-agent exemptions
 * - View agent statistics
 * - Bulk exemption management
 * 
 * @author Product Development Team
 * @date 2025-10-20
 */

$SEO = [
    'title' => 'API Restriction Manager — Admin',
    'description' => 'Manage API access restrictions and exemptions',
    'type' => 'website'
];

require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));
require_once __DIR__ . '/../required/api-restriction-checker.php';

$pdo = db();
$adminId = current_admin_id();

// Handle form submissions
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_global':
                // Update global settings
                $googleThreshold = floatval($_POST['google_threshold'] ?? 80);
                $aiThreshold = floatval($_POST['ai_threshold'] ?? 70);
                $firstNExempt = intval($_POST['first_n_exempt'] ?? 10);
                
                // Update Google API threshold
                $stmt = $pdo->prepare('
                    UPDATE agent_api_restriction_settings 
                    SET min_detail_percentage = ?, first_n_leads_exempt = ?
                    WHERE agent_id IS NULL AND restriction_type = "google_api"
                ');
                $stmt->execute([$googleThreshold, $firstNExempt]);
                
                // Update AI Email threshold
                $stmt = $pdo->prepare('
                    UPDATE agent_api_restriction_settings 
                    SET min_detail_percentage = ?, first_n_leads_exempt = ?
                    WHERE agent_id IS NULL AND restriction_type = "ai_email"
                ');
                $stmt->execute([$aiThreshold, $firstNExempt]);
                
                $success = 'Global settings updated successfully';
                break;
                
            case 'grant_exemption':
                $userId = intval($_POST['user_id'] ?? 0);
                $restrictionType = $_POST['restriction_type'] ?? 'both';
                $exemptionType = $_POST['exemption_type'] ?? 'days';
                $days = $exemptionType === 'days' ? intval($_POST['days'] ?? 7) : null;
                $untilDate = $exemptionType === 'date' ? $_POST['until_date'] : null;
                $reason = trim($_POST['reason'] ?? 'Admin granted exemption');
                
                if (!$userId) {
                    throw new Exception('User ID required');
                }
                
                $result = grant_api_exemption($userId, $restrictionType, $days, $untilDate, $reason, $adminId);
                
                if ($result) {
                    $success = 'Exemption granted successfully';
                } else {
                    $error = 'Failed to grant exemption';
                }
                break;
                
            case 'revoke_exemption':
                $userId = intval($_POST['user_id'] ?? 0);
                $restrictionType = $_POST['restriction_type'] ?? null;
                
                if (!$userId) {
                    throw new Exception('User ID required');
                }
                
                $result = revoke_api_exemption($userId, $restrictionType);
                
                if ($result) {
                    $success = 'Exemption revoked successfully';
                } else {
                    $error = 'Failed to revoke exemption';
                }
                break;
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Get global settings
$stmt = $pdo->query('SELECT * FROM agent_api_restriction_settings WHERE agent_id IS NULL ORDER BY restriction_type');
$globalSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$googleSettings = ['min_detail_percentage' => 80, 'first_n_leads_exempt' => 10];
$aiSettings = ['min_detail_percentage' => 70, 'first_n_leads_exempt' => 10];

foreach ($globalSettings as $setting) {
    if ($setting['restriction_type'] === 'google_api') {
        $googleSettings = $setting;
    } elseif ($setting['restriction_type'] === 'ai_email') {
        $aiSettings = $setting;
    }
}

// Get active exemptions
$stmt = $pdo->query('
    SELECT 
        s.*,
        u.first_name,
        u.last_name,
        u.email as agent_email,
        admin.first_name as granted_by_first_name,
        admin.last_name as granted_by_last_name
    FROM agent_api_restriction_settings s
    JOIN users u ON u.id = s.agent_id
    LEFT JOIN users admin ON admin.id = s.created_by
    WHERE s.agent_id IS NOT NULL 
      AND (s.exemption_until IS NOT NULL OR s.exemption_days IS NOT NULL)
      AND s.is_active = 1
    ORDER BY s.created_at DESC
');
$activeExemptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all sales agents with statistics
$stmt = $pdo->query('
    SELECT * FROM agent_lead_detail_statistics 
    ORDER BY detail_completion_rate ASC, total_leads DESC
    LIMIT 100
');
$agentStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all sales agents for exemption form
$stmt = $pdo->query('
    SELECT u.id, u.first_name, u.last_name, u.email 
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.name = "sales_agent"
    ORDER BY u.first_name, u.last_name
');
$salesAgents = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.restriction-manager {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.restriction-header {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    padding: 32px;
    border-radius: 12px;
    margin-bottom: 24px;
}

.restriction-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

.restriction-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.restriction-card h2 {
    margin: 0 0 20px 0;
    font-size: 20px;
    color: #1f2937;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
    color: #374151;
    font-size: 14px;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #3b82f6;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

.btn-small {
    padding: 6px 12px;
    font-size: 13px;
}

.exemption-table {
    width: 100%;
    border-collapse: collapse;
}

.exemption-table th,
.exemption-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.exemption-table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
    font-size: 13px;
}

.exemption-table td {
    font-size: 14px;
}

.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.badge-success {
    background: #d1fae5;
    color: #065f46;
}

.badge-warning {
    background: #fef3c7;
    color: #92400e;
}

.badge-error {
    background: #fee2e2;
    color: #991b1b;
}

.badge-info {
    background: #dbeafe;
    color: #1e40af;
}

.alert {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.stat-card {
    background: #f9fafb;
    padding: 16px;
    border-radius: 8px;
    text-align: center;
}

.stat-value {
    font-size: 32px;
    font-weight: 800;
    color: #1f2937;
}

.stat-label {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 4px;
}

@media (max-width: 1024px) {
    .restriction-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<div class="restriction-manager">
    <div class="restriction-header">
        <h1 style="margin: 0 0 8px 0;">🔒 API Restriction Manager</h1>
        <p style="margin: 0; opacity: 0.9;">Manage lead detail requirements and API access exemptions</p>
    </div>
    
    <?php if ($success): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Global Settings -->
    <div class="restriction-card" style="margin-bottom: 24px;">
        <h2>⚙️ Global Settings</h2>
        <form method="POST">
            <input type="hidden" name="action" value="update_global">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Google API Threshold (%)</label>
                    <input type="number" name="google_threshold" value="<?php echo $googleSettings['min_detail_percentage']; ?>" min="0" max="100" step="0.1" required>
                </div>
                
                <div class="form-group">
                    <label>AI Email Threshold (%)</label>
                    <input type="number" name="ai_threshold" value="<?php echo $aiSettings['min_detail_percentage']; ?>" min="0" max="100" step="0.1" required>
                </div>
                
                <div class="form-group">
                    <label>First N Leads Exempt</label>
                    <input type="number" name="first_n_exempt" value="<?php echo $googleSettings['first_n_leads_exempt']; ?>" min="0" max="100" required>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary">Update Global Settings</button>
        </form>
    </div>
    
    <div class="restriction-grid">
        <!-- Grant Exemption -->
        <div class="restriction-card">
            <h2>➕ Grant Exemption</h2>
            <form method="POST">
                <input type="hidden" name="action" value="grant_exemption">
                
                <div class="form-group">
                    <label>Sales Agent</label>
                    <select name="user_id" required>
                        <option value="">Select agent...</option>
                        <?php foreach ($salesAgents as $agent): ?>
                            <option value="<?php echo $agent['id']; ?>">
                                <?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name'] . ' (' . $agent['email'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>API Type</label>
                    <select name="restriction_type" required>
                        <option value="both">Both APIs</option>
                        <option value="google_api">Google Places only</option>
                        <option value="ai_email">AI Email only</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Exemption Type</label>
                    <select name="exemption_type" id="exemptionType" onchange="toggleExemptionFields()" required>
                        <option value="days">Number of Days</option>
                        <option value="date">Until Specific Date</option>
                    </select>
                </div>
                
                <div class="form-group" id="daysField">
                    <label>Days</label>
                    <input type="number" name="days" value="7" min="1" max="365">
                </div>
                
                <div class="form-group" id="dateField" style="display: none;">
                    <label>Until Date</label>
                    <input type="datetime-local" name="until_date">
                </div>
                
                <div class="form-group">
                    <label>Reason</label>
                    <textarea name="reason" rows="3" placeholder="e.g., Promotional period, New agent training, Special circumstance"></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">Grant Exemption</button>
            </form>
        </div>
        
        <!-- Active Exemptions -->
        <div class="restriction-card">
            <h2>🎁 Active Exemptions (<?php echo count($activeExemptions); ?>)</h2>
            
            <?php if (empty($activeExemptions)): ?>
                <p style="text-align: center; color: #6b7280; padding: 40px 0;">No active exemptions</p>
            <?php else: ?>
                <div style="max-height: 400px; overflow-y: auto;">
                    <table class="exemption-table">
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Type</th>
                                <th>Expires</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeExemptions as $exemption): 
                                $expiresAt = $exemption['exemption_until'] ?: 
                                    (new DateTime($exemption['created_at']))->modify('+' . $exemption['exemption_days'] . ' days')->format('Y-m-d H:i:s');
                                $isExpired = (new DateTime()) > new DateTime($expiresAt);
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($exemption['first_name'] . ' ' . $exemption['last_name']); ?></strong><br>
                                        <small style="color: #6b7280;"><?php echo htmlspecialchars($exemption['agent_email']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-info"><?php echo htmlspecialchars($exemption['restriction_type']); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($isExpired): ?>
                                            <span class="badge badge-error">Expired</span>
                                        <?php else: ?>
                                            <?php echo date('M j, Y', strtotime($expiresAt)); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="revoke_exemption">
                                            <input type="hidden" name="user_id" value="<?php echo $exemption['agent_id']; ?>">
                                            <input type="hidden" name="restriction_type" value="<?php echo $exemption['restriction_type']; ?>">
                                            <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Revoke this exemption?')">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Agent Statistics -->
    <div class="restriction-card">
        <h2>📊 Agent Statistics</h2>
        
        <div class="stat-grid">
            <?php 
            $totalAgents = count($agentStats);
            $meetsGoogle = count(array_filter($agentStats, fn($s) => $s['meets_google_api_threshold']));
            $meetsAI = count(array_filter($agentStats, fn($s) => $s['meets_ai_email_threshold']));
            $avgCompletion = $totalAgents > 0 ? array_sum(array_column($agentStats, 'detail_completion_rate')) / $totalAgents : 0;
            ?>
            <div class="stat-card">
                <div class="stat-value"><?php echo $totalAgents; ?></div>
                <div class="stat-label">Total Agents</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $meetsGoogle; ?></div>
                <div class="stat-label">Google API Access</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $meetsAI; ?></div>
                <div class="stat-label">AI Email Access</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($avgCompletion, 1); ?>%</div>
                <div class="stat-label">Avg Completion</div>
            </div>
        </div>
        
        <div style="overflow-x: auto;">
            <table class="exemption-table">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Total Leads</th>
                        <th>Detailed</th>
                        <th>Completion %</th>
                        <th>Google API</th>
                        <th>AI Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agentStats as $stat): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($stat['first_name'] . ' ' . $stat['last_name']); ?></strong>
                            </td>
                            <td><?php echo $stat['total_leads']; ?></td>
                            <td><?php echo $stat['detailed_leads']; ?></td>
                            <td>
                                <?php 
                                $rate = floatval($stat['detail_completion_rate']);
                                $color = $rate >= 80 ? 'success' : ($rate >= 70 ? 'warning' : 'error');
                                ?>
                                <span class="badge badge-<?php echo $color; ?>"><?php echo number_format($rate, 1); ?>%</span>
                            </td>
                            <td>
                                <?php echo $stat['meets_google_api_threshold'] ? '✅' : '🔒'; ?>
                            </td>
                            <td>
                                <?php echo $stat['meets_ai_email_threshold'] ? '✅' : '🔒'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function toggleExemptionFields() {
    const type = document.getElementById('exemptionType').value;
    document.getElementById('daysField').style.display = type === 'days' ? 'block' : 'none';
    document.getElementById('dateField').style.display = type === 'date' ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>









