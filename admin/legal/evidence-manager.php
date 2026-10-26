<?php
$SEO = [
    'title' => 'Evidence Manager — Product Legal Management',
    'description' => 'Upload and manage evidence metadata for legal cases.',
    'type' => 'article'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/legal-permissions.php';
require_once __DIR__ . '/../../required/legal-case-service.php';
require_legal_or_admin(path_with_base('/admin/'));

start_session();
$pdo = db();
$currentUser = current_user_id();
$service = new LegalCaseService($pdo);

$caseId = isset($_GET['case_id']) ? (int)$_GET['case_id'] : 0;
if ($caseId <= 0) {
    redirect(path_with_base('/admin/legal/index.php'));
}

$stmt = $pdo->prepare('SELECT * FROM legal_cases WHERE id = ? LIMIT 1');
$stmt->execute([$caseId]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$case || (!user_has_role($currentUser, 'admin') && !legal_user_can_access_case($currentUser, $caseId))) {
    redirect(path_with_base('/admin/legal/index.php'));
}

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['evidence_file'])) {
    $file = $_FILES['evidence_file'];
    $displayName = trim($_POST['display_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $docType = $_POST['document_type'] ?? 'other';
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $uploadsDir = __DIR__ . '/evidence-locker/';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        
        $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9\._\-]/', '_', basename($file['name']));
        $targetPath = $uploadsDir . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $hash = hash_file('sha256', $targetPath);
            $size = filesize($targetPath);
            $storagePath = '/admin/legal/evidence-locker/' . $fileName;
            
            $stmt = $pdo->prepare('INSERT INTO legal_case_documents (case_id, storage_path, display_name, description, document_type, hash_sha256, file_size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$caseId, $storagePath, $displayName ?: $fileName, $description, $docType, $hash, $size, $currentUser]);
            
            $service->logActivity($caseId, $currentUser, 'document_uploaded', ['file_name' => $fileName, 'display_name' => $displayName]);
            
            $success = true;
            redirect(path_with_base('/admin/legal/case-view.php?id=' . $caseId . '&tab=evidence'));
        } else {
            $error = 'Failed to save uploaded file.';
        }
    } else {
        $error = 'Upload error: ' . $file['error'];
    }
}

$documents = $service->listDocuments($caseId);

$breadcrumbs = [
    ['label' => $case['title'], 'url' => path_with_base('/admin/legal/case-view.php?id=' . $caseId)],
    ['label' => 'Evidence Manager', 'url' => null]
];
?>
<?php include __DIR__ . '/partials/breadcrumb.php'; ?>
<style>
.evidence-manager-shell {
    max-width: 960px;
    margin: 40px auto 80px auto;
    padding: 0 24px;
}
.evidence-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 12px 28px rgba(15,23,42,0.08);
    padding: 28px;
}
.upload-zone {
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    padding: 32px;
    text-align: center;
    background: #f8fafc;
    margin-bottom: 28px;
}
.upload-zone input[type="file"] {
    margin: 16px 0;
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #0f172a;
}
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 1rem;
}
.form-group textarea {
    min-height: 100px;
}
.btn {
    padding: 12px 24px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    cursor: pointer;
}
.btn-primary {
    background: #0d47a1;
    color: #fff;
}
.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.alert-error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}
</style>

