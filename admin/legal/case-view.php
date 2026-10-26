<?php
$SEO = [
    'title' => 'Case Details — Legal Management',
    'description' => 'View case details, evidence, team, and activity.',
    'type' => 'article'
];

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/legal-permissions.php';
require_once __DIR__ . '/../../required/legal-case-service.php';
require_login_or_redirect('/login.php');
require_legal_or_admin(path_with_base('/admin/'));

$pdo = db();
$currentUser = current_user_id();
$service = new LegalCaseService($pdo);
$isAdmin = user_has_role($currentUser, 'admin');

$caseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($caseId <= 0) {
    redirect(path_with_base('/admin/legal/cases.php'));
}

// Fetch case
$stmt = $pdo->prepare('SELECT * FROM legal_cases WHERE id = ? LIMIT 1');
$stmt->execute([$caseId]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$case) {
    redirect(path_with_base('/admin/legal/cases.php'));
}

// Permission check
if (!$isAdmin && !legal_user_can_access_case($currentUser, $caseId)) {
    flash_set('error', 'Access denied to this case.');
    redirect(path_with_base('/admin/legal/cases.php'));
}

// Fetch parties
$stmt = $pdo->prepare('SELECT * FROM legal_case_parties WHERE case_id = ? ORDER BY party_type, full_name');
$stmt->execute([$caseId]);
$parties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch team
$stmt = $pdo->prepare('
    SELECT t.*, u.first_name, u.last_name, u.email 
    FROM legal_case_team t 
    JOIN users u ON u.id = t.user_id 
    WHERE t.case_id = ? 
    ORDER BY t.created_at ASC
');
$stmt->execute([$caseId]);
$team = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch documents with evidence metadata
$documents = $service->listDocuments($caseId);

// Fetch notes
$stmt = $pdo->prepare('
    SELECT n.*, u.first_name, u.last_name 
    FROM legal_case_notes n 
    LEFT JOIN users u ON u.id = n.author_id 
    WHERE n.case_id = ? AND n.note_type = "note" AND n.deleted_at IS NULL 
    ORDER BY n.pinned DESC, n.created_at DESC 
    LIMIT 100
');
$stmt->execute([$caseId]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch activity
$stmt = $pdo->prepare('
    SELECT a.*, u.first_name, u.last_name 
    FROM legal_case_activity a 
    LEFT JOIN users u ON u.id = a.user_id 
    WHERE a.case_id = ? 
    ORDER BY a.created_at DESC 
    LIMIT 200
');
$stmt->execute([$caseId]);
$activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeTab = $_GET['tab'] ?? 'summary';

$breadcrumbs = [
    ['label' => 'Cases', 'url' => path_with_base('/admin/legal/cases.php')],
    ['label' => $case['title'], 'url' => null]
];

require_once __DIR__ . '/../../required/header.php';
?>

<style>
.case-view-container {
    max-width: 1400px;
    margin: 0 auto 80px auto;
    padding: 24px;
}

.case-header-card {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    color: #fff;
    border-radius: 16px;
    padding: 32px 40px;
    margin-bottom: 28px;
    box-shadow: 0 12px 32px rgba(30,58,138,0.25);
    position: relative;
}

.case-header-content {
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 24px;
    flex-wrap: wrap;
}

.case-title-section h1 {
    margin: 0 0 12px 0;
    font-size: 2.2rem;
    font-weight: 700;
    line-height: 1.2;
}

.case-meta-row {
    display: flex;
    gap: 28px;
    flex-wrap: wrap;
    font-size: 0.95rem;
    opacity: 0.95;
}

.case-meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.case-status-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
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
    background: #f1f5f9;
    color: #475569;
}

.status-archived {
    background: #e5e7eb;
    color: #6b7280;
}

.case-actions {
    display: flex;
    gap: 12px;
}

.btn {
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.95rem;
}

.btn-edit {
    background: #fff;
    color: #1e3a8a;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.btn-edit:hover {
    background: #f8fafc;
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.15);
}

.btn-back {
    background: rgba(255,255,255,0.15);
    color: #fff;
}

.btn-back:hover {
    background: rgba(255,255,255,0.25);
}

.tabs-navigation {
    display: flex;
    gap: 4px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 28px;
    flex-wrap: wrap;
    background: #fff;
    border-radius: 12px 12px 0 0;
    padding: 8px 8px 0 8px;
}

.tab-button {
    padding: 14px 20px;
    border: none;
    background: transparent;
    color: #64748b;
    font-weight: 600;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.tab-button.active {
    color: #1e3a8a;
    border-bottom-color: #1e3a8a;
    background: #f8fafc;
}

.tab-button:hover:not(.active) {
    color: #475569;
    background: #f8fafc;
}

.tab-badge {
    background: #e2e8f0;
    color: #475569;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
}

.tab-button.active .tab-badge {
    background: #dbeafe;
    color: #1e40af;
}

.content-card {
    background: #fff;
    border-radius: 0 0 12px 12px;
    border: 1px solid #e2e8f0;
    border-top: none;
    padding: 32px;
    min-height: 400px;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 24px;
    margin-bottom: 32px;
}

.info-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.info-label {
    font-size: 0.85rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    font-size: 1.05rem;
    color: #0f172a;
    font-weight: 500;
}

.summary-content {
    font-size: 1.05rem;
    line-height: 1.8;
    color: #475569;
    white-space: pre-wrap;
}

.parties-grid {
    display: grid;
    gap: 16px;
}

.party-card {
    border: 1px solid #e2e8f0;
    border-left: 4px solid;
    border-radius: 10px;
    padding: 20px;
    background: #f8fafc;
}

.party-card.plaintiff {
    border-left-color: #3b82f6;
}

.party-card.defendant {
    border-left-color: #ef4444;
}

.party-card.witness {
    border-left-color: #8b5cf6;
}

.party-card.expert {
    border-left-color: #10b981;
}

.party-card.other {
    border-left-color: #6b7280;
}

.party-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 12px;
}

.party-name {
    font-size: 1.2rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}

.party-type-badge {
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
}

.type-plaintiff {
    background: #dbeafe;
    color: #1e40af;
}

.type-defendant {
    background: #fee2e2;
    color: #991b1b;
}

.type-witness {
    background: #ede9fe;
    color: #6b21a8;
}

.type-expert {
    background: #d1fae5;
    color: #065f46;
}

.type-other {
    background: #f3f4f6;
    color: #374151;
}

.team-grid {
    display: grid;
    gap: 14px;
}

.team-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 18px;
    background: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.team-info h3 {
    margin: 0 0 4px 0;
    font-size: 1.05rem;
    color: #0f172a;
}

.team-email {
    font-size: 0.9rem;
    color: #64748b;
}

.team-role-badge {
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 700;
    background: #f1f5f9;
    color: #475569;
}

.evidence-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 18px;
}

.evidence-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    background: #fff;
    transition: all 0.2s;
}

