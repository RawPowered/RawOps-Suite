<?php
$SEO = [
    'title' => 'Agreement Signatures — Legal Management',
    'description' => 'View and manage all signed agreements and NDAs.',
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

// Get filter parameters
$agreementType = $_GET['agreement_type'] ?? 'all';
$signatureType = $_GET['signature_type'] ?? 'all';
$search = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build query
$query = '
    SELECT 
        ua.id,
        ua.signed_at,
        ua.signature_type,
        ua.signature_data,
        ua.ip_address,
        ua.user_agent,
        u.id as user_id,
        CONCAT(u.first_name, " ", u.last_name) as user_name,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.status as user_status,
        at.id as template_id,
        at.title as agreement_title,
        at.agreement_type,
        at.version as agreement_version,
        GROUP_CONCAT(DISTINCT r.name) as user_roles
    FROM user_agreements ua
    JOIN users u ON u.id = ua.user_id
    JOIN agreement_templates at ON at.id = ua.agreement_template_id
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    WHERE 1=1
';

$params = [];

// Apply filters
if ($agreementType !== 'all') {
    $query .= ' AND at.agreement_type = ?';
    $params[] = $agreementType;
}

if ($signatureType !== 'all') {
    $query .= ' AND ua.signature_type = ?';
    $params[] = $signatureType;
}

if (!empty($search)) {
    $query .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR at.title LIKE ?)';
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if (!empty($dateFrom)) {
    $query .= ' AND DATE(ua.signed_at) >= ?';
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $query .= ' AND DATE(ua.signed_at) <= ?';
    $params[] = $dateTo;
}

$query .= ' GROUP BY ua.id, ua.signed_at, ua.signature_type, ua.signature_data, ua.ip_address, ua.user_agent, u.id, u.first_name, u.last_name, u.email, u.phone, u.status, at.id, at.title, at.agreement_type, at.version';
$query .= ' ORDER BY ua.signed_at DESC';
$query .= ' LIMIT 500';

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $signatures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $statsStmt = $pdo->query('
        SELECT 
            COUNT(DISTINCT ua.id) as total_signatures,
            COUNT(DISTINCT ua.user_id) as unique_signers,
            COUNT(DISTINCT CASE WHEN DATE(ua.signed_at) >= CURDATE() - INTERVAL 30 DAY THEN ua.id END) as last_30_days,
            COUNT(DISTINCT CASE WHEN ua.signature_type = "typed" THEN ua.id END) as typed_count,
            COUNT(DISTINCT CASE WHEN ua.signature_type = "canvas" THEN ua.id END) as canvas_count,
            COUNT(DISTINCT CASE WHEN ua.signature_type = "checkbox" THEN ua.id END) as checkbox_count
        FROM user_agreements ua
    ');
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get agreement type list for filter
    $typesStmt = $pdo->query('SELECT DISTINCT agreement_type FROM agreement_templates WHERE is_active = 1 ORDER BY agreement_type');
    $agreementTypes = $typesStmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Throwable $e) {
    error_log('Signatures page error: ' . $e->getMessage());
    $signatures = [];
    $stats = ['total_signatures' => 0, 'unique_signers' => 0, 'last_30_days' => 0, 'typed_count' => 0, 'canvas_count' => 0, 'checkbox_count' => 0];
    $agreementTypes = [];
}

require_once __DIR__ . '/../../required/header.php';
?>

<style>
.signatures-page {
    max-width: 1600px;
    margin: 0 auto;
    padding: 40px 20px 80px 20px;
}

.page-header {
    margin-bottom: 32px;
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 8px 0;
}

.page-subtitle {
    color: #64748b;
    font-size: 1.05rem;
}

.stats-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.stat-box {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.stat-label {
    font-size: 0.85rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
}

.filters-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-label {
    font-weight: 600;
    font-size: 0.9rem;
    color: #0f172a;
    margin-bottom: 6px;
}

.filter-input, .filter-select {
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.95rem;
}

.filter-actions {
    display: flex;
    gap: 12px;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}

.btn-primary {
    background: #1e3a8a;
    color: #fff;
}

.btn-primary:hover {
    background: #1e40af;
}

.btn-secondary {
    background: #f1f5f9;
    color: #0f172a;
    border: 1px solid #cbd5e1;
}

.btn-secondary:hover {
    background: #e2e8f0;
}

.signatures-table {
    width: 100%;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.signatures-table th {
    background: #f8fafc;
    padding: 16px;
    text-align: left;
    font-weight: 700;
    color: #0f172a;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.signatures-table td {
    padding: 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: top;
}

.signatures-table tr:hover {
    background: #f8fafc;
}

.user-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.user-name {
    font-weight: 600;
    color: #0f172a;
}

.user-email {
    font-size: 0.85rem;
    color: #64748b;
}

.user-roles {
    font-size: 0.75rem;
    color: #94a3b8;
}

.signature-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
}

.sig-typed {
    background: #dbeafe;
    color: #1e40af;
}

.sig-canvas {
    background: #fce7f3;
    color: #9f1239;
}

.sig-checkbox {
    background: #dcfce7;
    color: #166534;
}

.agreement-badge {
    background: #f1f5f9;
    color: #475569;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
}

.view-btn {
    padding: 6px 14px;
    background: #1e3a8a;
    color: #fff;
    text-decoration: none;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-block;
    transition: all 0.2s;
}

.view-btn:hover {
    background: #1e40af;
}

.no-results {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
}

.no-results-icon {
    font-size: 4rem;
    margin-bottom: 16px;
}
</style>

<div class="signatures-page">
    <div class="page-header">
        <h1 class="page-title">✍️ Agreement Signatures</h1>
        <p class="page-subtitle">View and manage all signed agreements, NDAs, and legal documents</p>
    </div>
    
    <div class="stats-bar">
        <div class="stat-box">
            <div class="stat-label">Total Signatures</div>
            <div class="stat-value"><?php echo number_format($stats['total_signatures']); ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Unique Signers</div>
            <div class="stat-value"><?php echo number_format($stats['unique_signers']); ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Last 30 Days</div>
            <div class="stat-value"><?php echo number_format($stats['last_30_days']); ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Typed</div>
            <div class="stat-value"><?php echo number_format($stats['typed_count']); ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Canvas</div>
            <div class="stat-value"><?php echo number_format($stats['canvas_count']); ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Checkbox</div>
            <div class="stat-value"><?php echo number_format($stats['checkbox_count']); ?></div>
        </div>
    </div>
    
    <form method="GET" class="filters-card">
        <div class="filters-grid">
            <div class="filter-group">
                <label class="filter-label">Agreement Type</label>
                <select name="agreement_type" class="filter-select">
                    <option value="all" <?php echo $agreementType === 'all' ? 'selected' : ''; ?>>All Types</option>
                    <?php foreach ($agreementTypes as $type): ?>
                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $agreementType === $type ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $type))); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">Signature Type</label>
                <select name="signature_type" class="filter-select">
                    <option value="all" <?php echo $signatureType === 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="typed" <?php echo $signatureType === 'typed' ? 'selected' : ''; ?>>Typed</option>
                    <option value="canvas" <?php echo $signatureType === 'canvas' ? 'selected' : ''; ?>>Canvas</option>
                    <option value="checkbox" <?php echo $signatureType === 'checkbox' ? 'selected' : ''; ?>>Checkbox</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, email, or agreement..." class="filter-input">
            </div>
            
            <div class="filter-group">
                <label class="filter-label">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="filter-input">
            </div>
            
            <div class="filter-group">
                <label class="filter-label">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" class="filter-input">
            </div>
        </div>
        
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="signatures.php" class="btn btn-secondary">Clear Filters</a>
        </div>
    </form>
    
    <?php if (empty($signatures)): ?>
    <div class="no-results">
        <div class="no-results-icon">📝</div>
        <h3>No Signatures Found</h3>
        <p>No signatures match your current filters.</p>
    </div>
    <?php else: ?>
    <table class="signatures-table">
        <thead>
            <tr>
                <th>Signed Date</th>
                <th>User</th>
                <th>Agreement</th>
                <th>Signature Type</th>
                <th>IP Address</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($signatures as $sig): ?>
            <tr>
                <td>
                    <strong><?php echo date('M j, Y', strtotime($sig['signed_at'])); ?></strong><br>
                    <small style="color: #94a3b8;"><?php echo date('g:i A', strtotime($sig['signed_at'])); ?></small>
                </td>
                <td>
                    <div class="user-info">
                        <span class="user-name"><?php echo htmlspecialchars($sig['user_name']); ?></span>
                        <span class="user-email"><?php echo htmlspecialchars($sig['email']); ?></span>
                        <?php if ($sig['user_roles']): ?>
                        <span class="user-roles"><?php echo htmlspecialchars($sig['user_roles']); ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <div><?php echo htmlspecialchars($sig['agreement_title']); ?></div>
                    <span class="agreement-badge"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $sig['agreement_type']))); ?></span>
                    <small style="display:block;margin-top:4px;color:#94a3b8;">v<?php echo htmlspecialchars($sig['agreement_version']); ?></small>
                </td>
                <td>
                    <span class="signature-badge sig-<?php echo htmlspecialchars($sig['signature_type']); ?>">
                        <?php echo htmlspecialchars(ucfirst($sig['signature_type'])); ?>
                    </span>
                    <?php if ($sig['signature_type'] === 'typed' && $sig['signature_data']): ?>
                    <div style="font-size:0.85rem;margin-top:6px;color:#64748b;font-style:italic;">
                        "<?php echo htmlspecialchars($sig['signature_data']); ?>"
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <small style="color:#64748b;font-family:monospace;"><?php echo htmlspecialchars($sig['ip_address'] ?? 'N/A'); ?></small>
                </td>
                <td>
                    <a href="<?php echo path_with_base('/admin/users.php?search=' . urlencode($sig['email'])); ?>" class="view-btn">
                        View User
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>




