<?php
/**
 * Supplier Management API
 * Handles supplier CRUD, credential encryption, fuzzy matching, and verification workflows
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../required/config.php';

start_session();

// Authentication check
$userId = current_user_id();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Role check - only accountants and admins can manage suppliers
$roles = get_user_roles($userId);
$canManageSuppliers = in_array('accountant', $roles) || in_array('admin', $roles);

if (!$canManageSuppliers) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? $_REQUEST['action'] ?? '';
$pdo = db();

// Encryption key for credentials (should be in config.php)
define('CREDENTIAL_KEY', getenv('SUPPLIER_CREDENTIAL_KEY') ?: 'Product2025SecureKey!');

/**
 * Calculate Levenshtein distance percentage similarity
 */
function calculateSimilarity($str1, $str2) {
    $str1 = strtolower(trim($str1));
    $str2 = strtolower(trim($str2));
    
    $maxLen = max(strlen($str1), strlen($str2));
    if ($maxLen == 0) return 100;
    
    $distance = levenshtein($str1, $str2);
    $similarity = (1 - ($distance / $maxLen)) * 100;
    
    return round($similarity, 2);
}

/**
 * Fuzzy match vendor name to suppliers
 */
function findSupplierMatches($vendorName, $pdo) {
    $matches = [];
    
    // Get all active suppliers
    $stmt = $pdo->query("
        SELECT id, supplier_name 
        FROM suppliers 
        WHERE is_active = 1
    ");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($suppliers as $supplier) {
        $similarity = calculateSimilarity($vendorName, $supplier['supplier_name']);
        
        // Also check for partial matches
        $vendorLower = strtolower($vendorName);
        $supplierLower = strtolower($supplier['supplier_name']);
        
        if (strpos($vendorLower, $supplierLower) !== false || strpos($supplierLower, $vendorLower) !== false) {
            $similarity = max($similarity, 75); // Boost for substring matches
        }
        
        if ($similarity >= 60) { // Threshold for suggesting matches
            $matches[] = [
                'supplier_id' => $supplier['id'],
                'supplier_name' => $supplier['supplier_name'],
                'confidence' => $similarity,
                'match_algorithm' => 'levenshtein'
            ];
        }
    }
    
    // Sort by confidence descending
    usort($matches, function($a, $b) {
        return $b['confidence'] <=> $a['confidence'];
    });
    
    return $matches;
}

