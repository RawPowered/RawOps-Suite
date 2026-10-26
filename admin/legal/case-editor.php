<?php
$SEO = [
    'title' => 'Case Editor — Legal',
    'description' => 'Edit and manage legal case details.',
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

$isNew = isset($_GET['new']) && $_GET['new'] == 1;
$caseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$isNew && $caseId <= 0) {
    redirect(path_with_base('/admin/legal/cases.php'));
}

$case = null;
$parties = [];
$team = [];
$documents = [];
$notes = [];
$activity = [];

if (!$isNew) {
    // Fetch case
    $stmt = $pdo->prepare('SELECT * FROM legal_cases WHERE id = ? LIMIT 1');
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$case) {
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
    
    // Fetch documents
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
    
    // Fetch shoutbox messages
    $stmt = $pdo->prepare('
        SELECT n.*, u.first_name, u.last_name 
        FROM legal_case_notes n 
        LEFT JOIN users u ON u.id = n.author_id 
        WHERE n.case_id = ? AND n.note_type = "shoutbox" AND n.deleted_at IS NULL 
        ORDER BY n.created_at ASC 
        LIMIT 100
    ');
    $stmt->execute([$caseId]);
    $shoutboxMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
}

// Get all users for team assignment
$allUsers = $pdo->query('
    SELECT u.id, u.first_name, u.last_name, u.email 
    FROM users u 
    WHERE u.status = "active" 
    ORDER BY u.first_name, u.last_name
')->fetchAll(PDO::FETCH_ASSOC);

$activeTab = $_GET['tab'] ?? 'details';

require_once __DIR__ . '/../../required/header.php';
?>

<style>
.editor-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 40px 20px;
}

.editor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 16px;
}

.editor-title {
    font-size: 32px;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.header-actions {
    display: flex;
    gap: 12px;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
}

.btn-secondary {
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #d1d5db;
}

.btn-secondary:hover {
    background: #e5e7eb;
}

.btn-danger {
    background: #dc2626;
    color: white;
}

.btn-danger:hover {
    background: #b91c1c;
}

.tabs-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.tabs-nav {
    display: flex;
    border-bottom: 2px solid #e5e7eb;
    overflow-x: auto;
    background: #f9fafb;
}

.tab-button {
    padding: 16px 24px;
    border: none;
    background: transparent;
    color: #6b7280;
    font-weight: 600;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    white-space: nowrap;
}

.tab-button:hover {
    color: #1e3a8a;
    background: #f3f4f6;
}

.tab-button.active {
    color: #1e3a8a;
    border-bottom-color: #1e3a8a;
    background: white;
}

.tab-content {
    padding: 32px;
}

.tab-pane {
    display: none;
}

.tab-pane.active {
    display: block;
}

.form-group {
    margin-bottom: 24px;
}

.form-label {
    display: block;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
    font-size: 14px;
}

.form-input,
.form-select,
.form-textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
}

