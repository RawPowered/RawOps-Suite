<?php
// Legal breadcrumb navigation component
// Usage: include __DIR__ . '/partials/breadcrumb.php';
// Set $breadcrumbs array before including

if (!isset($breadcrumbs) || !is_array($breadcrumbs)) {
    $breadcrumbs = [];
}

// Build base breadcrumbs - only show Admin Portal for admin users
$baseCrumbs = [];

// Check if current user is admin
$currentUserId = current_user_id();
if (user_has_role($currentUserId, 'admin')) {
    $baseCrumbs[] = ['label' => 'Admin Portal', 'url' => path_with_base('/admin/')];
}

// Always show Legal Management as the home
$baseCrumbs[] = ['label' => 'Legal Management', 'url' => path_with_base('/admin/legal/')];

$allCrumbs = array_merge($baseCrumbs, $breadcrumbs);
?>
<nav class="legal-breadcrumb" aria-label="Breadcrumb navigation">
    <?php foreach ($allCrumbs as $idx => $crumb): ?>
        <?php if ($idx > 0): ?><span class="breadcrumb-sep">›</span><?php endif; ?>
        <?php if (isset($crumb['url'])): ?>
            <a href="<?php echo sanitize($crumb['url']); ?>" class="breadcrumb-link"><?php echo sanitize($crumb['label']); ?></a>
        <?php else: ?>
            <span class="breadcrumb-current"><?php echo sanitize($crumb['label']); ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<style>
.legal-breadcrumb {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 0;
    font-size: 0.9rem;
    color: #475569;
    flex-wrap: wrap;
}
.breadcrumb-link {
    color: #1d4ed8;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.2s;
}
.breadcrumb-link:hover {
    color: #1e40af;
    text-decoration: underline;
}
.breadcrumb-sep {
    color: #cbd5e1;
    font-weight: 600;
}
.breadcrumb-current {
    color: #0f172a;
    font-weight: 600;
}
</style>

