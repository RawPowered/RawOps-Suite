<?php
// Public Lab Results Search API (GET-only)
// Returns JSON; filters: term, product_id, batch; limit

ob_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

set_error_handler(function($errno, $errstr){
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
    exit;
});
set_exception_handler(function($e){
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Exception']);
    exit;
});

require_once __DIR__ . '/../required/config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        if (ob_get_level()) ob_clean();
        echo json_encode(['success' => true]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        if (ob_get_level()) ob_clean();
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Only GET allowed']);
        exit;
    }

    $pdo = db();

    $term = trim((string)($_GET['term'] ?? ''));
    $productId = intval($_GET['product_id'] ?? 0) ?: null;
    $batch = trim((string)($_GET['batch'] ?? ''));
    $limit = intval($_GET['limit'] ?? 50);
    if ($limit <= 0 || $limit > 200) { $limit = 50; }

    $where = ['lr.is_public = 1'];
    $args = [];

    if ($productId) {
        $where[] = 'lr.product_id = ?';
        $args[] = $productId;
    }
    if ($batch !== '') {
        $where[] = 'lr.batch_code LIKE ?';
        $args[] = '%' . $batch . '%';
    }
    if ($term !== '') {
        $where[] = '(
            p.sku LIKE ? OR p.name LIKE ? OR
            lr.result_title LIKE ? OR lr.lab_name LIKE ? OR lr.batch_code LIKE ?
        )';
        $like = '%' . $term . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Total count
    $sqlCount = "\n        SELECT COUNT(1)\n        FROM lab_results lr\n        LEFT JOIN products p ON p.id = lr.product_id\n        $whereSql\n    ";
    $stmt = $pdo->prepare($sqlCount);
    $stmt->execute($args);
    $total = intval($stmt->fetchColumn() ?: 0);

    // Page
    $sql = "\n        SELECT lr.id, lr.product_id, p.sku AS product_sku, p.name AS product_name,\n               lr.batch_code, lr.result_title, lr.lab_name, lr.result_date,\n               lr.file_path, lr.file_name, lr.file_mime, lr.file_size_bytes,\n               lr.created_at\n        FROM lab_results lr\n        LEFT JOIN products p ON p.id = lr.product_id\n        $whereSql\n        ORDER BY COALESCE(lr.result_date, lr.created_at) DESC\n        LIMIT $limit\n    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    if (ob_get_level()) ob_clean();
    echo json_encode(['success' => true, 'results' => $rows, 'total' => $total], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unhandled error']);
}

if (ob_get_level()) ob_end_flush();
?>




