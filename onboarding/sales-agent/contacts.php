<?php
$SEO = [
    'title' => 'Contacts — Sales Agent — Product',
    'description' => 'Manage contacts associated with your assigned accounts.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/header.php';
start_session();
$pdo = db();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

// Admin simulation bypasses role restrictions; agents see only their own
$isAdminSimulation = isset($_SESSION['admin_simulation']);
$adminMode = !empty($_SESSION['sales_acp_admin_mode']);

// Filters
$q = trim($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per = 25; $off = ($page - 1) * $per;

// Build query restricted to agent's accounts
$where = '1=1';
$params = [];
if (!$isAdminSimulation && !(current_admin_id() && $adminMode)) {
    $where .= ' AND wa.sales_agent_id = :uid';
    $params[':uid'] = $uid;
}
if ($q !== '') {
    $where .= ' AND (c.first_name LIKE :q OR c.last_name LIKE :q OR c.email LIKE :q OR c.phone LIKE :q)';
    $params[':q'] = "%$q%";
}

$sql = "SELECT c.*, wa.business_name
        FROM account_contacts ac
        JOIN crm_contacts c ON c.id = ac.contact_id
        JOIN wholesale_accounts wa ON wa.id = ac.wholesale_account_id
        WHERE $where
        ORDER BY c.last_name, c.first_name
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $per, PDO::PARAM_INT);
$stmt->bindValue(':offset', $off, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

// Optional detail view
$contactId = intval($_GET['id'] ?? 0);
$detail = null; $accounts = [];
if ($contactId > 0) {
    try {
        $w = $where . ' AND c.id = :cid';
        $ps = $params; $ps[':cid'] = $contactId;
        $st = $pdo->prepare("SELECT c.*, wa.business_name FROM account_contacts ac JOIN crm_contacts c ON c.id = ac.contact_id JOIN wholesale_accounts wa ON wa.id = ac.wholesale_account_id WHERE $w LIMIT 1");
        foreach ($ps as $k=>$v) $st->bindValue($k,$v);
        $st->execute();
        $detail = $st->fetch();
        if ($detail) {
            $ast = $pdo->prepare('SELECT wa.* FROM account_contacts ac JOIN wholesale_accounts wa ON wa.id = ac.wholesale_account_id WHERE ac.contact_id = ? ORDER BY wa.business_name');
            $ast->execute([$contactId]);
            $accounts = $ast->fetchAll();
        }
    } catch (Throwable $e) {}
}

// Calculate stats for contacts
$activeCount = 0;
foreach($rows as $r) { 
    $ast = $pdo->prepare('SELECT COUNT(*) FROM account_contacts ac JOIN wholesale_accounts wa ON wa.id = ac.wholesale_account_id WHERE ac.contact_id = ? AND wa.approved = 1');
    $ast->execute([$r['id']]);
    if ($ast->fetchColumn() > 0) $activeCount++;
}
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

    <!-- Enhanced Search and Filter Bar -->
    <div class="sacp_card" style="margin-bottom:20px;">
        <form method="get" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <div style="flex: 1; min-width:250px;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" 
                       placeholder="🔍 Search contacts by name, email or phone..." 
                       class="paladin-input" 
                       style="width:100%; padding:12px; border:2px solid var(--acp-platinum); border-radius:12px; font-size:14px;">
            </div>
            <button class="btn primary" type="submit" style="padding:12px 24px;">
                <span>🔍</span> Search
            </button>
            <?php if ($q): ?>
                <a href="contacts.php" class="btn secondary" style="padding:12px 20px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Contact Stats Bar -->
    <div class="sacp_stats-grid" style="margin-bottom:24px;">
        <div class="sacp_stat-card">
            <div class="sacp_stat-value"><?php echo count($rows); ?></div>
            <div class="sacp_stat-label">Total Contacts</div>
        </div>
        <div class="sacp_stat-card">
            <div class="sacp_stat-value"><?php echo $activeCount; ?></div>
            <div class="sacp_stat-label">Active Contacts</div>
        </div>
        <div class="sacp_stat-card">
            <div class="sacp_stat-value"><?php echo count($rows) - $activeCount; ?></div>
            <div class="sacp_stat-label">Pending Contacts</div>
        </div>
    </div>

    <?php if ($detail): ?>
    <!-- Enhanced Contact Detail View -->
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
        <div>
            <h1 style="margin:0; font-size:28px; color:var(--acp-carbon); display:flex; align-items:center; gap:12px;">
                <span style="font-size:32px;">👤</span>
                <?php echo htmlspecialchars($detail['first_name'].' '.$detail['last_name']); ?>
            </h1>
            <div style="color:var(--acp-graphite); margin-top:4px;">
                <span class="chip chip-info">Contact</span>
                <?php if ($detail['business_name']): ?>
                    <span style="margin-left:8px;">at <?php echo htmlspecialchars($detail['business_name']); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a class="btn secondary" href="contacts.php">← Back to Contacts</a>
            <a class="btn gold" href="mailto:<?php echo htmlspecialchars($detail['email']); ?>">
                <span>📧</span> Email Contact
            </a>
            <?php if ($detail['phone']): ?>
                <a class="btn primary" href="tel:<?php echo htmlspecialchars($detail['phone']); ?>">
                    <span>📞</span> Call Contact
                </a>
            <?php endif; ?>
        </div>
    </div>
    
    <div style="display:grid; grid-template-columns:2fr 1fr; gap:24px;" class="responsive-grid">
        <!-- Contact Information Card -->
        <div class="sacp_card">
            <h3 style="margin:0 0 20px 0; display:flex; align-items:center; gap:8px;">
                <span>📋</span> Contact Information
            </h3>
            <div class="kv" style="display:grid; grid-template-columns:140px 1fr; gap:12px; font-size:15px;">
                <span style="color:var(--acp-graphite); font-weight:600;">Full Name</span>
                <span style="color:var(--acp-carbon);"><?php echo htmlspecialchars($detail['first_name'].' '.$detail['last_name']); ?></span>
                
                <span style="color:var(--acp-graphite); font-weight:600;">Email Address</span>
                <span style="color:var(--acp-carbon);">
                    <a href="mailto:<?php echo htmlspecialchars($detail['email']); ?>" style="color:var(--acp-blue); text-decoration:none;">
                        <?php echo htmlspecialchars($detail['email']); ?>
                    </a>
                </span>
                
                <span style="color:var(--acp-graphite); font-weight:600;">Phone Number</span>
                <span style="color:var(--acp-carbon);">
                    <?php if ($detail['phone']): ?>
                        <a href="tel:<?php echo htmlspecialchars($detail['phone']); ?>" style="color:var(--acp-blue); text-decoration:none;">
                            <?php echo htmlspecialchars($detail['phone']); ?>
                        </a>
                    <?php else: ?>
                        <span style="color:var(--acp-silver);">Not provided</span>
                    <?php endif; ?>
                </span>
                
                <span style="color:var(--acp-graphite); font-weight:600;">Added On</span>
                <span style="color:var(--acp-carbon);"><?php echo date('M j, Y', strtotime($detail['created_at'] ?? 'now')); ?></span>
            </div>
        </div>
        
        <!-- Quick Actions Card -->
        <div class="sacp_card" style="background:linear-gradient(135deg, #f0f9ff, #e0f2fe);">
            <h3 style="margin:0 0 16px 0; display:flex; align-items:center; gap:8px; color:var(--acp-blue-deep);">
                <span>⚡</span> Quick Actions
            </h3>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <a href="calls.php?contact_id=<?php echo $detail['id']; ?>&action=add" class="btn" style="background:var(--acp-danger); text-align:center; width:100%;">
                    <span>📞</span> Log Call
                </a>
                <a href="tasks.php?contact_id=<?php echo $detail['id']; ?>&action=add" class="btn" style="background:var(--acp-warning); text-align:center; width:100%;">
                    <span>✅</span> Create Task
                </a>
                <a href="calls.php?contact_id=<?php echo $detail['id']; ?>&action=add&prefill=note" class="btn success" style="text-align:center; width:100%;">
                    <span>📝</span> Add Note
                </a>
            </div>
        </div>
    </div>
    
    <!-- Linked Accounts -->
    <div class="sacp_card" style="margin-top:24px;">
        <h3 style="margin:0 0 20px 0; display:flex; align-items:center; gap:8px;">
            <span>🏢</span> Linked Accounts
        </h3>
        <?php if (empty($accounts)): ?>
            <div style="text-align:center; padding:32px; color:var(--acp-silver);">
                <div style="font-size:48px; margin-bottom:12px;">🔗</div>
                <p>No accounts linked to this contact yet.</p>
            </div>
        <?php else: ?>
            <div style="display:grid; gap:12px;">
                <?php foreach ($accounts as $a): ?>
                <div class="lead-card" style="padding:16px; border-radius:12px; border:1px solid var(--acp-platinum); background:var(--acp-white); transition:all 250ms ease;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-weight:600; font-size:16px; margin-bottom:4px;">
                                <a href="accounts.php?id=<?php echo intval($a['id']); ?>" style="color:var(--acp-carbon); text-decoration:none;">
                                    <?php echo htmlspecialchars($a['business_name']); ?>
                                </a>
                            </div>
                            <div style="font-size:13px; color:var(--acp-graphite);">
                                Account #<?php echo str_pad($a['id'], 5, '0', STR_PAD_LEFT); ?>
                            </div>
                        </div>
                        <div>
                            <?php echo $a['approved'] ? 
                                '<span class="chip chip-success">Approved</span>' : 
                                '<span class="chip chip-status">Pending</span>'; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <!-- Enhanced Contact List -->
    <?php if (empty($rows)): ?>
        <div class="sacp_card" style="text-align:center; padding:48px;">
            <div style="font-size:64px; margin-bottom:16px;">👥</div>
            <h2 style="color:var(--acp-carbon); margin-bottom:8px;">No Contacts Found</h2>
            <p style="color:var(--acp-graphite); margin-bottom:24px;">
                <?php if ($q): ?>
                    No contacts match your search criteria.
                <?php else: ?>
                    Start building your contact network by adding new contacts.
                <?php endif; ?>
            </p>
            <?php if ($q): ?>
                <a href="contacts.php" class="btn primary">Clear Search</a>
            <?php else: ?>
                <a href="leads.php" class="btn primary">View Leads to Convert</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Toggle View Buttons -->
        <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:16px;">
            <button onclick="toggleView('grid')" id="grid-view-btn" class="btn secondary small" style="padding:8px 12px;">
                <span>⊞</span> Grid View
            </button>
            <button onclick="toggleView('list')" id="list-view-btn" class="btn primary small" style="padding:8px 12px;">
                <span>☰</span> List View
            </button>
        </div>
        
        <!-- Grid View (Hidden by default) -->
        <div id="contacts-grid" style="display:none; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:16px; margin-bottom:24px;">
            <?php foreach ($rows as $c): ?>
            <div class="sacp_card" style="position:relative; transition:all 250ms ease;">
                <div style="display:flex; align-items:center; gap:16px; margin-bottom:16px;">
                    <div style="width:48px; height:48px; border-radius:50%; background:linear-gradient(135deg, var(--acp-gold), var(--acp-gold-light)); display:flex; align-items:center; justify-content:center; font-weight:700; color:white; font-size:18px;">
                        <?php echo strtoupper(substr($c['first_name'] ?? '', 0, 1) . substr($c['last_name'] ?? '', 0, 1)); ?>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:600; font-size:16px; color:var(--acp-carbon);">
                            <?php echo htmlspecialchars($c['first_name'].' '.$c['last_name']); ?>
                        </div>
                        <div style="font-size:13px; color:var(--acp-graphite);">
                            <?php echo htmlspecialchars($c['business_name']); ?>
                        </div>
                    </div>
                </div>
                
                <div style="border-top:1px solid var(--acp-platinum); padding-top:12px; margin-bottom:12px;">
                    <div style="display:flex; align-items:center; gap:6px; margin-bottom:8px;">
                        <span style="color:var(--acp-silver);">📧</span>
                        <a href="mailto:<?php echo htmlspecialchars($c['email']); ?>" style="color:var(--acp-blue); text-decoration:none; font-size:13px;">
                            <?php echo htmlspecialchars($c['email']); ?>
                        </a>
                    </div>
                    <?php if ($c['phone']): ?>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <span style="color:var(--acp-silver);">📞</span>
                        <a href="tel:<?php echo htmlspecialchars($c['phone']); ?>" style="color:var(--acp-blue); text-decoration:none; font-size:13px;">
                            <?php echo htmlspecialchars($c['phone']); ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div style="display:flex; gap:8px;">
                    <a class="btn secondary small" href="contacts.php?id=<?php echo intval($c['id']); ?>" style="flex:1; text-align:center;">
                        <span>👁️</span> View
                    </a>
                    <a class="btn small" href="mailto:<?php echo htmlspecialchars($c['email']); ?>" style="flex:1; text-align:center; background:var(--acp-success);">
                        <span>📧</span> Email
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- List View (Default) -->
        <div id="contacts-list" class="sacp_card">
            <div class="table-wrapper">
                <table class="paladin-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Contact Name</th>
                            <th>Email</th>
                            <th class="hide-mobile">Phone</th>
                            <th>Account</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $c): ?>
                        <tr>
                            <td style="font-weight:600; color:var(--acp-carbon);">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <div style="width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg, var(--acp-blue-deep), var(--acp-blue)); display:flex; align-items:center; justify-content:center; font-weight:600; color:white; font-size:12px;">
                                        <?php echo strtoupper(substr($c['first_name'] ?? '', 0, 1) . substr($c['last_name'] ?? '', 0, 1)); ?>
                                    </div>
                                    <?php echo htmlspecialchars($c['first_name'].' '.$c['last_name']); ?>
                                </div>
                            </td>
                            <td>
                                <a href="mailto:<?php echo htmlspecialchars($c['email']); ?>" style="color:var(--acp-blue); text-decoration:none;">
                                    <?php echo htmlspecialchars($c['email']); ?>
                                </a>
                            </td>
                            <td class="hide-mobile">
                                <?php if ($c['phone']): ?>
                                    <a href="tel:<?php echo htmlspecialchars($c['phone']); ?>" style="color:var(--acp-graphite); text-decoration:none;">
                                        <?php echo htmlspecialchars($c['phone']); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color:var(--acp-silver);">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($c['business_name']); ?></td>
                            <td>
                                <div style="display:flex; gap:6px;">
                                    <a class="btn small secondary" href="contacts.php?id=<?php echo intval($c['id']); ?>">View</a>
                                    <a class="btn small success hide-mobile" href="mailto:<?php echo htmlspecialchars($c['email']); ?>">Email</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
// Toggle between grid and list views
function toggleView(view) {
    const gridView = document.getElementById('contacts-grid');
    const listView = document.getElementById('contacts-list');
    const gridBtn = document.getElementById('grid-view-btn');
    const listBtn = document.getElementById('list-view-btn');
    
    if (view === 'grid') {
        gridView.style.display = 'grid';
        listView.style.display = 'none';
        gridBtn.classList.remove('secondary');
        gridBtn.classList.add('primary');
        listBtn.classList.remove('primary');
        listBtn.classList.add('secondary');
    } else {
        gridView.style.display = 'none';
        listView.style.display = 'block';
        listBtn.classList.remove('secondary');
        listBtn.classList.add('primary');
        gridBtn.classList.remove('primary');
        gridBtn.classList.add('secondary');
    }
    
    // Save preference
    localStorage.setItem('contacts-view-preference', view);
}

// Load view preference
document.addEventListener('DOMContentLoaded', function() {
    const savedView = localStorage.getItem('contacts-view-preference');
    if (savedView === 'grid') {
        toggleView('grid');
    }
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