.evidence-card:hover {
    box-shadow: 0 8px 16px rgba(0,0,0,0.08);
    transform: translateY(-2px);
}

.evidence-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 12px 0;
    display: flex;
    align-items: start;
    gap: 8px;
}

.evidence-meta {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 0.9rem;
    color: #64748b;
}

.key-evidence-badge {
    background: #fef3c7;
    border: 1px solid #fbbf24;
    color: #92400e;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.notes-grid {
    display: grid;
    gap: 16px;
}

.note-card {
    border-left: 4px solid #3b82f6;
    background: #f8fafc;
    border-radius: 8px;
    padding: 20px;
}

.note-card.pinned {
    background: #fef3c7;
    border-left-color: #fbbf24;
}

.note-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.note-author {
    font-weight: 700;
    color: #0f172a;
}

.note-date {
    font-size: 0.85rem;
    color: #64748b;
}

.note-body {
    color: #475569;
    line-height: 1.7;
    white-space: pre-wrap;
}

.activity-timeline {
    position: relative;
    padding-left: 40px;
}

.activity-timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e2e8f0;
}

.activity-item {
    position: relative;
    margin-bottom: 24px;
}

.activity-item::before {
    content: '';
    position: absolute;
    left: -33px;
    top: 4px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #3b82f6;
    border: 3px solid #fff;
    box-shadow: 0 0 0 2px #e2e8f0;
}

.activity-content {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
}

.activity-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.activity-user {
    font-weight: 600;
    color: #0f172a;
}

.activity-time {
    font-size: 0.85rem;
    color: #94a3b8;
}

.activity-description {
    color: #64748b;
    font-size: 0.95rem;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
}

.empty-state-icon {
    font-size: 4rem;
    margin-bottom: 16px;
    opacity: 0.3;
}

.empty-state h3 {
    color: #64748b;
    margin-bottom: 8px;
}
</style>

<?php include __DIR__ . '/partials/breadcrumb.php'; ?>

