<?php
/**
 * Paladin Theme Navigation Component for Sales ACP
 * Premium CRM Navigation with Dropdown Support
 */

// Ensure we have required variables
if (!isset($uid)) {
    $uid = current_user_id();
}
if (!isset($pdo)) {
    $pdo = db();
}

// Get current page for active state
$currentPage = isset($currentPage) ? $currentPage : basename($_SERVER['PHP_SELF'] ?? '');
$currentPath = $_SERVER['REQUEST_URI'] ?? '';

// Check for overdue follow-ups and pending samples for notification badge
$overdueCount = 0;
$urgentTaskCount = 0;
$pendingSampleCount = 0;
if ($pdo && $uid) {
    try {
        // Count pending sample requests for this agent
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM sample_requests
            WHERE agent_id = ? 
            AND status = 'pending'
        ");
        $stmt->execute([$uid]);
        $pendingSampleCount = intval($stmt->fetchColumn());
        
        // Count urgent tasks
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM crm_tasks
            WHERE agent_id = ? 
            AND status = 'pending'
            AND priority = 'urgent'
            AND due_date <= DATE_ADD(NOW(), INTERVAL 1 DAY)
        ");
        $stmt->execute([$uid]);
        $urgentTaskCount = intval($stmt->fetchColumn());
    } catch (Throwable $e) {
        // Fail silently
    }
}

$totalNotifications = $pendingSampleCount + $urgentTaskCount;

// Get user info if not already available
if (!isset($user) && $uid && $pdo) {
    try {
        $userStmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
        $userStmt->execute([$uid]);
        $user = $userStmt->fetch();
    } catch (Throwable $e) {
        $user = ['first_name' => 'Agent', 'last_name' => '', 'email' => ''];
    }
}

// Ensure user has at least minimal info
if (!isset($user) || !$user) {
    $user = ['first_name' => 'Agent', 'last_name' => '', 'email' => ''];
}

// User initials for avatar
$userInitials = '';
if (isset($user)) {
    $userInitials = strtoupper(substr($user['first_name'] ?? '', 0, 1) . substr($user['last_name'] ?? '', 0, 1));
}
?>

<!-- FontAwesome Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

<!-- Include Paladin Theme CSS (path-agnostic) -->
<link rel="stylesheet" href="<?php echo outbound_path('/assets/sales-acp-core.css'); ?>?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo outbound_path('/assets/sales-acp-mobile.css'); ?>?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo outbound_path('/assets/sales-acp-crm-enhancements.css'); ?>?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo outbound_path('/assets/sales-acp-dropdown.css'); ?>?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo outbound_path('/assets/sales-acp-fontawesome-icons.css'); ?>?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo outbound_path('/assets/sales-acp-feedback.css'); ?>?v=<?php echo time(); ?>">

<!-- Ensure proper mobile viewport -->
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes">

<!-- Include Responsive JavaScript (path-agnostic) -->
<script src="<?php echo outbound_path('/assets/sales-acp-responsive.js'); ?>?v=<?php echo time(); ?>"></script>
<script src="<?php echo outbound_path('/assets/sales-acp-interactions.js'); ?>?v=<?php echo time(); ?>"></script>
<script src="<?php echo outbound_path('/assets/sales-acp-feedback-tracker.js'); ?>?v=<?php echo time(); ?>"></script>
<script src="<?php echo outbound_path('/assets/sales-acp-feedback-modal.js'); ?>?v=<?php echo time(); ?>"></script>
<?php // Floating navigation widget is deprecated/disabled intentionally for cleaner mobile UI ?>
<?php /* <script src="/assets/sales-acp-navigation-widget.js?v=<?php echo time(); ?>"></script> */ ?>

