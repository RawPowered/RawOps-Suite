<?php
$SEO = [
    'title' => 'Lab Manager — Admin',
    'description' => 'Upload and manage COAs (lab results) by product and batch.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$adminId = current_admin_id();

// Helpers (JSON cleansing-aware minimal)
function clean_str(?string $s): ?string {
    if ($s === null) return null;
    $s = trim($s);
    // Remove zero-width and BOM
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
    // Strip control chars except TAB(9), LF(10), CR(13)
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return $s;
}

// Load products for selects
$products = [];
try {
    $stmt = $pdo->query("SELECT id, sku, name FROM products WHERE active = 1 ORDER BY name ASC");
    $products = $stmt->fetchAll();
} catch (Throwable $e) { $products = []; }

$message = '';
$error = '';

// Handle actions: upload/save, toggle_public
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_lab') {
            $productId = intval($_POST['product_id'] ?? 0) ?: null;
            $batchCode = clean_str($_POST['batch_code'] ?? '') ?: '';
            $resultTitle = clean_str($_POST['result_title'] ?? '') ?: '';
            $labName = clean_str($_POST['lab_name'] ?? '') ?: null;
            $resultDate = clean_str($_POST['result_date'] ?? '') ?: null;
            $receivedDate = clean_str($_POST['received_date'] ?? '') ?: null;
            $notes = clean_str($_POST['notes'] ?? '') ?: null;
            $isPublic = isset($_POST['is_public']) ? 1 : 0;
            $analytesJson = null;
            if (isset($_POST['analytes_json']) && trim((string)$_POST['analytes_json']) !== '') {
                $raw = trim((string)$_POST['analytes_json']);
                // Enforce size limit 100KB
                if (strlen($raw) > 100 * 1024) { throw new Exception('Analytes JSON exceeds 100KB'); }
                $decoded = json_decode($raw, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid analytes JSON');
                }
                $analytesJson = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($batchCode === '' || $resultTitle === '') {
                throw new Exception('Batch code and title are required');
            }

            // Optional file upload
            $filePath = null; $fileName = null; $fileMime = null; $fileSize = null;
            if (!empty($_FILES['coa_file']) && is_uploaded_file($_FILES['coa_file']['tmp_name'])) {
                $maxBytes = 20 * 1024 * 1024; // 20MB
                $tmp = $_FILES['coa_file']['tmp_name'];
                $orig = $_FILES['coa_file']['name'] ?? 'file';
                $size = (int)($_FILES['coa_file']['size'] ?? 0);
                if ($size <= 0 || $size > $maxBytes) {
                    throw new Exception('Invalid file size (max 20MB)');
                }
                // mime (light check)
                $finfo = function_exists('mime_content_type') ? mime_content_type($tmp) : null;
                $allowed = ['application/pdf','image/png','image/jpeg','image/webp'];
                if ($finfo && !in_array($finfo, $allowed, true)) {
                    // allow common PDF alias
                    if (strpos($finfo, 'pdf') === false && strpos($finfo, 'image/') !== 0) {
                        throw new Exception('Unsupported file type');
                    }
                }

                // Build target path: /uploads/labs/YYYY/MM/
                $yy = date('Y'); $mm = date('m');
                $baseDir = realpath(__DIR__ . '/..'); // product
                $relDir = '/uploads/labs/' . $yy . '/' . $mm;
                $absDir = $baseDir . $relDir;
                if (!is_dir($absDir)) {
                    @mkdir($absDir, 0755, true);
                }
                if (!is_dir($absDir) || !is_writable($absDir)) {
                    throw new Exception('Upload directory not writable');
                }
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $safeBase = preg_replace('/[^a-zA-Z0-9\-_.]+/', '-', pathinfo($orig, PATHINFO_FILENAME));
                if ($safeBase === '' || $safeBase === null) { $safeBase = 'coa'; }
                $finalName = $safeBase . '-' . date('Ymd-His') . '-' . substr(sha1(random_bytes(8)), 0, 8) . ($ext ? ('.' . $ext) : '');
                $absFile = $absDir . '/' . $finalName;
                if (!move_uploaded_file($tmp, $absFile)) {
                    throw new Exception('Failed to move uploaded file');
                }
                @chmod($absFile, 0644);

                $filePath = $relDir . '/' . $finalName; // starts with /uploads
                $fileName = $finalName;
                $fileMime = $finfo ?: ($_FILES['coa_file']['type'] ?? null);
                $fileSize = $size;
            }

            // Insert
            $stmt = $pdo->prepare("\n                INSERT INTO lab_results (product_id, batch_code, result_title, lab_name, result_date, received_date, analytes_json, file_path, file_name, file_mime, file_size_bytes, notes, is_public, created_by, created_at)\n                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())\n            ");
            $stmt->execute([
                $productId,
                $batchCode,
                $resultTitle,
                $labName,
                $resultDate ?: null,
                $receivedDate ?: null,
                $analytesJson,
                $filePath,
                $fileName,
                $fileMime,
                $fileSize,
                $notes,
                $isPublic,
                $adminId,
            ]);

            flash_set('success', 'Lab result saved successfully');
            redirect(path_with_base('/admin/lab-manager.php'));
        } elseif ($action === 'toggle_public') {
            $labId = intval($_POST['lab_id'] ?? 0);
            $to = intval($_POST['to'] ?? 0) ? 1 : 0;
            if ($labId <= 0) throw new Exception('Invalid record');
            $stmt = $pdo->prepare('UPDATE lab_results SET is_public = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$to, $adminId, $labId]);
            flash_set('success', 'Visibility updated');
            redirect(path_with_base('/admin/lab-manager.php'));
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Search/list
$term = trim((string)($_GET['term'] ?? ''));
$productId = intval($_GET['product_id'] ?? 0) ?: null;
$batch = trim((string)($_GET['batch'] ?? ''));
$onlyNonPublic = isset($_GET['only_non_public']) ? 1 : 0;

$where = ['1=1']; $args = [];
if ($productId) { $where[] = 'lr.product_id = ?'; $args[] = $productId; }
if ($batch !== '') { $where[] = 'lr.batch_code LIKE ?'; $args[] = '%' . $batch . '%'; }
if ($term !== '') {
    $where[] = '(p.sku LIKE ? OR p.name LIKE ? OR lr.result_title LIKE ? OR lr.lab_name LIKE ? OR lr.batch_code LIKE ?)';
    $like = '%' . $term . '%';
    array_push($args, $like, $like, $like, $like, $like);
}
if ($onlyNonPublic) { $where[] = 'lr.is_public = 0'; }

$list = [];
try {
    $stmt = $pdo->prepare("\n        SELECT lr.id, lr.is_public, lr.product_id, p.sku AS product_sku, p.name AS product_name,\n               lr.batch_code, lr.result_title, lr.lab_name, lr.result_date, lr.received_date,\n               lr.file_path, lr.file_name, lr.created_at\n        FROM lab_results lr\n        LEFT JOIN products p ON p.id = lr.product_id\n        WHERE " . implode(' AND ', $where) . "\n        ORDER BY COALESCE(lr.result_date, lr.created_at) DESC\n        LIMIT 100\n    ");
    $stmt->execute($args);
    $list = $stmt->fetchAll();
} catch (Throwable $e) { $error = $error ?: $e->getMessage(); }
?>

<div class="container" style="margin-top: 18px;">
    <?php include __DIR__ . '/admin-menu.php'; ?>

    <?php if ($error): ?>
        <div class="card" style="border-color:#fecaca;background:#fff1f2;color:#7f1d1d; padding:12px; margin-bottom:12px;">Error: <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card" style="padding: 16px;">
        <h1 style="margin: 0 0 12px 0;">🧪 Lab Manager</h1>
        <form method="post" enctype="multipart/form-data" style="display:grid; grid-template-columns: repeat(12, 1fr); gap: 10px; align-items:end;">
            <input type="hidden" name="action" value="save_lab">

            <label style="grid-column: span 6;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">Product</div>
                <select name="product_id" style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
                    <option value="">Unlinked / General</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo intval($p['id']); ?>"><?php echo htmlspecialchars($p['name'] . ' (' . $p['sku'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="grid-column: span 3;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">Batch Code<span style="color:#ef4444;">*</span></div>
                <input name="batch_code" type="text" required placeholder="e.g. K-2025-09-01-A" style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
            </label>

            <label style="grid-column: span 3;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">Title<span style="color:#ef4444;">*</span></div>
                <input name="result_title" type="text" required placeholder="COA Title" style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
            </label>

            <label style="grid-column: span 3;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">Lab Name</div>
                <input name="lab_name" type="text" placeholder="e.g. ABC Labs" style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
            </label>

            <label style="grid-column: span 3;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">Result Date</div>
                <input name="result_date" type="date" style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
            </label>

            <label style="grid-column: span 3;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">Received Date</div>
                <input name="received_date" type="date" style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
            </label>

            <label style="grid-column: span 6;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">Analytes JSON (optional)</div>
                <textarea name="analytes_json" rows="3" placeholder='{"attribute":"20mg/oz"}' style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;"></textarea>
            </label>

            <label style="grid-column: span 6;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">COA File (PDF/Image up to 20MB)</div>
                <input name="coa_file" type="file" accept="application/pdf,image/*" style="width:100%; padding:8px; border:1px dashed #e5e7eb; border-radius:8px; background:#f9fafb;">
            </label>

            <label style="grid-column: span 12;">
                <div style="font-weight:600; font-size: 13px; color:#374151;">Notes</div>
                <input name="notes" type="text" placeholder="Internal notes (optional)" style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
            </label>

            <label style="grid-column: span 3; display:flex; align-items:center; gap:8px;">
                <input type="checkbox" name="is_public" checked>
                <span style="font-size: 13px; color:#374151;">Publicly visible</span>
            </label>

            <div style="grid-column: span 9; text-align:right;">
                <button type="submit" class="btn" style="padding:10px 14px;">Save Lab Result</button>
            </div>
        </form>
    </div>

    <div class="card" style="margin-top: 14px; padding: 12px;">
        <form method="get" style="display:grid; grid-template-columns: 1fr 220px 220px 160px; gap: 10px; align-items:end;">
            <label>
                <div style="font-weight:600; font-size: 13px; color:#374151;">Search</div>
                <input name="term" value="<?php echo htmlspecialchars($term); ?>" type="text" placeholder="Product, title, lab, batch..." style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
            </label>
            <label>
                <div style="font-weight:600; font-size: 13px; color:#374151;">Product</div>
                <select name="product_id" style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
                    <option value="">All products</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo intval($p['id']); ?>" <?php echo ($productId && $productId == $p['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name'] . ' (' . $p['sku'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <div style="font-weight:600; font-size: 13px; color:#374151;">Batch Code</div>
                <input name="batch" value="<?php echo htmlspecialchars($batch); ?>" type="text" placeholder="e.g. K-2025-09-01-A" style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
            </label>
            <label style="display:flex; align-items:center; gap:8px;">
                <input type="checkbox" name="only_non_public" value="1" <?php echo $onlyNonPublic ? 'checked' : ''; ?>>
                <span style="font-size: 13px; color:#374151;">Only non-public</span>
            </label>
            <div style="grid-column: 1 / -1; text-align:right;">
                <button type="submit" class="btn" style="padding:8px 12px;">Filter</button>
            </div>
        </form>
    </div>

    <div class="card" style="margin-top: 10px; padding: 0; overflow:auto;">
        <table style="width:100%; border-collapse: collapse;">
            <thead>
                <tr style="background:#f9fafb; text-align:left;">
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Product</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Batch</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Title</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Lab</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Dates</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb;">Public</th>
                    <th style="padding:10px; border-bottom:1px solid #e5e7eb; text-align:right;">File</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$list): ?>
                    <tr><td colspan="7" style="padding:12px; color:#6b7280;">No records found.</td></tr>
                <?php else: foreach ($list as $row): ?>
                    <tr>
                        <td style="padding:10px; border-bottom:1px solid #f3f4f6;">
                            <?php echo htmlspecialchars($row['product_name'] ?: '—'); ?>
                            <div style="color:#6b7280; font-size:12px;"><?php echo htmlspecialchars($row['product_sku'] ?: ''); ?></div>
                        </td>
                        <td style="padding:10px; border-bottom:1px solid #f3f4f6; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">
                            <?php echo htmlspecialchars($row['batch_code']); ?>
                        </td>
                        <td style="padding:10px; border-bottom:1px solid #f3f4f6;">
                            <?php echo htmlspecialchars($row['result_title']); ?>
                        </td>
                        <td style="padding:10px; border-bottom:1px solid #f3f4f6;">
                            <?php echo htmlspecialchars($row['lab_name'] ?: ''); ?>
                        </td>
                        <td style="padding:10px; border-bottom:1px solid #f3f4f6; white-space:nowrap;">
                            <?php 
                                $rd = $row['result_date'] ?: null; $rcd = $row['received_date'] ?: null;
                                echo $rd ? ('Result: ' . htmlspecialchars($rd)) : '';
                                echo ($rd && $rcd) ? ' · ' : '';
                                echo $rcd ? ('Recv: ' . htmlspecialchars($rcd)) : '';
                            ?>
                        </td>
                        <td style="padding:10px; border-bottom:1px solid #f3f4f6;">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_public">
                                <input type="hidden" name="lab_id" value="<?php echo intval($row['id']); ?>">
                                <input type="hidden" name="to" value="<?php echo $row['is_public'] ? 0 : 1; ?>">
                                <button type="submit" class="btn small" style="min-width:88px; <?php echo $row['is_public'] ? 'background:#059669;color:white;' : 'background:#e5e7eb;color:#374151;'; ?>">
                                    <?php echo $row['is_public'] ? 'Public' : 'Hidden'; ?>
                                </button>
                            </form>
                        </td>
                        <td style="padding:10px; border-bottom:1px solid #f3f4f6; text-align:right;">
                            <?php if (!empty($row['file_path'])): ?>
                                <a href="<?php echo outbound_path($row['file_path']); ?>" target="_blank" class="btn small">Open</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>




