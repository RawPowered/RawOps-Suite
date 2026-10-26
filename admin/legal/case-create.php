<?php
$SEO = [
    'title' => 'Create Legal Case — Product Legal Management',
    'description' => 'Onboard a new case into the legal management system.',
    'type' => 'article'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/legal-permissions.php';
require_once __DIR__ . '/../../required/legal-case-number.php';
require_legal_or_admin(path_with_base('/admin/'));

// Only admins can create cases
start_session();
$currentUser = current_user_id();
if (!user_has_role($currentUser, 'admin')) {
    redirect(path_with_base('/admin/legal/portal/'));
}

$pdo = db();

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $caseKey = trim($_POST['case_key'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $jurisdiction = trim($_POST['jurisdiction'] ?? '');
    $retentionPolicy = $_POST['retention_policy'] ?? 'never';
    $summary = trim($_POST['summary'] ?? '');
    
    if (!$caseKey || !$title) {
        $error = 'Case key and title are required.';
    } else {
        try {
            // Generate case number
            $caseNumber = generate_case_number();
            
            $stmt = $pdo->prepare('INSERT INTO legal_cases (case_key, case_number, title, jurisdiction, retention_policy, summary, status, created_by) VALUES (?, ?, ?, ?, ?, ?, "draft", ?)');
            $stmt->execute([$caseKey, $caseNumber, $title, $jurisdiction, $retentionPolicy, $summary, $currentUser]);
            $newCaseId = (int)$pdo->lastInsertId();
            
            // Auto-assign creator to team as admin
            $pdo->prepare('INSERT INTO legal_case_team (case_id, user_id, role, added_by) VALUES (?, ?, "admin", ?)')->execute([$newCaseId, $currentUser, $currentUser]);
            
            // Log activity
            $pdo->prepare('INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload) VALUES (?, ?, "case_created", ?)')->execute([
                $newCaseId,
                $currentUser,
                json_encode(['case_key' => $caseKey, 'title' => $title])
            ]);
            
            $success = true;
            redirect(path_with_base('/admin/legal/case-view.php?id=' . $newCaseId));
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'Case key already exists. Please choose a unique identifier.';
            } else {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/../../required/legal-case-service.php';
$service = new LegalCaseService($pdo);
$retentionOptions = $service->retentionOptions();

$breadcrumbs = [
    ['label' => 'Create Case', 'url' => null]
];
?>
<?php include __DIR__ . '/partials/breadcrumb.php'; ?>
<style>
.case-create-shell {
    max-width: 880px;
    margin: 40px auto 80px auto;
    padding: 0 24px;
}
.case-create-card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 12px 32px rgba(15,23,42,0.12);
    padding: 32px;
    border: 1px solid #e2e8f0;
}
.case-create-card h1 {
    margin: 0 0 8px 0;
    font-size: 2rem;
    color: #0f172a;
}
.case-create-card .subtitle {
    margin: 0 0 32px 0;
    color: #475569;
}
.form-group {
    margin-bottom: 24px;
}
.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #0f172a;
}
.form-group input[type="text"],
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 1rem;
}
.form-group textarea {
    min-height: 140px;
    resize: vertical;
}
.form-group small {
    display: block;
    margin-top: 6px;
    color: #64748b;
    font-size: 0.9rem;
}
.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 32px;
}
.btn {
    padding: 12px 24px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
}
.btn-primary {
    background: #0d47a1;
    color: #fff;
}
.btn-secondary {
    background: #f1f5f9;
    color: #0f172a;
}
.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 24px;
}
.alert-success {
    background: #d1fae5;
    border: 1px solid #6ee7b7;
    color: #065f46;
}
.alert-error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}
</style>

<div class="case-create-shell">
    <div class="case-create-card">
        <h1>Create New Legal Case</h1>
        <p class="subtitle">Initialize a new matter with metadata, retention policies, and team assignments.</p>
        
        <?php if ($success): ?>
            <div class="alert alert-success">Case created successfully! Redirecting...</div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="case_key">Case Key *</label>
                <input type="text" id="case_key" name="case_key" required placeholder="case-example-a" pattern="[a-z0-9\-]+" value="<?php echo sanitize($_POST['case_key'] ?? ''); ?>">
                <small>Lowercase letters, numbers, and hyphens only. This becomes the unique identifier.</small>
            </div>
            
            <div class="form-group">
                <label for="title">Case Title *</label>
                <input type="text" id="title" name="title" required placeholder="Example Party A vs. Example Party B" value="<?php echo sanitize($_POST['title'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="jurisdiction">Jurisdiction</label>
                <input type="text" id="jurisdiction" name="jurisdiction" placeholder="Florida" value="<?php echo sanitize($_POST['jurisdiction'] ?? ''); ?>">
                <small>State, federal district, or international jurisdiction.</small>
            </div>
            
            <div class="form-group">
                <label for="retention_policy">Retention Policy</label>
                <select id="retention_policy" name="retention_policy">
                    <?php foreach ($retentionOptions as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo (($_POST['retention_policy'] ?? 'never') === $key) ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Define how long evidence and case data must be retained for regulatory compliance.</small>
            </div>
            
            <div class="form-group">
                <label for="summary">Case Summary</label>
                <textarea id="summary" name="summary" placeholder="Brief overview of the legal matter..."><?php echo sanitize($_POST['summary'] ?? ''); ?></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Case</button>
                <a href="<?php echo path_with_base('/admin/legal/index.php'); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php';

