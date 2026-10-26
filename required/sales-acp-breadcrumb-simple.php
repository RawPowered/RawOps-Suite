<?php
/**
 * Simple Breadcrumb Component for Sales ACP
 * Shows only the navigation path, no extra menus
 */

// Get current page
$currentFile = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$leadId = $_GET['lead_id'] ?? $_GET['id'] ?? null;
$accountId = $_GET['account_id'] ?? $_GET['id'] ?? null;

// Define page hierarchy
$pageInfo = [
    'index.php' => ['title' => 'Dashboard', 'icon' => '🏠', 'parent' => null],
    'leads.php' => ['title' => 'Leads', 'icon' => '🎯', 'parent' => 'index.php'],
    'accounts.php' => ['title' => 'Accounts', 'icon' => '🏢', 'parent' => 'index.php'],
    'contacts.php' => ['title' => 'Contacts', 'icon' => '👥', 'parent' => 'index.php'],
    'calls.php' => ['title' => 'Calls', 'icon' => '📞', 'parent' => 'index.php'],
    'tasks.php' => ['title' => 'Tasks', 'icon' => '✅', 'parent' => 'index.php'],
    'invoices.php' => ['title' => 'Invoices', 'icon' => '🧾', 'parent' => 'index.php'],
    'invoice.php' => ['title' => 'Create Invoice', 'icon' => '📄', 'parent' => 'invoices.php'],
    'dropoffs.php' => ['title' => 'Demo Dropoffs', 'icon' => '🧪', 'parent' => 'index.php'],
    'dropoff.php' => ['title' => 'Record Dropoff', 'icon' => '📦', 'parent' => 'dropoffs.php'],
    'overview.php' => ['title' => 'Admin Overview', 'icon' => '👁️', 'parent' => 'index.php']
];

// Build breadcrumb path
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
?>

<?php if (count($breadcrumbs) > 1): ?>
<style>
.simple-breadcrumb {
    padding: 8px 16px;
    background: linear-gradient(135deg, #f9fafb, white);
    border-bottom: 1px solid #e5e7eb;
    font-size: 13px;
}
.simple-breadcrumb-list {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.simple-breadcrumb-item {
    display: flex;
    align-items: center;
    gap: 6px;
}
.simple-breadcrumb-link {
    color: #6b7280;
    text-decoration: none;
    transition: color 200ms ease;
}
.simple-breadcrumb-link:hover {
    color: #1f2937;
}
.simple-breadcrumb-current {
    color: #1f2937;
    font-weight: 600;
}
.simple-breadcrumb-separator {
    color: #9ca3af;
    font-size: 11px;
}
</style>

<div class="simple-breadcrumb">
    <ol class="simple-breadcrumb-list">
        <?php foreach ($breadcrumbs as $index => $crumb): ?>
            <li class="simple-breadcrumb-item">
                <?php if ($index < count($breadcrumbs) - 1): ?>
                    <a href="<?php echo $crumb['file']; ?>" class="simple-breadcrumb-link">
                        <span><?php echo $crumb['icon']; ?></span>
                        <span><?php echo htmlspecialchars($crumb['title']); ?></span>
                    </a>
                    <span class="simple-breadcrumb-separator">›</span>
                <?php else: ?>
                    <span class="simple-breadcrumb-current">
                        <span><?php echo $crumb['icon']; ?></span>
                        <span><?php echo htmlspecialchars($crumb['title']); ?></span>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</div>
<?php endif; ?>

<?php
// Page-specific quick filters (only for certain pages)
$currentFilter = $_GET['filter'] ?? null;
if (in_array($currentFile, ['leads.php', 'tasks.php']) && !isset($_GET['id'])): 
?>
<style>
.quick-filters {
    padding: 8px 16px;
    background: white;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    gap: 12px;
}
.quick-filter-link {
    padding: 6px 12px;
    border-radius: 6px;
    text-decoration: none;
    color: #6b7280;
    font-size: 13px;
    font-weight: 500;
    transition: all 200ms ease;
}
.quick-filter-link:hover {
    background: #f3f4f6;
    color: #1f2937;
}
.quick-filter-link.active {
    background: #fbbf24;
    color: #1f2937;
}
</style>

<div class="quick-filters">
    <?php if ($currentFile === 'leads.php'): ?>
        <a href="leads.php" class="quick-filter-link <?php echo !$currentFilter ? 'active' : ''; ?>">All Leads</a>
        <a href="leads.php?filter=hot" class="quick-filter-link <?php echo $currentFilter === 'hot' ? 'active' : ''; ?>">🔥 Hot Leads</a>
        <a href="leads.php?action=add" class="quick-filter-link">➕ Add Lead</a>
    <?php elseif ($currentFile === 'tasks.php'): ?>
        <a href="tasks.php?filter=active" class="quick-filter-link <?php echo $currentFilter === 'active' ? 'active' : ''; ?>">🚀 Active</a>
        <a href="tasks.php?filter=overdue" class="quick-filter-link <?php echo $currentFilter === 'overdue' ? 'active' : ''; ?>">⚠️ Overdue</a>
        <a href="tasks.php?filter=completed" class="quick-filter-link <?php echo $currentFilter === 'completed' ? 'active' : ''; ?>">✅ Completed</a>
        <a href="tasks.php?action=add" class="quick-filter-link">➕ New Task</a>
    <?php endif; ?>
</div>
<?php endif; ?>
