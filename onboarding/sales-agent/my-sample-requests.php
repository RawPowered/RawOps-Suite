<?php
/**
 * My Sample Requests
 * 
 * Sales agent view of their sample requests
 */

$SEO = [
    'title' => 'My Sample Requests — Sales Agent — Product',
    'description' => 'View and track your sample requests',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';

start_session();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

$pdo = db();

// Get agent's sample requests
$stmt = $pdo->prepare("
    SELECT 
        sr.*,
        p.sku AS product_sku,
        p.name AS product_name,
        l.company_name AS lead_company,
        wa.business_name AS account_business,
        CONCAT(approver.first_name, ' ', approver.last_name) AS approved_by_name
    FROM sample_requests sr
    JOIN products p ON p.id = sr.sample_product_id
    LEFT JOIN crm_leads l ON l.id = sr.lead_id
    LEFT JOIN wholesale_accounts wa ON wa.id = sr.account_id
    LEFT JOIN users approver ON approver.id = sr.approved_by
    WHERE sr.agent_id = ?
    ORDER BY sr.requested_at DESC
    LIMIT 100
");
$stmt->execute([$uid]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by status
$pending = array_filter($requests, fn($r) => $r['status'] === 'pending');
$approved = array_filter($requests, fn($r) => $r['status'] === 'approved');
$shipped = array_filter($requests, fn($r) => in_array($r['status'], ['shipped', 'delivered']));
$rejected = array_filter($requests, fn($r) => $r['status'] === 'rejected');

?>

<div class="container">
    <?php 
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php';
    ?>
    
    <div class="page-header">
        <div>
            <h1 class="page-title">📦 My Sample Requests</h1>
            <div class="page-subtitle muted"><?php echo count($requests); ?> total requests</div>
        </div>
        <div class="header-actions">
            <a href="request-sample.php" class="btn primary">+ Request New Sample</a>
        </div>
    </div>

    <!-- Stats Summary -->
    <div class="stats-grid" style="margin-bottom:24px;">
        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo count($pending); ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✓</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo count($approved); ?></div>
                <div class="stat-label">Approved</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📫</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo count($shipped); ?></div>
                <div class="stat-label">Shipped</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✗</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo count($rejected); ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>
    </div>

    <!-- Requests List -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="margin:0;">All Requests</h3>
            <select id="statusFilter" class="input small" onchange="filterRequests()">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="shipped">Shipped</option>
                <option value="delivered">Delivered</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>
        
        <?php if (empty($requests)): ?>
            <div class="empty-state">
                <div style="font-size:64px;margin-bottom:16px;">📦</div>
                <h2>No Sample Requests Yet</h2>
                <p class="muted" style="margin-bottom:24px;">Start requesting samples for your leads and accounts</p>
                <a href="request-sample.php" class="btn primary">Request Your First Sample</a>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="requests-table">
                    <thead>
                        <tr>
                            <th>Requested</th>
                            <th>Sample</th>
                            <th>Recipient</th>
                            <th>Method</th>
                            <th>Destination</th>
                            <th>Status</th>
                            <th>Tracking</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                        <tr data-status="<?php echo $req['status']; ?>">
                            <td><?php echo date('M j, Y', strtotime($req['requested_at'])); ?></td>
                            <td>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($req['product_name']); ?></div>
                                <div class="muted small">Qty: <?php echo $req['quantity']; ?></div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($req['recipient_name']); ?></div>
                                <?php if ($req['lead_company'] || $req['account_business']): ?>
                                <div class="muted small"><?php echo htmlspecialchars($req['lead_company'] ?: $req['account_business']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $req['request_type'] === 'shipping' ? 'badge-info' : 'badge-success'; ?>">
                                    <?php echo $req['request_type'] === 'shipping' ? '📮 Ship' : '🚗 Drop-off'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($req['request_type'] === 'shipping'): ?>
                                    <div style="font-size:13px;">
                                        <?php echo htmlspecialchars($req['recipient_city'] . ', ' . $req['recipient_state']); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="muted">In-person</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $req['status']; ?>">
                                    <?php echo ucfirst($req['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($req['tracking_number']): ?>
                                    <a href="<?php echo getTrackingUrl($req['shipping_carrier'], $req['tracking_number']); ?>" 
                                       target="_blank" class="link-primary" style="font-size:12px;">
                                        <?php echo htmlspecialchars($req['tracking_number']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}

.stat-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
}

.stat-icon {
    font-size: 32px;
}

.stat-content .stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #0066FF;
}

.stat-content .stat-label {
    font-size: 13px;
    color: #6b7280;
    margin-top: 4px;
}

.requests-table {
    width: 100%;
    border-collapse: collapse;
}

.requests-table thead tr {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.requests-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    font-size: 13px;
}

.requests-table td {
    padding: 12px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
}

.requests-table tbody tr:hover {
    background: #f9fafb;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-approved {
    background: #dbeafe;
    color: #1e40af;
}

.status-shipped,
.status-delivered {
    background: #dcfce7;
    color: #059669;
}

.status-rejected {
    background: #fee2e2;
    color: #991b1b;
}

.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 600;
}

.badge-info {
    background: #dbeafe;
    color: #1e40af;
}

.badge-success {
    background: #dcfce7;
    color: #059669;
}

.empty-state {
    text-align: center;
    padding: 48px 24px;
    color: #9ca3af;
}

.link-primary {
    color: #0066FF;
    text-decoration: none;
}

.link-primary:hover {
    text-decoration: underline;
}
</style>

<script>
function filterRequests() {
    const filter = document.getElementById('statusFilter').value;
    const rows = document.querySelectorAll('.requests-table tbody tr');
    
    rows.forEach(row => {
        const status = row.getAttribute('data-status');
        if (!filter || status === filter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<?php
function getTrackingUrl($carrier, $trackingNumber) {
    $carrier = strtolower($carrier ?? '');
    $trackingNumber = urlencode($trackingNumber);
    
    switch ($carrier) {
        case 'usps':
            return "https://tools.usps.com/go/TrackConfirmAction?tLabels={$trackingNumber}";
        case 'ups':
            return "https://www.ups.com/track?tracknum={$trackingNumber}";
        case 'fedex':
            return "https://www.fedex.com/fedextrack/?trknbr={$trackingNumber}";
        default:
            return '#';
    }
}

require_once __DIR__ . '/../../required/footer.php';
?>





