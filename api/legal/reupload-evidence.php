<?php
// API: Re-upload evidence file (replace physical file while keeping metadata)
// Updated: 2025-10-24 20:00:00 UTC - Fixed activity logging
// Version: 1.1.0
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Disabled for production, but errors logged
header('Content-Type: application/json');

// Clear any OpCache for this file
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

try {
    require_once __DIR__ . '/../../required/config.php';
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to load config: ' . $e->getMessage()]);
    exit;
}

try {
    require_once __DIR__ . '/../../required/legal-permissions.php';
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to load legal permissions: ' . $e->getMessage()]);
    exit;
}

try {
    // Authenticate user
    start_session();
    $currentUser = current_user_id();
    
    if (!$currentUser) {
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }
    
    // Only admins can re-upload evidence
    if (!user_has_role($currentUser, 'admin')) {
        echo json_encode(['success' => false, 'message' => 'Only administrators can re-upload evidence']);
        exit;
    }
    
    $pdo = db();
    
    // Validate document ID
    $documentId = filter_input(INPUT_POST, 'document_id', FILTER_VALIDATE_INT);
    if (!$documentId) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID']);
        exit;
    }
    
    // Get existing document metadata
    $stmt = $pdo->prepare("
        SELECT id, case_id, storage_path, display_name, document_type
        FROM legal_case_documents 
        WHERE id = ?
    ");
    $stmt->execute([$documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        echo json_encode(['success' => false, 'message' => 'Document not found or has been deleted']);
        exit;
    }
    
    // Validate file upload
    if (!isset($_FILES['evidence_file']) || $_FILES['evidence_file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = isset($_FILES['evidence_file']) ? 
            'File upload error code: ' . $_FILES['evidence_file']['error'] : 
            'No file uploaded';
        echo json_encode(['success' => false, 'message' => $errorMsg]);
        exit;
    }
    
    $file = $_FILES['evidence_file'];
    
    // Validate file size (50MB max)
    if ($file['size'] > 50 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File size exceeds 50MB limit']);
        exit;
    }
    
    // Get reupload reason
    $reason = trim($_POST['reupload_reason'] ?? '');
    
    // Target directory and path
    $targetDir = __DIR__ . '/../../admin/legal/evidence-locker/';
    $fileName = basename($document['storage_path']);
    $targetPath = $targetDir . $fileName;
    
    // Ensure target directory exists
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    // Backup old file if it exists
    $backupPath = null;
    if (is_file($targetPath)) {
        $backupPath = $targetPath . '.backup.' . time();
        copy($targetPath, $backupPath);
    }
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Restore backup if move failed
        if ($backupPath && is_file($backupPath)) {
            rename($backupPath, $targetPath);
        }
        $error = error_get_last();
        error_log('File reupload failed: ' . print_r($error, true));
        echo json_encode(['success' => false, 'message' => 'Failed to save file to server. Check directory permissions.']);
        exit;
    }
    
    // Calculate new hash
    $fileHash = hash_file('sha256', $targetPath);
    $newSize = filesize($targetPath);
    
    // Update file metadata
    $updateStmt = $pdo->prepare("
        UPDATE legal_case_documents 
        SET 
            file_size_bytes = ?,
            hash_sha256 = ?,
            uploaded_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$newSize, $fileHash, $documentId]);
    
    // Log activity
    $activityPayload = ['document_id' => $documentId];
    if ($reason) {
        $activityPayload['reason'] = $reason;
    }
    
    $activityStmt = $pdo->prepare("
        INSERT INTO legal_case_activity 
        (case_id, user_id, activity_type, activity_payload, created_at) 
        VALUES (?, ?, 'evidence_replaced', ?, NOW())
    ");
    $activityStmt->execute([
        $document['case_id'],
        $currentUser,
        json_encode($activityPayload)
    ]);
    
    // Delete backup file after successful upload
    if ($backupPath && is_file($backupPath)) {
        unlink($backupPath);
    }
    
    echo json_encode([
        'success' => true,
        'ok' => true,
        'message' => 'Evidence file re-uploaded successfully',
        'document_id' => $documentId,
        'file_hash' => $fileHash,
        'file_size' => $newSize
    ]);
    
} catch (Exception $e) {
    error_log('Legal evidence reupload error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while re-uploading: ' . $e->getMessage()
    ]);
} catch (Throwable $t) {
    error_log('Legal evidence reupload fatal error: ' . $t->getMessage());
    error_log('Stack trace: ' . $t->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'A fatal error occurred: ' . $t->getMessage()
    ]);
}

