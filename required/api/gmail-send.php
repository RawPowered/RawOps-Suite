<?php
// required/api/gmail-send.php
// Secure endpoint for sending emails via the Gmail API.

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../google_mailer.php';

try {
    require_admin_or_redirect(null, true);
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden: Admin access required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['to']) || empty($input['subject']) || empty($input['html'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid input. "to", "subject", and "html" are required.']);
    exit;
}

$adminId = current_admin_id();

$sent = google_send_mail($adminId, [
    'to' => $input['to'],
    'subject' => $input['subject'],
    'html' => $input['html'],
    'text' => $input['text'] ?? null,
    'from_name' => $input['from_name'] ?? 'Product Team',
    'from_email' => $input['from_email'] ?? null,
    'reply_to' => $input['reply_to'] ?? null
]);

if (!$sent) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gmail API error.']);
    exit;
}

echo json_encode(['success' => true]);
