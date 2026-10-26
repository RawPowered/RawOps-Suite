<?php
$SEO = [
    'title' => 'My Invoices — Sales Agent — Product',
    'description' => 'Track invoices you created and orders from your assigned stores with filters and metrics.',
    'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/config.php';

start_session();

$pdo = db();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

// Access control: sales_agent role or admin simulation
$isAdminSimulation = isset($_SESSION['admin_simulation']);
if (!$isAdminSimulation) {
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    try { $stmt->execute([$uid]); $hasRole = (bool)$stmt->fetch(); } catch (Throwable $e) { $hasRole = false; }
    if (!$hasRole && !is_training_complete($uid)) {
        redirect(path_with_base('/onboarding/trainee/index.php'));
    }
}

// Filters
$status = $_GET['status'] ?? 'any';
$source = $_GET['source'] ?? 'any'; // agent | shop | public | any
$from   = $_GET['from'] ?? date('Y-m-d', strtotime('-90 days'));
$to     = $_GET['to'] ?? date('Y-m-d');
$page   = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build WHERE
$where = ['(o.sales_agent_id = :agent_id OR wa.sales_agent_id = :agent_id_wa)', 'DATE(o.created_at) BETWEEN :from AND :to'];
$params = [':agent_id' => $uid, ':agent_id_wa' => $uid, ':from' => $from, ':to' => $to];

if ($status !== 'any') {
    $where[] = 'o.status = :status';
    $params[':status'] = $status;
}

if ($source !== 'any') {
    if ($source === 'agent') {
        $where[] = 'o.payment_method = "Agent Invoice"';
    } elseif ($source === 'public') {
        $where[] = 'o.payment_method = "Public Tool"';
    } elseif ($source === 'shop') {
        $where[] = 'o.payment_method NOT IN ("Agent Invoice", "Public Tool")';
    }
}

$whereSql = implode(' AND ', $where);

// Admin visibility: admin simulation or admin with admin-mode enabled
$isAdminView = $isAdminSimulation || (current_admin_id() && !empty($_SESSION['sales_acp_admin_mode']));

// Metrics
$metrics = [
    'count' => 0,
    'total' => 0.0,
    'commission' => 0.0,
    'agent_count' => 0,
    'shop_count' => 0,
    'public_count' => 0,
];

try {
    $sqlCount = "
        SELECT 
            COUNT(*) AS cnt,
            COALESCE(SUM(o.total),0) AS total_sum,
            COALESCE(SUM(CASE WHEN o.payment_method = 'Agent Invoice' THEN 1 ELSE 0 END),0) AS agent_cnt,
            COALESCE(SUM(CASE WHEN o.payment_method = 'Public Tool' THEN 1 ELSE 0 END),0) AS public_cnt,
            COALESCE(SUM(CASE WHEN o.payment_method NOT IN ('Agent Invoice','Public Tool') THEN 1 ELSE 0 END),0) AS shop_cnt
        FROM orders o
        LEFT JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
        WHERE $whereSql
    ";
    $stmt = $pdo->prepare($sqlCount);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if ($row) {
        $metrics['count'] = intval($row['cnt']);
        $metrics['total'] = (float)$row['total_sum'];
        $metrics['agent_count'] = intval($row['agent_cnt']);
        $metrics['public_count'] = intval($row['public_cnt']);
        $metrics['shop_count'] = intval($row['shop_cnt']);
    }

    // Agent commission total from commissions table (admin view only)
    $sqlCommission = "
        SELECT COALESCE(SUM(c.amount),0) AS commission_sum
        FROM commissions c 
        JOIN orders o ON o.id = c.order_id
        LEFT JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
        WHERE $whereSql AND c.user_id = :agent_id AND c.commission_type = 'sales_agent'
    ";
    if ($isAdminView) {
        $stmt = $pdo->prepare($sqlCommission);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $metrics['commission'] = (float)($row['commission_sum'] ?? 0);
    }
} catch (Throwable $e) {}

// Fetch rows
$orders = [];
try {
    $sql = "
        SELECT 
            o.id, o.order_number, o.created_at, o.status, o.total, o.subtotal, o.tax, o.shipping, o.payment_method, o.notes, o.commission_rate,
            wa.business_name,
            u.first_name, u.last_name, u.email
        FROM orders o
        LEFT JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
        LEFT JOIN users u ON u.id = o.user_id
        WHERE $whereSql
        ORDER BY o.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll();
} catch (Throwable $e) {}

// Fetch commissions per order for this agent in one query
$commissionByOrderId = [];
if (!empty($orders)) {
    $ids = array_map(fn($o) => intval($o['id']), $orders);
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("SELECT order_id, amount FROM commissions WHERE user_id = ? AND commission_type = 'sales_agent' AND order_id IN ($in)");
        $bind = array_merge([$uid], $ids);
        $stmt->execute($bind);
        while ($r = $stmt->fetch()) {
            $commissionByOrderId[intval($r['order_id'])] = (float)$r['amount'];
        }
    } catch (Throwable $e) {}
}
?>

