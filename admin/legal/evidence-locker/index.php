<?php
require_once __DIR__ . '/../../../required/header.php';
require_once __DIR__ . '/../../../required/legal-permissions.php';
require_once __DIR__ . '/../../../required/legal-case-service.php';
require_once __DIR__ . '/../../../required/legal-session.php';
require_legal_or_admin(path_with_base('/admin/'));

start_session();
$pdo = db();
$currentUser = current_user_id();
$service = new LegalCaseService($pdo);

$LOCKER_SESSION_KEY = 'admin_legal_locker_access';
$ALLOWED_HASH = getenv('RAWOPS_LEGAL_LOCKER_HASH') ?: ''; // No bundled access credential.
$PASSWORD_HINT = '';

$caseId = isset($_GET['case_id']) ? (int)$_GET['case_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    $response = ['success' => false];

    if ($action === 'unlock') {
        $password = trim($_POST['password'] ?? '');
        if ($password !== '') {
            $hash = hash('sha256', $password);
            if ($ALLOWED_HASH !== '' && hash_equals($ALLOWED_HASH, $hash)) {
                $_SESSION[$LOCKER_SESSION_KEY] = true;
                $response['success'] = true;
            } else {
                $response['message'] = 'Incorrect password.';
            }
        } else {
            $response['message'] = 'Enter a password.';
        }
    } elseif ($action === 'lock') {
        unset($_SESSION[$LOCKER_SESSION_KEY]);
        $response['success'] = true;
    }

    echo json_encode($response);
    exit;
}

// Auto-unlock for admin and legal role users
$isAdmin = $currentUser ? user_has_role($currentUser, 'admin') : false;
$isLegal = $currentUser ? user_has_role($currentUser, 'legal') : false;
$isUnlocked = ($isAdmin || $isLegal) || (($_SESSION[$LOCKER_SESSION_KEY] ?? false) === true);

// Prepare file metadata from database
$lockerDir = __DIR__;
$lockerUrlBase = path_with_base('/admin/legal/evidence-locker/');
$entries = [];

// Sorting options
$sortBy = $_GET['sort'] ?? 'uploaded_at';
$sortOrder = $_GET['order'] ?? 'DESC';

$validSorts = ['uploaded_at', 'evidence_date', 'timeline_date', 'exhibit_number', 'sort_order', 'display_name'];
if (!in_array($sortBy, $validSorts, true)) {
    $sortBy = 'uploaded_at';
}
if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
    $sortOrder = 'DESC';
}

if ($isUnlocked) {
    // Build query based on case filter
    $orderClause = "ORDER BY d.$sortBy $sortOrder, d.uploaded_at DESC";
    
    if ($caseId) {
        $stmt = $pdo->prepare("SELECT d.*, c.title AS case_title FROM legal_case_documents d JOIN legal_cases c ON c.id = d.case_id WHERE d.case_id = ? $orderClause");
        $stmt->execute([$caseId]);
    } else {
        $stmt = $pdo->query("SELECT d.*, c.title AS case_title FROM legal_case_documents d JOIN legal_cases c ON c.id = d.case_id $orderClause");
    }
    $dbDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($dbDocs as $doc) {
        $fileName = basename($doc['storage_path']);
        $fullPath = $lockerDir . DIRECTORY_SEPARATOR . $fileName;
        
        // If file exists physically, merge DB + file data; otherwise show DB-only entry
        if (is_file($fullPath)) {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $size = $doc['file_size_bytes'] ?: filesize($fullPath);
            $mtime = filemtime($fullPath);
        } else {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $size = $doc['file_size_bytes'] ?: 0;
            $mtime = strtotime($doc['uploaded_at']);
        }
        
        $entries[] = [
            'id' => $doc['id'],
            'case_id' => $doc['case_id'],
            'case_title' => $doc['case_title'],
            'name' => $doc['display_name'],
            'file_name' => $fileName,
            'storage_path' => $doc['storage_path'],
            'description' => $doc['description'],
            'ext' => $ext,
            'size' => $size,
            'modified' => $mtime,
            'url' => $lockerUrlBase . rawurlencode($fileName),
            'download_count' => $doc['download_count'],
            'document_type' => $doc['document_type'],
            'exhibit_number' => $doc['exhibit_number'],
            'evidence_date' => $doc['evidence_date'],
            'timeline_date' => $doc['timeline_date'],
            'is_key_evidence' => $doc['is_key_evidence'],
            'notes' => $doc['notes'],
            'tags' => $doc['tags'] ? json_decode($doc['tags'], true) : []
        ];
    }
}

try {
    $sessionConfig = legal_session_bootstrap([
        'page_url' => path_with_base('/admin/legal/evidence-locker/index.php' . ($caseId ? '?case_id=' . $caseId : ''))
    ]);
} catch (Throwable $e) {
    error_log('[legal/evidence-locker] session bootstrap failed: ' . $e->getMessage());
    $sessionConfig = null;
}

function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = $bytes;
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    return number_format($size, $size < 10 && $unitIndex > 0 ? 1 : 0) . ' ' . $units[$unitIndex];
}

function lockerIcon(string $ext): string {
    $ext = strtolower($ext);
    $map = [
        'jpg' => '🖼️',
        'jpeg' => '🖼️',
        'png' => '🖼️',
        'webp' => '🖼️',
        'pdf' => '📄',
        'doc' => '📄',
        'docx' => '📄',
        'xls' => '📊',
        'xlsx' => '📊',
        'csv' => '📊',
    ];
    return $map[$ext] ?? '📁';
}

