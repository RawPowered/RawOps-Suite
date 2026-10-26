<!-- Clean Admin Navigation -->
<div class="admin-header" style="background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-radius: 12px; padding: 20px; margin-bottom: 24px; border: 1px solid #e5e7eb;">
    
    <!-- Main Navigation (Cleaned & Reorganized) -->
    <nav class="admin-nav" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px;">
        
        <!-- Dashboard -->
        <a href="<?php echo path_with_base('/admin/'); ?>" class="nav-item primary" style="background: #3b82f6; color: white;">📊 Dashboard</a>

        <!-- CRM Suite -->
        <details class="menu-group">
            <summary class="nav-item primary" style="background:#6d28d9; color:white;">🧩 CRM Suite</summary>
            <div class="group-items">
                <a href="<?php echo path_with_base('/admin/crm-overview.php'); ?>">🎯 CRM Overview</a>
                <a href="<?php echo path_with_base('/admin/crm-reports.php'); ?>">📊 CRM Reports</a>
                <a href="<?php echo path_with_base('/admin/orders.php'); ?>">📦 Orders</a>
                <a href="<?php echo path_with_base('/admin/wholesale-accounts.php'); ?>">🏢 Wholesale Accounts</a>
                <a href="<?php echo path_with_base('/admin/products.php'); ?>">🛍️ Products</a>
                <a href="<?php echo path_with_base('/admin/product-groups.php'); ?>">🏷️ Product Groups</a>
                <a href="<?php echo path_with_base('/admin/pricing-manager.php'); ?>">💰 Pricing Manager</a>
                <a href="<?php echo path_with_base('/admin/commissions.php'); ?>">💵 Commissions</a>
                <a href="<?php echo path_with_base('/admin/business-cards.php'); ?>">📇 Business Cards</a>
            </div>
        </details>

        <!-- LMS & Training -->
        <details class="menu-group">
            <summary class="nav-item primary" style="background:#7c3aed; color:white;">📚 LMS & Training</summary>
            <div class="group-items">
                <a href="<?php echo path_with_base('/admin/lms-manager.php'); ?>">📚 LMS Manager</a>
                <a href="<?php echo path_with_base('/admin/shortcode-builder.php'); ?>">⚡ Shortcode Builder</a>
                <a href="<?php echo path_with_base('/admin/walkthrough-manager.php'); ?>">🎓 Walkthrough Manager</a>
                <a href="<?php echo path_with_base('/admin/quiz-question-edit.php?return=lms'); ?>">❓ Create Quiz</a>
                <a href="<?php echo path_with_base('/admin/trainee-monitor.php'); ?>">👥 Trainee Monitor</a>
                <a href="<?php echo path_with_base('/admin/training-analytics.php'); ?>">📊 Training Analytics</a>
                <a href="<?php echo path_with_base('/admin/sales-workouts.php'); ?>">🏋️ Sales Workouts</a>
                <a href="<?php echo path_with_base('/admin/module-reviews.php'); ?>">⭐ Module Reviews</a>
            </div>
        </details>

        <!-- Accounting & Finance -->
        <details class="menu-group">
            <summary class="nav-item primary" style="background: #0369a1; color: white;">💼 Accounting</summary>
            <div class="group-items">
                <a href="<?php echo path_with_base('/admin/accounting/'); ?>">📊 Dashboard</a>
                <a href="<?php echo path_with_base('/admin/accounting/ledger.php'); ?>">📒 Chart of Accounts</a>
                <a href="<?php echo path_with_base('/admin/accounting/journal.php'); ?>">📝 Journal Entries</a>
                <a href="<?php echo path_with_base('/admin/accounting/reports/'); ?>">📈 Reports</a>
                <a href="<?php echo path_with_base('/admin/accounting/taxes.php'); ?>">💵 Tax Management</a>
            </div>
        </details>

        <!-- Samples & Fulfillment -->
        <details class="menu-group">
            <summary class="nav-item primary" style="background: #059669; color: white;">📦 Samples</summary>
            <div class="group-items">
                <a href="<?php echo path_with_base('/admin/samples/'); ?>">📦 Dashboard</a>
                <a href="<?php echo path_with_base('/admin/samples/inventory.php'); ?>">📊 Inventory</a>
                <a href="<?php echo path_with_base('/admin/samples/?status=pending'); ?>">⏳ Pending</a>
                <a href="<?php echo path_with_base('/admin/samples/?status=shipped'); ?>">✅ Shipped</a>
                <a href="<?php echo path_with_base('/admin/beta-sample-reviews.php'); ?>" style="border-top:1px solid #e5e7eb;margin-top:4px;padding-top:8px;">🧪 Beta Reviews</a>
            </div>
        </details>

        <!-- Legal & Compliance -->
        <details class="menu-group">
            <summary class="nav-item primary" style="background: #1e40af; color: white;">⚖️ Legal</summary>
            <div class="group-items">
                <a href="<?php echo path_with_base('/admin/legal/index.php'); ?>">⚖️ Legal Dashboard</a>
                <a href="<?php echo path_with_base('/admin/legal/agreements.php'); ?>">📝 Agreements</a>
                <a href="<?php echo path_with_base('/admin/legal/1099-management.php'); ?>">💼 1099 Forms</a>
            </div>
        </details>

        <!-- Operations & Monitoring -->
        <details class="menu-group">
            <summary class="nav-item" style="background:#0ea5e9; color:white;">⚙️ Operations</summary>
            <div class="group-items">
                <a href="<?php echo path_with_base('/operations/'); ?>">🚚 Operations</a>
                <a href="<?php echo path_with_base('/admin/error-monitoring.php'); ?>">🚨 Error Monitor</a>
                <a href="<?php echo path_with_base('/admin/ai-chat-logs.php'); ?>">🤖 AI Logs</a>
                <a href="<?php echo path_with_base('/admin/audit-log.php'); ?>">📋 Audit Log</a>
                <a href="<?php echo path_with_base('/admin/lab-manager.php'); ?>">🧪 Labs</a>
            </div>
        </details>

        <!-- Printify Integration -->
        <details class="menu-group">
            <summary class="nav-item" style="background:#10b981; color:white;">🖨️ Printify</summary>
            <div class="group-items">
                <a href="<?php echo path_with_base('/admin/tools-printify-integration.php'); ?>">⚙️ API Configuration</a>
                <a href="<?php echo path_with_base('/admin/tools-printify-products.php'); ?>">📦 Product Sync</a>
                <a href="<?php echo path_with_base('/admin/tools-printify-images-publish.php'); ?>">🖼️ Images & Publishing</a>
                <a href="<?php echo path_with_base('/admin/tools-printify-webhooks.php'); ?>">🔔 Webhooks</a>
                <a href="<?php echo path_with_base('/admin/orders.php?printify=1'); ?>">🚚 Order Fulfillment</a>
            </div>
        </details>

        <!-- Tools & Utilities -->
        <details class="menu-group">
            <summary class="nav-item" style="background:#111827; color:white;">🛠️ Tools</summary>
            <div class="group-items">
                <a href="<?php echo path_with_base('/admin/system-sync-verification.php'); ?>">🔄 System Sync</a>
                <a href="<?php echo path_with_base('/admin/shortcode-migration-tool.php'); ?>">⚡ Shortcode Migration</a>
                <a href="<?php echo path_with_base('/admin/quiz-diagnostic.php'); ?>">🔍 Quiz Diagnostic</a>
                <a href="<?php echo path_with_base('/admin/api-restriction-manager.php'); ?>">🔒 API Restrictions</a>
                <a href="<?php echo path_with_base('/admin/users.php'); ?>">👥 Users</a>
                <a href="<?php echo path_with_base('/admin/spam-management.php'); ?>">🛡️ Spam Management</a>
                <a href="<?php echo path_with_base('/admin/reports.php'); ?>">📈 Reports</a>
                <a href="<?php echo path_with_base('/admin/site-config.php'); ?>">🌐 Site Config</a>
            </div>
        </details>
        
    </nav>
    
    <!-- Secondary Actions (Collapsible) -->
    <details class="admin-secondary" style="margin-top: 16px;">
        <summary style="cursor: pointer; font-weight: 600; color: #6b7280; font-size: 14px; padding: 8px 0; border-top: 1px solid #e5e7eb; padding-top: 16px;">
            ⚙️ Advanced Tools & Configuration
        </summary>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; padding: 12px; background: #fff; border-radius: 8px; border: 1px solid #e5e7eb;">
            <a href="<?php echo path_with_base('/admin/dev-tasks.php'); ?>" class="btn secondary small">🛠️ Dev Tasks</a>
            <a href="<?php echo path_with_base('/admin/shipping-config.php'); ?>" class="btn secondary small">🚚 Shipping</a>
            <a href="<?php echo path_with_base('/admin/search-replace.php'); ?>" class="btn secondary small">🔎 Search & Replace</a>
            <a href="<?php echo path_with_base('/admin/tools-access-keys.php'); ?>" class="btn secondary small">🔑 Access Keys</a>
            <a href="<?php echo path_with_base('/admin/tools-google-integrations.php'); ?>" class="btn secondary small">🧩 Google Integrations</a>
            <a href="<?php echo path_with_base('/admin/email-automation-v2.php'); ?>" class="btn secondary small">📧 Email Automation</a>
            <a href="<?php echo path_with_base('/admin/debug-panel.php'); ?>" class="btn secondary small">🔧 Debug</a>
            <a href="<?php echo path_with_base('/admin/system_details.php'); ?>" class="btn secondary small">🔍 System Details</a>
        </div>
    </details>
    
    <!-- Role Explorer & Account Actions -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; padding-top: 16px; border-top: 1px solid #e5e7eb;">
        
        <!-- Role Explorer -->
        <details class="role-explorer">
            <summary style="cursor: pointer; font-weight: 600; color: #3b82f6; font-size: 14px; display: flex; align-items: center; gap: 6px;">
                🎭 Explore as Role
            </summary>
            <div style="position: absolute; z-index: 1000; background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-top: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); min-width: 280px;">
                
                <!-- Current Status -->
                <?php 
                $currentUser = current_user_id();
                $adminUser = current_admin_id();
                $isSimulating = $currentUser && $adminUser && ($currentUser !== $adminUser);
                
                if ($isSimulating && $currentUser): 
                    $pdo = db();
                    $stmt = $pdo->prepare('SELECT u.first_name, u.last_name, r.name as role_name FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id WHERE u.id = ? LIMIT 1');
                    $stmt->execute([$currentUser]);
                    $userInfo = $stmt->fetch();
                    $roleName = $userInfo['role_name'] ?? 'Guest';
                    if (!$userInfo) {
                        $roleName = 'Guest';
                        $userInfo = ['first_name' => 'Anonymous', 'last_name' => 'User'];
                    }
                ?>
                    <div style="background:#dcfce7; border:1px solid #86efac; border-radius:6px; padding:8px; margin-bottom:12px; text-align:center; font-size:12px;">
                        <strong>Currently: <?php echo ucfirst(str_replace('_', ' ', $roleName)); ?></strong>
                        <div style="color:#6b7280;"><?php echo htmlspecialchars($userInfo['first_name'] . ' ' . $userInfo['last_name']); ?></div>
                    </div>
                <?php elseif (!$currentUser): ?>
                    <div style="background:#fef3c7; border:1px solid #f59e0b; border-radius:6px; padding:8px; margin-bottom:12px; text-align:center; font-size:12px;">
                        <strong>Currently: Guest (No Login)</strong>
                        <div style="color:#6b7280;">Not logged in</div>
                    </div>
                <?php endif; ?>
                
                <!-- Role Buttons -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;">
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="simulate_role">
                        <input type="hidden" name="role" value="admin">
                        <button type="submit" style="width:100%; padding:6px 8px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:4px; color:#374151; font-size:11px; cursor:pointer; transition:all 0.2s;">
                            ⚙️ Admin
                        </button>
                    </form>
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="simulate_role">
                        <input type="hidden" name="role" value="sales_agent">
                        <button type="submit" style="width:100%; padding:6px 8px; background:#059669; color:white; border:1px solid #047857; border-radius:4px; font-size:11px; cursor:pointer; transition:all 0.2s; font-weight:600;" title="Go to Sales Control Panel">
                            💼 Sales Agent
                        </button>
                    </form>
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="simulate_role">
                        <input type="hidden" name="role" value="trainee">
                        <button type="submit" style="width:100%; padding:6px 8px; background:#7c3aed; color:white; border:1px solid #6d28d9; border-radius:4px; font-size:11px; cursor:pointer; transition:all 0.2s; font-weight:600;" title="Go to Training Area">
                            🎓 Trainee
                        </button>
                    </form>
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="simulate_role">
                        <input type="hidden" name="role" value="operations">
                        <button type="submit" style="width:100%; padding:6px 8px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:4px; color:#374151; font-size:11px; cursor:pointer; transition:all 0.2s;">
                            📦 Operations
                        </button>
                    </form>
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="simulate_role">
                        <input type="hidden" name="role" value="customer">
                        <button type="submit" style="width:100%; padding:6px 8px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:4px; color:#374151; font-size:11px; cursor:pointer; transition:all 0.2s;">
                            🛒 Customer
                        </button>
                    </form>
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="margin:0;">
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="margin:0;">
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="margin:0;">
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="margin:0;">
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="simulate_role">
                        <input type="hidden" name="role" value="guest">
                        <button type="submit" style="width:100%; padding:6px 8px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:4px; color:#374151; font-size:11px; cursor:pointer; transition:all 0.2s; grid-column: span 2;">
                            👤 Guest (No Login)
                        </button>
                    </form>
                </div>
                
                <!-- Enhanced Instructions -->
                <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:10px; font-size:11px; color:#6b7280; margin-bottom:12px;">
                    <strong style="color:#374151;">🎭 Role Simulation:</strong><br>
                    • <strong style="color:#059669;">Sales Agent</strong> → Sales Control Panel<br>
                    • <strong style="color:#7c3aed;">Trainee</strong> → Training Area<br>
                    • Other roles → Respective dashboards<br>
                    <em>Use the sticky header to switch roles or return to admin mode.</em>
                </div>
                
                <!-- Advanced Options -->
                <details style="margin-bottom: 12px;">
                    <summary style="font-size: 11px; color: #6b7280; cursor: pointer;">Advanced</summary>
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="display:flex; gap:4px; align-items:center; margin-top:8px;">
                        <input type="hidden" name="action" value="simulate_user">
                        <input name="email" type="email" placeholder="sample-004@example.com" style="flex:1; padding:4px 6px; border:1px solid #e5e7eb; border-radius:4px; font-size:11px;">
                        <button type="submit" style="padding:4px 8px; background:#3b82f6; color:white; border:none; border-radius:4px; font-size:11px; cursor:pointer;">Go</button>
                    </form>
                </details>
                
                <!-- End Simulation -->
                <?php if ($isSimulating): ?>
                    <form method="post" action="<?php echo path_with_base('/admin/role-simulator.php'); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="end_simulation">
                        <button type="submit" style="width:100%; background:#ef4444; color:white; border:none; padding:6px; border-radius:4px; font-size:11px; cursor:pointer;">End Simulation</button>
                    </form>
                <?php endif; ?>
            </div>
        </details>
        
        <!-- Account Actions -->
        <a href="<?php echo path_with_base('/admin/logout.php'); ?>" class="btn secondary" style="font-size: 14px;">Logout</a>
        
    </div>
    