.form-textarea {
    min-height: 120px;
    resize: vertical;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.party-item,
.team-member-item {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.party-info,
.member-info {
    flex: 1;
}

.party-name,
.member-name {
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 4px;
}

.party-type,
.member-role {
    font-size: 13px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.btn-small {
    padding: 6px 12px;
    font-size: 13px;
}

.document-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}

.document-item {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 16px;
}

.document-name {
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 8px;
}

.document-meta {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 4px;
}

.note-item {
    background: #f9fafb;
    border-left: 3px solid #3b82f6;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.note-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 8px;
}

.note-author {
    font-weight: 600;
    color: #1f2937;
}

.note-date {
    font-size: 13px;
    color: #6b7280;
}

.note-body {
    color: #4b5563;
    line-height: 1.6;
}

.activity-item {
    display: flex;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid #e5e7eb;
}

.activity-icon {
    width: 32px;
    height: 32px;
    background: #e0e7ff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.activity-content {
    flex: 1;
}

.activity-text {
    color: #4b5563;
    margin-bottom: 4px;
}

.activity-time {
    font-size: 13px;
    color: #6b7280;
}

.timeline-item {
    position: relative;
    padding-left: 32px;
    padding-bottom: 24px;
    border-left: 2px solid #e5e7eb;
}

.timeline-item:last-child {
    border-left-color: transparent;
}

.timeline-dot {
    position: absolute;
    left: -6px;
    top: 0;
    width: 12px;
    height: 12px;
    background: #3b82f6;
    border-radius: 50%;
}

.timeline-date {
    font-weight: 600;
    color: #1e3a8a;
    margin-bottom: 4px;
}

.timeline-content {
    color: #4b5563;
}

.add-form {
    background: #f9fafb;
    border: 2px dashed #d1d5db;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #6b7280;
}

.save-indicator {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #10b981;
    color: white;
    padding: 12px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    display: none;
}

.save-indicator.show {
    display: block;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        transform: translateY(100px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}
</style>

<div class="editor-container">
    <div class="editor-header">
        <h1 class="editor-title">
            <?php if ($isNew): ?>
                ➕ Create New Case
            <?php else: ?>
                📝 <?php echo htmlspecialchars($case['title']); ?>
            <?php endif; ?>
        </h1>
        <div class="header-actions">
            <a href="<?php echo path_with_base('/admin/legal/cases.php'); ?>" class="btn btn-secondary">
                ← Back to Cases
            </a>
            <?php if (!$isNew): ?>
            <button type="button" class="btn btn-primary" onclick="saveCase()">
                💾 Save Changes
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="tabs-container">
        <div class="tabs-nav">
            <button class="tab-button <?php echo $activeTab === 'details' ? 'active' : ''; ?>" data-tab="details">
                📋 Details
            </button>
            <?php if (!$isNew): ?>
            <button class="tab-button <?php echo $activeTab === 'summary' ? 'active' : ''; ?>" data-tab="summary">
                📄 Summary
            </button>
            <button class="tab-button <?php echo $activeTab === 'parties' ? 'active' : ''; ?>" data-tab="parties">
                👥 Parties (<?php echo count($parties); ?>)
            </button>
            <button class="tab-button <?php echo $activeTab === 'team' ? 'active' : ''; ?>" data-tab="team">
                🤝 Team (<?php echo count($team); ?>)
            </button>
            <button class="tab-button <?php echo $activeTab === 'evidence' ? 'active' : ''; ?>" data-tab="evidence">
                📎 Evidence (<?php echo count($documents); ?>)
            </button>
            <button class="tab-button <?php echo $activeTab === 'notes' ? 'active' : ''; ?>" data-tab="notes">
                📝 Notes (<?php echo count($notes); ?>)
            </button>
            <button class="tab-button <?php echo $activeTab === 'timeline' ? 'active' : ''; ?>" data-tab="timeline">
                📅 Timeline
            </button>
            <button class="tab-button <?php echo $activeTab === 'activity' ? 'active' : ''; ?>" data-tab="activity">
                📊 Activity (<?php echo count($activity); ?>)
            </button>
            <button class="tab-button <?php echo $activeTab === 'shoutbox' ? 'active' : ''; ?>" data-tab="shoutbox">
                💬 Team Chat
            </button>
            <?php endif; ?>
        </div>

        <div class="tab-content">
            <!-- Details Tab -->
            <div class="tab-pane <?php echo $activeTab === 'details' ? 'active' : ''; ?>" id="tab-details">
                <form id="detailsForm" onsubmit="return handleDetailsSubmit(event)">
                    <input type="hidden" name="case_id" value="<?php echo $isNew ? '' : $case['id']; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Case Title *</label>
                        <input 
                            type="text" 
                            name="title" 
                            class="form-input" 
                            value="<?php echo $isNew ? '' : htmlspecialchars($case['title']); ?>" 
                            required
                            placeholder="e.g., Smith v. Johnson"
                        >
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Case Number</label>
                            <input 
                                type="text" 
                                name="case_number" 
                                class="form-input" 
                                value="<?php echo $isNew ? '' : htmlspecialchars($case['case_number'] ?? ''); ?>"
                                placeholder="Auto-generated if left blank"
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required>
                                <option value="draft" <?php echo (!$isNew && $case['status'] === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                <option value="active" <?php echo (!$isNew && $case['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="on_hold" <?php echo (!$isNew && $case['status'] === 'on_hold') ? 'selected' : ''; ?>>On Hold</option>
                                <option value="closed" <?php echo (!$isNew && $case['status'] === 'closed') ? 'selected' : ''; ?>>Closed</option>
                                <option value="archived" <?php echo (!$isNew && $case['status'] === 'archived') ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Jurisdiction</label>
                            <input 
                                type="text" 
                                name="jurisdiction" 
                                class="form-input" 
                                value="<?php echo $isNew ? '' : htmlspecialchars($case['jurisdiction'] ?? ''); ?>"
                                placeholder="e.g., Florida, Federal Court"
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label">Retention Policy</label>
                            <select name="retention_policy" class="form-select">
                                <option value="never" <?php echo (!$isNew && $case['retention_policy'] === 'never') ? 'selected' : ''; ?>>Never Delete</option>
                                <option value="1_year" <?php echo (!$isNew && $case['retention_policy'] === '1_year') ? 'selected' : ''; ?>>1 Year</option>
                                <option value="2_year" <?php echo (!$isNew && $case['retention_policy'] === '2_year') ? 'selected' : ''; ?>>2 Years</option>
                                <option value="3_year" <?php echo (!$isNew && $case['retention_policy'] === '3_year') ? 'selected' : ''; ?>>3 Years</option>
                                <option value="5_year" <?php echo (!$isNew && $case['retention_policy'] === '5_year') ? 'selected' : ''; ?>>5 Years</option>
                                <option value="10_year" <?php echo (!$isNew && $case['retention_policy'] === '10_year') ? 'selected' : ''; ?>>10 Years</option>
                                <option value="20_year" <?php echo (!$isNew && $case['retention_policy'] === '20_year') ? 'selected' : ''; ?>>20 Years</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <input 
                                type="checkbox" 
                                name="regulatory_hold" 
                                value="1"
                                <?php echo (!$isNew && $case['regulatory_hold']) ? 'checked' : ''; ?>
                            >
                            Regulatory Hold (Prevent auto-deletion)
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Regulatory Notes</label>
                        <textarea 
                            name="regulatory_notes" 
                            class="form-textarea"
                            placeholder="Notes about regulatory compliance or holds..."
                        ><?php echo $isNew ? '' : htmlspecialchars($case['regulatory_notes'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <?php echo $isNew ? '➕ Create Case' : '💾 Save Details'; ?>
                    </button>
                </form>
            </div>

            <?php if (!$isNew): ?>
            <!-- Summary Tab -->
            <div class="tab-pane <?php echo $activeTab === 'summary' ? 'active' : ''; ?>" id="tab-summary">
                <form id="summaryForm" onsubmit="return handleSummarySubmit(event)">
                    <input type="hidden" name="case_id" value="<?php echo $case['id']; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Case Summary</label>
                        <textarea 
                            name="summary" 
                            class="form-textarea" 
                            style="min-height: 400px;"
                            placeholder="Enter a comprehensive summary of the case, including key facts, allegations, and legal issues..."
                        ><?php echo htmlspecialchars($case['summary'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">💾 Save Summary</button>
                </form>
            </div>

            <!-- Parties Tab -->
            <div class="tab-pane <?php echo $activeTab === 'parties' ? 'active' : ''; ?>" id="tab-parties">
                <div class="add-form" id="addPartyForm">
                    <h3 style="margin-top: 0;">➕ Add Party</h3>
                    <form onsubmit="return handleAddParty(event)">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" class="form-input" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Party Type *</label>
                                <select name="party_type" class="form-select" required>
                                    <option value="plaintiff">Plaintiff</option>
                                    <option value="defendant">Defendant</option>
                                    <option value="witness">Witness</option>
                                    <option value="expert">Expert</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Entity Type</label>
                                <select name="entity_type" class="form-select">
                                    <option value="individual">Individual</option>
                                    <option value="organization">Organization</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email (optional)</label>
                                <input type="email" name="email" class="form-input">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-textarea" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary btn-small">➕ Add Party</button>
                    </form>
                </div>

                <div id="partiesList">
                    <?php if (empty($parties)): ?>
                    <div class="empty-state">No parties added yet.</div>
                    <?php else: ?>
                        <?php foreach ($parties as $party): ?>
                        <div class="party-item" data-party-id="<?php echo $party['id']; ?>">
                            <div class="party-info">
                                <div class="party-name"><?php echo htmlspecialchars($party['full_name']); ?></div>
                                <div class="party-type">
                                    <?php echo htmlspecialchars($party['party_type']); ?> 
                                    • <?php echo htmlspecialchars($party['entity_type']); ?>
                                </div>
                                <?php if ($party['notes']): ?>
                                <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">
                                    <?php echo htmlspecialchars($party['notes']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-danger btn-small" onclick="removeParty(<?php echo $party['id']; ?>)">
                                🗑️ Remove
                            </button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Team Tab -->
            <div class="tab-pane <?php echo $activeTab === 'team' ? 'active' : ''; ?>" id="tab-team">
                <div class="add-form">
                    <h3 style="margin-top: 0;">➕ Add Team Member</h3>
                    <form onsubmit="return handleAddTeamMember(event)">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">User *</label>
                                <select name="user_id" class="form-select" required>
                                    <option value="">Select a user...</option>
                                    <?php foreach ($allUsers as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                        (<?php echo htmlspecialchars($user['email']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Role *</label>
                                <select name="role" class="form-select" required>
                                    <option value="observer">Observer</option>
                                    <option value="admin">Admin</option>
                                    <option value="lead_counsel">Lead Counsel</option>
                                    <option value="co_counsel">Co-Counsel</option>
                                    <option value="paralegal">Paralegal</option>
                                    <option value="investigator">Investigator</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-small">➕ Add to Team</button>
                    </form>
                </div>

                <div id="teamList">
                    <?php if (empty($team)): ?>
                    <div class="empty-state">No team members assigned yet.</div>
                    <?php else: ?>
                        <?php foreach ($team as $member): ?>
                        <div class="team-member-item" data-member-id="<?php echo $member['id']; ?>">
                            <div class="member-info">
                                <div class="member-name">
                                    <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                </div>
                                <div class="member-role">
                                    <?php echo str_replace('_', ' ', $member['role']); ?>
                                    • <?php echo htmlspecialchars($member['email']); ?>
                                </div>
                            </div>
                            <button type="button" class="btn btn-danger btn-small" onclick="removeTeamMember(<?php echo $member['id']; ?>)">
                                🗑️ Remove
                            </button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Evidence Tab -->
            <div class="tab-pane <?php echo $activeTab === 'evidence' ? 'active' : ''; ?>" id="tab-evidence">
                <div style="margin-bottom: 24px;">
                    <a href="<?php echo path_with_base('/admin/legal/evidence-locker/index.php?case_id=' . $case['id']); ?>" 
                       class="btn btn-primary" target="_blank">
                        🔐 Open Evidence Locker
                    </a>
                </div>

                <?php if (empty($documents)): ?>
                <div class="empty-state">No evidence uploaded yet.</div>
                <?php else: ?>
                <div class="document-grid">
                    <?php foreach ($documents as $doc): ?>
                    <div class="document-item">
                        <div class="document-name"><?php echo htmlspecialchars($doc['display_name']); ?></div>
                        <?php if ($doc['exhibit_number']): ?>
                        <div class="document-meta">📋 <?php echo htmlspecialchars($doc['exhibit_number']); ?></div>
                        <?php endif; ?>
                        <div class="document-meta">📁 <?php echo ucfirst($doc['document_type']); ?></div>
                        <?php if ($doc['evidence_date']): ?>
                        <div class="document-meta">📅 <?php echo date('M j, Y', strtotime($doc['evidence_date'])); ?></div>
                        <?php endif; ?>
                        <?php if ($doc['is_key_evidence']): ?>
                        <div style="margin-top: 8px; color: #dc2626; font-weight: 600; font-size: 13px;">
                            ⭐ Key Evidence
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Notes Tab -->
            <div class="tab-pane <?php echo $activeTab === 'notes' ? 'active' : ''; ?>" id="tab-notes">
                <div class="add-form">
                    <h3 style="margin-top: 0;">➕ Add Note</h3>
                    <form onsubmit="return handleAddNote(event)">
                        <div class="form-group">
                            <textarea 
                                name="note_body" 
                                class="form-textarea" 
                                placeholder="Enter your note here..."
                                required
                            ></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                <input type="checkbox" name="pinned" value="1">
                                Pin this note to the top
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-small">➕ Add Note</button>
                    </form>
                </div>

                <div id="notesList">
                    <?php if (empty($notes)): ?>
                    <div class="empty-state">No notes yet.</div>
                    <?php else: ?>
                        <?php foreach ($notes as $note): ?>
                        <div class="note-item" data-note-id="<?php echo $note['id']; ?>">
                            <div class="note-header">
                                <div class="note-author">
                                    <?php echo $note['first_name'] ? htmlspecialchars($note['first_name'] . ' ' . $note['last_name']) : 'Unknown'; ?>
                                    <?php if ($note['pinned']): ?><span style="color: #dc2626;">📌</span><?php endif; ?>
                                </div>
                                <div class="note-date">
                                    <?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?>
                                </div>
                            </div>
                            <div class="note-body"><?php echo nl2br(htmlspecialchars($note['note_body'])); ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Timeline Tab -->
            <div class="tab-pane <?php echo $activeTab === 'timeline' ? 'active' : ''; ?>" id="tab-timeline">
                <?php
                $timelineEvents = [];
                foreach ($documents as $doc) {
                    if ($doc['timeline_date']) {
                        $timelineEvents[] = [
                            'date' => $doc['timeline_date'],
                            'content' => $doc['display_name'],
                            'type' => 'document'
                        ];
                    }
                }
                usort($timelineEvents, function($a, $b) {
                    return strtotime($b['date']) - strtotime($a['date']);
                });
                ?>

                <?php if (empty($timelineEvents)): ?>
                <div class="empty-state">No timeline events yet. Add documents with timeline dates to build the case timeline.</div>
                <?php else: ?>
                    <?php foreach ($timelineEvents as $event): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot"></div>
                        <div class="timeline-date"><?php echo date('F j, Y', strtotime($event['date'])); ?></div>
                        <div class="timeline-content"><?php echo htmlspecialchars($event['content']); ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Activity Tab -->
            <div class="tab-pane <?php echo $activeTab === 'activity' ? 'active' : ''; ?>" id="tab-activity">
                <?php if (empty($activity)): ?>
                <div class="empty-state">No activity recorded yet.</div>
                <?php else: ?>
                    <?php foreach ($activity as $act): ?>
                    <div class="activity-item">
                        <div class="activity-icon">📋</div>
                        <div class="activity-content">
                            <div class="activity-text">
                                <?php if ($act['first_name']): ?>
                                    <strong><?php echo htmlspecialchars($act['first_name'] . ' ' . $act['last_name']); ?></strong>
                                <?php else: ?>
                                    <strong>System</strong>
                                <?php endif; ?>
                                <?php echo str_replace('_', ' ', $act['activity_type']); ?>
                            </div>
                            <div class="activity-time">
                                <?php echo date('M j, Y g:i A', strtotime($act['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Shoutbox Tab -->
            <div class="tab-pane <?php echo $activeTab === 'shoutbox' ? 'active' : ''; ?>" id="tab-shoutbox">
                <div style="max-width:900px;">
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:24px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                            <h3 style="margin:0;font-size:1.3rem;color:#0f172a;">💬 Team Chat</h3>
                            <button onclick="refreshShoutbox()" style="padding:8px 16px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:0.9rem;">
                                🔄 Refresh
                            </button>
                        </div>
                        
                        <div id="shoutbox-messages" style="min-height:400px;max-height:500px;overflow-y:auto;margin-bottom:20px;padding:16px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;display:flex;flex-direction:column;gap:12px;">
                            <!-- Messages load here -->
                        </div>
                        
                        <form id="shoutbox-form" style="display:flex;flex-direction:column;gap:12px;">
                            <textarea id="shoutbox-input" placeholder="Type your message... Use @username or @filename to mention teammates or evidence" style="width:100%;min-height:100px;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font-size:0.95rem;resize:vertical;font-family:inherit;"></textarea>
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <p style="margin:0;font-size:0.85rem;color:#94a3b8;">💡 Tip: Use @mentions to notify team members or reference evidence files</p>
                                <button type="submit" style="padding:12px 24px;background:#0d47a1;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:0.95rem;">
                                    Send Message
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="save-indicator" id="saveIndicator">
    ✓ Saved successfully
</div>

<script>
const caseId = <?php echo $isNew ? 'null' : $case['id']; ?>;
const isNew = <?php echo $isNew ? 'true' : 'false'; ?>;

// Tab switching
document.querySelectorAll('.tab-button').forEach(btn => {
    btn.addEventListener('click', () => {
        const tab = btn.dataset.tab;
        
        document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        
        btn.classList.add('active');
        document.getElementById('tab-' + tab).classList.add('active');
        
        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);
    });
});

// Details form submit
async function handleDetailsSubmit(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    
    try {
        const response = await fetch('<?php echo path_with_base('/api/legal/case-create.php'); ?>', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSaveIndicator();
            if (isNew && result.case_id) {
                // Redirect to the new case editor
                window.location.href = '<?php echo path_with_base('/admin/legal/case-editor.php'); ?>?id=' + result.case_id;
            }
        } else {
            alert('Error: ' + (result.message || 'Failed to save'));
        }
    } catch (error) {
        alert('Error saving case: ' + error.message);
    }
    
    return false;
}

// Summary form submit
async function handleSummarySubmit(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    
    const payload = {
        case_id: parseInt(formData.get('case_id')),
        summary: formData.get('summary')
    };
    
    try {
        const response = await fetch('<?php echo path_with_base('/api/legal/update-case.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        
        const result = await response.json();
        
        if (result.ok) {
            showSaveIndicator();
        } else {
            alert('Error: ' + (result.message || 'Failed to save summary'));
        }
    } catch (error) {
        console.error('Save summary error:', error);
        alert('Error saving summary: ' + error.message);
    }
    
    return false;
}

// Add party
async function handleAddParty(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    formData.append('case_id', caseId);
    
    try {
        const response = await fetch('<?php echo path_with_base('/api/legal/case-parties-update.php'); ?>', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSaveIndicator();
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'Failed to add party'));
        }
    } catch (error) {
        alert('Error adding party: ' + error.message);
    }
    
    return false;
}

// Remove party
async function removeParty(partyId) {
    if (!confirm('Remove this party from the case?')) return;
    
    const formData = new FormData();
    formData.append('action', 'remove');
    formData.append('party_id', partyId);
    
    try {
        const response = await fetch('<?php echo path_with_base('/api/legal/case-parties-update.php'); ?>', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSaveIndicator();
            document.querySelector(`[data-party-id="${partyId}"]`).remove();
        } else {
            alert('Error: ' + (result.message || 'Failed to remove party'));
        }
    } catch (error) {
        alert('Error removing party: ' + error.message);
    }
}

// Add team member
async function handleAddTeamMember(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    formData.append('case_id', caseId);
    
    try {
        const response = await fetch('<?php echo path_with_base('/api/legal/assign-team-member.php'); ?>', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSaveIndicator();
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'Failed to add team member'));
        }
    } catch (error) {
        alert('Error adding team member: ' + error.message);
    }
    
    return false;
}

// Remove team member
async function removeTeamMember(memberId) {
    if (!confirm('Remove this team member from the case?')) return;
    
    const formData = new FormData();
    formData.append('member_id', memberId);
    
    try {
        const response = await fetch('<?php echo path_with_base('/api/legal/team-remove.php'); ?>', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSaveIndicator();
            document.querySelector(`[data-member-id="${memberId}"]`).remove();
        } else {
            alert('Error: ' + (result.message || 'Failed to remove team member'));
        }
    } catch (error) {
        alert('Error removing team member: ' + error.message);
    }
}

// Add note
async function handleAddNote(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    
    const payload = {
        case_id: caseId,
        note_body: formData.get('note_body'),
        visibility: 'team',
        pinned: formData.get('pinned') === 'on' ? true : false
    };
    
    try {
        const response = await fetch('<?php echo path_with_base('/api/legal/add-note.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        
        const result = await response.json();
        
        if (result.ok) {
            showSaveIndicator();
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'Failed to add note'));
        }
    } catch (error) {
        console.error('Add note error:', error);
        alert('Error adding note: ' + error.message);
    }
    
    return false;
}

function showSaveIndicator() {
    const indicator = document.getElementById('saveIndicator');
    indicator.classList.add('show');
    setTimeout(() => {
        indicator.classList.remove('show');
    }, 2000);
}

function saveCase() {
    const activeTab = document.querySelector('.tab-pane.active').id;
    
    if (activeTab === 'tab-details') {
        document.getElementById('detailsForm').dispatchEvent(new Event('submit'));
    } else if (activeTab === 'tab-summary') {
        document.getElementById('summaryForm').dispatchEvent(new Event('submit'));
    }
}

// ========== SHOUTBOX FUNCTIONALITY ==========
<?php if (!$isNew): ?>
const isAdmin = <?php echo user_has_role($currentUser, 'admin') ? 'true' : 'false'; ?>;
let lastNoteId = 0;
const shoutboxMessages = document.getElementById('shoutbox-messages');
const shoutboxForm = document.getElementById('shoutbox-form');
const shoutboxInput = document.getElementById('shoutbox-input');

function renderShoutboxMessage(note) {
    const div = document.createElement('div');
    const isCurrentUser = note.author_id === <?php echo $currentUser; ?>;
    
    div.style.cssText = `
        padding: 12px 16px;
        background: ${isCurrentUser ? '#eff6ff' : '#f8fafc'};
        border: 1px solid ${isCurrentUser ? '#bfdbfe' : '#e2e8f0'};
        border-radius: 8px;
        position: relative;
    `;
    
    const authorName = note.first_name && note.last_name ? 
        `${note.first_name} ${note.last_name}` : 'Unknown';
    
    const messageContent = note.content
        .replace(/@(\w+)/g, '<strong style="color:#1d4ed8;">@$1</strong>')
        .replace(/\n/g, '<br>');
    
    div.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:6px;">
            <strong style="color:#0f172a;font-size:0.95rem;">${authorName}</strong>
            <span style="font-size:0.8rem;color:#94a3b8;">${new Date(note.created_at).toLocaleString()}</span>
        </div>
        <div style="color:#1f2937;font-size:0.9rem;line-height:1.5;">${messageContent}</div>
        ${isAdmin || isCurrentUser ? `
            <button onclick="deleteShoutboxMessage(${note.id})" style="position:absolute;top:8px;right:8px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:0.75rem;">
                🗑️ Delete
            </button>
        ` : ''}
    `;
    
    return div;
}

async function loadShoutbox() {
    try {
        const res = await fetch(`<?php echo path_with_base('/api/legal/shoutbox-fetch.php'); ?>?case_id=${caseId}&since=${lastNoteId}`);
        const json = await res.json();
        if (json.ok && json.notes.length > 0) {
            json.notes.forEach(note => {
                shoutboxMessages.appendChild(renderShoutboxMessage(note));
                lastNoteId = Math.max(lastNoteId, note.id);
            });
            shoutboxMessages.scrollTop = shoutboxMessages.scrollHeight;
        }
    } catch (err) {
        console.error('Shoutbox load error:', err);
    }
}

function refreshShoutbox() {
    lastNoteId = 0;
    shoutboxMessages.innerHTML = '';
    loadShoutbox();
}

shoutboxForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const message = shoutboxInput?.value.trim();
    if (!message) return;
    
    try {
        const res = await fetch('<?php echo path_with_base('/api/legal/shoutbox-post.php'); ?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 
                'Content-Type': 'application/json', 
                'X-Requested-With': 'XMLHttpRequest' 
            },
            body: JSON.stringify({ case_id: caseId, message })
        });
        const json = await res.json();
        if (json.ok) {
            shoutboxInput.value = '';
            shoutboxMessages.appendChild(renderShoutboxMessage(json.note));
            lastNoteId = json.note.id;
            shoutboxMessages.scrollTop = shoutboxMessages.scrollHeight;
        } else {
            alert(json.message || 'Failed to send message');
        }
    } catch (err) {
        console.error('Send error:', err);
        alert('Failed to send message');
    }
});

async function deleteShoutboxMessage(noteId) {
    if (!confirm('Delete this message?')) return;
    
    try {
        const res = await fetch('<?php echo path_with_base('/api/legal/shoutbox-delete.php'); ?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 
                'Content-Type': 'application/json', 
                'X-Requested-With': 'XMLHttpRequest' 
            },
            body: JSON.stringify({ note_id: noteId })
        });
        const json = await res.json();
        if (json.ok) {
            refreshShoutbox();
        } else {
            alert(json.message || 'Failed to delete');
        }
    } catch (err) {
        console.error('Delete error:', err);
        alert('Failed to delete message');
    }
}

// Load shoutbox when tab is active
if (document.querySelector('#tab-shoutbox.active')) {
    loadShoutbox();
    setInterval(loadShoutbox, 5000);
}

// Load shoutbox when switching to the tab
document.querySelectorAll('.tab-button').forEach(btn => {
    btn.addEventListener('click', () => {
        if (btn.dataset.tab === 'shoutbox') {
            setTimeout(() => {
                if (!shoutboxMessages.children.length) {
                    loadShoutbox();
                }
                setInterval(loadShoutbox, 5000);
            }, 100);
        }
    });
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

