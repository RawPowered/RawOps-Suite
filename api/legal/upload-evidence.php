<?php
// API: Upload evidence to legal case evidence locker
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json');

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
    
    // Only admins can upload evidence
    if (!user_has_role($currentUser, 'admin')) {
        echo json_encode(['success' => false, 'message' => 'Only administrators can upload evidence']);
        exit;
    }
    
    $pdo = db();
    
    // Validate file upload
    if (!isset($_FILES['evidence_file']) || $_FILES['evidence_file']['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = 'File upload failed';
        if (isset($_FILES['evidence_file']['error'])) {
            switch ($_FILES['evidence_file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMessage = 'File is too large (max 50MB)';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errorMessage = 'No file was uploaded';
                    break;
            }
        }
        echo json_encode(['success' => false, 'message' => $errorMessage]);
        exit;
    }
    
    $file = $_FILES['evidence_file'];
    $fileSize = $file['size'];
    $maxSize = 50 * 1024 * 1024; // 50MB
    
    if ($fileSize > $maxSize) {
        echo json_encode(['success' => false, 'message' => 'File is too large (max 50MB)']);
        exit;
    }
    
    // Validate required fields
    $displayName = trim($_POST['display_name'] ?? '');
    $documentType = $_POST['document_type'] ?? 'exhibit';
    $timelineDate = trim($_POST['timeline_date'] ?? '');
    $caseId = isset($_POST['case_id']) && $_POST['case_id'] !== '' ? (int)$_POST['case_id'] : null;
    
    if (empty($displayName)) {
        echo json_encode(['success' => false, 'message' => 'Display name is required']);
        exit;
    }
    
    if (empty($timelineDate)) {
        echo json_encode(['success' => false, 'message' => 'Timeline date is required']);
        exit;
    }
    
    // Optional fields
    $description = trim($_POST['description'] ?? '');
    $exhibitNumber = trim($_POST['exhibit_number'] ?? '');
    $evidenceDate = trim($_POST['evidence_date'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '');
    $isKeyEvidence = isset($_POST['is_key_evidence']) ? 1 : 0;
    
    // Validate document type
    $validTypes = ['exhibit', 'analysis', 'correspondence', 'financial', 'media', 'other'];
    if (!in_array($documentType, $validTypes)) {
        $documentType = 'exhibit';
    }
    
    // Generate secure filename
    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $timestamp = time();
    $secureFilename = $timestamp . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME)) . '.' . $extension;
    
    // Target directory
    $targetDir = __DIR__ . '/../../admin/legal/evidence-locker/';
    $targetPath = $targetDir . $secureFilename;
    
    // Ensure target directory exists
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        $error = error_get_last();
        error_log('File upload failed: ' . print_r($error, true));
        echo json_encode(['success' => false, 'message' => 'Failed to save file to server. Check directory permissions.']);
        exit;
    }
    
    // Calculate file hash
    $fileHash = hash_file('sha256', $targetPath);
    
    // Storage path for database (relative to web root)
    $storagePath = '/admin/legal/evidence-locker/' . $secureFilename;
    
    // Insert into database
    $stmt = $pdo->prepare('
        INSERT INTO legal_case_documents (
            case_id, storage_path, display_name, description, document_type,
            evidence_date, timeline_date, exhibit_number, is_key_evidence, notes,
            hash_sha256, file_size_bytes, uploaded_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    
    $stmt->execute([
        $caseId,
        $storagePath,
        $displayName,
        $description,
        $documentType,
        $evidenceDate,
        $timelineDate,
        $exhibitNumber,
        $isKeyEvidence,
        $notes,
        $fileHash,
        $fileSize,
        $currentUser
    ]);
    
    $documentId = $pdo->lastInsertId();
    
    // Log activity if case is specified
    if ($caseId) {
        $stmt = $pdo->prepare('
            INSERT INTO legal_case_activity (case_id, user_id, activity_type, activity_payload)
            VALUES (?, ?, "document_uploaded", ?)
        ');
        $stmt->execute([
            $caseId,
            $currentUser,
            json_encode([
                'document_id' => $documentId,
                'file_name' => $secureFilename,
                'display_name' => $displayName,
                'timeline_date' => $timelineDate
            ])
        ]);
    }
    
    echo json_encode([
        'success' => true,
        'ok' => true,
        'message' => 'Evidence uploaded successfully',
        'document_id' => $documentId,
        'filename' => $secureFilename
    ]);
    
} catch (Exception $e) {
    error_log('Legal evidence upload error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while uploading: ' . $e->getMessage()
    ]);
} catch (Throwable $t) {
    error_log('Legal evidence upload fatal error: ' . $t->getMessage());
    error_log('Stack trace: ' . $t->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'A fatal error occurred: ' . $t->getMessage()
    ]);
}

