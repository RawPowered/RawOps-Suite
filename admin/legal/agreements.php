<?php
$SEO = [
    'title' => 'Agreement Editor — Admin',
    'description' => 'Edit and manage legal agreement templates.',
    'type' => 'website'
];
require_once __DIR__ . '/../../required/config.php';
require_login_or_redirect('/login.php');

// Require admin role
$uid = current_user_id();
if (!in_array('admin', user_roles($uid) ?? [])) {
    flash_set('error', 'Admin access required.');
    redirect('/');
}

$pdo = db();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_agreement') {
        $agreementId = intval($_POST['agreement_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        if ($agreementId && $title && $content) {
            try {
                $pdo->beginTransaction();
                
                // Get current version
                $stmt = $pdo->prepare('SELECT version, content FROM agreement_templates WHERE id = ?');
                $stmt->execute([$agreementId]);
                $current = $stmt->fetch();
                
                if ($current) {
                    // Check if content changed
                    $contentChanged = ($current['content'] !== $content);
                    $newVersion = $contentChanged ? ($current['version'] + 1) : $current['version'];
                    
                    // Update agreement
                    $stmt = $pdo->prepare('
                        UPDATE agreement_templates 
                        SET title = ?, content = ?, version = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ');
                    $stmt->execute([$title, $content, $newVersion, $isActive, $agreementId]);
                    
                    $pdo->commit();
                    
                    if ($contentChanged) {
                        flash_set('success', 'Agreement updated successfully! Version incremented to ' . $newVersion . '. Existing users will need to re-sign if required.');
                    } else {
                        flash_set('success', 'Agreement updated successfully!');
                    }
                } else {
                    flash_set('error', 'Agreement not found.');
                }
                
                redirect('/admin/legal/agreements.php');
                
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Agreement update error: ' . $e->getMessage());
                flash_set('error', 'Failed to update agreement.');
                redirect('/admin/legal/agreements.php');
            }
        } else {
            flash_set('error', 'All fields are required.');
            redirect('/admin/legal/agreements.php');
        }
    }
}

// Get editing agreement if specified
$editingAgreement = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $stmt = $pdo->prepare('SELECT * FROM agreement_templates WHERE id = ?');
    $stmt->execute([$editId]);
    $editingAgreement = $stmt->fetch();
}

// Get all agreements
try {
    $stmt = $pdo->query('
        SELECT 
            at.*,
            COUNT(DISTINCT ua.user_id) as signature_count
        FROM agreement_templates at
        LEFT JOIN user_agreements ua ON ua.agreement_template_id = at.id
        GROUP BY at.id
        ORDER BY FIELD(at.agreement_type, "nda", "non_compete", "ai_communications", "sms_consent", "email_consent", "phone_consent", "gmail_account")
    ');
    $agreements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Agreements fetch error: ' . $e->getMessage());
    $agreements = [];
}

require_once __DIR__ . '/../../required/header.php';
?>

<style>
.agreements-editor {
    max-width: 1400px;
    margin: 0 auto;
    padding: 40px 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
}

.page-title {
    font-size: 32px;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-secondary {
    background: #f3f4f6;
    color: #374151;
}

.btn-secondary:hover {
    background: #e5e7eb;
}

.btn-primary {
    background: #10b981;
    color: white;
}

.btn-primary:hover {
    background: #059669;
}

.btn-edit {
    background: #3b82f6;
    color: white;
    padding: 6px 12px;
    font-size: 13px;
}

.btn-edit:hover {
    background: #2563eb;
}

.agreements-list {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    margin-bottom: 32px;
}

.agreement-item {
    padding: 24px;
    border-bottom: 1px solid #f3f4f6;
}

.agreement-item:last-child {
    border-bottom: none;
}

.agreement-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 12px;
}

.agreement-title {
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 4px;
}

.agreement-meta {
    display: flex;
    gap: 16px;
    font-size: 14px;
    color: #6b7280;
}

.agreement-type {
    text-transform: capitalize;
    font-weight: 500;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-active {
    background: #d1fae5;
    color: #065f46;
}

.status-inactive {
    background: #fee2e2;
    color: #991b1b;
}

.editor-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    overflow-y: auto;
}

.editor-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
}

