<?php
/**
 * Sales ACP Breadcrumb & Submenu Navigation
 * Dynamic navigation based on current page context
 */

// Get current page and parameters
$currentFile = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$leadId = $_GET['lead_id'] ?? $_GET['id'] ?? null;
$accountId = $_GET['account_id'] ?? $_GET['id'] ?? null;
$contactId = $_GET['contact_id'] ?? $_GET['id'] ?? null;
$taskId = $_GET['task_id'] ?? $_GET['id'] ?? null;
$callId = $_GET['call_id'] ?? $_GET['id'] ?? null;
$invoiceId = $_GET['invoice_id'] ?? $_GET['id'] ?? null;

// Define page structure and relationships
$pageInfo = [
    'index.php' => [
        'title' => 'Dashboard',
        'icon' => '🏠',
        'section' => 'overview',
        'parent' => null
    ],
    'leads.php' => [
        'title' => 'Leads',
        'icon' => '🎯',
        'section' => 'sales',
        'parent' => 'index.php',
        'submenu' => ['add_lead', 'hot_leads', 'follow_ups', 'conversions']
    ],
    'accounts.php' => [
        'title' => 'Accounts',
        'icon' => '🏢',
        'section' => 'sales',
        'parent' => 'index.php',
        'submenu' => ['all_accounts', 'approved', 'pending', 'add_account']
    ],
    'contacts.php' => [
        'title' => 'Contacts',
        'icon' => '👥',
        'section' => 'sales',
        'parent' => 'index.php',
        'submenu' => ['all_contacts', 'primary', 'add_contact']
    ],
    'calls.php' => [
        'title' => 'Calls',
        'icon' => '📞',
        'section' => 'activity',
        'parent' => 'index.php',
        'submenu' => ['recent_calls', 'scheduled', 'log_call']
    ],
    'tasks.php' => [
        'title' => 'Tasks',
        'icon' => '✅',
        'section' => 'activity',
        'parent' => 'index.php',
        'submenu' => ['active', 'overdue', 'completed', 'add_task']
    ],
    'invoices.php' => [
        'title' => 'Invoices',
        'icon' => '🧾',
        'section' => 'financial',
        'parent' => 'index.php',
        'submenu' => ['all_invoices', 'pending', 'paid', 'create_invoice']
    ],
    'invoice.php' => [
        'title' => 'Create Invoice',
        'icon' => '📄',
        'section' => 'financial',
        'parent' => 'invoices.php'
    ],
    'my-sample-requests.php' => [
        'title' => 'My Samples',
        'icon' => '📦',
        'section' => 'samples',
        'parent' => 'index.php',
        'submenu' => ['all_samples', 'pending', 'approved', 'shipped', 'request_sample']
    ],
    'request-sample.php' => [
        'title' => 'Request Sample',
        'icon' => '➕',
        'section' => 'samples',
        'parent' => 'my-sample-requests.php'
    ],
    'overview.php' => [
        'title' => 'Admin Overview',
        'icon' => '👁️',
        'section' => 'admin',
        'parent' => 'index.php'
    ]
];

// Get current page info
$current = $pageInfo[$currentFile] ?? ['title' => 'Unknown', 'icon' => '📄', 'section' => 'other', 'parent' => 'index.php'];

// Build breadcrumb trail
$breadcrumbs = [];
$page = $currentFile;
while ($page && isset($pageInfo[$page])) {
    array_unshift($breadcrumbs, [
        'file' => $page,
        'title' => $pageInfo[$page]['title'],
        'icon' => $pageInfo[$page]['icon']
    ]);
    $page = $pageInfo[$page]['parent'];
}

// Add context-specific breadcrumbs (only if database is available)
if (isset($pdo) && $pdo) {
    if ($leadId && $currentFile !== 'leads.php') {
        try {
            $stmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) as name FROM crm_leads WHERE id = ?');
            $stmt->execute([$leadId]);
            $lead = $stmt->fetch();
            if ($lead) {
                array_splice($breadcrumbs, -1, 0, [[
                    'file' => 'leads.php?id=' . $leadId,
                    'title' => $lead['name'],
                    'icon' => '🎯'
                ]]);
            }
        } catch (Exception $e) {}
    }

    if ($accountId && $currentFile !== 'accounts.php') {
        try {
            $stmt = $pdo->prepare('SELECT business_name FROM wholesale_accounts WHERE id = ?');
            $stmt->execute([$accountId]);
            $account = $stmt->fetch();
            if ($account) {
                array_splice($breadcrumbs, -1, 0, [[
                    'file' => 'accounts.php?id=' . $accountId,
                    'title' => $account['business_name'],
                    'icon' => '🏢'
                ]]);
            }
        } catch (Exception $e) {}
    }
}