<!-- Enhanced Paladin Theme Styling -->

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
    
    <!-- Enhanced Header -->
    <div class="sacp_dropoff-header" style="margin-bottom: 24px;">
        <div>
            <h1 class="sacp_page-title">
                <span>🧾</span> My Invoices
            </h1>
            <p class="sacp_page-subtitle">Invoices you created and orders from your assigned stores</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="index.php" class="btn secondary">← Back to CRM</a>
            <a href="invoice.php" class="btn primary" style="background: var(--acp-gold, #FFD700);">
                <span>➕</span> Create Invoice
            </a>
            <a href="../trainee/" class="btn secondary">Review Training</a>
        </div>
    </div>

    <!-- Enhanced Filter Bar -->
    <form method="get" class="sacp_filter-bar">
        <div class="sacp_filter-group">
            <label class="sacp_filter-label">Status</label>
            <select name="status" class="sacp_filter-select">
                <?php foreach (['any'=>'Any','submitted'=>'Submitted','pending'=>'Pending','processing'=>'Processing','completed'=>'Completed','cancelled'=>'Cancelled'] as $val=>$label): ?>
                <option value="<?php echo $val; ?>" <?php echo $status === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sacp_filter-group">
            <label class="sacp_filter-label">Source</label>
            <select name="source" class="sacp_filter-select">
                <?php foreach (['any'=>'Any','agent'=>'Agent Invoice','shop'=>'Shop','public'=>'Public Tool'] as $val=>$label): ?>
                <option value="<?php echo $val; ?>" <?php echo $source === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sacp_filter-group">
            <label class="sacp_filter-label">From Date</label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="sacp_filter-input">
        </div>
        <div class="sacp_filter-group">
            <label class="sacp_filter-label">To Date</label>
            <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" class="sacp_filter-input">
        </div>
        <div style="display: flex; align-items: end;">
            <button class="btn primary" style="background: var(--acp-info, #0369a1);" type="submit">
                <span>🔍</span> Apply Filters
            </button>
        </div>
    </form>

    <!-- Enhanced Metrics Grid -->
    <div class="sacp_metrics-grid">
        <div class="sacp_metric-card">
            <div class="sacp_metric-value"><?php echo number_format($metrics['count']); ?></div>
            <div class="sacp_metric-label">Total Invoices</div>
        </div>
        <div class="sacp_metric-card" style="background: linear-gradient(135deg, #dcfce7, #bbf7d0);">
            <div class="sacp_metric-value" style="color: var(--acp-success, #059669);">$<?php echo number_format($metrics['total'], 2); ?></div>
            <div class="sacp_metric-label">Total Volume</div>
        </div>
        <?php if ($isAdminView): ?>
        <div class="sacp_metric-card" style="background: linear-gradient(135deg, var(--acp-gold-light, #ffed4e), var(--acp-gold, #FFD700));">
            <div class="sacp_metric-value" style="color: var(--acp-carbon, #1f2937);">$<?php echo number_format($metrics['commission'], 2); ?></div>
            <div class="sacp_metric-label">💰 Agent Commission</div>
        </div>
        <?php endif; ?>
        <div class="sacp_metric-card" style="background: linear-gradient(135deg, #dbeafe, #bfdbfe);">
            <div class="sacp_metric-value" style="color: var(--acp-info, #0369a1);"><?php echo number_format($metrics['agent_count']); ?></div>
            <div class="sacp_metric-label">Agent Invoices</div>
        </div>
        <div class="sacp_metric-card">
            <div class="sacp_metric-value"><?php echo number_format($metrics['shop_count']); ?></div>
            <div class="sacp_metric-label">Shop Orders</div>
        </div>
        <div class="sacp_metric-card">
            <div class="sacp_metric-value"><?php echo number_format($metrics['public_count']); ?></div>
            <div class="sacp_metric-label">Public Tool</div>
        </div>
    </div>

    <!-- Enhanced Invoices Table -->
    <div class="sacp_card" style="margin-top: 24px;">
        <h3 style="margin:0 0 20px 0; display: flex; align-items: center; gap: 8px; color: var(--acp-carbon, #1f2937);">
            <span>📋</span> Invoice Records
        </h3>
        <div class="sacp_table-wrapper">
            <table class="sacp_paladin-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Account</th>
                        <th>Customer</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th style="text-align:right;">Total</th>
                        <?php if ($isAdminView): ?>
                        <th style="text-align:right;">Agent Commission</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                    <tr><td colspan="8" style="text-align:center; color:#6b7280; padding: 24px;">No invoices found for the selected filters.</td></tr>
                    <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                    <?php 
                        $src = ($o['payment_method'] === 'Agent Invoice') ? 'agent' : (($o['payment_method'] === 'Public Tool') ? 'public' : 'shop');
                        // Agent commission is either recorded or derived. Developer commission (if any) is not shown here.
                        $commission = $commissionByOrderId[intval($o['id'])] ?? ($o['subtotal'] * ((float)$o['commission_rate'] / 100));
                    ?>
                    <tr>
                        <td>
                            <strong style="color: var(--acp-carbon, #1f2937); font-size: 14px;">
                                <a href="invoice-detail.php?id=<?php echo intval($o['id']); ?>" class="link-plain" style="color: inherit; text-decoration:none;">
                                    <?php echo htmlspecialchars($o['order_number']); ?>
                                </a>
                            </strong>
                        </td>
                        <td style="color: var(--acp-graphite, #6b7280);"><?php echo date('M j, Y', strtotime($o['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($o['business_name'] ?: '—'); ?></td>
                        <td>
                            <div style="font-size: 13px;">
                                <?php echo htmlspecialchars(trim(($o['first_name'] ?: '') . ' ' . ($o['last_name'] ?: '')) ?: ($o['email'] ?: '')); ?>
                            </div>
                        </td>
                        <td>
                            <span class="chip <?php 
                                echo $src === 'agent' ? 'chip-success' : 
                                     ($src === 'public' ? 'chip-danger' : 'chip-info'); 
                            ?>">
                                <?php echo ucfirst($src); ?>
                            </span>
                        </td>
                        <td>
                            <span class="chip chip-status"><?php echo ucwords($o['status']); ?></span>
                        </td>
                        <td style="text-align:right; font-weight: 600;">$<?php echo number_format((float)$o['total'], 2); ?></td>
                        <?php if ($isAdminView): ?>
                        <td style="text-align:right; color: var(--acp-success, #059669); font-weight: 700;">
                            $<?php echo number_format((float)$commission, 2); ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($metrics['count'] > $perPage): $totalPages = ceil($metrics['count'] / $perPage); ?>
        <div style="display:flex; justify-content:center; align-items:center; gap:8px; margin-top:20px; padding-top:20px; border-top:1px solid var(--acp-platinum, #e5e7eb);">
            <?php if ($page > 1): ?>
                <?php $query = $_GET; $query['page'] = $page - 1; $href = '?' . http_build_query($query); ?>
                <a href="<?php echo $href; ?>" class="btn small secondary">←</a>
            <?php endif; ?>
            
            <?php 
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            ?>
            
            <?php if ($startPage > 1): ?>
                <?php $query = $_GET; $query['page'] = 1; $href = '?' . http_build_query($query); ?>
                <a href="<?php echo $href; ?>" class="btn small secondary">1</a>
                <?php if ($startPage > 2): ?>
                    <span style="color: var(--acp-graphite, #6b7280);">...</span>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <?php $query = $_GET; $query['page'] = $i; $href = '?' . http_build_query($query); ?>
                <a href="<?php echo $href; ?>" class="btn small <?php echo $i === $page ? '' : 'secondary'; ?>" 
                   style="<?php echo $i === $page ? 'background: var(--acp-gold, #FFD700); color: var(--acp-carbon, #1f2937);' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <span style="color: var(--acp-graphite, #6b7280);">...</span>
                <?php endif; ?>
                <?php $query = $_GET; $query['page'] = $totalPages; $href = '?' . http_build_query($query); ?>
                <a href="<?php echo $href; ?>" class="btn small secondary"><?php echo $totalPages; ?></a>
            <?php endif; ?>
            
            <?php if ($page < $totalPages): ?>
                <?php $query = $_GET; $query['page'] = $page + 1; $href = '?' . http_build_query($query); ?>
                <a href="<?php echo $href; ?>" class="btn small secondary">→</a>
            <?php endif; ?>
            
            <span style="margin-left:12px; color: var(--acp-graphite, #6b7280); font-size:13px;">
                Page <?php echo $page; ?> of <?php echo $totalPages; ?> • <?php echo $metrics['count']; ?> total records
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>