</div>

<!-- Enhanced Styles -->
<style>
.nav-item {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 12px 16px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    text-decoration: none;
    color: #374151;
    font-weight: 500;
    font-size: 14px;
    transition: all 0.2s ease;
}

.nav-item:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    border-color: #d1d5db;
}

.nav-item.primary {
    font-weight: 600;
}

.nav-item.tool {
    font-weight: 600;
}

.menu-group {
    position: relative;
}

.menu-group > .group-items {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 10px 20px rgba(0,0,0,0.08);
    padding: 8px;
    min-width: 220px;
    display: none;
    z-index: 50;
}

.menu-group[open] > .group-items { display: block; }

.menu-group .group-items a {
    display: block;
    padding: 8px 10px;
    border-radius: 6px;
    color: #374151;
    text-decoration: none;
    font-size: 14px;
}

.menu-group .group-items a:hover {
    background: #f3f4f6;
}

.menu-group .group-items a {
    font-weight: normal !important;
}

.menu-group .group-items a[style*="background"] {
    font-weight: normal !important;
}

.admin-nav {
    position: relative;
}

/* Mobile: stack and use inline accordion */
@media (max-width: 768px) {
    .admin-nav { grid-template-columns: 1fr; }
    .menu-group > .group-items {
        position: static;
        box-shadow: none;
        margin-top: 6px;
        display: block; /* respects [open] via visibility below */
    }
    .menu-group:not([open]) > .group-items { display: none; }
    .menu-group[open] > summary.nav-item {
        border-bottom-left-radius: 0;
        border-bottom-right-radius: 0;
    }
}

