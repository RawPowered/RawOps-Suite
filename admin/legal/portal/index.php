<?php
$SEO = [
    'title' => 'Legal Portal — Product Legal Management',
    'description' => 'Legal team member portal for assigned cases.',
    'type' => 'article'
];

require_once __DIR__ . '/../../../required/header.php';
require_once __DIR__ . '/../../../required/legal-permissions.php';
require_once __DIR__ . '/../../../required/legal-case-service.php';
require_once __DIR__ . '/../../../required/legal-session.php';

// Legal users only (non-admin)
start_session();
$currentUser = current_user_id();

if (!$currentUser || !user_has_role($currentUser, 'legal')) {
    // If admin, redirect to main legal dashboard
    if (user_has_role($currentUser, 'admin')) {
        redirect(path_with_base('/admin/legal/index.php'));
    }
    redirect(path_with_base('/admin/'));
}

$pdo = db();
$service = new LegalCaseService($pdo);

// Fetch assigned cases
$cases = $service->getCasesForUser($currentUser, false);

// Get user info
$stmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$currentUser]);
$userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
$sessionConfig = legal_session_bootstrap([
    'page_url' => path_with_base('/admin/legal/portal/index.php')
]);

$breadcrumbs = [
    ['label' => 'Legal Portal', 'url' => null]
];
?>
<?php include __DIR__ . '/../partials/breadcrumb.php'; ?>

<style>
.legal-portal {
    max-width: 1200px;
    margin: 40px auto 80px auto;
    padding: 0 24px;
}
.portal-hero {
    background: linear-gradient(135deg, #1e3a8a 0%, #7c3aed 100%);
    color: #fff;
    border-radius: 16px;
    padding: 32px 40px;
    margin-bottom: 32px;
    box-shadow: 0 16px 32px rgba(30,58,138,0.2);
}
.portal-hero h1 {
    margin: 0 0 8px 0;
    font-size: 2rem;
}
.portal-hero p {
    margin: 0;
    opacity: 0.9;
}
.portal-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 20px;
}
.case-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 24px;
    box-shadow: 0 10px 24px rgba(15,23,42,0.08);
    transition: transform 0.2s, box-shadow 0.2s;
}
.case-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 32px rgba(15,23,42,0.14);
}
.case-card h2 {
    margin: 0 0 12px 0;
    font-size: 1.3rem;
    color: #0f172a;
}
.case-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
    font-size: 0.9rem;
    color: #64748b;
}
.case-tag {
    background: #eff6ff;
    color: #1d4ed8;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.85rem;
    font-weight: 600;
}
.case-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}
.btn {
    padding: 10px 18px;
    border-radius: 6px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}
.btn-primary {
    background: #0d47a1;
    color: #fff;
}
.btn-secondary {
    background: #f1f5f9;
    color: #0f172a;
}
.empty-state {
    text-align: center;
    padding: 60px 24px;
    color: #64748b;
}
.empty-state h2 {
    margin: 0 0 12px 0;
    color: #1f2937;
}
</style>

<div class="legal-portal">
    <section class="portal-hero">
        <h1>Welcome, <?php echo sanitize($userInfo['first_name'] . ' ' . $userInfo['last_name']); ?></h1>
        <p>Legal Team Member Portal • <?php echo count($cases); ?> assigned case(s)</p>
    </section>
    
    <?php if (empty($cases)): ?>
        <div class="empty-state">
            <h2>No Cases Assigned</h2>
            <p>You have not been assigned to any cases yet. Contact your administrator to request case access.</p>
        </div>
    <?php else: ?>
        <section class="portal-grid">
            <?php foreach ($cases as $case): ?>
                <article class="case-card">
                    <h2><?php echo sanitize($case['title']); ?></h2>
                    <div class="case-meta">
                        <span class="case-tag"><?php echo strtoupper($case['status']); ?></span>
                        <span><?php echo sanitize($case['jurisdiction'] ?: 'N/A'); ?></span>
                    </div>
                    <?php if ($case['summary']): ?>
                        <p style="margin:0 0 16px 0;color:#475569;font-size:0.95rem;line-height:1.6;">
                            <?php echo sanitize(substr($case['summary'], 0, 180)); ?><?php echo strlen($case['summary']) > 180 ? '...' : ''; ?>
                        </p>
                    <?php endif; ?>
                    <div style="font-size:0.85rem;color:#94a3b8;margin-bottom:16px;">
                        Updated <?php echo date('M j, Y g:ia', strtotime($case['updated_at'])); ?>
                    </div>
                    <div class="case-actions">
                        <a href="<?php echo path_with_base('/admin/legal/portal/case-view.php?id=' . $case['id']); ?>" class="btn btn-primary">View Case</a>
                        <a href="<?php echo path_with_base('/admin/legal/evidence-locker/index.php?case_id=' . $case['id']); ?>" class="btn btn-secondary">Evidence</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../../required/footer.php';

?>
<?php if (!empty($sessionConfig)): ?>
<script>window.__LEGAL_ACTIVITY_CONFIG = <?php echo json_encode($sessionConfig, JSON_UNESCAPED_SLASHES); ?>;</script>
<?php endif; ?>
<?php if (!empty($sessionConfig)): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!document.querySelector('script[data-legal-tracker="1"]')) {
        const tracker = document.createElement('script');
        tracker.src = path_with_base('/assets/legal-activity-tracker.js?v=<?php echo ASSET_VER; ?>');
        tracker.defer = true;
        tracker.setAttribute('data-legal-tracker', '1');
        document.head.appendChild(tracker);
    }
});
</script>
<?php endif; ?>