try {
    switch ($action) {
        
        case 'get_suppliers':
            // List all suppliers with pagination and search
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? 'active';
            $limit = min(intval($_GET['limit'] ?? 100), 500);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "
                SELECT s.*,
                       ec.category_name as default_category_name,
                       creator.first_name as creator_first_name,
                       creator.last_name as creator_last_name,
                       (SELECT COUNT(*) FROM supplier_credentials WHERE supplier_id = s.id AND is_active = 1) as credential_count,
                       (SELECT COUNT(*) FROM supplier_expenses_link WHERE supplier_id = s.id AND verification_status = 'confirmed') as expense_count,
                       (SELECT COALESCE(SUM(e.amount), 0) FROM expenses e 
                        JOIN supplier_expenses_link sel ON sel.expense_id = e.id 
                        WHERE sel.supplier_id = s.id AND sel.verification_status = 'confirmed' 
                        AND YEAR(e.expense_date) = YEAR(CURDATE())) as ytd_spend
                FROM suppliers s
                LEFT JOIN expense_categories ec ON ec.id = s.default_expense_category_id
                LEFT JOIN users creator ON creator.id = s.created_by
                WHERE 1=1
            ";
            $params = [];
            
            if ($status === 'active') {
                $sql .= " AND s.is_active = 1";
            } elseif ($status === 'inactive') {
                $sql .= " AND s.is_active = 0";
            }
            
            if ($search) {
                $sql .= " AND (s.supplier_name LIKE ? OR s.supplier_code LIKE ? OR s.contact_name LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $sql .= " ORDER BY s.supplier_name ASC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM suppliers s WHERE 1=1";
            if ($status === 'active') {
                $countSql .= " AND s.is_active = 1";
            } elseif ($status === 'inactive') {
                $countSql .= " AND s.is_active = 0";
            }
            $countStmt = $pdo->query($countSql);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            echo json_encode([
                'success' => true,
                'suppliers' => $suppliers,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;
            
        case 'get_supplier':
            // Get single supplier with credentials (decrypted)
            $supplierId = $_GET['supplier_id'] ?? 0;
            
            if (!$supplierId) {
                throw new Exception('Supplier ID required');
            }
            
            // Get supplier details
            $stmt = $pdo->prepare("
                SELECT s.*,
                       ec.category_name as default_category_name,
                       (SELECT COUNT(*) FROM supplier_expenses_link WHERE supplier_id = s.id AND verification_status = 'confirmed') as expense_count
                FROM suppliers s
                LEFT JOIN expense_categories ec ON ec.id = s.default_expense_category_id
                WHERE s.id = ?
            ");
            $stmt->execute([$supplierId]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$supplier) {
                throw new Exception('Supplier not found');
            }
            
            // Get credentials (decrypt passwords)
            $credStmt = $pdo->prepare("
                SELECT id, credential_name, login_url, login_type, username,
                       CAST(AES_DECRYPT(encrypted_password, ?) AS CHAR) as decrypted_password,
                       password_hint, two_factor_method, account_number, additional_info,
                       last_verified_date, is_active, created_at
                FROM supplier_credentials
                WHERE supplier_id = ?
                ORDER BY is_active DESC, credential_name ASC
            ");
            $credStmt->execute([CREDENTIAL_KEY, $supplierId]);
            $credentials = $credStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Log credential access
            foreach ($credentials as $cred) {
                $logStmt = $pdo->prepare("
                    INSERT INTO supplier_credential_access_log 
                    (credential_id, accessed_by, access_type, ip_address, user_agent)
                    VALUES (?, ?, 'view', ?, ?)
                ");
                $logStmt->execute([
                    $cred['id'],
                    $userId,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            }
            
            $supplier['credentials'] = $credentials;
            
            echo json_encode([
                'success' => true,
                'supplier' => $supplier
            ]);
            break;
            
        case 'create_supplier':
            // Create new supplier
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['supplier_name'])) {
                throw new Exception('Supplier name is required');
            }
            
            $pdo->beginTransaction();
            
            // Create supplier
            $stmt = $pdo->prepare("
                INSERT INTO suppliers 
                (supplier_name, supplier_code, contact_name, contact_email, contact_phone,
                 website_url, address_line1, address_line2, city, state, zip_code, country,
                 tax_id_type, tax_id_last4, supplier_type, payment_terms, 
                 default_expense_category_id, is_1099_required, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $input['supplier_name'],
                $input['supplier_code'] ?? null,
                $input['contact_name'] ?? null,
                $input['contact_email'] ?? null,
                $input['contact_phone'] ?? null,
                $input['website_url'] ?? null,
                $input['address_line1'] ?? null,
                $input['address_line2'] ?? null,
                $input['city'] ?? null,
                $input['state'] ?? null,
                $input['zip_code'] ?? null,
                $input['country'] ?? 'USA',
                $input['tax_id_type'] ?? 'none',
                $input['tax_id_last4'] ?? null,
                $input['supplier_type'] ?? 'other',
                $input['payment_terms'] ?? null,
                $input['default_expense_category_id'] ?? null,
                $input['is_1099_required'] ?? 0,
                $input['notes'] ?? null,
                $userId
            ]);
            
            $supplierId = $pdo->lastInsertId();
            
            // Add credentials if provided
            if (!empty($input['credentials']) && is_array($input['credentials'])) {
                $credStmt = $pdo->prepare("
                    INSERT INTO supplier_credentials 
                    (supplier_id, credential_name, login_url, login_type, username, 
                     encrypted_password, password_hint, two_factor_method, account_number,
                     additional_info, created_by)
                    VALUES (?, ?, ?, ?, ?, AES_ENCRYPT(?, ?), ?, ?, ?, ?, ?)
                ");
                
                foreach ($input['credentials'] as $cred) {
                    $credStmt->execute([
                        $supplierId,
                        $cred['credential_name'] ?? 'Main Account',
                        $cred['login_url'] ?? null,
                        $cred['login_type'] ?? 'username',
                        $cred['username'] ?? null,
                        $cred['password'] ?? null,
                        CREDENTIAL_KEY,
                        $cred['password_hint'] ?? null,
                        $cred['two_factor_method'] ?? null,
                        $cred['account_number'] ?? null,
                        $cred['additional_info'] ?? null,
                        $userId
                    ]);
                }
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Supplier created successfully',
                'supplier_id' => $supplierId
            ]);
            break;
            
        case 'update_supplier':
            // Update existing supplier
            $input = json_decode(file_get_contents('php://input'), true);
            $supplierId = $input['supplier_id'] ?? 0;
            
            if (!$supplierId) {
                throw new Exception('Supplier ID required');
            }
            
            $pdo->beginTransaction();
            
            // Update supplier
            $stmt = $pdo->prepare("
                UPDATE suppliers SET
                    supplier_name = ?,
                    supplier_code = ?,
                    contact_name = ?,
                    contact_email = ?,
                    contact_phone = ?,
                    website_url = ?,
                    address_line1 = ?,
                    address_line2 = ?,
                    city = ?,
                    state = ?,
                    zip_code = ?,
                    country = ?,
                    tax_id_type = ?,
                    tax_id_last4 = ?,
                    supplier_type = ?,
                    payment_terms = ?,
                    default_expense_category_id = ?,
                    is_1099_required = ?,
                    notes = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $input['supplier_name'],
                $input['supplier_code'] ?? null,
                $input['contact_name'] ?? null,
                $input['contact_email'] ?? null,
                $input['contact_phone'] ?? null,
                $input['website_url'] ?? null,
                $input['address_line1'] ?? null,
                $input['address_line2'] ?? null,
                $input['city'] ?? null,
                $input['state'] ?? null,
                $input['zip_code'] ?? null,
                $input['country'] ?? 'USA',
                $input['tax_id_type'] ?? 'none',
                $input['tax_id_last4'] ?? null,
                $input['supplier_type'] ?? 'other',
                $input['payment_terms'] ?? null,
                $input['default_expense_category_id'] ?? null,
                $input['is_1099_required'] ?? 0,
                $input['notes'] ?? null,
                $supplierId
            ]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Supplier updated successfully'
            ]);
            break;
            
        case 'delete_supplier':
            // Soft delete supplier
            $input = json_decode(file_get_contents('php://input'), true);
            $supplierId = $input['supplier_id'] ?? 0;
            
            if (!$supplierId) {
                throw new Exception('Supplier ID required');
            }
            
            $stmt = $pdo->prepare("UPDATE suppliers SET is_active = 0 WHERE id = ?");
            $stmt->execute([$supplierId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Supplier deactivated successfully'
            ]);
            break;
            
        case 'search_suppliers':
            // Type-ahead search for suppliers
            $query = $_GET['q'] ?? '';
            $limit = min(intval($_GET['limit'] ?? 10), 50);
            
            if (strlen($query) < 2) {
                echo json_encode(['success' => true, 'results' => []]);
                break;
            }
            
            $stmt = $pdo->prepare("
                SELECT id, supplier_name, supplier_code, contact_email, supplier_type
                FROM suppliers
                WHERE is_active = 1
                  AND (supplier_name LIKE ? OR supplier_code LIKE ?)
                ORDER BY supplier_name ASC
                LIMIT ?
            ");
            
            $searchTerm = '%' . $query . '%';
            $stmt->execute([$searchTerm, $searchTerm, $limit]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'results' => $results
            ]);
            break;
            
        case 'suggest_supplier_match':
            // Fuzzy match vendor name to suppliers
            $vendorName = $_POST['vendor_name'] ?? $_GET['vendor_name'] ?? '';
            
            if (!$vendorName) {
                throw new Exception('Vendor name required');
            }
            
            $matches = findSupplierMatches($vendorName, $pdo);
            
            echo json_encode([
                'success' => true,
                'matches' => $matches,
                'vendor_name' => $vendorName
            ]);
            break;
            
        case 'link_expense_to_supplier':
            // Link expense to supplier (auto or manual)
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['expense_id']) || empty($input['supplier_id'])) {
                throw new Exception('Expense ID and Supplier ID required');
            }
            
            // Check if link already exists
            $checkStmt = $pdo->prepare("
                SELECT id FROM supplier_expenses_link WHERE expense_id = ?
            ");
            $checkStmt->execute([$input['expense_id']]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                // Update existing link
                $stmt = $pdo->prepare("
                    UPDATE supplier_expenses_link SET
                        supplier_id = ?,
                        match_type = ?,
                        match_confidence = ?,
                        match_algorithm = ?,
                        verification_status = ?,
                        verified_by = ?,
                        verified_at = NOW()
                    WHERE expense_id = ?
                ");
                $stmt->execute([
                    $input['supplier_id'],
                    $input['match_type'] ?? 'manual',
                    $input['match_confidence'] ?? null,
                    $input['match_algorithm'] ?? null,
                    $input['verification_status'] ?? 'confirmed',
                    $userId,
                    $input['expense_id']
                ]);
            } else {
                // Create new link
                $stmt = $pdo->prepare("
                    INSERT INTO supplier_expenses_link 
                    (expense_id, supplier_id, match_type, match_confidence, original_vendor_name,
                     match_algorithm, verification_status, verified_by, verified_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $input['expense_id'],
                    $input['supplier_id'],
                    $input['match_type'] ?? 'manual',
                    $input['match_confidence'] ?? null,
                    $input['original_vendor_name'] ?? null,
                    $input['match_algorithm'] ?? null,
                    $input['verification_status'] ?? 'confirmed',
                    $userId
                ]);
            }
            
            // Update expense supplier_id
            $updateStmt = $pdo->prepare("UPDATE expenses SET supplier_id = ? WHERE id = ?");
            $updateStmt->execute([$input['supplier_id'], $input['expense_id']]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Expense linked to supplier successfully'
            ]);
            break;
            
        case 'confirm_supplier_match':
            // Admin confirms auto-matched supplier
            $input = json_decode(file_get_contents('php://input'), true);
            $linkId = $input['link_id'] ?? 0;
            
            if (!$linkId) {
                throw new Exception('Link ID required');
            }
            
            $stmt = $pdo->prepare("
                UPDATE supplier_expenses_link SET
                    verification_status = 'confirmed',
                    verified_by = ?,
                    verified_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$userId, $linkId]);
            
            // Update expense supplier_id
            $linkStmt = $pdo->prepare("SELECT expense_id, supplier_id FROM supplier_expenses_link WHERE id = ?");
            $linkStmt->execute([$linkId]);
            $link = $linkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($link) {
                $updateStmt = $pdo->prepare("UPDATE expenses SET supplier_id = ? WHERE id = ?");
                $updateStmt->execute([$link['supplier_id'], $link['expense_id']]);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Supplier match confirmed'
            ]);
            break;
            
        case 'reject_supplier_match':
            // Admin rejects and manually corrects
            $input = json_decode(file_get_contents('php://input'), true);
            $linkId = $input['link_id'] ?? 0;
            $correctSupplierId = $input['correct_supplier_id'] ?? null;
            
            if (!$linkId) {
                throw new Exception('Link ID required');
            }
            
            if ($correctSupplierId) {
                // Update with correct supplier
                $stmt = $pdo->prepare("
                    UPDATE supplier_expenses_link SET
                        supplier_id = ?,
                        match_type = 'manual',
                        verification_status = 'confirmed',
                        verified_by = ?,
                        verified_at = NOW(),
                        rejection_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $correctSupplierId,
                    $userId,
                    $input['rejection_reason'] ?? 'Incorrect auto-match',
                    $linkId
                ]);
                
                // Update expense
                $linkStmt = $pdo->prepare("SELECT expense_id FROM supplier_expenses_link WHERE id = ?");
                $linkStmt->execute([$linkId]);
                $link = $linkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($link) {
                    $updateStmt = $pdo->prepare("UPDATE expenses SET supplier_id = ? WHERE id = ?");
                    $updateStmt->execute([$correctSupplierId, $link['expense_id']]);
                }
            } else {
                // Just mark as rejected
                $stmt = $pdo->prepare("
                    UPDATE supplier_expenses_link SET
                        verification_status = 'rejected',
                        verified_by = ?,
                        verified_at = NOW(),
                        rejection_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $userId,
                    $input['rejection_reason'] ?? 'Incorrect match',
                    $linkId
                ]);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Match rejected and corrected'
            ]);
            break;
            
        case 'get_pending_matches':
            // Get pending supplier matches for verification
            $limit = min(intval($_GET['limit'] ?? 50), 200);
            
            $stmt = $pdo->prepare("
                SELECT * FROM v_pending_supplier_matches
                ORDER BY matched_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'pending_matches' => $pending,
                'count' => count($pending)
            ]);
            break;
            
        case 'get_supplier_expenses':
            // Get all expenses for a specific supplier
            $supplierId = $_GET['supplier_id'] ?? 0;
            $startDate = $_GET['start_date'] ?? date('Y-01-01');
            $endDate = $_GET['end_date'] ?? date('Y-12-31');
            
            if (!$supplierId) {
                throw new Exception('Supplier ID required');
            }
            
            $stmt = $pdo->prepare("
                SELECT e.*, ec.category_name,
                       sel.match_type, sel.match_confidence, sel.verification_status
                FROM expenses e
                JOIN supplier_expenses_link sel ON sel.expense_id = e.id
                JOIN expense_categories ec ON ec.id = e.expense_category_id
                WHERE sel.supplier_id = ?
                  AND sel.verification_status = 'confirmed'
                  AND e.expense_date BETWEEN ? AND ?
                ORDER BY e.expense_date DESC
            ");
            $stmt->execute([$supplierId, $startDate, $endDate]);
            $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate totals
            $totalAmount = array_sum(array_column($expenses, 'amount'));
            
            echo json_encode([
                'success' => true,
                'expenses' => $expenses,
                'total_amount' => $totalAmount,
                'count' => count($expenses)
            ]);
            break;
            
        case 'add_credential':
            // Add new credential to supplier
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['supplier_id'])) {
                throw new Exception('Supplier ID required');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO supplier_credentials 
                (supplier_id, credential_name, login_url, login_type, username, 
                 encrypted_password, password_hint, two_factor_method, account_number,
                 additional_info, created_by)
                VALUES (?, ?, ?, ?, ?, AES_ENCRYPT(?, ?), ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $input['supplier_id'],
                $input['credential_name'] ?? 'Account',
                $input['login_url'] ?? null,
                $input['login_type'] ?? 'username',
                $input['username'] ?? null,
                $input['password'] ?? null,
                CREDENTIAL_KEY,
                $input['password_hint'] ?? null,
                $input['two_factor_method'] ?? null,
                $input['account_number'] ?? null,
                $input['additional_info'] ?? null,
                $userId
            ]);
            
            $credentialId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Credential added successfully',
                'credential_id' => $credentialId
            ]);
            break;
            
        case 'update_credential':
            // Update existing credential
            $input = json_decode(file_get_contents('php://input'), true);
            $credentialId = $input['credential_id'] ?? 0;
            
            if (!$credentialId) {
                throw new Exception('Credential ID required');
            }
            
            // Build update query dynamically
            $updates = [];
            $params = [];
            
            $fields = ['credential_name', 'login_url', 'login_type', 'username', 
                      'password_hint', 'two_factor_method', 'account_number', 'additional_info'];
            
            foreach ($fields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "{$field} = ?";
                    $params[] = $input[$field];
                }
            }
            
            // Handle password separately (needs encryption)
            if (isset($input['password']) && $input['password'] !== '') {
                $updates[] = "encrypted_password = AES_ENCRYPT(?, ?)";
                $params[] = $input['password'];
                $params[] = CREDENTIAL_KEY;
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
            }
            
            $params[] = $credentialId;
            $sql = "UPDATE supplier_credentials SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Log access
            $logStmt = $pdo->prepare("
                INSERT INTO supplier_credential_access_log 
                (credential_id, accessed_by, access_type, ip_address)
                VALUES (?, ?, 'edit', ?)
            ");
            $logStmt->execute([$credentialId, $userId, $_SERVER['REMOTE_ADDR'] ?? null]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Credential updated successfully'
            ]);
            break;
            
        case 'delete_credential':
            // Soft delete credential
            $input = json_decode(file_get_contents('php://input'), true);
            $credentialId = $input['credential_id'] ?? 0;
            
            if (!$credentialId) {
                throw new Exception('Credential ID required');
            }
            
            $stmt = $pdo->prepare("UPDATE supplier_credentials SET is_active = 0 WHERE id = ?");
            $stmt->execute([$credentialId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Credential removed successfully'
            ]);
            break;
            
        case 'add_login_type':
            // Add new custom login type
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['type_code']) || empty($input['type_label'])) {
                throw new Exception('Type code and label required');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO supplier_login_types 
                (type_code, type_label, icon_class, sort_order, created_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    type_label = VALUES(type_label),
                    icon_class = VALUES(icon_class),
                    sort_order = VALUES(sort_order)
            ");
            
            $stmt->execute([
                $input['type_code'],
                $input['type_label'],
                $input['icon_class'] ?? 'bi-gear',
                $input['sort_order'] ?? 100,
                $userId
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Login type added successfully'
            ]);
            break;
            
        case 'get_login_types':
            // Get all available login types
            $stmt = $pdo->query("
                SELECT * FROM supplier_login_types
                WHERE is_active = 1
                ORDER BY sort_order ASC, type_label ASC
            ");
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'login_types' => $types
            ]);
            break;
            
        case 'get_supplier_stats':
            // Get overall supplier statistics
            $stmt = $pdo->query("
                SELECT 
                    COUNT(*) as total_active_suppliers,
                    SUM(CASE WHEN is_1099_required = 1 THEN 1 ELSE 0 END) as suppliers_1099,
                    (SELECT COUNT(*) FROM supplier_expenses_link WHERE verification_status = 'pending') as pending_matches,
                    (SELECT COUNT(*) FROM supplier_credentials WHERE is_active = 1) as total_credentials
                FROM suppliers
                WHERE is_active = 1
            ");
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}