<!-- Enhanced Paladin Navigation with SACP Prefixed Classes -->
<div class="sacp_nav">
    <div class="sacp_brand">
        <a href="<?php echo path_with_base('/onboarding/sales-agent/index.php'); ?>" class="sacp_brand-link" aria-label="Product‑It">
            <img src="<?php echo outbound_path('/assets/logo.svg'); ?>" alt="Product‑It" width="120" height="30" decoding="async">
        </a>
    </div>
    
    <!-- Primary Navigation -->
    <div class="sacp_links">
        <?php if (current_admin_id()): ?>
        <a href="<?php echo outbound_path('/admin/'); ?>" class="sacp_link">
            <i class="fas fa-arrow-left"></i> Back to Admin
        </a>
        <?php endif; ?>
        
        <a href="index.php" class="sacp_link<?php echo $currentPage === 'index.php' ? ' active' : ''; ?>">
            <i class="fas fa-chart-line"></i> Dashboard
        </a>
        
        <!-- CRM Dropdown -->
        <div class="sacp_dropdown<?php echo in_array($currentPage, ['leads.php', 'accounts.php', 'contacts.php']) ? ' active' : ''; ?>">
            <a href="#" class="sacp_link sacp_dropdown-toggle" onclick="toggleDropdown(event, 'crm-dropdown')">
                <i class="fas fa-users"></i> CRM
                <span class="sacp_dropdown-arrow">▾</span>
            </a>
            <div class="sacp_dropdown-menu" id="crm-dropdown">
                <a href="leads.php" class="sacp_dropdown-item<?php echo $currentPage === 'leads.php' ? ' active' : ''; ?>">
                    <i class="fas fa-bullseye"></i> Leads
                    <span class="sacp_dropdown-desc">Manage sales leads and prospects</span>
                </a>
                <a href="accounts.php" class="sacp_dropdown-item<?php echo $currentPage === 'accounts.php' ? ' active' : ''; ?>">
                    <i class="fas fa-building"></i> Accounts
                    <span class="sacp_dropdown-desc">Customer accounts and companies</span>
                </a>
                <a href="contacts.php" class="sacp_dropdown-item<?php echo $currentPage === 'contacts.php' ? ' active' : ''; ?>">
                    <i class="fas fa-address-book"></i> Contacts
                    <span class="sacp_dropdown-desc">Contact directory and details</span>
                </a>
            </div>
        </div>
        
        <!-- Activities Dropdown -->
        <div class="sacp_dropdown<?php echo in_array($currentPage, ['calls.php', 'tasks.php']) ? ' active' : ''; ?>">
            <a href="#" class="sacp_link sacp_dropdown-toggle" onclick="toggleDropdown(event, 'activities-dropdown')">
                <i class="fas fa-list-check"></i> Activities
                <?php if ($urgentTaskCount > 0): ?>
                    <span class="sacp_badge"><?php echo $urgentTaskCount; ?></span>
                <?php endif; ?>
                <span class="sacp_dropdown-arrow">▾</span>
            </a>
            <div class="sacp_dropdown-menu" id="activities-dropdown">
                <a href="calls.php" class="sacp_dropdown-item<?php echo $currentPage === 'calls.php' ? ' active' : ''; ?>">
                    <i class="fas fa-phone"></i> Calls
                    <span class="sacp_dropdown-desc">Call log and phone activities</span>
                </a>
                <a href="tasks.php" class="sacp_dropdown-item<?php echo $currentPage === 'tasks.php' ? ' active' : ''; ?>">
                    <i class="fas fa-tasks"></i> Tasks
                    <span class="sacp_dropdown-desc">To-dos and follow-ups</span>
                </a>
            </div>
        </div>
        
        <!-- Sales Tools Dropdown -->
        <div class="sacp_dropdown<?php echo in_array($currentPage, ['invoice.php', 'invoices.php', 'request-sample.php', 'my-sample-requests.php', 'email-workflows.php']) ? ' active' : ''; ?>">
            <a href="#" class="sacp_link sacp_dropdown-toggle" onclick="toggleDropdown(event, 'sales-tools-dropdown')">
                <i class="fas fa-briefcase"></i> Sales
                <?php if ($pendingSampleCount > 0): ?>
                    <span class="sacp_badge"><?php echo $pendingSampleCount; ?></span>
                <?php endif; ?>
                <span class="sacp_dropdown-arrow">▾</span>
            </a>
            <div class="sacp_dropdown-menu" id="sales-tools-dropdown">
                <a href="invoices.php" class="sacp_dropdown-item<?php echo $currentPage === 'invoices.php' ? ' active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar"></i> Invoices
                    <span class="sacp_dropdown-desc">View and manage invoices</span>
                </a>
                <a href="invoice.php" class="sacp_dropdown-item<?php echo $currentPage === 'invoice.php' ? ' active' : ''; ?>">
                    <i class="fas fa-plus-circle"></i> Create Invoice
                    <span class="sacp_dropdown-desc">Generate new invoice</span>
                </a>
                <div class="sacp_dropdown-divider"></div>
                <a href="my-sample-requests.php" class="sacp_dropdown-item<?php echo $currentPage === 'my-sample-requests.php' ? ' active' : ''; ?>">
                    <i class="fas fa-flask"></i> My Samples
                    <span class="sacp_dropdown-desc">View sample request history</span>
                    <?php if ($pendingSampleCount > 0): ?>
                        <span class="sacp_badge" style="margin-left: auto;"><?php echo $pendingSampleCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="request-sample.php" class="sacp_dropdown-item<?php echo $currentPage === 'request-sample.php' ? ' active' : ''; ?>">
                    <i class="fas fa-plus-square"></i> Request Sample
                    <span class="sacp_dropdown-desc">Request product samples for leads</span>
                </a>
                <div class="sacp_dropdown-divider"></div>
                <a href="email-workflows.php" class="sacp_dropdown-item<?php echo $currentPage === 'email-workflows.php' ? ' active' : ''; ?>">
                    <i class="fas fa-paper-plane"></i> Email Workflows
                    <span class="sacp_dropdown-desc">Automated campaigns</span>
                </a>
            </div>
        </div>
        
        <!-- Finances Dropdown -->
        <div class="sacp_dropdown<?php echo in_array($currentPage, ['finances.php', 'my-financials.php', 'books-detail.php', 'my-1099.php']) ? ' active' : ''; ?>">
            <a href="#" class="sacp_link sacp_dropdown-toggle" onclick="toggleDropdown(event, 'finances-dropdown')">
                <i class="fas fa-dollar-sign"></i> Finances
                <span class="sacp_dropdown-arrow">▾</span>
            </a>
            <div class="sacp_dropdown-menu" id="finances-dropdown">
                <a href="finances.php" class="sacp_dropdown-item<?php echo $currentPage === 'finances.php' ? ' active' : ''; ?>">
                    <i class="fas fa-chart-pie"></i> Overview
                    <span class="sacp_dropdown-desc">Commission summary</span>
                </a>
                <a href="my-financials.php" class="sacp_dropdown-item<?php echo $currentPage === 'my-financials.php' ? ' active' : ''; ?>">
                    <i class="fas fa-money-bill-wave"></i> My Financials
                    <span class="sacp_dropdown-desc">P&L and earnings</span>
                </a>
                <a href="books-detail.php" class="sacp_dropdown-item<?php echo $currentPage === 'books-detail.php' ? ' active' : ''; ?>">
                    <i class="fas fa-book"></i> My Books
                    <span class="sacp_dropdown-desc">Transaction ledger</span>
                </a>
                <a href="my-1099.php" class="sacp_dropdown-item<?php echo $currentPage === 'my-1099.php' ? ' active' : ''; ?>">
                    <i class="fas fa-file-invoice"></i> 1099 Info
                    <span class="sacp_dropdown-desc">Tax information</span>
                </a>
            </div>
        </div>
        
        <!-- More Dropdown -->
        <div class="sacp_dropdown<?php echo in_array($currentPage, ['profile.php', 'overview.php', 'my-business-card.php']) ? ' active' : ''; ?>">
            <a href="#" class="sacp_link sacp_dropdown-toggle" onclick="toggleDropdown(event, 'more-dropdown')">
                <i class="fas fa-ellipsis-h"></i> More
                <span class="sacp_dropdown-arrow">▾</span>
            </a>
            <div class="sacp_dropdown-menu" id="more-dropdown">
                <a href="my-business-card.php" class="sacp_dropdown-item<?php echo $currentPage === 'my-business-card.php' ? ' active' : ''; ?>">
                    <i class="fas fa-address-card"></i> My Business Card
                    <span class="sacp_dropdown-desc">Digital business card & QR code</span>
                </a>
                <div class="sacp_dropdown-divider"></div>
                <a href="javascript:void(0)" class="sacp_dropdown-item sacp_feedback-trigger" onclick="window.SACPFeedbackModal.open()">
                    <i class="fas fa-comment-dots"></i> Send Feedback
                    <span class="sacp_dropdown-desc">Report bugs or suggest features</span>
                </a>
                <a href="<?php echo outbound_path('/profile.php'); ?>" class="sacp_dropdown-item">
                    <i class="fas fa-user-circle"></i> My Profile
                    <span class="sacp_dropdown-desc">Account settings</span>
                </a>
                <?php if (current_admin_id()): ?>
                <div class="sacp_dropdown-divider"></div>
                <a href="overview.php" class="sacp_dropdown-item<?php echo $currentPage === 'overview.php' ? ' active' : ''; ?>">
                    <i class="fas fa-cog"></i> Overview
                    <span class="sacp_dropdown-desc">Admin tools</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
        
        <!-- Mobile Menu Toggle -->
        <div class="sacp_overflow">
            <button class="sacp_overflow-btn" type="button" aria-expanded="false" aria-controls="mobile-menu">
                <i class="fas fa-bars"></i> Menu
                <?php if ($totalNotifications > 0): ?>
                    <span class="sacp_badge" style="position: absolute; top: -4px; right: -4px;">
                        <?php echo $totalNotifications; ?>
                    </span>
                <?php endif; ?>
            </button>
            <div class="sacp_overflow-menu" id="mobile-menu">
                <?php if (current_admin_id()): ?>
                    <a href="<?php echo outbound_path('/admin/'); ?>" class="sacp_link"><i class="fas fa-arrow-left"></i> Back to Admin</a>
                <?php endif; ?>
                <a href="index.php" class="sacp_link"><i class="fas fa-chart-line"></i> Dashboard</a>
                
                <div class="sacp_mobile-section">
                    <div class="sacp_mobile-section-title"><i class="fas fa-users"></i> CRM</div>
                    <a href="leads.php" class="sacp_link" style="padding-left: 32px;"><i class="fas fa-bullseye"></i> Leads</a>
                    <a href="accounts.php" class="sacp_link" style="padding-left: 32px;"><i class="fas fa-building"></i> Accounts</a>
                    <a href="contacts.php" class="sacp_link" style="padding-left: 32px;"><i class="fas fa-address-book"></i> Contacts</a>
                </div>
                
                <div class="sacp_mobile-section">
                    <div class="sacp_mobile-section-title"><i class="fas fa-list-check"></i> Activities</div>
                    <a href="calls.php" class="sacp_link" style="padding-left: 32px;"><i class="fas fa-phone"></i> Calls</a>
                    <a href="tasks.php" class="sacp_link" style="padding-left: 32px;">
                        <i class="fas fa-tasks"></i> Tasks
                        <?php if ($urgentTaskCount > 0): ?>
                            <span class="sacp_badge" style="float: right;"><?php echo $urgentTaskCount; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="sacp_mobile-section">
                    <div class="sacp_mobile-section-title"><i class="fas fa-briefcase"></i> Sales</div>
                    <a href="invoices.php" class="sacp_link" style="padding-left: 32px;"><i class="fas fa-file-invoice-dollar"></i> Invoices</a>
                    <a href="invoice.php" class="sacp_link" style="padding-left: 32px;"><i class="fas fa-plus-circle"></i> Create Invoice</a>
                    <a href="my-sample-requests.php" class="sacp_link" style="padding-left: 32px;">
                        <i class="fas fa-flask"></i> My Samples
                        <?php if ($pendingSampleCount > 0): ?>
                            <span class="sacp_badge" style="float: right;"><?php echo $pendingSampleCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="request-sample.php" class="sacp_link" style="padding-left: 32px;"><i class="fas fa-plus-square"></i> Request Sample</a>
                    <a href="email-workflows.php" class="sacp_link" style="padding-left: 32px;"><i class="fas fa-paper-plane"></i> Email Workflows</a>
                </div>
                
                <a href="finances.php" class="sacp_link"><i class="fas fa-dollar-sign"></i> Finances</a>
                <a href="<?php echo outbound_path('/profile.php'); ?>" class="sacp_link"><i class="fas fa-user-circle"></i> My Profile</a>
                <a href="javascript:void(0)" class="sacp_link sacp_feedback-trigger" onclick="window.SACPFeedbackModal.open()"><i class="fas fa-comment-dots"></i> Send Feedback</a>
                
                <?php if (current_admin_id()): ?>
                    <a href="overview.php" class="sacp_link"><i class="fas fa-cog"></i> Overview</a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- User Section -->
        <div class="sacp_user">
            <?php if (current_admin_id()): ?>
                <?php $adminMode = !empty($_SESSION['sales_acp_admin_mode']); ?>
                <a class="sacp_admin-toggle<?php echo $adminMode ? ' active' : ''; ?>" 
                   href="?toggle_admin_mode=<?php echo $adminMode ? '0' : '1'; ?>" 
                   title="Toggle Admin Mode">
                    <i class="fas fa-<?php echo $adminMode ? 'crown' : 'user-tie'; ?>"></i>
                    Admin: <?php echo $adminMode ? 'On' : 'Off'; ?>
                </a>
            <?php endif; ?>
            
            <a href="<?php echo outbound_path('/profile.php'); ?>" class="sacp_user-wrapper" style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 12px; transition: opacity 0.2s;" title="View Profile">
                <?php if ($userInitials): ?>
                    <div class="sacp_user-avatar">
                        <?php echo $userInitials; ?>
                    </div>
                <?php endif; ?>
                <div class="sacp_user-info">
                    <div class="sacp_user-name">
                        <?php echo htmlspecialchars($user['first_name'] ?? 'Agent'); ?>
                    </div>
                    <div class="sacp_user-role">
                        Sales Agent
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>

