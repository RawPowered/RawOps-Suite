<?php
$SEO = [
    'title' => 'Case Manager — Legal',
    'description' => 'Manage all legal cases, view details, and track evidence.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/legal-permissions.php';
require_login_or_redirect('/login.php');
require_legal_or_admin(path_with_base('/admin/'));

$pdo = db();
$currentUser = current_user_id();

// Handle search and filters
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$validStatuses = ['draft', 'active', 'on_hold', 'closed', 'archived'];

// Build query
$whereClauses = [];
$params = [];

if ($search) {
    $whereClauses[] = "(c.title LIKE ? OR c.case_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($statusFilter && in_array($statusFilter, $validStatuses)) {
    $whereClauses[] = "c.status = ?";
    $params[] = $statusFilter;
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get cases with document counts
$stmt = $pdo->prepare("
    SELECT 
        c.*,
        COUNT(DISTINCT d.id) as document_count,
        COUNT(DISTINCT t.user_id) as team_count
    FROM legal_cases c
    LEFT JOIN legal_case_documents d ON d.case_id = c.id
    LEFT JOIN legal_case_team t ON t.case_id = c.id
    $whereSQL
    GROUP BY c.id
    ORDER BY c.updated_at DESC
");
$stmt->execute($params);
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) as total_cases,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_cases,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_cases,
        (SELECT COUNT(*) FROM legal_case_documents) as total_documents
    FROM legal_cases
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../required/header.php';
?>

<style>
.cases-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 40px 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 16px;
}

.page-title {
    font-size: 32px;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.create-case-btn {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    color: white;
    padding: 12px 24px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: transform 0.2s, box-shadow 0.2s;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.create-case-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(30, 58, 138, 0.3);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.stat-value {
    font-size: 36px;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 4px;
}

.stat-label {
    font-size: 14px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.controls-bar {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: center;
}

.search-input {
    flex: 1;
    min-width: 250px;
    padding: 10px 16px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
}

.filter-select {
    padding: 10px 16px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    background: white;
    min-width: 150px;
}

.cases-grid {
    display: grid;
    gap: 20px;
}

.case-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
    transition: all 0.2s;
    cursor: pointer;
}

.case-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.case-card-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 16px;
    gap: 16px;
}

.case-title {
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 4px 0;
}

.case-number {
    font-size: 14px;
    color: #6b7280;
    font-family: 'Courier New', monospace;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-draft {
    background: #f3f4f6;
    color: #4b5563;
}

.status-active {
    background: #dcfce7;
    color: #166534;
}

.status-on_hold {
    background: #fef3c7;
    color: #92400e;
}

.status-closed {
    background: #e0e7ff;
    color: #3730a3;
}

.status-archived {
    background: #f3f4f6;
    color: #6b7280;
}

.case-meta {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    font-size: 14px;
    color: #6b7280;
    margin-bottom: 12px;
}

.case-meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.case-summary {
    color: #4b5563;
    font-size: 14px;
    line-height: 1.6;
    margin: 12px 0;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.case-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
    font-size: 13px;
    color: #6b7280;
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.empty-state-icon {
    font-size: 64px;
    margin-bottom: 16px;
}

.empty-state-title {
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 8px;
}

.empty-state-text {
    font-size: 16px;
    color: #6b7280;
    margin-bottom: 24px;
}
</style>

<div class="cases-container">
    <div class="page-header">
        <h1 class="page-title">⚖️ Case Manager</h1>
        <a href="<?php echo path_with_base('/admin/legal/case-editor.php?new=1'); ?>" class="create-case-btn">
            <span>➕</span> Create New Case
        </a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($stats['total_cases'] ?? 0); ?></div>
            <div class="stat-label">Total Cases</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($stats['active_cases'] ?? 0); ?></div>
            <div class="stat-label">Active Cases</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($stats['draft_cases'] ?? 0); ?></div>
            <div class="stat-label">Draft Cases</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($stats['total_documents'] ?? 0); ?></div>
            <div class="stat-label">Total Documents</div>
        </div>
    </div>

    <div class="controls-bar">
        <input 
            type="text" 
            class="search-input" 
            placeholder="🔍 Search cases by title or case number..."
            value="<?php echo htmlspecialchars($search); ?>"
            id="searchInput"
        >
        <select class="filter-select" id="statusFilter">
            <option value="">All Statuses</option>
            <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="on_hold" <?php echo $statusFilter === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
            <option value="closed" <?php echo $statusFilter === 'closed' ? 'selected' : ''; ?>>Closed</option>
            <option value="archived" <?php echo $statusFilter === 'archived' ? 'selected' : ''; ?>>Archived</option>
        </select>
    </div>

    <?php if (empty($cases)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">📋</div>
        <h2 class="empty-state-title">No Cases Found</h2>
        <p class="empty-state-text">
            <?php if ($search || $statusFilter): ?>
                Try adjusting your search or filter criteria.
            <?php else: ?>
                Get started by creating your first legal case.
            <?php endif; ?>
        </p>
        <?php if (!$search && !$statusFilter): ?>
        <a href="<?php echo path_with_base('/admin/legal/case-editor.php?new=1'); ?>" class="create-case-btn">
            <span>➕</span> Create First Case
        </a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="cases-grid">
        <?php foreach ($cases as $case): ?>
        <div class="case-card" onclick="window.location.href='<?php echo path_with_base('/admin/legal/case-editor.php?id=' . $case['id']); ?>'">
            <div class="case-card-header">
                <div>
                    <h3 class="case-title"><?php echo htmlspecialchars($case['title']); ?></h3>
                    <?php if ($case['case_number']): ?>
                    <div class="case-number"><?php echo htmlspecialchars($case['case_number']); ?></div>
                    <?php endif; ?>
                </div>
                <span class="status-badge status-<?php echo $case['status']; ?>">
                    <?php echo str_replace('_', ' ', $case['status']); ?>
                </span>
            </div>

            <div class="case-meta">
                <?php if ($case['jurisdiction']): ?>
                <div class="case-meta-item">
                    <span>📍</span>
                    <span><?php echo htmlspecialchars($case['jurisdiction']); ?></span>
                </div>
                <?php endif; ?>
                <div class="case-meta-item">
                    <span>📎</span>
                    <span><?php echo $case['document_count']; ?> documents</span>
                </div>
                <div class="case-meta-item">
                    <span>👥</span>
                    <span><?php echo $case['team_count']; ?> team members</span>
                </div>
            </div>

            <?php if ($case['summary']): ?>
            <div class="case-summary"><?php echo htmlspecialchars(substr($case['summary'], 0, 250)); ?></div>
            <?php endif; ?>

            <div class="case-footer">
                <span>Created <?php echo date('M j, Y', strtotime($case['created_at'])); ?></span>
                <span>Updated <?php echo date('M j, Y', strtotime($case['updated_at'])); ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
// Real-time search
const searchInput = document.getElementById('searchInput');
const statusFilter = document.getElementById('statusFilter');

let searchTimeout;
searchInput.addEventListener('input', () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        updateURL();
    }, 300);
});

statusFilter.addEventListener('change', updateURL);

function updateURL() {
    const params = new URLSearchParams();
    if (searchInput.value) params.set('search', searchInput.value);
    if (statusFilter.value) params.set('status', statusFilter.value);
    
    window.location.href = '<?php echo path_with_base('/admin/legal/cases.php'); ?>' + 
        (params.toString() ? '?' + params.toString() : '');
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>




