<?php
$SEO = [
    'title' => 'Legal Management — Admin',
    'description' => 'Manage legal agreements, documents, and compliance.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/config.php';
require_login_or_redirect('/login.php');

// Require admin role
$uid = current_user_id();
if (!in_array('admin', user_roles($uid) ?? [])) {
    flash_set('error', 'Admin access required.');
    redirect('/');
}

$pdo = db();

// Get agreement statistics
try {
    $stmt = $pdo->query('
        SELECT 
            at.agreement_type,
            at.title,
            COUNT(DISTINCT ua.user_id) as signed_count,
            at.version as current_version
        FROM agreement_templates at
        LEFT JOIN user_agreements ua ON ua.agreement_template_id = at.id
        WHERE at.is_active = 1
        GROUP BY at.id, at.agreement_type, at.title, at.version
        ORDER BY FIELD(at.agreement_type, "nda", "non_compete", "ai_communications", "sms_consent", "email_consent", "phone_consent", "gmail_account")
    ');
    $agreementStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total users requiring agreements (sales agents)
    $stmt = $pdo->query('
        SELECT COUNT(DISTINCT u.id) as total_agents
        FROM users u
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r ON r.id = ur.role_id
        WHERE r.name = "sales_agent" AND u.status = "active"
    ');
    $totalAgents = $stmt->fetchColumn();
    
    // Get 1099 forms count
    $stmt = $pdo->query('SELECT COUNT(*) FROM form_1099_documents WHERE is_active = 1');
    $active1099Forms = $stmt->fetchColumn();
    
    // Get recent agreement signatures
    $stmt = $pdo->query('
        SELECT 
            ua.signed_at,
            CONCAT(u.first_name, " ", u.last_name) as user_name,
            u.email,
            at.title as agreement_title,
            ua.signature_type
        FROM user_agreements ua
        JOIN users u ON u.id = ua.user_id
        JOIN agreement_templates at ON at.id = ua.agreement_template_id
        ORDER BY ua.signed_at DESC
        LIMIT 20
    ');
    $recentSignatures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pending legal access requests
    $stmt = $pdo->query('
        SELECT 
            r.id,
            r.requestor_name,
            r.email,
            r.law_firm,
            r.created_at,
            c.title as case_title,
            c.case_number
        FROM legal_access_requests r
        LEFT JOIN legal_cases c ON c.id = r.case_id
        WHERE r.status = "pending"
        ORDER BY r.created_at DESC
        LIMIT 10
    ');
    $pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingCount = count($pendingRequests);
    
} catch (Throwable $e) {
    error_log('Legal dashboard error: ' . $e->getMessage());
    $agreementStats = [];
    $totalAgents = 0;
    $active1099Forms = 0;
    $recentSignatures = [];
    $pendingRequests = [];
    $pendingCount = 0;
}

require_once __DIR__ . '/../../required/header.php';
?>

<style>
.legal-dashboard {
    max-width: 1400px;
    margin: 0 auto;
    padding: 40px 20px;
}

.dashboard-header {
    margin-bottom: 40px;
}

.dashboard-title {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 8px;
    color: #1f2937;
}

.dashboard-subtitle {
    font-size: 16px;
    color: #6b7280;
}

.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.action-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    text-decoration: none;
    transition: all 0.2s;
    display: block;
}

.action-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.action-icon {
    font-size: 32px;
    margin-bottom: 12px;
}

.action-title {
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 8px;
}

.action-description {
    font-size: 14px;
    color: #6b7280;
    line-height: 1.5;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
    margin-bottom: 40px;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.stat-title {
    font-size: 14px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 36px;
    font-weight: 700;
    color: #1f2937;
}

.stat-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.stat-item {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid #f3f4f6;
}

.stat-item:last-child {
    border-bottom: none;
}

.stat-item-label {
    font-size: 14px;
    color: #6b7280;
}

.stat-item-value {
    font-size: 14px;
    font-weight: 600;
    color: #1f2937;
}

.recent-section {
    background: white;
    border-radius: 12px;
    padding: 32px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.section-title {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 24px;
    color: #1f2937;
}

.signatures-table {
    width: 100%;
    border-collapse: collapse;
}

.signatures-table th {
    text-align: left;
    padding: 12px;
    background: #f9fafb;
    color: #6b7280;
    font-weight: 600;
    font-size: 14px;
    border-bottom: 2px solid #e5e7eb;
}

.signatures-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
}

.signature-type-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.signature-type-typed {
    background: #dbeafe;
    color: #1e40af;
}

.signature-type-canvas {
    background: #fce7f3;
    color: #9f1239;
}

.signature-type-checkbox {
    background: #dcfce7;
    color: #166534;
}
</style>

<div class="legal-dashboard">
    <div class="dashboard-header">
        <h1 class="dashboard-title">⚖️ Legal Management</h1>
        <p class="dashboard-subtitle">Manage agreements, 1099 forms, and compliance documentation</p>
    </div>
    
    <div class="quick-actions">
        <a href="cases.php" class="action-card">
            <div class="action-icon">⚖️</div>
            <div class="action-title">Case Manager</div>
            <div class="action-description">Manage legal cases and evidence</div>
        </a>
        
        <a href="create-user.php" class="action-card">
            <div class="action-icon">👤</div>
            <div class="action-title">Create Legal User</div>
            <div class="action-description">Directly create legal team member account</div>
        </a>
        
        <a href="access-requests.php" class="action-card" style="position:relative;">
            <div class="action-icon">👥</div>
            <div class="action-title">Legal Team Requests</div>
            <div class="action-description">Review access requests from legal professionals</div>
            <?php if ($pendingCount > 0): ?>
                <span style="position:absolute;top:16px;right:16px;background:#dc2626;color:#fff;padding:4px 10px;border-radius:999px;font-size:0.75rem;font-weight:700;"><?php echo $pendingCount; ?> pending</span>
            <?php endif; ?>
        </a>
        
        <a href="agreements.php" class="action-card">
            <div class="action-icon">📝</div>
            <div class="action-title">Edit Agreements</div>
            <div class="action-description">Manage agreement templates and content</div>
        </a>
        
        <a href="1099-management.php" class="action-card">
            <div class="action-icon">💼</div>
            <div class="action-title">1099 Forms</div>
            <div class="action-description">Upload and manage tax forms</div>
        </a>
        
        <a href="signatures.php" class="action-card">
            <div class="action-icon">✍️</div>
            <div class="action-title">View Signatures</div>
            <div class="action-description">Review all signed agreements</div>
        </a>
    </div>
    
    <?php if ($pendingCount > 0): ?>
    <div style="margin:48px 0;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
            <h2 style="font-size:1.5rem;font-weight:700;color:#0f172a;margin:0;">⏳ Pending Legal Team Requests</h2>
            <a href="access-requests.php" style="padding:10px 20px;background:#1e3a8a;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:0.9rem;">View All Requests →</a>
        </div>
        
        <div style="display:grid;gap:16px;">
            <?php foreach ($pendingRequests as $request): ?>
            <div style="background:#fff;border:2px solid #fbbf24;border-radius:12px;padding:20px;box-shadow:0 8px 16px rgba(0,0,0,0.08);">
                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;">
                    <div>
                        <h3 style="margin:0 0 8px 0;font-size:1.2rem;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($request['requestor_name']); ?></h3>
                        <p style="margin:0;color:#64748b;font-size:0.9rem;">
                            <?php if ($request['law_firm']): ?>
                                <strong><?php echo htmlspecialchars($request['law_firm']); ?></strong> •
                            <?php endif; ?>
                            <?php echo htmlspecialchars($request['email']); ?>
                        </p>
                    </div>
                    <span style="background:#fef3c7;border:1px solid #fbbf24;color:#92400e;padding:6px 14px;border-radius:999px;font-size:0.8rem;font-weight:700;">PENDING</span>
                </div>
                
                <?php if ($request['case_title']): ?>
                <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:12px;">
                    <div style="font-size:0.85rem;color:#64748b;margin-bottom:4px;">Requesting access to:</div>
                    <div style="font-weight:600;color:#1e3a8a;">
                        <?php echo htmlspecialchars($request['case_number'] ?? ''); ?> — <?php echo htmlspecialchars($request['case_title']); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div style="font-size:0.85rem;color:#94a3b8;">
                        <strong>Submitted:</strong> <?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?>
                    </div>
                    <a href="access-requests.php" style="padding:8px 16px;background:#1e3a8a;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:0.85rem;">Review →</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Active Sales Agents</span>
            </div>
            <div class="stat-value"><?php echo number_format($totalAgents); ?></div>
        </div>
        
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Active 1099 Forms</span>
            </div>
            <div class="stat-value"><?php echo number_format($active1099Forms); ?></div>
        </div>
        
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Agreement Types</span>
            </div>
            <div class="stat-value"><?php echo count($agreementStats); ?></div>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card" style="grid-column: 1 / -1;">
            <h3 class="section-title">Agreement Signature Status</h3>
            <ul class="stat-list">
                <?php foreach ($agreementStats as $stat): ?>
                <li class="stat-item">
                    <span class="stat-item-label">
                        <?php echo htmlspecialchars($stat['title']); ?> 
                        <small style="color: #9ca3af;">(v<?php echo $stat['current_version']; ?>)</small>
                    </span>
                    <span class="stat-item-value">
                        <?php echo number_format($stat['signed_count']); ?> / <?php echo number_format($totalAgents); ?> signed
                        <?php if ($totalAgents > 0): ?>
                        (<?php echo round(($stat['signed_count'] / $totalAgents) * 100); ?>%)
                        <?php endif; ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    
    <div class="recent-section">
        <h2 class="section-title">Recent Signatures</h2>
        <?php if (empty($recentSignatures)): ?>
        <p style="color: #6b7280; text-align: center; padding: 40px;">No signatures recorded yet.</p>
        <?php else: ?>
        <table class="signatures-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>User</th>
                    <th>Agreement</th>
                    <th>Signature Type</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentSignatures as $sig): ?>
                <tr>
                    <td><?php echo date('M j, Y g:i A', strtotime($sig['signed_at'])); ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($sig['user_name']); ?></strong><br>
                        <small style="color: #6b7280;"><?php echo htmlspecialchars($sig['email']); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($sig['agreement_title']); ?></td>
                    <td>
                        <span class="signature-type-badge signature-type-<?php echo htmlspecialchars($sig['signature_type']); ?>">
                            <?php echo ucfirst($sig['signature_type']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