$allowedPreview = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
?>

<style>
.locker-shell {
    max-width: 1200px;
    margin: 60px auto;
    padding: 0 24px 60px 24px;
}

.locker-card {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
    padding: 32px;
    border: 1px solid #dbe2ec;
}

.locker-card h1 {
    margin-top: 0;
    font-size: 2rem;
    color: #0f172a;
}

.locker-actions {
    display: flex;
    gap: 12px;
    margin-top: 24px;
    flex-wrap: wrap;
}

.locker-button {
    padding: 12px 18px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.locker-button.primary {
    background: #0d47a1;
    color: #fff;
}

.locker-button.secondary {
    background: #f1f5f9;
    color: #0f172a;
}

.locker-grid {
    margin-top: 32px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 18px;
}

.locker-item {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    background: linear-gradient(180deg, rgba(241,245,249,0.65) 0%, rgba(255,255,255,0.8) 100%);
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.locker-item h3 {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
    word-break: break-word;
    display: flex;
    align-items: center;
    gap: 8px;
}

.locker-item .meta {
    font-size: 0.85rem;
    color: #64748b;
}

.locker-preview {
    position: relative;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    background: #0f172a;
    display: grid;
    place-items: center;
    color: #fff;
    min-height: 180px;
}

.locker-preview img,
.locker-preview iframe {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.locker-preview iframe {
    border: none;
}

.locker-empty {
    margin-top: 24px;
    padding: 24px;
    border: 1px dashed #9ca3af;
    border-radius: 10px;
    background: #f8fafc;
    color: #475569;
    text-align: center;
}

.locker-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
    max-width: 420px;
}

.locker-form input[type="password"] {
    padding: 12px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 1rem;
}

.locker-form button {
    align-self: flex-start;
}

.locker-hint {
    font-size: 0.85rem;
    color: #64748b;
}

.locker-alert {
    margin: 0;
    padding: 12px 14px;
    border-radius: 6px;
    font-size: 0.95rem;
}

.locker-alert.error {
    background: rgba(220, 53, 69, 0.12);
    border: 1px solid rgba(220, 53, 69, 0.3);
    color: #b0222f;
}

.locker-breadcrumb {
    font-size: 0.9rem;
    color: #475569;
    margin-bottom: 16px;
}

.locker-breadcrumb a {
    color: #0d47a1;
    text-decoration: none;
    font-weight: 600;
}

.locker-breadcrumb a:hover {
    text-decoration: underline;
}

.locker-tools {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.locker-tools .status {
    background: #ecfeff;
    color: #0f766e;
    border: 1px solid #99f6e4;
    padding: 6px 12px;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 600;
}

.locker-download {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.95rem;
    color: #0f172a;
    text-decoration: none;
    font-weight: 600;
}

.locker-download:hover {
    color: #0d47a1;
}

.locker-preview-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.9rem;
    color: #0d47a1;
    text-decoration: none;
}

.locker-preview-link:hover {
    text-decoration: underline;
}

.locker-meta-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.locker-meta-row .time {
    font-size: 0.8rem;
    color: #94a3b8;
}

.timeline-nav {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 24px;
    margin: 24px 0;
}

.timeline-toggle {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
}

.timeline-toggle button {
    padding: 8px 16px;
    border: 1px solid #cbd5e1;
    background: #fff;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.9rem;
}

.timeline-toggle button.active {
    background: #0d47a1;
    color: #fff;
    border-color: #0d47a1;
}

.timeline-horizontal {
    display: flex;
    overflow-x: auto;
    gap: 16px;
    padding: 16px 0;
}

.timeline-vertical {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.timeline-item {
    position: relative;
    min-width: 200px;
    cursor: pointer;
    transition: all 0.2s;
}

.timeline-horizontal .timeline-item {
    flex-shrink: 0;
}

.timeline-vertical .timeline-item {
    padding-left: 32px;
    padding-bottom: 24px;
    border-left: 3px solid #cbd5e1;
}

.timeline-vertical .timeline-item:last-child {
    border-left-color: transparent;
}

.timeline-vertical .timeline-item::before {
    content: '';
    position: absolute;
    left: -9px;
    top: 0;
    width: 15px;
    height: 15px;
    border-radius: 50%;
    background: #0d47a1;
    border: 3px solid #fff;
    box-shadow: 0 0 0 2px #cbd5e1;
}

.timeline-vertical .timeline-item.key-evidence::before {
    background: #fbbf24;
    box-shadow: 0 0 0 2px #fbbf24;
}

.timeline-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
    box-shadow: 0 2px 8px rgba(15,23,42,0.06);
}

.timeline-horizontal .timeline-card:hover,
.timeline-vertical .timeline-card:hover {
    border-color: #0d47a1;
    box-shadow: 0 4px 12px rgba(13,71,161,0.15);
}

.timeline-date-badge {
    display: inline-block;
    background: #eff6ff;
    color: #1d4ed8;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-bottom: 8px;
}

.timeline-title {
    font-weight: 600;
    color: #0f172a;
    font-size: 0.95rem;
    margin-bottom: 6px;
}

.timeline-desc {
    font-size: 0.85rem;
    color: #64748b;
    line-height: 1.4;
}

@media (max-width: 768px) {
    .locker-card {
        padding: 24px;
    }

    .locker-grid {
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    }
    
    .timeline-horizontal {
        flex-direction: column;
    }
    
    .timeline-item {
        min-width: auto;
    }
}
</style>

<main class="locker-shell">
    <div class="locker-card">
        <?php
        $breadcrumbs = [];
        if ($caseId) {
            $caseTitle = $pdo->prepare('SELECT title FROM legal_cases WHERE id = ? LIMIT 1');
            $caseTitle->execute([$caseId]);
            $title = $caseTitle->fetchColumn();
            $breadcrumbs[] = ['label' => $title, 'url' => path_with_base('/admin/legal/case-view.php?id=' . $caseId)];
        }
        $breadcrumbs[] = ['label' => 'Evidence Locker', 'url' => null];
        include __DIR__ . '/../partials/breadcrumb.php';
        ?>
        <h1>Secured Evidence Locker<?php if ($caseId): ?> — <?php $c = $pdo->query("SELECT title FROM legal_cases WHERE id = $caseId")->fetchColumn(); echo sanitize($c); ?><?php endif; ?></h1>
        <p style="margin-top:12px; font-size:1.05rem; color:#475569; max-width:780px;">
            This section contains authenticated exhibits <?php echo $caseId ? 'for this case' : 'across all cases'; ?>. Access is restricted. A valid password grants a temporary session until locked.
        </p>
        
        <?php if ($isUnlocked && !empty($entries)): ?>
            <!-- Chronological Timeline Navigation -->
            <?php
            $timelineEntries = array_filter($entries, fn($e) => !empty($e['timeline_date']) || !empty($e['evidence_date']));
            if (!empty($timelineEntries)):
                // Sort by timeline_date or evidence_date for timeline display
                usort($timelineEntries, function($a, $b) {
                    $dateA = $a['timeline_date'] ?: $a['evidence_date'] ?: '9999-12-31';
                    $dateB = $b['timeline_date'] ?: $b['evidence_date'] ?: '9999-12-31';
                    return strcmp($dateA, $dateB);
                });
            ?>
                <div class="timeline-nav">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                        <h3 style="margin:0;font-size:1.2rem;color:#0f172a;">📅 Case Timeline</h3>
                        <div class="timeline-toggle">
                            <button id="timeline-horizontal-btn" class="active" onclick="switchTimelineView('horizontal')">Horizontal</button>
                            <button id="timeline-vertical-btn" onclick="switchTimelineView('vertical')">Vertical</button>
                        </div>
                    </div>
                    
                    <div id="timeline-container-horizontal" class="timeline-horizontal">
                        <?php foreach ($timelineEntries as $item): ?>
                            <div class="timeline-item <?php echo $item['is_key_evidence'] ? 'key-evidence' : ''; ?>" onclick="scrollToEvidence(<?php echo $item['id']; ?>)">
                                <div class="timeline-card">
                                    <div class="timeline-date-badge">
                                        <?php 
                                        $displayDate = $item['timeline_date'] ?: $item['evidence_date'];
                                        echo date('M j, Y', strtotime($displayDate)); 
                                        ?>
                                    </div>
                                    <div class="timeline-title"><?php echo sanitize(substr($item['name'], 0, 50)); ?><?php echo strlen($item['name']) > 50 ? '...' : ''; ?></div>
                                    <?php if ($item['description']): ?>
                                        <div class="timeline-desc"><?php echo sanitize(substr($item['description'], 0, 80)); ?><?php echo strlen($item['description']) > 80 ? '...' : ''; ?></div>
                                    <?php endif; ?>
                                    <?php if ($item['exhibit_number']): ?>
                                        <div style="margin-top:8px;font-size:0.75rem;color:#1d4ed8;font-weight:600;"><?php echo sanitize($item['exhibit_number']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div id="timeline-container-vertical" class="timeline-vertical" style="display:none;">
                        <?php foreach ($timelineEntries as $item): ?>
                            <div class="timeline-item <?php echo $item['is_key_evidence'] ? 'key-evidence' : ''; ?>" onclick="scrollToEvidence(<?php echo $item['id']; ?>)">
                                <div class="timeline-card">
                                    <div class="timeline-date-badge">
                                        <?php 
                                        $displayDate = $item['timeline_date'] ?: $item['evidence_date'];
                                        echo date('M j, Y', strtotime($displayDate)); 
                                        ?>
                                    </div>
                                    <div class="timeline-title"><?php echo sanitize($item['name']); ?></div>
                                    <?php if ($item['description']): ?>
                                        <div class="timeline-desc"><?php echo sanitize($item['description']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($item['exhibit_number']): ?>
                                        <div style="margin-top:8px;font-size:0.8rem;color:#1d4ed8;font-weight:600;"><?php echo sanitize($item['exhibit_number']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Sorting Controls -->
            <div style="margin-top:20px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <label style="font-weight:600;color:#0f172a;">Sort by:</label>
                <select id="sort-select" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                    <option value="uploaded_at" <?php echo $sortBy === 'uploaded_at' ? 'selected' : ''; ?>>Upload Date</option>
                    <option value="evidence_date" <?php echo $sortBy === 'evidence_date' ? 'selected' : ''; ?>>Evidence Date</option>
                    <option value="timeline_date" <?php echo $sortBy === 'timeline_date' ? 'selected' : ''; ?>>Timeline Date</option>
                    <option value="exhibit_number" <?php echo $sortBy === 'exhibit_number' ? 'selected' : ''; ?>>Exhibit Number</option>
                    <option value="display_name" <?php echo $sortBy === 'display_name' ? 'selected' : ''; ?>>Name</option>
                    <option value="sort_order" <?php echo $sortBy === 'sort_order' ? 'selected' : ''; ?>>Manual Order</option>
                </select>
                <select id="order-select" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                    <option value="ASC" <?php echo $sortOrder === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                    <option value="DESC" <?php echo $sortOrder === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                </select>
                <button onclick="applySorting()" style="padding:8px 16px;background:#0d47a1;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Apply</button>
            </div>
        <?php endif; ?>

        <?php if (!$isUnlocked): ?>
            <form class="locker-form" method="post" id="locker-auth-form">
                <input type="hidden" name="action" value="unlock">
                <label for="locker-password" style="font-weight:600; color:#0f172a;">Enter Access Password</label>
                <input type="password" name="password" id="locker-password" autocomplete="off" placeholder="Password" required>
                <button type="submit" class="locker-button primary">
                    Unlock Evidence Locker
                </button>
                <p class="locker-hint">Hint: Contact legal operations if you have lost the credential.</p>
                <p class="locker-alert error" id="locker-error" style="display:none;">Incorrect password. Please try again.</p>
            </form>
        <?php else: ?>
            <div class="locker-tools">
                <span class="status">Access granted<?php if ($isAdmin || $isLegal): ?> (Role-based)<?php endif; ?></span>
                <?php if (!$isAdmin && !$isLegal): ?>
                    <button type="button" class="locker-button secondary" id="locker-lock">
                        Lock Locker
                    </button>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <button type="button" class="locker-button primary" onclick="document.getElementById('upload-modal').style.display='flex'">
                        ➕ Upload Evidence
                    </button>
                <?php endif; ?>
                <a class="locker-button secondary" href="<?php echo path_with_base('/admin/legal/index.php'); ?>">
                    Return to Dashboard
                </a>
            </div>

            <?php if (empty($entries)): ?>
                <div class="locker-empty">
                    No files have been uploaded yet. Contact the document custodian to provision evidence.
                </div>
            <?php else: ?>
                <section class="locker-grid" aria-label="Evidence files">
                    <?php foreach ($entries as $entry): ?>
                        <article class="locker-item" data-doc-id="<?php echo $entry['id']; ?>">
                            <?php
                            // Check if physical file exists
                            $fileName = basename($entry['storage_path']);
                            $lockerPath = __DIR__ . DIRECTORY_SEPARATOR . $fileName;
                            $fileExists = is_file($lockerPath);
                            ?>
                            <h3>
                                <span aria-hidden="true"><?php echo lockerIcon($entry['ext']); ?></span>
                                <span class="doc-display-name"><?php echo sanitize($entry['name']); ?></span>
                                <?php if (!$fileExists): ?>
                                    <span style="background:#fef2f2;color:#dc2626;font-size:0.7rem;padding:2px 8px;border-radius:999px;margin-left:8px;">FILE MISSING</span>
                                <?php endif; ?>
                            </h3>
                            <div class="meta">
                                <span><?php echo formatBytes($entry['size']); ?></span> • <span style="color:#1d4ed8;"><?php echo sanitize($entry['case_title']); ?></span>
                            </div>
                            <div class="doc-description" style="margin:10px 0 0 0;font-size:0.9rem;color:#475569;line-height:1.5;">
                                <?php echo $entry['description'] ? sanitize($entry['description']) : '<em style="color:#94a3b8;">No description</em>'; ?>
                            </div>
                            <div class="locker-meta-row" style="margin-top:12px;">
                                <a class="locker-download" href="<?php echo sanitize($entry['url']); ?>" download <?php if (!$fileExists) echo 'style="opacity:0.5;pointer-events:none;"'; ?>>
                                    ⬇️ Download
                                </a>
                                <time class="time" datetime="<?php echo date('c', $entry['modified']); ?>"><?php echo date('M j, Y', $entry['modified']); ?></time>
                            </div>
                            <div style="font-size:0.85rem;color:#94a3b8;margin-top:8px;">
                                Downloads: <?php echo number_format($entry['download_count']); ?> • Type: <span class="doc-type"><?php echo sanitize($entry['document_type']); ?></span>
                                <?php if ($entry['exhibit_number']): ?>
                                    <br>Exhibit: <strong><?php echo sanitize($entry['exhibit_number']); ?></strong>
                                <?php endif; ?>
                                <?php if ($entry['evidence_date']): ?>
                                    <br>Evidence Date: <?php echo date('M j, Y', strtotime($entry['evidence_date'])); ?>
                                <?php endif; ?>
                                <?php if ($entry['is_key_evidence']): ?>
                                    <br><span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:999px;font-size:0.75rem;font-weight:700;">⭐ KEY EVIDENCE</span>
                                <?php endif; ?>
                            </div>
                            <?php if (user_has_role($currentUser, 'admin')): ?>
                                <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
                                    <button class="edit-doc-btn" data-id="<?php echo $entry['id']; ?>" data-doc='<?php echo htmlspecialchars(json_encode($entry), ENT_QUOTES); ?>' style="padding:8px 14px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;font-size:0.85rem;font-weight:600;cursor:pointer;">✏️ Edit</button>
                                    <button class="reupload-doc-btn" data-doc='<?php echo htmlspecialchars(json_encode($entry), ENT_QUOTES); ?>' style="padding:8px 14px;background:<?php echo !$fileExists ? '#dcfce7' : '#f1f5f9'; ?>;border:1px solid <?php echo !$fileExists ? '#86efac' : '#cbd5e1'; ?>;color:<?php echo !$fileExists ? '#15803d' : '#0f172a'; ?>;border-radius:6px;font-size:0.85rem;font-weight:600;cursor:pointer;"><?php echo !$fileExists ? '🔄 Re-upload Required' : '🔄 Replace'; ?></button>
                                    <button class="delete-doc-btn" data-id="<?php echo $entry['id']; ?>" data-name="<?php echo sanitize($entry['name']); ?>" style="padding:8px 14px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:6px;font-size:0.85rem;font-weight:600;cursor:pointer;">🗑️ Delete</button>
                                </div>
                            <?php endif; ?>
                            <?php if ($fileExists && in_array($entry['ext'], $allowedPreview, true)): ?>
                                <div class="locker-preview" aria-label="Preview of <?php echo sanitize($entry['name']); ?>" style="margin-top:12px;">
                                    <?php if (in_array($entry['ext'], ['jpg', 'jpeg', 'png', 'webp'], true)): ?>
                                        <img src="<?php echo sanitize($entry['url']); ?>" alt="Preview of <?php echo sanitize($entry['name']); ?>">
                                    <?php else: ?>
                                        <iframe src="<?php echo sanitize($entry['url']); ?>#toolbar=0&navpanes=0" title="<?php echo sanitize($entry['name']); ?>"></iframe>
                                    <?php endif; ?>
                                </div>
                                <a class="locker-preview-link" href="<?php echo sanitize($entry['url']); ?>" target="_blank" rel="noopener">
                                    Open full preview ↗
                                </a>
                            <?php elseif (!$fileExists): ?>
                                <div style="margin-top:12px;padding:16px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;text-align:center;">
                                    <div style="font-size:2rem;margin-bottom:8px;">⚠️</div>
                                    <div style="color:#991b1b;font-weight:600;font-size:0.9rem;">File Not Found</div>
                                    <div style="color:#dc2626;font-size:0.8rem;margin-top:4px;">The physical file is missing from the server. Please re-upload.</div>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<!-- Edit Evidence Modal -->
<div id="edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;max-width:700px;width:90%;max-height:90vh;overflow-y:auto;padding:32px;box-shadow:0 24px 48px rgba(0,0,0,0.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
            <h2 style="margin:0;font-size:1.6rem;color:#0f172a;">Edit Evidence Metadata</h2>
            <button id="close-modal" style="border:none;background:transparent;font-size:2rem;cursor:pointer;color:#6b7280;">&times;</button>
        </div>
        
        <form id="edit-form" style="display:grid;gap:16px;">
            <input type="hidden" id="edit-doc-id">
            
            <div>
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Display Name</label>
                <input type="text" id="edit-display-name" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
            </div>
            
            <div>
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Description</label>
                <textarea id="edit-description" style="width:100%;min-height:80px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Document Type</label>
                    <select id="edit-document-type" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                        <option value="exhibit">Exhibit</option>
                        <option value="analysis">Legal Analysis</option>
                        <option value="correspondence">Correspondence</option>
                        <option value="financial">Financial</option>
                        <option value="media">Media</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Exhibit Number</label>
                    <input type="text" id="edit-exhibit-number" placeholder="e.g., Exhibit A, P-001" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Evidence Date</label>
                    <input type="date" id="edit-evidence-date" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                    <small style="display:block;margin-top:4px;color:#64748b;font-size:0.85rem;">When evidence was created/captured</small>
                </div>
                
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Timeline Date</label>
                    <input type="date" id="edit-timeline-date" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                    <small style="display:block;margin-top:4px;color:#64748b;font-size:0.85rem;">Date for case chronology</small>
                </div>
            </div>
            
            <div>
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Internal Notes</label>
                <textarea id="edit-notes" placeholder="Internal notes about this evidence..." style="width:100%;min-height:60px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
            </div>
            
            <div>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="edit-key-evidence">
                    <span style="font-weight:600;color:#0f172a;">⭐ Mark as Key Evidence</span>
                </label>
            </div>
            
            <div style="display:flex;gap:12px;margin-top:16px;">
                <button type="submit" style="flex:1;padding:12px 24px;background:#10b981;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;">Save Changes</button>
                <button type="button" id="cancel-edit" style="flex:1;padding:12px 24px;background:#f1f5f9;color:#0f172a;border:none;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Upload Evidence Modal -->
<div id="upload-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;max-width:700px;width:90%;max-height:90vh;overflow-y:auto;padding:32px;box-shadow:0 24px 48px rgba(0,0,0,0.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
            <h2 style="margin:0;font-size:1.6rem;color:#0f172a;">➕ Upload New Evidence</h2>
            <button onclick="closeUploadModal()" style="border:none;background:transparent;font-size:2rem;cursor:pointer;color:#6b7280;">&times;</button>
        </div>
        
        <form id="upload-form" enctype="multipart/form-data" style="display:grid;gap:16px;">
            <div>
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Evidence File *</label>
                <input type="file" id="upload-file" name="evidence_file" required style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                <small style="display:block;margin-top:4px;color:#64748b;font-size:0.85rem;">Maximum 50MB. Supported: PDF, JPG, PNG, DOC, XLS, etc.</small>
            </div>
            
            <div>
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Display Name *</label>
                <input type="text" id="upload-display-name" name="display_name" required placeholder="e.g., Payment Receipt - Example Party A" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
            </div>
            
            <div>
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Description</label>
                <textarea id="upload-description" name="description" placeholder="Detailed description of this evidence..." style="width:100%;min-height:80px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Document Type *</label>
                    <select id="upload-document-type" name="document_type" required style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                        <option value="exhibit">Exhibit</option>
                        <option value="analysis">Legal Analysis</option>
                        <option value="correspondence">Correspondence</option>
                        <option value="financial">Financial</option>
                        <option value="media">Media</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Exhibit Number</label>
                    <input type="text" id="upload-exhibit-number" name="exhibit_number" placeholder="e.g., EXHIBIT-A-001" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Evidence Date</label>
                    <input type="date" id="upload-evidence-date" name="evidence_date" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                    <small style="display:block;margin-top:4px;color:#64748b;font-size:0.85rem;">When evidence was created</small>
                </div>
                
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Timeline Date *</label>
                    <input type="date" id="upload-timeline-date" name="timeline_date" required style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                    <small style="display:block;margin-top:4px;color:#64748b;font-size:0.85rem;">Date for case timeline</small>
                </div>
            </div>
            
            <div>
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Internal Notes</label>
                <textarea id="upload-notes" name="notes" placeholder="Internal notes about this evidence..." style="width:100%;min-height:60px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
            </div>
            
            <div>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="upload-key-evidence" name="is_key_evidence" value="1">
                    <span style="font-weight:600;color:#0f172a;">⭐ Mark as Key Evidence</span>
                </label>
            </div>
            
            <input type="hidden" name="case_id" value="<?php echo $caseId ?: ''; ?>">
            
            <div style="display:flex;gap:12px;margin-top:16px;">
                <button type="submit" style="flex:1;padding:12px 24px;background:#1e3a8a;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;">Upload Evidence</button>
                <button type="button" onclick="closeUploadModal()" style="flex:1;padding:12px 24px;background:#f1f5f9;color:#0f172a;border:none;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
            </div>
            
            <div id="upload-progress" style="display:none;margin-top:12px;">
                <div style="background:#f1f5f9;border-radius:999px;height:8px;overflow:hidden;">
                    <div id="upload-progress-bar" style="background:#1e3a8a;height:100%;width:0%;transition:width 0.3s;"></div>
                </div>
                <p id="upload-status" style="text-align:center;margin-top:8px;font-size:0.9rem;color:#64748b;">Uploading...</p>
            </div>
        </form>
    </div>
</div>

<!-- Re-upload Evidence Modal -->
<div id="reupload-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;max-width:600px;width:90%;padding:32px;box-shadow:0 24px 48px rgba(0,0,0,0.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
            <h2 style="margin:0;font-size:1.6rem;color:#0f172a;">🔄 Re-upload Evidence File</h2>
            <button onclick="closeReuploadModal()" style="border:none;background:transparent;font-size:2rem;cursor:pointer;color:#6b7280;">&times;</button>
        </div>
        
        <div style="padding:16px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;margin-bottom:20px;">
            <div style="font-weight:600;color:#92400e;margin-bottom:8px;">Document: <span id="reupload-doc-name"></span></div>
            <div style="font-size:0.85rem;color:#854d0e;">Current file: <code id="reupload-file-name" style="background:#fef9c3;padding:2px 6px;border-radius:4px;"></code></div>
            <div style="font-size:0.85rem;color:#854d0e;margin-top:6px;">⚠️ This will replace the physical file but keep all metadata and history.</div>
        </div>
        
        <form id="reupload-form" enctype="multipart/form-data" style="display:grid;gap:16px;">
            <input type="hidden" id="reupload-doc-id" name="document_id">
            
            <div>
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Select Replacement File *</label>
                <input type="file" name="evidence_file" required style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;">
                <small style="display:block;margin-top:4px;color:#64748b;font-size:0.85rem;">Maximum 50MB. Must match or be compatible with original file type.</small>
            </div>
            
            <div>
                <label style="display:block;font-weight:600;margin-bottom:6px;color:#0f172a;">Reason for Re-upload</label>
                <textarea name="reupload_reason" placeholder="e.g., Original file corrupted, updated version, better quality scan..." style="width:100%;min-height:80px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;"></textarea>
            </div>
            
            <div style="display:flex;gap:12px;margin-top:16px;">
                <button type="submit" style="flex:1;padding:12px 24px;background:#15803d;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;">🔄 Replace File</button>
                <button type="button" onclick="closeReuploadModal()" style="flex:1;padding:12px 24px;background:#f1f5f9;color:#0f172a;border:none;border-radius:8px;font-weight:600;cursor:pointer;">Cancel</button>
            </div>
            
            <div id="reupload-progress" style="display:none;margin-top:12px;">
                <div style="background:#f1f5f9;border-radius:999px;height:8px;overflow:hidden;">
                    <div id="reupload-progress-bar" style="background:#15803d;height:100%;width:0%;transition:width 0.3s;"></div>
                </div>
                <p id="reupload-status" style="text-align:center;margin-top:8px;font-size:0.9rem;color:#64748b;">Uploading...</p>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($sessionConfig)): ?>
<script>window.__LEGAL_ACTIVITY_CONFIG = <?php echo json_encode($sessionConfig, JSON_UNESCAPED_SLASHES); ?>;</script>
<?php endif; ?>

<script>
// Define path_with_base helper for JavaScript
function path_with_base(path) {
    const base = window.__BASE_PREFIX || '';
    if (path.startsWith('/')) {
        return base + path;
    }
    return base + '/' + path;
}

(function() {
    <?php if (!empty($sessionConfig)): ?>
    if (!document.querySelector('script[data-legal-tracker="1"]')) {
        const tracker = document.createElement('script');
        tracker.src = path_with_base('/assets/legal-activity-tracker.js?v=<?php echo ASSET_VER; ?>');
        tracker.defer = true;
        tracker.setAttribute('data-legal-tracker', '1');
        document.head.appendChild(tracker);
    }
    <?php endif; ?>

    const form = document.getElementById('locker-auth-form');
    const errorEl = document.getElementById('locker-error');
    const passwordInput = document.getElementById('locker-password');
    const lockBtn = document.getElementById('locker-lock');

    async function postLocker(action, payload = {}) {
        const body = new FormData();
        body.append('action', action);
        Object.entries(payload).forEach(([key, value]) => body.append(key, value));

        const response = await fetch(window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body
        });
        return response.json();
    }

    if (form) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (errorEl) {
                errorEl.style.display = 'none';
            }

            const password = passwordInput?.value.trim() ?? '';
            if (!password) {
                if (errorEl) {
                    errorEl.textContent = 'Enter a password.';
                    errorEl.style.display = 'block';
                }
                passwordInput?.focus();
                return;
            }

            try {
                const result = await postLocker('unlock', { password });
                if (result.success) {
                    window.location.reload();
                } else if (errorEl) {
                    errorEl.textContent = result.message || 'Unable to unlock.';
                    errorEl.style.display = 'block';
                }
            } catch (err) {
                if (errorEl) {
                    errorEl.textContent = 'An error occurred. Please try again.';
                    errorEl.style.display = 'block';
                }
            }
        });
    }

    if (lockBtn) {
        lockBtn.addEventListener('click', async () => {
            try {
                const result = await postLocker('lock');
                if (result.success) {
                    window.location.reload();
                }
            } catch (err) {
                /* swallow */
            }
        });
    }

    // Modal controls
    const editModal = document.getElementById('edit-modal');
    const closeModal = document.getElementById('close-modal');
    const cancelEdit = document.getElementById('cancel-edit');
    const editForm = document.getElementById('edit-form');
    
    function openEditModal(docData) {
        document.getElementById('edit-doc-id').value = docData.id;
        document.getElementById('edit-display-name').value = docData.name || '';
        document.getElementById('edit-description').value = docData.description || '';
        document.getElementById('edit-document-type').value = docData.document_type || 'exhibit';
        document.getElementById('edit-exhibit-number').value = docData.exhibit_number || '';
        document.getElementById('edit-evidence-date').value = docData.evidence_date || '';
        document.getElementById('edit-timeline-date').value = docData.timeline_date || '';
        document.getElementById('edit-notes').value = docData.notes || '';
        document.getElementById('edit-key-evidence').checked = docData.is_key_evidence == 1;
        
        editModal.style.display = 'flex';
    }
    
    function closeEditModal() {
        editModal.style.display = 'none';
    }
    
    closeModal?.addEventListener('click', closeEditModal);
    cancelEdit?.addEventListener('click', closeEditModal);
    
    // Close upload modal function
    window.closeUploadModal = function() {
        document.getElementById('upload-modal').style.display = 'none';
        document.getElementById('upload-form').reset();
        document.getElementById('upload-progress').style.display = 'none';
    };
    
    // Handle upload form submission
    document.getElementById('upload-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const form = e.target;
        const formData = new FormData(form);
        const progressDiv = document.getElementById('upload-progress');
        const progressBar = document.getElementById('upload-progress-bar');
        const statusText = document.getElementById('upload-status');
        
        // Show progress
        progressDiv.style.display = 'block';
        progressBar.style.width = '0%';
        statusText.textContent = 'Uploading...';
        
        try {
            const xhr = new XMLHttpRequest();
            
            // Progress tracking
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    progressBar.style.width = percentComplete + '%';
                    statusText.textContent = `Uploading... ${Math.round(percentComplete)}%`;
                }
            });
            
            // Handle response
            xhr.addEventListener('load', () => {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success || response.ok) {
                            statusText.textContent = 'Upload complete! Reloading...';
                            progressBar.style.width = '100%';
                            setTimeout(() => window.location.reload(), 1000);
                        } else {
                            alert('Upload failed: ' + (response.message || 'Unknown error'));
                            progressDiv.style.display = 'none';
                        }
                    } catch (err) {
                        alert('Upload failed: Invalid response from server');
                        progressDiv.style.display = 'none';
                    }
                } else {
                    alert('Upload failed: Server returned ' + xhr.status);
                    progressDiv.style.display = 'none';
                }
            });
            
            xhr.addEventListener('error', () => {
                alert('Upload failed: Network error');
                progressDiv.style.display = 'none';
            });
            
            xhr.open('POST', path_with_base('/api/legal/upload-evidence.php'));
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(formData);
            
        } catch (err) {
            console.error(err);
            alert('Upload failed: ' + err.message);
            progressDiv.style.display = 'none';
        }
    });
    
    // Edit document metadata
    document.querySelectorAll('.edit-doc-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            try {
                const docData = JSON.parse(btn.dataset.doc);
                openEditModal(docData);
            } catch (err) {
                console.error('Failed to parse document data:', err);
                alert('Unable to load document data');
            }
        });
    });
    
    // Save changes via AJAX
    editForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const docId = parseInt(document.getElementById('edit-doc-id').value, 10);
        const payload = {
            document_id: docId,
            display_name: document.getElementById('edit-display-name').value,
            description: document.getElementById('edit-description').value,
            document_type: document.getElementById('edit-document-type').value,
            exhibit_number: document.getElementById('edit-exhibit-number').value,
            evidence_date: document.getElementById('edit-evidence-date').value,
            timeline_date: document.getElementById('edit-timeline-date').value,
            notes: document.getElementById('edit-notes').value,
            is_key_evidence: document.getElementById('edit-key-evidence').checked
        };
        
        try {
            const res = await fetch(path_with_base('/api/legal/update-evidence-metadata.php'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            });
            const json = await res.json();
            if (json.ok) {
                window.location.reload();
            } else {
                alert(json.message || 'Update failed');
            }
        } catch (err) {
            console.error(err);
            alert('Unable to update document');
        }
    });

    // Re-upload document
    window.openReuploadModal = function(docData) {
        const modal = document.getElementById('reupload-modal');
        document.getElementById('reupload-doc-id').value = docData.id;
        document.getElementById('reupload-doc-name').textContent = docData.name;
        document.getElementById('reupload-file-name').textContent = docData.file_name;
        modal.style.display = 'flex';
    };
    
    window.closeReuploadModal = function() {
        const modal = document.getElementById('reupload-modal');
        modal.style.display = 'none';
        document.getElementById('reupload-form').reset();
        document.getElementById('reupload-progress').style.display = 'none';
    };
    
    document.querySelectorAll('.reupload-doc-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const docData = JSON.parse(btn.dataset.doc);
            window.openReuploadModal(docData);
        });
    });
    
    document.getElementById('reupload-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const progressDiv = document.getElementById('reupload-progress');
        const progressBar = document.getElementById('reupload-progress-bar');
        const statusText = document.getElementById('reupload-status');
        
        progressDiv.style.display = 'block';
        progressBar.style.width = '0%';
        statusText.textContent = 'Uploading...';
        
        try {
            const xhr = new XMLHttpRequest();
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    progressBar.style.width = percentComplete + '%';
                    statusText.textContent = `Uploading... ${Math.round(percentComplete)}%`;
                }
            });
            xhr.addEventListener('load', () => {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success || response.ok) {
                            statusText.textContent = 'Upload complete! Reloading...';
                            progressBar.style.width = '100%';
                            setTimeout(() => window.location.reload(), 1000);
                        } else {
                            alert('Upload failed: ' + (response.message || 'Unknown error'));
                            progressDiv.style.display = 'none';
                        }
                    } catch (err) {
                        alert('Upload failed: Invalid response from server');
                        progressDiv.style.display = 'none';
                    }
                } else {
                    alert('Upload failed: Server returned ' + xhr.status);
                    progressDiv.style.display = 'none';
                }
            });
            xhr.addEventListener('error', () => {
                alert('Upload failed: Network error');
                progressDiv.style.display = 'none';
            });
            xhr.open('POST', path_with_base('/api/legal/reupload-evidence.php'));
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(formData);
        } catch (err) {
            console.error(err);
            alert('Upload failed: ' + err.message);
            progressDiv.style.display = 'none';
        }
    });

    // Delete document
    document.querySelectorAll('.delete-doc-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const docId = parseInt(btn.dataset.id, 10);
            const docName = btn.dataset.name;
            
            if (!confirm(`Delete "${docName}"? This action cannot be undone.`)) {
                return;
            }
            
            const reason = prompt('Select reason:\n1. Duplicate Entry\n2. Filed Under Wrong Name\n3. Filed Under Wrong Category\n4. Incorrect File Uploaded\n\nEnter number (1-4):');
            if (!reason) return;
            
            const reasonMap = {
                '1': 'duplicate_entry',
                '2': 'wrong_name',
                '3': 'wrong_category',
                '4': 'incorrect_file'
            };
            
            const reasonCode = reasonMap[reason.trim()];
            if (!reasonCode) {
                alert('Invalid reason selected');
                return;
            }
            
            try {
                const res = await fetch(path_with_base('/api/legal/delete-document.php'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        document_id: docId,
                        reason: reasonCode
                    })
                });
                const json = await res.json();
                if (json.ok) {
                    window.location.reload();
                } else {
                    alert(json.message || 'Delete failed');
                }
            } catch (err) {
                console.error(err);
                alert('Unable to delete document');
            }
        });
    });
    
    // Sorting controls
    function applySorting() {
        const sort = document.getElementById('sort-select')?.value || 'uploaded_at';
        const order = document.getElementById('order-select')?.value || 'DESC';
        const url = new URL(window.location.href);
        url.searchParams.set('sort', sort);
        url.searchParams.set('order', order);
        window.location.href = url.toString();
    }
    
    // Timeline view switching
    window.switchTimelineView = function(view) {
        const horizontalContainer = document.getElementById('timeline-container-horizontal');
        const verticalContainer = document.getElementById('timeline-container-vertical');
        const horizontalBtn = document.getElementById('timeline-horizontal-btn');
        const verticalBtn = document.getElementById('timeline-vertical-btn');
        
        if (view === 'horizontal') {
            horizontalContainer.style.display = 'flex';
            verticalContainer.style.display = 'none';
            horizontalBtn.classList.add('active');
            verticalBtn.classList.remove('active');
        } else {
            horizontalContainer.style.display = 'none';
            verticalContainer.style.display = 'flex';
            horizontalBtn.classList.remove('active');
            verticalBtn.classList.add('active');
        }
    };
    
    // Scroll to evidence item
    window.scrollToEvidence = function(docId) {
        const item = document.querySelector(`[data-doc-id="${docId}"]`);
        if (item) {
            item.scrollIntoView({ behavior: 'smooth', block: 'center' });
            item.style.background = '#fef3c7';
            setTimeout(() => {
                item.style.background = '';
            }, 2000);
        }
    };
})();
</script>

<?php require_once __DIR__ . '/../../../required/footer.php';