.btn.small {
    padding: 6px 12px;
    font-size: 12px;
}

.role-explorer[open] summary {
    color: #1d4ed8;
}

.admin-secondary[open] summary {
    color: #374151;
}
</style>

<!-- Legacy Education Links Notice (for transition period) -->
<div style="background: #fef3c7; border: 1px solid #f59e0b; color: #92400e; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; display: none;" id="legacy-notice">
    <strong>📚 Education Management Consolidated:</strong> All training content, quizzes, analytics, and trainee management is now available in the 
    <a href="<?php echo path_with_base('/admin/lms-manager.php'); ?>" style="color: #7c2d12; text-decoration: underline;">LMS Manager</a>. 
    Legacy individual pages are still available but will be deprecated.
</div></nav>

<script>
(function() {
    const container = document.querySelector('.admin-header');
    if (!container) return;
    const groups = container.querySelectorAll('.menu-group');

    // Close other groups when one opens (accordion behavior)
    groups.forEach(function(group) {
        group.addEventListener('toggle', function() {
            if (!group.open) return;
            groups.forEach(function(other) { if (other !== group) other.open = false; });
        });
    });

    // Click outside closes any open dropdown (desktop UX)
    document.addEventListener('click', function(ev) {
        const isInside = ev.target.closest('.admin-header');
        if (!isInside) { groups.forEach(function(g) { g.open = false; }); }
    });

    // Escape key closes menus
    document.addEventListener('keydown', function(ev) {
        if (ev.key === 'Escape') { groups.forEach(function(g) { g.open = false; }); }
    });
})();
</script>