<script>
// Enhanced navigation JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Handle dropdown menus
    const dropdowns = document.querySelectorAll('.acp-nav-dropdown');
    
    dropdowns.forEach(dropdown => {
        const toggle = dropdown.querySelector('.acp-nav-dropdown-toggle');
        
        toggle.addEventListener('click', function(e) {
            // If it's the main link and not clicking the dropdown arrow, navigate
            if (!e.target.closest('::after')) {
                // Allow default navigation
                return;
            }
            
            e.preventDefault();
            
            // Close other dropdowns
            dropdowns.forEach(other => {
                if (other !== dropdown) {
                    other.classList.remove('open');
                }
            });
            
            // Toggle this dropdown
            dropdown.classList.toggle('open');
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.acp-nav-dropdown')) {
            dropdowns.forEach(dropdown => {
                dropdown.classList.remove('open');
            });
        }
    });
    
    // Hover to open dropdowns on desktop
    if (window.innerWidth > 768) {
        dropdowns.forEach(dropdown => {
            let hoverTimeout;
            
            dropdown.addEventListener('mouseenter', function() {
                clearTimeout(hoverTimeout);
                dropdown.classList.add('open');
            });
            
            dropdown.addEventListener('mouseleave', function() {
                hoverTimeout = setTimeout(() => {
                    dropdown.classList.remove('open');
                }, 300);
            });
        });
    }
});

// Mobile menu toggle
function toggleMobileMenu() {
    const overflow = document.querySelector('.sacp_overflow');
    if (overflow) {
        overflow.classList.toggle('open');
    }
}

// Dropdown toggle function
function toggleDropdown(event, dropdownId) {
    event.preventDefault();
    event.stopPropagation();
    
    const dropdown = document.getElementById(dropdownId);
    const container = event.currentTarget.closest('.sacp_dropdown');
    
    // Close other dropdowns
    document.querySelectorAll('.sacp_dropdown').forEach(d => {
        if (d !== container) {
            d.classList.remove('open');
        }
    });
    
    // Toggle current dropdown
    container.classList.toggle('open');
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function closeDropdown(e) {
        if (!container.contains(e.target)) {
            container.classList.remove('open');
            document.removeEventListener('click', closeDropdown);
        }
    });
}

// Add pulse effect to urgent items
document.addEventListener('DOMContentLoaded', function() {
    // Find all urgent notification badges
    const urgentBadges = document.querySelectorAll('.badge');
    urgentBadges.forEach(badge => {
        const count = parseInt(badge.textContent);
        if (count > 0) {
            badge.style.animation = 'pulse-badge 2s infinite';
        }
    });
});
</script>