<div class="case-view-container">
    <!-- Case Header -->
    <div class="case-header-card">
        <div class="case-header-content">
            <div class="case-title-section">
                <h1>📋 <?php echo htmlspecialchars($case['title']); ?></h1>
                <div class="case-meta-row">
                    <div class="case-meta-item">
                        <strong>📋 Case #:</strong> <?php echo htmlspecialchars($case['case_number'] ?? 'N/A'); ?>
                    </div>
                    <div class="case-meta-item">
                        <strong>⚖️ Jurisdiction:</strong> <?php echo htmlspecialchars($case['jurisdiction'] ?? 'N/A'); ?>
                    </div>
                    <div class="case-meta-item">
                        <strong>Status:</strong> 
                        <span class="case-status-badge status-<?php echo htmlspecialchars($case['status']); ?>">
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $case['status']))); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="case-actions">
                <a href="<?php echo path_with_base('/admin/legal/cases.php'); ?>" class="btn btn-back">
                    ← Back to Cases
                </a>
                <?php if ($isAdmin): ?>
                <a href="<?php echo path_with_base('/admin/legal/case-editor.php?id=' . $caseId . '&tab=' . $activeTab); ?>" class="btn btn-edit">
                    ✏️ Edit Case
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="tabs-navigation">
        <button class="tab-button <?php echo $activeTab === 'summary' ? 'active' : ''; ?>" onclick="switchTab('summary')">
            📄 Summary
        </button>
        <button class="tab-button <?php echo $activeTab === 'parties' ? 'active' : ''; ?>" onclick="switchTab('parties')">
            👥 Parties <span class="tab-badge"><?php echo count($parties); ?></span>
        </button>
        <button class="tab-button <?php echo $activeTab === 'team' ? 'active' : ''; ?>" onclick="switchTab('team')">
            🤝 Team <span class="tab-badge"><?php echo count($team); ?></span>
        </button>
        <button class="tab-button <?php echo $activeTab === 'evidence' ? 'active' : ''; ?>" onclick="switchTab('evidence')">
            📁 Evidence <span class="tab-badge"><?php echo count($documents); ?></span>
        </button>
        <button class="tab-button <?php echo $activeTab === 'notes' ? 'active' : ''; ?>" onclick="switchTab('notes')">
            📝 Notes <span class="tab-badge"><?php echo count($notes); ?></span>
        </button>
        <button class="tab-button <?php echo $activeTab === 'activity' ? 'active' : ''; ?>" onclick="switchTab('activity')">
            📊 Activity <span class="tab-badge"><?php echo count($activity); ?></span>
        </button>
    </div>

    <!-- Tab Content -->
    <div class="content-card">
        <!-- Summary Tab -->
        <div id="tab-summary" class="tab-content <?php echo $activeTab === 'summary' ? 'active' : ''; ?>">
            <h2 style="margin-top:0;">Case Summary</h2>
            <?php if ($case['summary']): ?>
                <div class="summary-content"><?php echo htmlspecialchars($case['summary']); ?></div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📄</div>
                    <h3>No Summary Available</h3>
                    <p>A case summary has not been added yet.</p>
                </div>
            <?php endif; ?>

            <div style="margin-top:40px;">
                <h3>Case Details</h3>
                <div class="info-grid">
                    <div class="info-field">
                        <div class="info-label">Case Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($case['case_number'] ?? 'Not assigned'); ?></div>
                    </div>
                    <div class="info-field">
                        <div class="info-label">Status</div>
                        <div class="info-value"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $case['status']))); ?></div>
                    </div>
                    <div class="info-field">
                        <div class="info-label">Jurisdiction</div>
                        <div class="info-value"><?php echo htmlspecialchars($case['jurisdiction'] ?? 'Not specified'); ?></div>
                    </div>
                    <div class="info-field">
                        <div class="info-label">Retention Policy</div>
                        <div class="info-value"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $case['retention_policy']))); ?></div>
                    </div>
                    <div class="info-field">
                        <div class="info-label">Created</div>
                        <div class="info-value"><?php echo date('M j, Y', strtotime($case['created_at'])); ?></div>
                    </div>
                    <div class="info-field">
                        <div class="info-label">Last Updated</div>
                        <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($case['updated_at'])); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Parties Tab -->
        <div id="tab-parties" class="tab-content <?php echo $activeTab === 'parties' ? 'active' : ''; ?>">
            <h2 style="margin-top:0;">Case Parties</h2>
            <?php if (!empty($parties)): ?>
                <div class="parties-grid">
                    <?php foreach ($parties as $party): ?>
                    <div class="party-card <?php echo htmlspecialchars($party['party_type']); ?>">
                        <div class="party-header">
                            <h3 class="party-name"><?php echo htmlspecialchars($party['full_name']); ?></h3>
                            <span class="party-type-badge type-<?php echo htmlspecialchars($party['party_type']); ?>">
                                <?php echo htmlspecialchars(ucfirst($party['party_type'])); ?>
                            </span>
                        </div>
                        <?php if ($party['entity_type']): ?>
                            <div style="font-size:0.9rem;color:#64748b;margin-bottom:8px;">
                                <?php echo htmlspecialchars(ucfirst($party['entity_type'])); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($party['notes']): ?>
                            <div style="font-size:0.95rem;color:#475569;line-height:1.6;">
                                <?php echo htmlspecialchars($party['notes']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">👥</div>
                    <h3>No Parties Listed</h3>
                    <p>Parties have not been added to this case yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Team Tab -->
        <div id="tab-team" class="tab-content <?php echo $activeTab === 'team' ? 'active' : ''; ?>">
            <h2 style="margin-top:0;">Case Team</h2>
            <?php if (!empty($team)): ?>
                <div class="team-grid">
                    <?php foreach ($team as $member): ?>
                    <div class="team-card">
                        <div class="team-info">
                            <h3><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h3>
                            <div class="team-email"><?php echo htmlspecialchars($member['email']); ?></div>
                        </div>
                        <span class="team-role-badge">
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $member['role_on_case']))); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🤝</div>
                    <h3>No Team Members</h3>
                    <p>No team members have been assigned to this case.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Evidence Tab -->
        <div id="tab-evidence" class="tab-content <?php echo $activeTab === 'evidence' ? 'active' : ''; ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
                <h2 style="margin:0;">Evidence & Documents</h2>
                <a href="<?php echo path_with_base('/admin/legal/evidence-locker/index.php?case_id=' . $caseId); ?>" class="btn btn-edit" style="background:#1e3a8a;color:#fff;">
                    🗂️ Open Evidence Locker
                </a>
            </div>
            <?php if (!empty($documents)): ?>
                <div class="evidence-grid">
                    <?php foreach ($documents as $doc): ?>
                    <div class="evidence-card">
                        <h3 class="evidence-title">
                            📄 <?php echo htmlspecialchars($doc['display_name'] ?? $doc['file_name']); ?>
                        </h3>
                        <div class="evidence-meta">
                            <?php if (!empty($doc['exhibit_number'])): ?>
                                <div><strong>Exhibit:</strong> <?php echo htmlspecialchars($doc['exhibit_number']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($doc['document_type'])): ?>
                                <div><strong>Type:</strong> <?php echo htmlspecialchars(ucfirst($doc['document_type'])); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($doc['evidence_date'])): ?>
                                <div><strong>Date:</strong> <?php echo date('M j, Y', strtotime($doc['evidence_date'])); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($doc['is_key_evidence'])): ?>
                                <div><span class="key-evidence-badge">⭐ Key Evidence</span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📁</div>
                    <h3>No Evidence Available</h3>
                    <p>No documents have been uploaded to this case yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Notes Tab -->
        <div id="tab-notes" class="tab-content <?php echo $activeTab === 'notes' ? 'active' : ''; ?>">
            <h2 style="margin-top:0;">Case Notes</h2>
            <?php if (!empty($notes)): ?>
                <div class="notes-grid">
                    <?php foreach ($notes as $note): ?>
                    <div class="note-card <?php echo $note['pinned'] ? 'pinned' : ''; ?>">
                        <div class="note-header">
                            <div class="note-author">
                                <?php echo $note['pinned'] ? '📌 ' : ''; ?>
                                <?php echo htmlspecialchars($note['first_name'] . ' ' . $note['last_name']); ?>
                            </div>
                            <div class="note-date"><?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?></div>
                        </div>
                        <div class="note-body"><?php echo htmlspecialchars($note['note_body']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <h3>No Notes Available</h3>
                    <p>No notes have been added to this case yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Activity Tab -->
        <div id="tab-activity" class="tab-content <?php echo $activeTab === 'activity' ? 'active' : ''; ?>">
            <h2 style="margin-top:0;">Activity Log</h2>
            <?php if (!empty($activity)): ?>
                <div class="activity-timeline">
                    <?php foreach ($activity as $act): ?>
                    <div class="activity-item">
                        <div class="activity-content">
                            <div class="activity-header">
                                <div class="activity-user">
                                    <?php echo $act['first_name'] && $act['last_name'] ? 
                                        htmlspecialchars($act['first_name'] . ' ' . $act['last_name']) : 
                                        'System'; ?>
                                </div>
                                <div class="activity-time"><?php echo date('M j, Y g:i A', strtotime($act['created_at'])); ?></div>
                            </div>
                            <div class="activity-description">
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $act['activity_type']))); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📊</div>
                    <h3>No Activity Logged</h3>
                    <p>No activity has been recorded for this case yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active from all buttons
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById('tab-' + tabName).classList.add('active');
    
    // Add active to clicked button
    event.target.classList.add('active');
    
    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.pushState({}, '', url);
}
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
