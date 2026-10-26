<?php
$SEO = [
    'title' => 'Wholesale Account Management — Admin',
    'description' => 'Approve and manage wholesale account applications.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect('/admin/');
require_once __DIR__ . '/../required/admin/wholesale-permissions.php';

$pdo = db();
$message = '';
$error = '';

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $account_id = intval($_POST['account_id'] ?? 0);
    
    if ($account_id > 0) {
        try {
            if ($action === 'approve') {
                $sales_agent_id = intval($_POST['sales_agent_id'] ?? 0);
                
                // Update approval and assign sales agent
                $stmt = $pdo->prepare('
                    UPDATE wholesale_accounts 
                    SET approved = 1, sales_agent_id = ?, updated_at = NOW() 
                    WHERE id = ?
                ');
                $stmt->execute([$sales_agent_id ?: null, $account_id]);
                
                // Get user email for notification
                $stmt = $pdo->prepare('
                    SELECT u.email, u.first_name, wa.business_name 
                    FROM wholesale_accounts wa 
                    JOIN users u ON u.id = wa.user_id 
                    WHERE wa.id = ?
                ');
                $stmt->execute([$account_id]);
                $account = $stmt->fetch();
                
                $message = "Approved wholesale account for {$account['business_name']}";
                if ($sales_agent_id) {
                    $message .= " and assigned sales agent";
                }
                
                // TODO: Send approval email to $account['email']
                
            } elseif ($action === 'assign_agent') {
                $sales_agent_id = intval($_POST['sales_agent_id'] ?? 0);
                
                $stmt = $pdo->prepare('
                    UPDATE wholesale_accounts 
                    SET sales_agent_id = ?, updated_at = NOW() 
                    WHERE id = ?
                ');
                $stmt->execute([$sales_agent_id ?: null, $account_id]);
                
                $message = "Sales agent assigned successfully";
                
            } elseif ($action === 'reject') {
                $stmt = $pdo->prepare('UPDATE wholesale_accounts SET approved = 0, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$account_id]);
                $message = "Rejected wholesale account application";
                
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare('DELETE FROM wholesale_accounts WHERE id = ?');
                $stmt->execute([$account_id]);
                $message = "Deleted wholesale account";
            }
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'pending';
$search = $_GET['search'] ?? '';

// Get available sales agents (including those with admin override)
$stmt = $pdo->query("
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.email,
           CASE 
               WHEN tor.id IS NOT NULL THEN 'admin_override'
               WHEN COUNT(DISTINCT tp.module_id) >= (SELECT COUNT(*) FROM training_modules WHERE required = 1) THEN 'completed'
               ELSE 'in_training'
           END as training_status,
           COUNT(DISTINCT wa.id) as account_count
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    LEFT JOIN training_progress tp ON tp.user_id = u.id AND tp.status = 'completed'
    LEFT JOIN training_overrides tor ON tor.user_id = u.id
    LEFT JOIN wholesale_accounts wa ON wa.sales_agent_id = u.id
    WHERE r.name = 'sales_agent' OR tor.id IS NOT NULL
    GROUP BY u.id
    HAVING training_status IN ('completed', 'admin_override')
    ORDER BY u.first_name, u.last_name
");
$availableAgents = $stmt->fetchAll();

// Build query based on filter
$query = '
    SELECT wa.*, u.email, u.first_name, u.last_name, u.phone as user_phone,
           sa.first_name as agent_first_name, sa.last_name as agent_last_name,
           (SELECT COUNT(*) FROM orders WHERE wholesale_account_id = wa.id) as order_count,
           (SELECT SUM(total) FROM orders WHERE wholesale_account_id = wa.id) as total_revenue
    FROM wholesale_accounts wa
    JOIN users u ON u.id = wa.user_id
    LEFT JOIN users sa ON sa.id = wa.sales_agent_id
    WHERE 1=1
';

$params = [];

if ($filter === 'pending') {
    $query .= ' AND wa.approved = 0';
} elseif ($filter === 'approved') {
    $query .= ' AND wa.approved = 1';
}

if (!empty($search)) {
    $query .= ' AND (wa.business_name LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

$query .= ' ORDER BY wa.created_at DESC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$accounts = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'total_revenue' => 0
];

try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM wholesale_accounts');
    $stats['total'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM wholesale_accounts WHERE approved = 0');
    $stats['pending'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM wholesale_accounts WHERE approved = 1');
    $stats['approved'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query('SELECT SUM(o.total) FROM orders o JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id');
    $stats['total_revenue'] = floatval($stmt->fetchColumn());
} catch (Exception $e) {
    // Handle error
}
?>

<style>
.admin-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.stat-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.stat-value {
    font-size: 32px;
    font-weight: bold;
    margin: 8px 0;
}
.stat-label {
    color: #6b7280;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.filter-bar {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 24px;
    display: flex;
    gap: 16px;
    align-items: center;
    flex-wrap: wrap;
}
.filter-tabs {
    display: flex;
    gap: 8px;
}
.filter-tab {
    padding: 8px 16px;
    background: #f3f4f6;
    border: none;
    border-radius: 8px;
    color: #6b7280;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}
.filter-tab:hover {
    background: #e5e7eb;
}
.filter-tab.active {
    background: #3b82f6;
    color: white;
}
.accounts-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.accounts-table table {
    width: 100%;
    border-collapse: collapse;
}
.accounts-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
    font-size: 14px;
}
.accounts-table td {
    padding: 16px 12px;
    border-bottom: 1px solid #f3f4f6;
}
.accounts-table tr:hover {
    background: #f9fafb;
}
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.status-approved {
    background: #dcfce7;
    color: #14532d;
}
.status-pending {
    background: #fef3c7;
    color: #92400e;
}
.action-buttons {
    display: flex;
    gap: 8px;
}
.btn-small {
    padding: 6px 12px;
    font-size: 14px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-approve {
    background: #10b981;
    color: white;
}
.btn-reject {
    background: #ef4444;
    color: white;
}
.btn-view {
    background: #3b82f6;
    color: white;
}
.btn-small:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.search-box {
    flex: 1;
    max-width: 400px;
}
.search-box input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}
</style>

<div class="admin-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">Wholesale Account Management</h1>
        <p style="font-size: 18px; opacity: 0.9;">Review and approve wholesale applications</p>
    </div>
</div>

<?php if (($_GET['view'] ?? '') === 'detail' && ($accountId = intval($_GET['id'] ?? 0))):
    // Detail view with payment methods
    try {
        $stmt = $pdo->prepare('SELECT wa.*, u.first_name, u.last_name, u.email FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id WHERE wa.id = ?');
        $stmt->execute([$accountId]);
        $acc = $stmt->fetch();
        if ($acc):
            $viewerId = current_user_id();
            $canViewFull = user_can_view_full_payment($viewerId, $accountId);
            $canEditFull = user_can_edit_full_payment($viewerId, $accountId);
            $pm = $pdo->prepare('SELECT * FROM payment_methods WHERE wholesale_account_id = ? ORDER BY is_default DESC, created_at DESC');
            $pm->execute([$accountId]);
            $methods = $pm->fetchAll();
    ?>
    <div class="container" style="margin-top:24px;">
        <div class="card" style="margin-bottom:16px;">
            <h2 style="margin:0 0 12px 0;">Account: <?php echo htmlspecialchars($acc['business_name']); ?></h2>
            <div style="font-size:14px; color:#6b7280;">Contact: <?php echo htmlspecialchars($acc['first_name'] . ' ' . $acc['last_name']); ?> — <?php echo htmlspecialchars($acc['email']); ?></div>
        </div>

        <div class="card">
            <h3 style="margin:0 0 12px 0;">Payment Methods</h3>
            <?php if (empty($methods)): ?>
                <p style="color:#6b7280;">No methods on file.</p>
            <?php else: ?>
                <div style="display:grid; gap:12px;">
                <?php foreach ($methods as $m): ?>
                    <div style="border:1px solid #e5e7eb; border-radius:8px; padding:12px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <div style="font-weight:600;">
                                    <?php if ($m['type'] === 'credit_card'): ?>
                                        <?php if ($canViewFull && !empty($m['card_enc'])): ?>
                                            <?php $full = secure_decrypt($m['card_enc']); ?>
                                            Card: <?php echo htmlspecialchars($m['card_brand'] ?: 'Card'); ?> — <?php echo htmlspecialchars($full ?: ('•••• ' . ($m['last_four'] ?? ''))); ?>
                                        <?php else: ?>
                                            Card: <?php echo htmlspecialchars($m['card_brand'] ?: 'Card'); ?> — •••• <?php echo htmlspecialchars($m['last_four'] ?? ''); ?>
                                        <?php endif; ?>
                                        <?php if ($m['exp_month'] && $m['exp_year']): ?>
                                            <span style="color:#6b7280; font-size:12px;">exp <?php echo htmlspecialchars($m['exp_month'] . '/' . substr($m['exp_year'], -2)); ?></span>
                                        <?php endif; ?>
                                    <?php elseif ($m['type'] === 'ach'): ?>
                                        Bank: <?php echo htmlspecialchars($m['bank_name'] ?: 'ACH'); ?> — •••• <?php echo htmlspecialchars($m['last_four'] ?? ''); ?>
                                    <?php else: ?>
                                        <?php echo ucwords(str_replace('_',' ', $m['type'])); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($m['is_default']): ?><div style="font-size:12px; color:#059669;">Default</div><?php endif; ?>
                            </div>
                            <div style="display:flex; gap:8px;">
                                <?php if ($canEditFull): ?>
                                    <form method="post" action="<?php echo path_with_base('/admin/tools-update-payment.php'); ?>" style="display:inline;">
                                        <input type="hidden" name="payment_method_id" value="<?php echo $m['id']; ?>">
                                        <button class="btn small secondary" type="submit" name="action" value="set_default">Make Default</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($canViewFull && $m['card_holder_name']): ?>
                            <div style="font-size:12px; color:#6b7280; margin-top:6px;">Name: <?php echo htmlspecialchars($m['card_holder_name']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($canEditFull): ?>
        <div class="card" style="margin-top:16px;">
            <h3 style="margin:0 0 12px 0;">Add Credit Card</h3>
            <form method="post" action="<?php echo path_with_base('/admin/tools-add-card.php'); ?>" onsubmit="return confirm('Store card securely? CVV is never stored.');">
                <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <label>Cardholder Name
                        <input type="text" name="card_holder_name" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
                    </label>
                    <label>Brand
                        <input type="text" name="card_brand" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" placeholder="Visa/Mastercard">
                    </label>
                    <label>Card Number (PAN)
                        <input type="text" name="card_number" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;" autocomplete="off">
                    </label>
                    <div style="display:flex; gap:12px;">
                        <label>Exp Month
                            <input type="text" name="exp_month" maxlength="2" style="width:80px; padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
                        </label>
                        <label>Exp Year
                            <input type="text" name="exp_year" maxlength="4" style="width:100px; padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
                        </label>
                    </div>
                </div>
                <div style="margin-top:12px; display:flex; gap:8px;">
                    <button type="submit" class="btn">Save Card</button>
                    <a class="btn secondary" href="wholesale-accounts.php">Back</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; } catch (Throwable $e) { ?>
        <div class="container"><div class="card" style="background:#fee2e2; color:#991b1b;">Error loading account.</div></div>
    <?php } endif; ?>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
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
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Accounts</div>
            <div class="stat-value"><?php echo $stats['total']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pending Approval</div>
            <div class="stat-value" style="color: #f59e0b;"><?php echo $stats['pending']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Approved</div>
            <div class="stat-value" style="color: #10b981;"><?php echo $stats['approved']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value" style="font-size: 24px;">$<?php echo number_format($stats['total_revenue'], 2); ?></div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="filter-tabs">
            <a href="?filter=pending" class="filter-tab <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                Pending (<?php echo $stats['pending']; ?>)
            </a>
            <a href="?filter=approved" class="filter-tab <?php echo $filter === 'approved' ? 'active' : ''; ?>">
                Approved (<?php echo $stats['approved']; ?>)
            </a>
            <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                All (<?php echo $stats['total']; ?>)
            </a>
        </div>
        
        <div class="search-box">
            <form method="get" style="display: flex; gap: 8px;">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                <input type="text" name="search" placeholder="Search by business name, email, or name..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn" style="padding: 8px 16px;">Search</button>
            </form>
        </div>
    </div>
    
    <!-- Accounts Table -->
    <div class="accounts-table">
        <?php if (empty($accounts)): ?>
            <div style="padding: 60px; text-align: center;">
                <p style="color: #6b7280;">No wholesale accounts found</p>
            </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Contact</th>
                    <th>Sales Agent</th>
                    <th>Location</th>
                    <th>Orders</th>
                    <th>Revenue</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $account): ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($account['business_name']); ?></strong>
                        <?php if ($account['website']): ?>
                            <br><small style="color: #6b7280;"><?php echo htmlspecialchars($account['website']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?>
                        <br><small style="color: #6b7280;"><?php echo htmlspecialchars($account['email']); ?></small>
                        <?php if ($account['phone'] || $account['user_phone']): ?>
                            <br><small style="color: #6b7280;"><?php echo htmlspecialchars($account['phone'] ?: $account['user_phone']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($account['sales_agent_id']): ?>
                            <strong><?php echo htmlspecialchars($account['agent_first_name'] . ' ' . $account['agent_last_name']); ?></strong>
                        <?php else: ?>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="action" value="assign_agent">
                                <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                <select name="sales_agent_id" style="padding: 4px; font-size: 13px; border: 1px solid #e5e7eb; border-radius: 4px;">
                                    <option value="">Select Agent...</option>
                                    <?php foreach ($availableAgents as $agent): ?>
                                        <option value="<?php echo $agent['id']; ?>">
                                            <?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?>
                                            (<?php echo $agent['account_count']; ?> accounts)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn-small" style="background: #3b82f6; color: white; padding: 4px 8px; font-size: 12px;">Assign</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($account['city'] && $account['state_region']): ?>
                            <?php echo htmlspecialchars($account['city'] . ', ' . $account['state_region']); ?>
                            <?php if ($account['country']): ?>
                                <br><small style="color: #6b7280;"><?php echo htmlspecialchars($account['country']); ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: #9ca3af;">Not provided</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo intval($account['order_count']); ?> orders
                    </td>
                    <td>
                        $<?php echo number_format(floatval($account['total_revenue']), 2); ?>
                    </td>
                    <td>
                        <?php if ($account['approved']): ?>
                            <span class="status-badge status-approved">Approved</span>
                        <?php else: ?>
                            <span class="status-badge status-pending">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <?php if (!$account['approved']): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                    <button type="submit" class="btn-small btn-approve">Approve</button>
                                </form>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                    <button type="submit" class="btn-small btn-reject">Reject</button>
                                </form>
                            <?php else: ?>
                <a class="btn-small btn-view" href="wholesale-accounts.php?view=detail&id=<?php echo $account['id']; ?>#payment">View Details</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
