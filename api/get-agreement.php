<?php
/**
 * Get Agreement Content API
 * Returns agreement template content for display in modals
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../required/config.php';

$agreementType = $_GET['type'] ?? '';

if (empty($agreementType)) {
    echo json_encode(['ok' => false, 'error' => 'Agreement type required']);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT title, content 
        FROM agreement_templates 
        WHERE agreement_type = ? AND is_active = 1 
        LIMIT 1
    ');
    $stmt->execute([$agreementType]);
    $agreement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$agreement) {
        echo json_encode(['ok' => false, 'error' => 'Agreement not found']);
        exit;
    }
    
    echo json_encode([
        'ok' => true,
        'title' => $agreement['title'],
        'content' => $agreement['content']
    ]);
    
} catch (Throwable $e) {
    error_log('Get agreement error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}




