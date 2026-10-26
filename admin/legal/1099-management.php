<?php
$SEO = [
    'title' => '1099 Form Management — Admin',
    'description' => 'Upload and manage 1099 tax forms for sales agents.',
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

// Create 1099 directory if it doesn't exist
$uploadsDir = __DIR__ . '/documents/1099/';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

// Handle form upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_1099') {
    $taxYear = intval($_POST['tax_year'] ?? date('Y'));
    
    if (isset($_FILES['form_file']) && $_FILES['form_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['form_file'];
        
        // Validate file type
        $allowedTypes = ['application/pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            flash_set('error', 'Only PDF files are allowed.');
            redirect('/admin/legal/1099-management.php');
        }
        
        // Generate filename
        $fileName = 'form-1099-misc-' . $taxYear . '.pdf';
        $filePath = $uploadsDir . $fileName;
        $relativeFilePath = '/admin/legal/documents/1099/' . $fileName;
        
        try {
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                $pdo->beginTransaction();
                
                // Deactivate previous forms for this year
                $stmt = $pdo->prepare('UPDATE form_1099_documents SET is_active = 0 WHERE tax_year = ?');
                $stmt->execute([$taxYear]);
                
                // Insert new form
                $stmt = $pdo->prepare('
                    INSERT INTO form_1099_documents 
                    (file_name, file_path, tax_year, uploaded_by, is_active)
                    VALUES (?, ?, ?, ?, 1)
                ');
                $stmt->execute([$fileName, $relativeFilePath, $taxYear, $uid]);
                
                $pdo->commit();
                
                flash_set('success', 'Form 1099-MISC uploaded successfully for tax year ' . $taxYear . '!');
                redirect('/admin/legal/1099-management.php');
            } else {
                throw new Exception('Failed to move uploaded file.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('1099 upload error: ' . $e->getMessage());
            flash_set('error', 'Failed to upload form. Please try again.');
            redirect('/admin/legal/1099-management.php');
        }
    } else {
        flash_set('error', 'Please select a file to upload.');
        redirect('/admin/legal/1099-management.php');
    }
}

// Handle deactivate action
if (isset($_GET['action']) && $_GET['action'] === 'deactivate' && isset($_GET['id'])) {
    $formId = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare('UPDATE form_1099_documents SET is_active = 0 WHERE id = ?');
        $stmt->execute([$formId]);
        flash_set('success', 'Form deactivated successfully.');
        redirect('/admin/legal/1099-management.php');
    } catch (Throwable $e) {
        error_log('1099 deactivate error: ' . $e->getMessage());
        flash_set('error', 'Failed to deactivate form.');
        redirect('/admin/legal/1099-management.php');
    }
}

// Get all 1099 forms
try {
    $stmt = $pdo->query('
        SELECT 
            f.*,
            CONCAT(u.first_name, " ", u.last_name) as uploaded_by_name
        FROM form_1099_documents f
        JOIN users u ON u.id = f.uploaded_by
        ORDER BY f.tax_year DESC, f.uploaded_at DESC
    ');
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('1099 forms fetch error: ' . $e->getMessage());
    $forms = [];
}

require_once __DIR__ . '/../../required/header.php';
?>

<style>
.form-1099-manager {
    max-width: 1200px;
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
    display: inline-block;
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

.btn-danger {
    background: #ef4444;
    color: white;
    padding: 6px 12px;
    font-size: 13px;
}

.btn-danger:hover {
    background: #dc2626;
}

.upload-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    padding: 32px;
    margin-bottom: 32px;
}

.card-title {
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 24px;
}

.upload-form {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 16px;
    align-items: end;
}

.form-group {
    margin-bottom: 0;
}

.form-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
}

.form-input, .form-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
}

.forms-list {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.forms-table {
    width: 100%;
    border-collapse: collapse;
}

.forms-table th {
    text-align: left;
    padding: 16px;
    background: #f9fafb;
    color: #6b7280;
    font-weight: 600;
    font-size: 14px;
    border-bottom: 2px solid #e5e7eb;
}

.forms-table td {
    padding: 16px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
}

.forms-table tr:last-child td {
    border-bottom: none;
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

.action-links {
    display: flex;
    gap: 12px;
}

.info-box {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 24px;
}

.info-box p {
    margin: 0;
    color: #1e40af;
    font-size: 14px;
    line-height: 1.6;
}
</style>

<div class="form-1099-manager">
    <div class="page-header">
        <h1 class="page-title">💼 1099 Form Management</h1>
        <a href="/admin/legal/index.php" class="btn btn-secondary">← Back to Legal</a>
    </div>
    
    <div class="upload-card">
        <h2 class="card-title">Upload New 1099 Form</h2>
        
        <div class="info-box">
            <p>
                <strong>📋 Instructions:</strong> Upload the pre-filled 1099-MISC form for sales agents. 
                The form should have your company information (EIN, LLC name, address) already filled in. 
                Sales agents will download this form from their Finances page and complete it with their earnings data.
            </p>
        </div>
        
        <form method="POST" enctype="multipart/form-data" class="upload-form">
            <input type="hidden" name="action" value="upload_1099">
            
            <div class="form-group">
                <label class="form-label">Tax Year</label>
                <select name="tax_year" class="form-select" required>
                    <?php for ($year = date('Y') + 1; $year >= 2020; $year--): ?>
                    <option value="<?php echo $year; ?>" <?php echo $year == date('Y') ? 'selected' : ''; ?>>
                        <?php echo $year; ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">PDF File</label>
                <input type="file" name="form_file" class="form-input" accept=".pdf" required>
            </div>
            
            <button type="submit" class="btn btn-primary">Upload Form</button>
        </form>
    </div>
    
    <div class="forms-list">
        <h2 class="card-title" style="padding: 24px 24px 0;">Uploaded Forms</h2>
        
        <?php if (empty($forms)): ?>
        <p style="padding: 40px; text-align: center; color: #6b7280;">No forms uploaded yet.</p>
        <?php else: ?>
        <table class="forms-table">
            <thead>
                <tr>
                    <th>Tax Year</th>
                    <th>File Name</th>
                    <th>Uploaded By</th>
                    <th>Upload Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $form): ?>
                <tr>
                    <td><strong><?php echo $form['tax_year']; ?></strong></td>
                    <td><?php echo htmlspecialchars($form['file_name']); ?></td>
                    <td><?php echo htmlspecialchars($form['uploaded_by_name']); ?></td>
                    <td><?php echo date('M j, Y g:i A', strtotime($form['uploaded_at'])); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $form['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $form['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-links">
                            <a href="<?php echo htmlspecialchars($form['file_path']); ?>" target="_blank" class="btn btn-secondary">View</a>
                            <?php if ($form['is_active']): ?>
                            <a href="?action=deactivate&id=<?php echo $form['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to deactivate this form?');">Deactivate</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>