// Get counts if database is available
$hotLeadCount = 0;
$overdueTaskCount = 0;
$pendingSampleCount = 0;
$approvedSampleCount = 0;

if (isset($pdo) && $pdo && isset($uid)) {
    try {
        // Get hot lead count
        $stmt = $pdo->query("SELECT COUNT(*) FROM crm_leads WHERE lead_status = 'customer_review'");
        $hotLeadCount = $stmt ? $stmt->fetchColumn() : 0;
        
        // Get overdue task count
        $stmt = $pdo->query("SELECT COUNT(*) FROM crm_tasks WHERE status IN ('pending', 'in_progress') AND due_date < NOW()");
        $overdueTaskCount = $stmt ? $stmt->fetchColumn() : 0;
        
        // Get pending sample requests for current agent
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sample_requests WHERE agent_id = ? AND status = 'pending'");
        $stmt->execute([$uid]);
        $pendingSampleCount = $stmt ? $stmt->fetchColumn() : 0;
        
        // Get approved sample requests for current agent
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sample_requests WHERE agent_id = ? AND status = 'approved'");
        $stmt->execute([$uid]);
        $approvedSampleCount = $stmt ? $stmt->fetchColumn() : 0;
    } catch (Exception $e) {
        // Ignore errors and use default values
    }
}

// Define submenu items
$submenus = [
    'sales' => [
        ['file' => 'leads.php', 'title' => 'All Leads', 'icon' => '🎯'],
        ['file' => 'leads.php?filter=hot', 'title' => 'Hot Leads', 'icon' => '🔥', 'badge' => $hotLeadCount],
        ['file' => 'leads.php?action=add', 'title' => 'Add Lead', 'icon' => '➕'],
        ['divider' => true],
        ['file' => 'accounts.php', 'title' => 'Accounts', 'icon' => '🏢'],
        ['file' => 'contacts.php', 'title' => 'Contacts', 'icon' => '👥'],
    ],
    'activity' => [
        ['file' => 'calls.php', 'title' => 'Call Log', 'icon' => '📞'],
        ['file' => 'calls.php?action=add', 'title' => 'Log Call', 'icon' => '➕'],
        ['divider' => true],
        ['file' => 'tasks.php', 'title' => 'All Tasks', 'icon' => '✅'],
        ['file' => 'tasks.php?filter=overdue', 'title' => 'Overdue', 'icon' => '⚠️', 'badge' => $overdueTaskCount],
        ['file' => 'tasks.php?action=add', 'title' => 'Create Task', 'icon' => '➕'],
    ],
    'financial' => [
        ['file' => 'invoices.php', 'title' => 'All Invoices', 'icon' => '🧾'],
        ['file' => 'invoices.php?status=pending', 'title' => 'Pending', 'icon' => '⏳'],
        ['file' => 'invoices.php?status=paid', 'title' => 'Paid', 'icon' => '✅'],
        ['file' => 'invoice.php', 'title' => 'Create Invoice', 'icon' => '➕'],
    ],
    'samples' => [
        ['file' => 'my-sample-requests.php', 'title' => 'All Samples', 'icon' => '📦'],
        ['file' => 'my-sample-requests.php?status=pending', 'title' => 'Pending', 'icon' => '⏳', 'badge' => $pendingSampleCount],
        ['file' => 'my-sample-requests.php?status=approved', 'title' => 'Approved', 'icon' => '✅', 'badge' => $approvedSampleCount],
        ['file' => 'my-sample-requests.php?status=shipped', 'title' => 'Shipped', 'icon' => '🚚'],
        ['file' => 'request-sample.php', 'title' => 'Request Sample', 'icon' => '➕'],
    ]
];
?>

<!-- Include Enhanced Navigation CSS -->
<link rel="stylesheet" href="<?php echo outbound_path('/assets/sales-acp-navigation.css'); ?>?v=<?php echo time(); ?>">

