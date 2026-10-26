<?php
require_once __DIR__ . '/../../required/config.php';

header('Content-Type: application/json');

// Require investor role
$uid = current_user_id();
if (!$uid || !user_is_investor($uid)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['accept']) || $input['accept'] !== true) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Record NDA agreement
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    
    // Get NDA template
    $stmt = $pdo->prepare('SELECT id, version FROM agreement_templates WHERE agreement_type = ? AND is_active = 1 LIMIT 1');
    $stmt->execute(['investor_nda']);
    $ndaTemplate = $stmt->fetch();
    
    if ($ndaTemplate) {
        $stmt = $pdo->prepare('
            INSERT INTO user_agreements 
            (user_id, agreement_template_id, signature_type, signature_data, ip_address, user_agent, agreement_version)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $uid,
            $ndaTemplate['id'],
            'digital_acceptance',
            json_encode(['timestamp' => date('c'), 'method' => 'web_modal']),
            $ipAddress,
            $userAgent,
            $ndaTemplate['version']
        ]);
    }
    
    // Get platform access template
    $stmt->execute(['investor_platform_access']);
    $platformTemplate = $stmt->fetch();
    
    if ($platformTemplate) {
        $stmt = $pdo->prepare('
            INSERT INTO user_agreements 
            (user_id, agreement_template_id, signature_type, signature_data, ip_address, user_agent, agreement_version)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $uid,
            $platformTemplate['id'],
            'digital_acceptance',
            json_encode(['timestamp' => date('c'), 'method' => 'web_modal']),
            $ipAddress,
            $userAgent,
            $platformTemplate['version']
        ]);
    }
    
    // Update investor profile
    $stmt = $pdo->prepare('
        UPDATE investor_profiles 
        SET nda_completed = 1, platform_access_granted = 1, access_granted_at = NOW()
        WHERE user_id = ?
    ');
    $stmt->execute([$uid]);
    
    $pdo->commit();
    
    echo json_encode(['ok' => true, 'message' => 'NDA accepted successfully']);
} catch (Throwable $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    error_log('NDA acceptance error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
?>