.editor-container {
    background: white;
    border-radius: 12px;
    max-width: 1000px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.editor-header {
    padding: 24px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.editor-title {
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.close-btn {
    background: none;
    border: none;
    font-size: 24px;
    color: #6b7280;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
}

.close-btn:hover {
    color: #1f2937;
}

.editor-form {
    padding: 24px;
}

.form-group {
    margin-bottom: 24px;
}

.form-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
}

.form-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
}

.form-textarea {
    width: 100%;
    min-height: 400px;
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    font-family: monospace;
}

.form-checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding: 24px;
    border-top: 1px solid #e5e7eb;
}

.preview-content {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 24px;
    margin-top: 12px;
}
</style>

<div class="agreements-editor">
    <div class="page-header">
        <h1 class="page-title">📝 Agreement Templates</h1>
        <a href="/admin/legal/index.php" class="btn btn-secondary">← Back to Legal</a>
    </div>
    
    <div class="agreements-list">
        <?php foreach ($agreements as $agreement): ?>
        <div class="agreement-item">
            <div class="agreement-header">
                <div>
                    <div class="agreement-title"><?php echo htmlspecialchars($agreement['title']); ?></div>
                    <div class="agreement-meta">
                        <span class="agreement-type"><?php echo str_replace('_', ' ', $agreement['agreement_type']); ?></span>
                        <span>Version <?php echo $agreement['version']; ?></span>
                        <span><?php echo number_format($agreement['signature_count']); ?> signatures</span>
                        <span><?php echo $agreement['updated_at'] ? date('M j, Y', strtotime($agreement['updated_at'])) : date('M j, Y', strtotime($agreement['created_at'])); ?></span>
                    </div>
                </div>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <span class="status-badge status-<?php echo $agreement['is_active'] ? 'active' : 'inactive'; ?>">
                        <?php echo $agreement['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                    <a href="?edit=<?php echo $agreement['id']; ?>" class="btn btn-edit">Edit</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($editingAgreement): ?>
<div class="editor-modal active" id="editor-modal">
    <div class="editor-container">
        <div class="editor-header">
            <h2 class="editor-title">Edit: <?php echo htmlspecialchars($editingAgreement['title']); ?></h2>
            <a href="/admin/legal/agreements.php" class="close-btn">×</a>
        </div>
        
        <form method="POST" class="editor-form">
            <input type="hidden" name="action" value="update_agreement">
            <input type="hidden" name="agreement_id" value="<?php echo $editingAgreement['id']; ?>">
            
            <div class="form-group">
                <label class="form-label">Agreement Type</label>
                <input type="text" class="form-input" value="<?php echo ucwords(str_replace('_', ' ', $editingAgreement['agreement_type'])); ?>" disabled>
                <small style="color: #6b7280; font-size: 13px;">Agreement type cannot be changed</small>
            </div>
            
            <div class="form-group">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-input" value="<?php echo htmlspecialchars($editingAgreement['title']); ?>" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Content (HTML)</label>
                <textarea name="content" class="form-textarea" required><?php echo htmlspecialchars($editingAgreement['content']); ?></textarea>
                <small style="color: #6b7280; font-size: 13px;">Supports HTML formatting. Changing content will increment the version number.</small>
            </div>
            
            <div class="form-group">
                <div class="form-checkbox-group">
                    <input type="checkbox" name="is_active" id="is_active" <?php echo $editingAgreement['is_active'] ? 'checked' : ''; ?>>
                    <label for="is_active" class="form-label" style="margin: 0;">Active</label>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Preview</label>
                <div class="preview-content" id="preview-content">
                    <?php echo $editingAgreement['content']; ?>
                </div>
            </div>
            
            <div class="form-actions">
                <a href="/admin/legal/agreements.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const contentTextarea = document.querySelector('textarea[name="content"]');
    const previewContent = document.getElementById('preview-content');
    
    if (contentTextarea && previewContent) {
        contentTextarea.addEventListener('input', function() {
            previewContent.innerHTML = this.value;
        });
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>