<!-- Breadcrumb Navigation -->
<?php if (count($breadcrumbs) > 1): // Only show breadcrumbs if there's a path ?>
<div class="sacp_breadcrumb">
    <ol class="sacp_breadcrumb-list">
        <?php foreach ($breadcrumbs as $index => $crumb): ?>
            <li class="sacp_breadcrumb-item">
                <?php if ($index < count($breadcrumbs) - 1): ?>
                    <a href="<?php echo $crumb['file']; ?>" class="sacp_breadcrumb-link">
                        <span><?php echo $crumb['icon']; ?></span>
                        <?php echo htmlspecialchars($crumb['title']); ?>
                    </a>
                    <span class="sacp_breadcrumb-separator">›</span>
                <?php else: ?>
                    <span class="sacp_breadcrumb-current">
                        <span><?php echo $crumb['icon']; ?></span>
                        <?php echo htmlspecialchars($crumb['title']); ?>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</div>
<?php endif; ?>

<!-- Submenu Navigation - Only show on specific pages that need submenus -->
<?php 
$showSubmenu = in_array($currentFile, ['leads.php', 'accounts.php', 'contacts.php', 'calls.php', 'tasks.php', 'invoices.php', 'my-sample-requests.php']);
if ($showSubmenu && isset($submenus[$current['section']])): 
?>
<div class="sacp_submenu">
    <div class="sacp_submenu-section">
        <?php 
        // Only show relevant submenu items for the current page
        $relevantItems = [];
        switch($currentFile) {
            case 'leads.php':
                $relevantItems = [
                    ['file' => 'leads.php', 'title' => 'All Leads', 'icon' => '🎯'],
                    ['file' => 'leads.php?filter=hot', 'title' => 'Hot Leads', 'icon' => '🔥', 'badge' => $hotLeadCount],
                    ['file' => 'leads.php?action=add', 'title' => 'Add Lead', 'icon' => '➕'],
                ];
                break;
            case 'tasks.php':
                $relevantItems = [
                    ['file' => 'tasks.php?filter=active', 'title' => 'Active', 'icon' => '🚀'],
                    ['file' => 'tasks.php?filter=overdue', 'title' => 'Overdue', 'icon' => '⚠️', 'badge' => $overdueTaskCount],
                    ['file' => 'tasks.php?filter=completed', 'title' => 'Completed', 'icon' => '✅'],
                    ['file' => 'tasks.php?action=add', 'title' => 'Create Task', 'icon' => '➕'],
                ];
                break;
            case 'invoices.php':
                $relevantItems = [
                    ['file' => 'invoices.php', 'title' => 'All Invoices', 'icon' => '🧾'],
                    ['file' => 'invoices.php?status=pending', 'title' => 'Pending', 'icon' => '⏳'],
                    ['file' => 'invoices.php?status=paid', 'title' => 'Paid', 'icon' => '✅'],
                    ['file' => 'invoice.php', 'title' => 'Create Invoice', 'icon' => '➕'],
                ];
                break;
            case 'my-sample-requests.php':
                $relevantItems = [
                    ['file' => 'my-sample-requests.php', 'title' => 'All Samples', 'icon' => '📦'],
                    ['file' => 'my-sample-requests.php?status=pending', 'title' => 'Pending', 'icon' => '⏳', 'badge' => $pendingSampleCount],
                    ['file' => 'my-sample-requests.php?status=approved', 'title' => 'Approved', 'icon' => '✅', 'badge' => $approvedSampleCount],
                    ['file' => 'my-sample-requests.php?status=shipped', 'title' => 'Shipped', 'icon' => '🚚'],
                    ['file' => 'request-sample.php', 'title' => 'Request Sample', 'icon' => '➕'],
                ];
                break;
        }
        
        foreach ($relevantItems as $item):
            $isActive = false;
            if (strpos($item['file'], '?') !== false) {
                // Check if query params match
                parse_str(parse_url($item['file'], PHP_URL_QUERY), $itemParams);
                $matches = true;
                foreach ($itemParams as $key => $value) {
                    if (!isset($_GET[$key]) || $_GET[$key] !== $value) {
                        $matches = false;
                        break;
                    }
                }
                $isActive = $matches;
            } else {
                $isActive = ($item['file'] === $currentFile);
            }
        ?>
            <a href="<?php echo $item['file']; ?>" 
               class="sacp_submenu-link <?php echo $isActive ? 'active' : ''; ?>">
                <span><?php echo $item['icon']; ?></span>
                <?php echo htmlspecialchars($item['title']); ?>
                <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
                    <span class="sacp_submenu-badge"><?php echo $item['badge']; ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Quick Navigation Bar (for detail pages) -->
