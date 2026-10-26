<?php
/**
 * Secure Receipt Viewer
 * Allows authorized users to view uploaded expense receipts
 */

require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/accounting-permissions.php';

start_session();

// Authentication & permission check
$userId = current_user_id();
if (!$userId) {
    http_response_code(401);
    die('Authentication required');
}

if (!can_view_accounting($userId)) {
    http_response_code(403);
    die('Permission denied');
}

// Get expense ID and validate
$expenseId = intval($_GET['expense_id'] ?? 0);
if (!$expenseId) {
    http_response_code(400);
    die('Expense ID required');
}

$pdo = db();

// Get expense with receipt path
$stmt = $pdo->prepare("
    SELECT receipt_file_path, vendor_name, description
    FROM expenses
    WHERE id = ?
");
$stmt->execute([$expenseId]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense || !$expense['receipt_file_path']) {
    http_response_code(404);
    die('Receipt not found');
}

// Build full file path
$filePath = __DIR__ . '/' . $expense['receipt_file_path'];

if (!file_exists($filePath)) {
    http_response_code(404);
    die('Receipt file not found on server');
}

// Serve the PDF file
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($filePath);
exit;