<div class="evidence-manager-shell">
    <div class="evidence-card">
        <h1 style="margin:0 0 8px 0;">Evidence Manager</h1>
        <p style="margin:0 0 24px 0;color:#475569;">Upload new evidence for <strong><?php echo sanitize($case['title']); ?></strong></p>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo sanitize($error); ?></div>
        <?php endif; ?>
        
        <form id="upload-form" enctype="multipart/form-data">
            <div class="upload-zone">
                <h3 style="margin:0 0 12px 0;color:#0f172a;">Select File</h3>
                <input type="file" name="evidence_file" id="evidence_file" required>
                <p style="margin:12px 0 0 0;font-size:0.9rem;color:#64748b;">Supported: PDF, JPG, PNG, DOCX, XLSX. Max 50MB.</p>
                <div id="upload-progress" style="display:none;margin-top:12px;">
                    <div style="background:#e2e8f0;border-radius:999px;height:8px;overflow:hidden;">
                        <div id="upload-progress-bar" style="background:#0d47a1;height:100%;width:0%;transition:width 0.3s;"></div>
                    </div>
                    <p id="upload-status" style="margin:8px 0 0 0;font-size:0.9rem;color:#475569;text-align:center;">Uploading...</p>
                </div>
            </div>
            
            <div class="form-group">
                <label for="display_name">Display Name</label>
                <input type="text" id="display_name" name="display_name" placeholder="IRS CP575 Notice" required>
            </div>
            
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" placeholder="Official EIN assignment notice evidencing fraud..."></textarea>
            </div>
            
            <div class="form-group">
                <label for="document_type">Document Type</label>
                <select id="document_type" name="document_type">
                    <option value="exhibit">Exhibit</option>
                    <option value="analysis">Legal Analysis</option>
                    <option value="correspondence">Correspondence</option>
                    <option value="financial">Financial</option>
                    <option value="media">Media</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <div style="display:flex;gap:12px;">
                <button type="submit" class="btn btn-primary" id="upload-submit-btn">Upload Evidence</button>
                <a href="<?php echo path_with_base('/admin/legal/case-view.php?id=' . $caseId . '&tab=evidence'); ?>" class="btn" style="background:#f1f5f9;color:#0f172a;">Cancel</a>
            </div>
        </form>
        
        <h2 style="margin:40px 0 16px 0;">Existing Evidence</h2>
        <?php if (empty($documents)): ?>
            <p style="color:#64748b;">No documents uploaded yet.</p>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;margin-top:12px;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:12px;text-align:left;">Name</th>
                        <th style="padding:12px;text-align:left;">Type</th>
                        <th style="padding:12px;text-align:left;">Downloads</th>
                        <th style="padding:12px;text-align:left;">Uploaded</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $doc): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px;"><a href="<?php echo path_with_base($doc['storage_path']); ?>" style="color:#1d4ed8;font-weight:600;"><?php echo sanitize($doc['display_name']); ?></a></td>
                            <td style="padding:12px;color:#475569;"><?php echo sanitize($doc['document_type']); ?></td>
                            <td style="padding:12px;color:#475569;"><?php echo number_format($doc['download_count']); ?></td>
                            <td style="padding:12px;color:#64748b;font-size:0.9rem;"><?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
const caseId = <?php echo $caseId; ?>;

// Define path_with_base helper for JavaScript
function path_with_base(path) {
    const base = window.__BASE_PREFIX || '';
    if (path.startsWith('/')) {
        return base + path;
    }
    return base + '/' + path;
}
const uploadForm = document.getElementById('upload-form');
const uploadProgress = document.getElementById('upload-progress');
const uploadProgressBar = document.getElementById('upload-progress-bar');
const uploadStatus = document.getElementById('upload-status');
const uploadSubmitBtn = document.getElementById('upload-submit-btn');

uploadForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(uploadForm);
    formData.append('case_id', caseId);
    
    uploadProgress.style.display = 'block';
    uploadSubmitBtn.disabled = true;
    uploadSubmitBtn.textContent = 'Uploading...';
    
    try {
        const xhr = new XMLHttpRequest();
        
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percentComplete = (e.loaded / e.total) * 100;
                uploadProgressBar.style.width = percentComplete + '%';
                uploadStatus.textContent = `Uploading... ${Math.round(percentComplete)}%`;
            }
        });
        
        xhr.addEventListener('load', () => {
            if (xhr.status === 200) {
                uploadStatus.textContent = 'Upload complete! Redirecting...';
                uploadProgressBar.style.width = '100%';
                setTimeout(() => {
                    window.location.href = path_with_base('/admin/legal/case-view.php?id=' + caseId + '&tab=evidence');
                }, 800);
            } else {
                alert('Upload failed: ' + xhr.statusText);
                uploadProgress.style.display = 'none';
                uploadSubmitBtn.disabled = false;
                uploadSubmitBtn.textContent = 'Upload Evidence';
            }
        });
        
        xhr.addEventListener('error', () => {
            alert('Upload error occurred');
            uploadProgress.style.display = 'none';
            uploadSubmitBtn.disabled = false;
            uploadSubmitBtn.textContent = 'Upload Evidence';
        });
        
        xhr.open('POST', window.location.href, true);
        xhr.send(formData);
        
    } catch (err) {
        console.error(err);
        alert('Unable to upload');
        uploadProgress.style.display = 'none';
        uploadSubmitBtn.disabled = false;
        uploadSubmitBtn.textContent = 'Upload Evidence';
    }
});
</script>

<?php require_once __DIR__ . '/../../required/footer.php';