<?php if (false && ($leadId || $accountId || $contactId) && isset($pdo) && $pdo): // Temporarily disabled ?>
<div class="sacp_quick-nav">
    <div class="sacp_quick-nav-section">
        <span class="sacp_quick-nav-title">Related:</span>
        <?php if ($leadId): ?>
            <a href="calls.php?lead_id=<?php echo $leadId; ?>&action=add" class="sacp_quick-nav-btn">
                <span>📞</span> Log Call
            </a>
            <a href="tasks.php?lead_id=<?php echo $leadId; ?>&action=add" class="sacp_quick-nav-btn">
                <span>✅</span> Create Task
            </a>
            <a href="calls.php?lead_id=<?php echo $leadId; ?>&action=add&prefill=note" class="sacp_quick-nav-btn">
                <span>📝</span> Add Note
            </a>
        <?php elseif ($accountId): ?>
            <a href="invoice.php?account_id=<?php echo $accountId; ?>" class="sacp_quick-nav-btn primary">
                <span>📄</span> Create Invoice
            </a>
            <a href="contacts.php?account_id=<?php echo $accountId; ?>&action=add" class="sacp_quick-nav-btn">
                <span>👤</span> Add Contact
            </a>
            <a href="invoices.php?account_id=<?php echo $accountId; ?>" class="sacp_quick-nav-btn">
                <span>🧾</span> View Invoices
            </a>
        <?php endif; ?>
    </div>
    
    <div class="sacp_quick-nav-section">
        <span class="sacp_quick-nav-title">Navigate:</span>
        <a href="leads.php" class="sacp_quick-nav-btn">Leads</a>
        <a href="accounts.php" class="sacp_quick-nav-btn">Accounts</a>
        <a href="index.php" class="sacp_quick-nav-btn">Dashboard</a>
    </div>
</div>
<?php endif; ?>

<!-- Mobile Navigation Grid (shows on mobile) -->
<?php if (false): // Temporarily disabled ?>
<div class="sacp_mobile-nav" id="mobileNavGrid">
    <div class="sacp_mobile-nav-grid">
        <a href="index.php" class="sacp_mobile-nav-item">
            <span>🏠</span>
            <span>Dashboard</span>
        </a>
        <a href="leads.php" class="sacp_mobile-nav-item">
            <span>🎯</span>
            <span>Leads</span>
        </a>
        <a href="accounts.php" class="sacp_mobile-nav-item">
            <span>🏢</span>
            <span>Accounts</span>
        </a>
        <a href="calls.php" class="sacp_mobile-nav-item">
            <span>📞</span>
            <span>Calls</span>
        </a>
        <a href="tasks.php" class="sacp_mobile-nav-item">
            <span>✅</span>
            <span>Tasks</span>
        </a>
        <a href="invoices.php" class="sacp_mobile-nav-item">
            <span>🧾</span>
            <span>Invoices</span>
        </a>
    </div>
</div>
<?php endif; ?>

<script>
// Mobile navigation toggle
document.addEventListener('DOMContentLoaded', function() {
    const mobileNav = document.getElementById('mobileNavGrid');
    let touchStartY = 0;
    let touchEndY = 0;
    
    // Swipe up to show navigation
    document.addEventListener('touchstart', function(e) {
        touchStartY = e.changedTouches[0].screenY;
    });
    
    document.addEventListener('touchend', function(e) {
        touchEndY = e.changedTouches[0].screenY;
        handleSwipe();
    });
    
    function handleSwipe() {
        if (touchStartY - touchEndY > 50) {
            // Swipe up
            mobileNav.classList.add('show');
        }
        if (touchEndY - touchStartY > 50) {
            // Swipe down
            mobileNav.classList.remove('show');
        }
    }
    
    // Click outside to close
    document.addEventListener('click', function(e) {
        if (!mobileNav.contains(e.target)) {
            mobileNav.classList.remove('show');
        }
    });
});
</script>
