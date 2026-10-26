<?php
$SEO = [
    'title' => 'Evidence Lockers — Legal Portal',
    'description' => 'Select a case evidence locker to access.',
    'type' => 'article'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/legal-permissions.php';
require_once __DIR__ . '/../../required/legal-case-service.php';

start_session();
$currentUser = current_user_id();

// Legal users only (non-admin)
if (!$currentUser || !user_has_role($currentUser, 'legal')) {
    if (user_has_role($currentUser, 'admin')) {
        redirect(path_with_base('/admin/legal/evidence-locker/index.php'));
    }
    redirect(path_with_base('/admin/legal/auth-gate.php'));
}

$pdo = db();
$service = new LegalCaseService($pdo);

// Get assigned cases with evidence counts
$stmt = $pdo->prepare('
    SELECT c.*, COUNT(d.id) as evidence_count
    FROM legal_cases c
    JOIN legal_case_team t ON t.case_id = c.id
    LEFT JOIN legal_case_documents d ON d.case_id = c.id
    WHERE t.user_id = ? AND c.status IN ("active","draft","on_hold")
    GROUP BY c.id
    ORDER BY c.updated_at DESC
');
$stmt->execute([$currentUser]);
$assignedCases = $stmt->fetchAll(PDO::FETCH_ASSOC);

$breadcrumbs = [
    ['label' => 'Evidence Lockers', 'url' => null]
];
?>
<?php include __DIR__ . '/partials/breadcrumb.php'; ?>

<style>
.evidence-selector-shell {
    max-width: 1200px;
    margin: 40px auto 80px auto;
    padding: 0 24px;
}
.selector-hero {
    background: linear-gradient(135deg, #1e3a8a 0%, #7c3aed 100%);
    color: #fff;
    border-radius: 16px;
    padding: 32px 40px;
    margin-bottom: 32px;
    box-shadow: 0 16px 32px rgba(30,58,138,0.2);
}
.selector-hero h1 {
    margin: 0 0 8px 0;
    font-size: 2rem;
}
.selector-hero p {
    margin: 0;
    opacity: 0.9;
}
.cases-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 24px;
}
.case-locker-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 28px;
    box-shadow: 0 12px 28px rgba(15,23,42,0.08);
    transition: all 0.3s;
    cursor: pointer;
}
.case-locker-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px rgba(15,23,42,0.15);
    border-color: #1d4ed8;
}
.case-locker-card h2 {
    margin: 0 0 12px 0;
    font-size: 1.4rem;
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
    font-size: 0.8rem;
    font-weight: 600;
}
.evidence-stats {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    margin: 16px 0;
}
.evidence-count {
    font-size: 2rem;
    font-weight: 700;
    color: #0d47a1;
    margin-bottom: 4px;
}
.evidence-label {
    font-size: 0.85rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.access-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: #0d47a1;
    color: #fff;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}
.access-btn:hover {
    background: #1e40af;
    box-shadow: 0 8px 20px rgba(13,71,161,0.3);
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

<div class="evidence-selector-shell">
    <section class="selector-hero">
        <h1>Evidence Lockers</h1>
        <p>Access secure evidence for your assigned cases</p>
    </section>
    
    <?php if (empty($assignedCases)): ?>
        <div class="empty-state">
            <h2>No Cases Assigned</h2>
            <p>You have not been assigned to any cases yet. Contact your administrator to request case access.</p>
        </div>
    <?php else: ?>
        <section class="cases-grid">
            <?php foreach ($assignedCases as $case): ?>
                <article class="case-locker-card" onclick="window.location.href='<?php echo path_with_base('/admin/legal/evidence-locker/index.php?case_id=' . $case['id']); ?>'">
                    <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;">
                        <h2><?php echo sanitize($case['title']); ?></h2>
                        <?php if ($case['case_number']): ?>
                            <span style="background:#eff6ff;color:#1d4ed8;padding:4px 10px;border-radius:999px;font-size:0.8rem;font-weight:600;white-space:nowrap;"><?php echo sanitize($case['case_number']); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="case-meta">
                        <span class="case-tag"><?php echo strtoupper($case['status']); ?></span>
                        <span><?php echo sanitize($case['jurisdiction'] ?: 'N/A'); ?></span>
                    </div>
                    
                    <?php if ($case['summary']): ?>
                        <p style="margin:0 0 16px 0;color:#475569;font-size:0.95rem;line-height:1.6;">
                            <?php echo sanitize(substr($case['summary'], 0, 120)); ?><?php echo strlen($case['summary']) > 120 ? '...' : ''; ?>
                        </p>
                    <?php endif; ?>
                    
                    <div class="evidence-stats">
                        <div class="evidence-count"><?php echo number_format($case['evidence_count']); ?></div>
                        <div class="evidence-label">Evidence Files</div>
                    </div>
                    
                    <div style="display:flex;gap:12px;margin-top:20px;">
                        <a href="<?php echo path_with_base('/admin/legal/evidence-locker/index.php?case_id=' . $case['id']); ?>" class="access-btn">
                            🗃️ Open Evidence Locker
                        </a>
                    </div>
                    
                    <div style="font-size:0.85rem;color:#94a3b8;margin-top:16px;">
                        Updated <?php echo date('M j, Y g:ia', strtotime($case['updated_at'])); ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../required/footer.php';






