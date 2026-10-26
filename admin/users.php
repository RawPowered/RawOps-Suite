<?php
$SEO = [
    'title' => 'User Management — Admin',
    'description' => 'Manage all system users, roles, and permissions.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/admin_user_utils.php';
require_once __DIR__ . '/../required/spam_prevention_utils.php';
require_admin_or_redirect(path_with_base('/admin/'));

$pdo = db();
$message = '';
$error = '';

// Get pending legal access requests for display
try {
    $stmt = $pdo->query('
        SELECT 
            r.id,
            r.requestor_name,
            r.email,
            r.law_firm,
            r.desired_username,
            r.created_at,
            c.title as case_title,
            c.case_number
        FROM legal_access_requests r
        LEFT JOIN legal_cases c ON c.id = r.case_id
        WHERE r.status = "pending"
        ORDER BY r.created_at DESC
        LIMIT 5
    ');
    $pendingLegalRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingLegalCount = count($pendingLegalRequests);
} catch (Exception $e) {
    error_log('Error fetching pending legal requests: ' . $e->getMessage());
    $pendingLegalRequests = [];
    $pendingLegalCount = 0;
}

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    
    try {
        if ($action === 'create_user') {
            // Create new user
            $email = trim($_POST['email']);
            $firstName = trim($_POST['first_name']);
            $lastName = trim($_POST['last_name']);
            $phone = trim($_POST['phone']);
            $password = $_POST['password'];
            $roles = $_POST['roles'] ?? [];
            $status = $_POST['status'] ?? 'active';
            $businessName = trim($_POST['business_name'] ?? '');
            
            // Validate required fields
            if (empty($email) || empty($firstName) || empty($lastName) || empty($password)) {
                throw new Exception('Email, first name, last name, and password are required.');
            }
            
            // Validate first name for spam
            $firstNameValidation = validate_name_field($firstName, 'First name');
            if (!$firstNameValidation['valid']) {
                throw new Exception(implode(' ', $firstNameValidation['errors']));
            }
            
            // Validate last name for spam
            $lastNameValidation = validate_name_field($lastName, 'Last name');
            if (!$lastNameValidation['valid']) {
                throw new Exception(implode(' ', $lastNameValidation['errors']));
            }
            
            // Check if email already exists
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new Exception('Email address already exists.');
            }
            
            // Hash password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, phone, status) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$email, $passwordHash, $firstName, $lastName, $phone, $status]);
            $newUserId = $pdo->lastInsertId();
            
            // Assign roles
            foreach ($roles as $roleId) {
                $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                $stmt->execute([$newUserId, intval($roleId)]);
            }
            
            // Create wholesale account if business name provided and user has sales_agent or customer role
            if (!empty($businessName) && (in_array('2', $roles) || in_array('4', $roles))) {
                // Check if wholesale account already exists
                $stmt = $pdo->prepare('SELECT id FROM wholesale_accounts WHERE user_id = ?');
                $stmt->execute([$newUserId]);
                if (!$stmt->fetch()) {
                    $approved = in_array('2', $roles) ? 1 : 0; // Auto-approve for sales agents
                    $stmt = $pdo->prepare('INSERT INTO wholesale_accounts (user_id, business_name, approved) VALUES (?, ?, ?)');
                    $stmt->execute([$newUserId, $businessName, $approved]);
                }
            }
            
            $message = "User created successfully with assigned roles.";
        }
        
        elseif ($action === 'update_roles' && $user_id > 0) {
            $roles = $_POST['roles'] ?? [];
            
            // Remove all current roles
            $stmt = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?');
            $stmt->execute([$user_id]);
            
            // Add new roles
            foreach ($roles as $roleId) {
                $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                $stmt->execute([$user_id, intval($roleId)]);
            }
            
            $message = "User roles updated successfully.";
        }
        
        elseif ($user_id > 0) {
            switch ($action) {
                case 'activate':
                    $stmt = $pdo->prepare('UPDATE users SET status = "active" WHERE id = ?');
                    $stmt->execute([$user_id]);
                    $message = "User activated successfully";
                    break;
                    
                case 'suspend':
                    $stmt = $pdo->prepare('UPDATE users SET status = "suspended" WHERE id = ?');
                    $stmt->execute([$user_id]);
                    $message = "User suspended";
                    break;
                    
                case 'delete':
                    $deleteResult = admin_delete_user($pdo, $user_id);
                    if ($deleteResult['mode'] === 'deleted') {
                        $message = 'User deleted.';
                    } elseif ($deleteResult['mode'] === 'missing') {
                        $message = 'User already removed.';
                    } else {
                        $message = 'User archived and anonymized.';
                        if (!empty($deleteResult['warning'])) {
                            flash_set('warning', $deleteResult['warning']);
                        }
                    }
                    break;
                    
                case 'reset_password':
                    $newPassword = $_POST['new_password'];
                    if (strlen($newPassword) < 6) {
                        throw new Exception('Password must be at least 6 characters.');
                    }
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $stmt->execute([$passwordHash, $user_id]);
                    $message = "Password reset successfully.";
                    break;
            }
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
    
    // Redirect to prevent form resubmission
if ($message || $error) {
    if ($message) flash_set('success', $message);
    if ($error) flash_set('error', $error);
    redirect($_SERVER['REQUEST_URI']);
}
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? 'all';

// Build query
$query = '
    SELECT u.*, 
           GROUP_CONCAT(r.name) as roles,
           wa.business_name,
           wa.approved as wholesale_approved,
           (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as order_count
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    LEFT JOIN wholesale_accounts wa ON wa.user_id = u.id
    WHERE u.status <> "deleted"
';

$params = [];

if ($filter === 'active') {
    $query .= ' AND u.status = "active"';
} elseif ($filter === 'suspended') {
    $query .= ' AND u.status = "suspended"';
} elseif ($filter === 'pending') {
    $query .= ' AND u.status = "pending"';
}

if (!empty($search)) {
    $query .= ' AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR wa.business_name LIKE ?)';
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

if ($role_filter !== 'all') {
    $query .= ' AND r.name = ?';
    $params[] = $role_filter;
}

$query .= ' GROUP BY u.id ORDER BY u.created_at DESC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => $pdo->query('SELECT COUNT(*) FROM users WHERE status != "deleted"')->fetchColumn(),
    'active' => $pdo->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn(),
    'trainees' => $pdo->query('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.name = "trainee"')->fetchColumn(),
    'agents' => $pdo->query('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.name = "sales_agent"')->fetchColumn(),
    'admins' => $pdo->query('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.name = "admin"')->fetchColumn(),
    'customers' => $pdo->query('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.name = "customer"')->fetchColumn(),
    'pending' => $pdo->query('SELECT COUNT(*) FROM users WHERE status = "pending"')->fetchColumn(),
    'suspended' => $pdo->query('SELECT COUNT(*) FROM users WHERE status = "suspended"')->fetchColumn()
];

// Get all available roles and ensure customer role exists
try {
    // Add customer role if it doesn't exist
    $pdo->query('INSERT IGNORE INTO roles (id, name) VALUES (4, "customer")');
} catch (Exception $e) {
    // Role might already exist, continue
}

$rolesQuery = $pdo->query('SELECT * FROM roles ORDER BY id');
$availableRoles = $rolesQuery->fetchAll();
?>

<style>
body {
    background: #f8f9fa;
}
.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 24px;
}
.admin-header {
    background: white;
    border-bottom: 1px solid #e8eaed;
    padding: 32px 0;
    margin-bottom: 24px;
}
.admin-header h1 {
    color: #202124;
    font-weight: 400;
    font-size: 28px;
}
.admin-header p {
    color: #5f6368;
    font-size: 14px;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.stat-card {
    background: white;
    padding: 24px 20px;
    border-radius: 8px;
    border: 1px solid #dadce0;
    text-align: center;
    transition: all 0.2s;
}
.stat-card:hover {
    box-shadow: 0 1px 3px 0 rgba(60,64,67,.3), 0 4px 8px 3px rgba(60,64,67,.15);
    border-color: transparent;
}
.stat-value {
    font-size: 32px;
    font-weight: 400;
    margin: 8px 0;
    color: #202124;
}
.stat-label {
    color: #5f6368;
    font-size: 12px;
    text-transform: none;
    font-weight: 500;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.filter-bar {
    background: white;
    padding: 16px 20px;
    border-radius: 8px;
    border: 1px solid #dadce0;
    margin-bottom: 16px;
}
.users-table {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #dadce0;
}
.users-table table {
    width: 100%;
    border-collapse: collapse;
}
.users-table th {
    background: #f8f9fa;
    padding: 14px 16px;
    text-align: left;
    font-weight: 500;
    color: #5f6368;
    border-bottom: 1px solid #dadce0;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.users-table td {
    padding: 16px;
    border-bottom: 1px solid #e8eaed;
    font-size: 14px;
    color: #202124;
}
.users-table tbody tr {
    transition: background-color 0.1s;
}
.users-table tbody tr:hover {
    background-color: #f8f9fa;
}
.role-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 16px;
    font-size: 11px;
    font-weight: 500;
    margin-right: 6px;
    border: 1px solid;
}
.role-badge i { margin-right: 4px; font-size: 10px; }
.role-admin { background: #fce8e6; color: #c5221f; border-color: #f28b82; }
.role-sales_agent { background: #e8f0fe; color: #1967d2; border-color: #aecbfa; }
.role-trainee { background: #fef7e0; color: #f29900; border-color: #fdd663; }
.role-customer { background: #e6f4ea; color: #137333; border-color: #81c995; }
.role-investor { background: #f3e8fd; color: #8e24aa; border-color: #d7aefb; }
.role-retail { background: #e4f7fb; color: #129eaf; border-color: #a8dab5; }
.status-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    border: 1px solid;
}
.status-active { background: #e6f4ea; color: #137333; border-color: #81c995; }
.status-suspended { background: #fce8e6; color: #c5221f; border-color: #f28b82; }
.status-pending { background: #fef7e0; color: #f29900; border-color: #fdd663; }
.action-buttons {
    display: flex;
    gap: 4px;
    align-items: center;
}
.btn-small {
    padding: 8px 12px;
    font-size: 14px;
    border-radius: 4px;
    border: 1px solid #dadce0;
    background: white;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
}
.btn-small:hover {
    background: #f8f9fa;
    border-color: #5f6368;
    box-shadow: 0 1px 2px 0 rgba(60,64,67,.3), 0 1px 3px 1px rgba(60,64,67,.15);
}
.btn-small i {
    font-size: 14px;
}
.btn-activate:hover { background: #e6f4ea; color: #137333; border-color: #137333; }
.btn-suspend:hover { background: #fef7e0; color: #f29900; border-color: #f29900; }
.btn-edit:hover { background: #e8f0fe; color: #1967d2; border-color: #1967d2; }
.btn-roles:hover { background: #e8f0fe; color: #1967d2; border-color: #1967d2; }
.btn-password:hover { background: #f3e8fd; color: #8e24aa; border-color: #8e24aa; }
.btn-delete:hover { background: #fce8e6; color: #c5221f; border-color: #c5221f; }
.btn-incinerate:hover { background: #e8eaed; color: #202124; border-color: #202124; }
.btn {
    padding: 10px 24px;
    border-radius: 4px;
    border: none;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    background: #1a73e8;
    color: white;
    text-decoration: none;
    display: inline-block;
}
.btn:hover {
    background: #1765cc;
    box-shadow: 0 1px 2px 0 rgba(60,64,67,.3), 0 1px 3px 1px rgba(60,64,67,.15);
}
.btn.secondary {
    background: white;
    color: #5f6368;
    border: 1px solid #dadce0;
}
.btn.secondary:hover {
    background: #f8f9fa;
    border-color: #5f6368;
}
input[type="text"],
input[type="email"],
input[type="tel"],
input[type="password"],
select,
textarea {
    padding: 12px 16px;
    border: 1px solid #dadce0;
    border-radius: 4px;
    font-size: 14px;
    color: #202124;
    transition: all 0.2s;
    background: white;
}
input:focus,
select:focus,
textarea:focus {
    outline: none;
    border-color: #1a73e8;
    box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.1);
}
label {
    font-size: 14px;
    color: #5f6368;
    font-weight: 500;
}
</style>

<div class="admin-header">
    <div class="container">
        <h1><i class="fas fa-users-cog" style="margin-right: 12px; color: #5f6368;"></i>User Management</h1>
        <p>Manage all system users and their roles</p>
    </div>
</div>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <?php if ($pendingLegalCount > 0): ?>
    <div style="background:linear-gradient(135deg, #fef3c7 0%, #fef9c3 100%);border:2px solid #fbbf24;border-radius:12px;padding:24px;margin-bottom:28px;box-shadow:0 8px 16px rgba(0,0,0,0.08);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <div>
                <h3 style="margin:0 0 8px 0;font-size:1.3rem;font-weight:700;color:#0f172a;">⏳ Pending Legal Team Access Requests</h3>
                <p style="margin:0;color:#854d0e;font-size:0.95rem;"><?php echo $pendingLegalCount; ?> legal professional<?php echo $pendingLegalCount > 1 ? 's' : ''; ?> waiting for approval</p>
            </div>
            <a href="<?php echo path_with_base('/admin/legal/access-requests.php'); ?>" style="padding:12px 24px;background:#1e3a8a;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">Review Requests →</a>
        </div>
        
        <div style="display:grid;gap:12px;margin-top:20px;">
            <?php foreach (array_slice($pendingLegalRequests, 0, 3) as $request): ?>
            <div style="background:#fff;border:1px solid #fbbf24;border-radius:8px;padding:16px;display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <div style="font-weight:600;color:#0f172a;margin-bottom:4px;">
                        <?php echo htmlspecialchars($request['requestor_name']); ?>
                        <?php if ($request['law_firm']): ?>
                            <span style="color:#64748b;font-weight:normal;">— <?php echo htmlspecialchars($request['law_firm']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:0.85rem;color:#64748b;">
                        <?php echo htmlspecialchars($request['email']); ?> • Username: <strong><?php echo htmlspecialchars($request['desired_username']); ?></strong>
                        <?php if ($request['case_title']): ?>
                            <br><span style="color:#1e3a8a;">Case: <?php echo htmlspecialchars($request['case_number'] ?? ''); ?> — <?php echo htmlspecialchars($request['case_title']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="font-size:0.8rem;color:#94a3b8;white-space:nowrap;">
                    <?php echo date('M j, Y', strtotime($request['created_at'])); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($pendingLegalCount > 3): ?>
        <div style="text-align:center;margin-top:12px;">
            <a href="<?php echo path_with_base('/admin/legal/access-requests.php'); ?>" style="color:#1e3a8a;font-weight:600;font-size:0.9rem;">View all <?php echo $pendingLegalCount; ?> requests →</a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($message): ?>
        <div class="card" style="background: #dcfce7; color: #14532d; margin-bottom: 24px;">
            ✅ <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="card" style="background: #fee2e2; color: #991b1b; margin-bottom: 24px;">
            ❌ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Enhanced Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label"><i class="fas fa-users"></i> Total Users</div>
            <div class="stat-value"><?php echo $stats['total']; ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">System-wide</div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fas fa-circle-check"></i> Active</div>
            <div class="stat-value" style="color: #059669;"><?php echo $stats['active']; ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;"><?php echo $stats['pending']; ?> pending</div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fas fa-graduation-cap"></i> Trainees</div>
            <div class="stat-value" style="color: #f59e0b;"><?php echo $stats['trainees']; ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">In training</div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fas fa-user-tie"></i> Sales Agents</div>
            <div class="stat-value" style="color: #3b82f6;"><?php echo $stats['agents']; ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">Certified</div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fas fa-shield-halved"></i> Admins</div>
            <div class="stat-value" style="color: #dc2626;"><?php echo $stats['admins']; ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">System access</div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fas fa-shopping-cart"></i> Customers</div>
            <div class="stat-value" style="color: #059669;"><?php echo $stats['customers']; ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">Shop access</div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fas fa-ban"></i> Suspended</div>
            <div class="stat-value" style="color: #9ca3af;"><?php echo $stats['suspended']; ?></div>
            <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">Restricted</div>
        </div>
    </div>
    
    <!-- Create New User -->
    <div style="background: white; border-radius: 8px; border: 1px solid #dadce0; padding: 24px; margin-bottom: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0; color: #202124; font-size: 18px; font-weight: 500;"><i class="fas fa-user-plus" style="margin-right: 8px; color: #5f6368;"></i>Create New User</h2>
            <button type="button" onclick="toggleCreateForm()" id="create-toggle" class="btn secondary">
                Show Create Form
            </button>
        </div>
        
        <div id="create-user-form" style="display: none;">
            <form method="post">
                <input type="hidden" name="action" value="create_user">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #374151;">Email *</label>
                        <input type="email" name="email" required style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #374151;">Password *</label>
                        <input type="password" name="password" required minlength="6" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px;">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #374151;">First Name *</label>
                        <input type="text" name="first_name" required style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #374151;">Last Name *</label>
                        <input type="text" name="last_name" required style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px;">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #374151;">Phone</label>
                        <input type="tel" name="phone" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #374151;">Status</label>
                        <select name="status" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px;">
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 12px; color: #374151;">User Roles *</label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
                        <?php foreach ($availableRoles as $role): ?>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 12px; background: #f9fafb; border-radius: 8px; cursor: pointer;">
                                <input type="checkbox" name="roles[]" value="<?php echo $role['id']; ?>" style="margin: 0;">
                                <span style="font-weight: 600; color: #374151;">
                                    <?php echo ucfirst(str_replace('_', ' ', $role['name'])); ?>
                                </span>
                                <span style="font-size: 11px; color: #6b7280;">
                                    (ID: <?php echo $role['id']; ?>)
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top: 8px; font-size: 12px; color: #6b7280;">
                        <i class="fas fa-lightbulb"></i> <strong>Role Guide:</strong> Trainee (learning), Sales Agent (certified), Admin (full access), Customer (wholesale shop)
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #374151;">Business Name (for Sales Agents/Customers)</label>
                    <input type="text" name="business_name" placeholder="Leave empty for non-business users" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px;">
                    <div style="margin-top: 4px; font-size: 12px; color: #6b7280;">
                        Sales Agents get auto-approved wholesale accounts. Customers get pending accounts for review.
                    </div>
                </div>
                
                <div style="display: flex; gap: 12px;">
                    <button type="submit" class="btn" style="background: #059669;">Create User</button>
                    <button type="button" onclick="toggleCreateForm()" class="btn secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="get" style="display: flex; gap: 16px; flex-wrap: wrap;">
            <input type="text" name="search" placeholder="Search users..." 
                   value="<?php echo htmlspecialchars($search); ?>"
                   style="flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
            
            <select name="filter" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                <option value="all">All Status</option>
                <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="suspended" <?php echo $filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
            </select>
            
            <select name="role" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                <option value="all">All Roles</option>
                <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                <option value="sales_agent" <?php echo $role_filter === 'sales_agent' ? 'selected' : ''; ?>>Sales Agent</option>
                <option value="trainee" <?php echo $role_filter === 'trainee' ? 'selected' : ''; ?>>Trainee</option>
                <option value="customer" <?php echo $role_filter === 'customer' ? 'selected' : ''; ?>>Customer</option>
            </select>
            
            <button type="submit" class="btn">Filter</button>
        </form>
    </div>
    
    <!-- Users Table -->
<div class="users-table">
    <div style="display:flex; align-items:center; gap:12px; padding:12px; border-bottom:1px solid #e5e7eb; background:#f9fafb;">
        <select id="bulkActionSelect" required style="padding:8px 12px; border:1px solid #d1d5db; border-radius:8px;">
            <option value="">Bulk action…</option>
            <option value="activate">Activate</option>
            <option value="pending">Mark Pending</option>
            <option value="suspend">Suspend</option>
            <option value="delete">Delete</option>
            <option value="incinerate">Incinerate (permanent)</option>
            <option value="mark_spam">Mark as Spam & Blacklist</option>
            <option value="role_add">Add Role</option>
            <option value="role_remove">Remove Role</option>
        </select>
        <select id="bulkRoleSelect" style="padding:8px 12px; border:1px solid #d1d5db; border-radius:8px; display:none;">
            <option value="">Select role…</option>
            <?php foreach ($availableRoles as $role): ?>
                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$role['name']))); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn" style="padding:8px 16px;" onclick="triggerBulkAction()">Apply</button>
        <span style="font-size:12px; color:#6b7280;">Use the checkboxes to target multiple users.</span>
    </div>
    <div id="bulkStatus" style="display:none; padding:12px; font-size:13px; color:#0f172a; background:#eef2ff; border-bottom:1px solid #e0e7ff;"></div>
    <table>
        <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" id="selectAll"></th>
                <th>User</th>
                <th>Email</th>
                <th>Roles</th>
                <th>Business</th>
                <th>Orders</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr data-user-id="<?php echo $user['id']; ?>">
                <td><input type="checkbox" value="<?php echo $user['id']; ?>" class="row-checkbox"></td>
                <td>
                    <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                </td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td>
                        <?php 
                        $roles = explode(',', $user['roles'] ?? '');
                        $roleIcons = [
                            'admin' => 'fa-shield-halved',
                            'sales_agent' => 'fa-user-tie',
                            'trainee' => 'fa-graduation-cap',
                            'customer' => 'fa-shopping-cart',
                            'investor' => 'fa-chart-line',
                            'retail' => 'fa-store'
                        ];
                        foreach ($roles as $role):
                            if ($role):
                                $icon = $roleIcons[$role] ?? 'fa-user';
                        ?>
                            <span class="role-badge role-<?php echo $role; ?>">
                                <i class="fas <?php echo $icon; ?>"></i><?php echo ucfirst(str_replace('_', ' ', $role)); ?>
                            </span>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </td>
                    <td>
                        <?php if ($user['business_name']): ?>
                            <?php echo htmlspecialchars($user['business_name']); ?>
                            <?php if ($user['wholesale_approved']): ?>
                                <span style="color: #059669;">✓</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: #9ca3af;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo intval($user['order_count']); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $user['status']; ?>">
                            <?php echo ucfirst($user['status']); ?>
                        </span>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                    <td>
                        <div class="action-buttons">
                            <!-- Status Actions -->
                            <?php if ($user['status'] !== 'active'): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn-small btn-activate" title="Activate User">
                                        <i class="fas fa-circle-check"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <?php if ($user['status'] === 'active'): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="suspend">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn-small btn-suspend" title="Suspend User" onclick="return confirm('Suspend this user?')">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <!-- User Detail -->
                            <a href="<?php echo path_with_base('/admin/user-detail.php'); ?>?id=<?php echo $user['id']; ?>" class="btn-small btn-edit" title="Edit User Details">
                                <i class="fas fa-pen-to-square"></i>
                            </a>
                            
                            <!-- Role Management -->
                            <button type="button" class="btn-small btn-roles" onclick="showRoleEditor(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['roles'] ?? ''); ?>')" title="Edit Roles">
                                <i class="fas fa-user-shield"></i>
                            </button>
                            
                            <!-- Password Reset -->
                            <button type="button" class="btn-small btn-password" onclick="showPasswordReset(<?php echo $user['id']; ?>)" title="Reset Password">
                                <i class="fas fa-key"></i>
                            </button>
                            
                            <!-- Delete -->
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <button type="submit" class="btn-small btn-delete" title="Delete User" onclick="return confirm('Are you sure you want to delete this user?')">
                                    <i class="fas fa-trash-can"></i>
                                </button>
                            </form>

                            <!-- Incinerate -->
                            <button type="button" class="btn-small btn-incinerate" title="Incinerate User (Permanent)" onclick="incinerateUser(<?php echo $user['id']; ?>)">
                                <i class="fas fa-fire"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
        </tbody>
    </table>
    
    <?php if (empty($users)): ?>
        <div style="padding: 60px; text-align: center;">
            <p style="color: #6b7280;">No users found</p>
        </div>
    <?php endif; ?>
</div>
</div>

<!-- Role Editor Modal -->
<div id="role-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 32px; border-radius: 12px; max-width: 500px; width: 90%;">
        <h3 style="margin: 0 0 20px 0;">Edit User Roles</h3>
        <form method="post" id="role-form">
            <input type="hidden" name="action" value="update_roles">
            <input type="hidden" name="user_id" id="role-user-id">
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 12px;">Select Roles:</label>
                <div style="display: grid; grid-template-columns: 1fr; gap: 8px;">
                    <?php foreach ($availableRoles as $role): ?>
                        <label style="display: flex; align-items: center; gap: 8px; padding: 8px; background: #f9fafb; border-radius: 6px; cursor: pointer;">
                            <input type="checkbox" name="roles[]" value="<?php echo $role['id']; ?>" class="role-checkbox" data-role="<?php echo $role['name']; ?>">
                            <span style="font-weight: 600;">
                                <?php echo ucfirst(str_replace('_', ' ', $role['name'])); ?>
                            </span>
                            <span style="font-size: 11px; color: #6b7280;">
                                (<?php echo $role['name']; ?>)
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" onclick="hideRoleEditor()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn">Update Roles</button>
            </div>
        </form>
    </div>
</div>

<!-- Password Reset Modal -->
<div id="password-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 32px; border-radius: 12px; max-width: 400px; width: 90%;">
        <h3 style="margin: 0 0 20px 0;">Reset Password</h3>
        <form method="post" id="password-form">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="password-user-id">
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">New Password (min 6 characters):</label>
                <input type="password" name="new_password" required minlength="6" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px;">
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" onclick="hidePasswordReset()" class="btn secondary">Cancel</button>
                <button type="submit" class="btn" style="background: #dc2626;">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<div id="incinerator-warning" style="display:none; position:fixed; inset:0; background:rgba(17,24,39,0.88); z-index:2000; align-items:center; justify-content:center;">
    <div style="background:#ffffff; color:#111827; padding:32px; max-width:520px; width:92%; border-radius:16px; box-shadow:0 25px 50px -12px rgba(30,41,59,0.45);">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
            <span style="font-size:34px; color:#dc2626;"><i class="fas fa-fire"></i></span>
            <div>
                <h2 style="margin:0; font-size:22px;"><i class="fas fa-triangle-exclamation" style="color:#dc2626;"></i> Admin Incinerator</h2>
                <p style="margin:4px 0 0; color:#6b7280; font-size:13px;">Permanent destruction of users, wholesale accounts, associated leads, call logs, tasks, orders, payment methods, and audit trails.</p>
            </div>
        </div>
        <ul style="margin:0 0 16px 20px; color:#374151; font-size:13px;">
            <li>No backups are created. Data is removed at the database level.</li>
            <li>All wholesale accounts owned by the user are deleted with their orders, payment methods, and COC permissions.</li>
            <li>Assigned CRM leads and converted accounts tied to those leads are purged.</li>
            <li>API keys, OAuth tokens, sessions, and login activity are erased.</li>
            <li>This action cannot be undone. Only use on demo/test or legally required removals.</li>
        </ul>
        <p style="margin:0 0 12px; font-weight:600;">Type <code style="background:#f3f4f6; padding:2px 6px; border-radius:4px;">INCINERATE</code> to confirm this action.</p>
        <input id="incinerate-confirm-input" type="text" placeholder="Type INCINERATE" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; margin-bottom:16px;">
        <div style="display:flex; justify-content:flex-end; gap:12px;">
            <button type="button" class="btn secondary" onclick="hideIncineratorWarning(true)">Cancel</button>
            <button type="button" class="btn" style="background:#111827;" onclick="confirmIncineratorProceed()">Proceed</button>
        </div>
    </div>
</div>

<script>
function toggleCreateForm() {
    const form = document.getElementById('create-user-form');
    const toggle = document.getElementById('create-toggle');
    
    if (form.style.display === 'none') {
        form.style.display = 'block';
        toggle.textContent = 'Hide Create Form';
    } else {
        form.style.display = 'none';
        toggle.textContent = 'Show Create Form';
    }
}

function showRoleEditor(userId, currentRoles) {
    document.getElementById('role-user-id').value = userId;
    
    // Clear all checkboxes
    document.querySelectorAll('.role-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Check current roles
    const roles = currentRoles.split(',');
    roles.forEach(role => {
        const checkbox = document.querySelector(`.role-checkbox[data-role="${role.trim()}"]`);
        if (checkbox) {
            checkbox.checked = true;
        }
    });
    
    document.getElementById('role-modal').style.display = 'block';
}

function hideRoleEditor() {
    document.getElementById('role-modal').style.display = 'none';
}

function showPasswordReset(userId) {
    document.getElementById('password-user-id').value = userId;
    document.getElementById('password-modal').style.display = 'block';
}

function hidePasswordReset() {
    document.getElementById('password-modal').style.display = 'none';
    document.querySelector('input[name="new_password"]').value = '';
}

// Close modals when clicking outside
document.getElementById('role-modal').addEventListener('click', function(e) {
    if (e.target === this) hideRoleEditor();
});

document.getElementById('password-modal').addEventListener('click', function(e) {
    if (e.target === this) hidePasswordReset();
});

document.getElementById('selectAll').addEventListener('change', function(){
    const checked = this.checked;
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = checked);
});

document.getElementById('bulkActionSelect').addEventListener('change', function(){
    const needsRole = this.value === 'role_add' || this.value === 'role_remove';
    const roleSel = document.getElementById('bulkRoleSelect');
    roleSel.style.display = needsRole ? 'inline-block' : 'none';
    if (!needsRole) {
        roleSel.value = '';
    }
});

let incineratorPendingAction = null;

function showIncineratorWarning(pendingFn) {
    incineratorPendingAction = pendingFn;
    const overlay = document.getElementById('incinerator-warning');
    if (overlay) {
        overlay.style.display = 'flex';
        const input = document.getElementById('incinerate-confirm-input');
        if (input) {
            input.value = '';
            setTimeout(() => input.focus(), 30);
        }
    }
}

function hideIncineratorWarning(clearPending = false) {
    if (clearPending) {
        incineratorPendingAction = null;
    }
    const overlay = document.getElementById('incinerator-warning');
    if (overlay) overlay.style.display = 'none';
}

function confirmIncineratorProceed() {
    const input = document.getElementById('incinerate-confirm-input');
    if (!input || input.value.trim().toUpperCase() !== 'INCINERATE') {
        alert('Please type INCINERATE to confirm.');
        return;
    }
    const pending = incineratorPendingAction;
    if (typeof pending !== 'function') {
        alert('No incineration action pending. Please try again.');
        hideIncineratorWarning(true);
        return;
    }
    incineratorPendingAction = null;
    hideIncineratorWarning();
    pending();
}

const incineratorInputEl = document.getElementById('incinerate-confirm-input');
if (incineratorInputEl) {
    incineratorInputEl.addEventListener('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmIncineratorProceed();
        }
    });
}

async function triggerBulkAction(){
    const checkboxes = Array.from(document.querySelectorAll('.row-checkbox:checked'));
    if (!checkboxes.length){
        alert('Select at least one user for bulk actions.');
        return;
    }
    const action = document.getElementById('bulkActionSelect').value;
    if (!action){
        alert('Choose a bulk action first.');
        return;
    }
    if ((action === 'role_add' || action === 'role_remove') && !document.getElementById('bulkRoleSelect').value){
        alert('Select a role to apply.');
        return;
    }
    if (action === 'delete' && !confirm('Delete selected users? This cannot be undone.')){
        return;
    }
    if (action === 'incinerate' && !confirm('Incinerate permanently? This wipes users, related accounts, leads, and activity. Proceed?')) {
        return;
    }
    if (action === 'mark_spam' && !confirm('Mark selected users as spam and add to blacklist? This will prevent them from registering again.')) {
        return;
    }

    const userIds = checkboxes.map(cb => parseInt(cb.value,10));
    const roleId = parseInt(document.getElementById('bulkRoleSelect').value,10) || null;

    const statusBar = document.getElementById('bulkStatus');
    statusBar.style.display = 'block';
    statusBar.style.color = '#0f172a';
    statusBar.textContent = 'Processing...';

    try{
        let res;
        if (action === 'incinerate') {
            showIncineratorWarning(() => runIncineratorBulk(userIds));
            statusBar.textContent = 'Awaiting confirmation...';
            return;
        } else if (action === 'mark_spam') {
            res = await fetch('<?=outbound_path('/api/spam-admin-api.php?action=mark_users_as_spam')?>',{
                method:'POST',
                credentials:'same-origin',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({user_ids:userIds, delete_users:false})
            });
        } else {
            res = await fetch('<?=outbound_path('/api/email-admin-api.php?action=bulk_user_action')?>',{
                method:'POST',
                credentials:'same-origin',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({bulk_action:action,user_ids:userIds,role_id:roleId})
            });
        }
        const json = await res.json();
        if(!json.ok){
            throw new Error(json.error || 'Bulk action failed');
        }
        if (action === 'incinerate') {
            statusBar.style.color = '#15803d';
            statusBar.textContent = `Incinerated ${json.count || userIds.length} user(s).`;
        } else if (action === 'mark_spam') {
            statusBar.style.color = '#15803d';
            statusBar.textContent = `Marked ${json.count || userIds.length} user(s) as spam with ${json.blacklisted_entries || 0} blacklist entries.`;
        } else {
            const summary = json.summary;
            statusBar.style.color = '#15803d';
            statusBar.textContent = summary.message || 'Bulk update completed.';
            if(summary.warnings?.length){
                statusBar.style.color = '#b45309';
                statusBar.textContent += ' Warnings: ' + summary.warnings.join('; ');
            }
        }

        refreshUserTable();
    }catch(err){
        statusBar.textContent = 'Error: ' + err.message;
        statusBar.style.color = '#b91c1c';
    }
}

async function runIncineratorBulk(userIds){
    const statusBar = document.getElementById('bulkStatus');
    statusBar.style.display = 'block';
    statusBar.style.color = '#0f172a';
    statusBar.textContent = 'Incinerating selected users...';
    try {
        const res = await fetch('<?=outbound_path('/api/admin-incinerator.php?action=incinerate_user')?>',{
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({user_ids:userIds, delete_accounts:true, delete_assigned_leads:true})
        });
        const json = await res.json();
        if(!json.ok){
            throw new Error(json.error || 'Bulk incineration failed');
        }
        statusBar.style.color = '#15803d';
        statusBar.textContent = `Incinerated ${json.count || userIds.length} user(s).`;
        refreshUserTable();
    } catch(err){
        statusBar.textContent = 'Error: ' + err.message;
        statusBar.style.color = '#b91c1c';
    }
}

async function incinerateUser(userId){
    showIncineratorWarning(async () => {
        const statusBar = document.getElementById('bulkStatus');
        if (statusBar) {
            statusBar.style.display = 'block';
            statusBar.style.color = '#0f172a';
            statusBar.textContent = 'Incinerating user #' + userId + '...';
        }
        try {
            const res = await fetch('<?=outbound_path('/api/admin-incinerator.php?action=incinerate_user')?>',{
                method:'POST',
                credentials:'same-origin',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({user_ids:[userId], delete_accounts:true, delete_assigned_leads:true})
            });
            const json = await res.json();
            if (!json.ok) {
                throw new Error(json.error || 'Incineration failed');
            }
            if (statusBar) {
                statusBar.style.color = '#15803d';
                statusBar.textContent = `Incinerated user #${userId}.`;
            }
            refreshUserTable();
        } catch (err) {
            alert(err.message || 'Failed to incinerate user.');
            if (statusBar) {
                statusBar.style.color = '#b91c1c';
                statusBar.textContent = 'Error: ' + err.message;
            }
        }
    });
}

async function refreshUserTable(){
    try{
        const params = new URLSearchParams(window.location.search);
        params.set('ajax','1');
        const res = await fetch(window.location.pathname + '?' + params.toString(),{credentials:'same-origin'});
        const html = await res.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html,'text/html');
        const newTable = doc.querySelector('.users-table');
        if(!newTable){
            throw new Error('Unable to refresh user list');
        }
        const tableWrapper = document.querySelector('.users-table');
        if(tableWrapper){
            tableWrapper.innerHTML = newTable.innerHTML;
        }
        bindBulkHandlers();
    }catch(e){
        console.error('Failed to refresh user list',e);
    }
}

function bindBulkHandlers(){
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function(){
        const checked = this.checked;
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = checked);
        });
    }
    const bulkSelect = document.getElementById('bulkActionSelect');
    if (bulkSelect) {
        bulkSelect.addEventListener('change', function(){
        const needsRole = this.value === 'role_add' || this.value === 'role_remove';
        const roleSel = document.getElementById('bulkRoleSelect');
        roleSel.style.display = needsRole ? 'inline-block' : 'none';
        if (!needsRole) {
            roleSel.value = '';
        }
        });
    }
}

bindBulkHandlers();

// Auto-generate password suggestion
document.querySelector('input[name="password"]')?.addEventListener('focus', function() {
    if (!this.value) {
        const chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        let password = '';
        for (let i = 0; i < 8; i++) {
            password += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        this.placeholder = `Suggestion: ${password}`;
    }
});

// Show/hide business name field based on business role selection
document.querySelectorAll('input[name="roles[]"]').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const businessField = document.querySelector('input[name="business_name"]').closest('div');
        const salesAgentSelected = document.querySelector('input[name="roles[]"][value="2"]').checked;
        const customerSelected = document.querySelector('input[name="roles[]"][value="4"]').checked;
        
        if (salesAgentSelected || customerSelected) {
            businessField.style.display = 'block';
            businessField.style.background = salesAgentSelected ? '#f0f9ff' : '#f0fdf4';
            businessField.style.padding = '16px';
            businessField.style.borderRadius = '8px';
            businessField.style.border = salesAgentSelected ? '2px solid #3b82f6' : '2px solid #059669';
        } else {
            businessField.style.display = 'block';
            businessField.style.background = 'transparent';
            businessField.style.padding = '0';
            businessField.style.border = 'none';
        }
    });
});

// Real-time name validation for spam prevention
function validateNameField(input, fieldLabel) {
    const name = input.value.trim();
    const errors = [];
    
    // Check length (max 30 characters)
    if (name.length > 30) {
        errors.push(fieldLabel + ' cannot exceed 30 characters (currently ' + name.length + ')');
    }
    
    // Check uppercase count (max 3)
    const uppercaseCount = (name.match(/[A-Z]/g) || []).length;
    if (uppercaseCount > 3) {
        errors.push(fieldLabel + ' cannot have more than 3 uppercase letters (found ' + uppercaseCount + ')');
    }
    
    // Check apostrophe count (max 2)
    const apostropheCount = (name.match(/[''\']/g) || []).length;
    if (apostropheCount > 2) {
        errors.push(fieldLabel + ' cannot have more than 2 apostrophes (found ' + apostropheCount + ')');
    }
    
    return {
        valid: errors.length === 0,
        errors: errors
    };
}

function updateNameFieldValidation(input, fieldLabel) {
    const validation = validateNameField(input, fieldLabel);
    const parent = input.parentElement;
    
    // Remove any existing error message
    const existingError = parent.querySelector('.name-validation-error');
    if (existingError) {
        existingError.remove();
    }
    
    if (!validation.valid) {
        input.style.borderColor = '#dc2626';
        input.style.background = '#fef2f2';
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'name-validation-error';
        errorDiv.style.cssText = 'color: #dc2626; font-size: 12px; margin-top: 4px;';
        errorDiv.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ' + validation.errors.join('. ');
        parent.appendChild(errorDiv);
    } else if (input.value.trim().length > 0) {
        input.style.borderColor = '#059669';
        input.style.background = '#f0fdf4';
    } else {
        input.style.borderColor = '#d1d5db';
        input.style.background = 'white';
    }
}

// Attach validation listeners to name fields in create form
const firstNameInput = document.querySelector('input[name="first_name"]');
const lastNameInput = document.querySelector('input[name="last_name"]');

if (firstNameInput) {
    firstNameInput.addEventListener('input', function() {
        updateNameFieldValidation(this, 'First name');
    });
}

if (lastNameInput) {
    lastNameInput.addEventListener('input', function() {
        updateNameFieldValidation(this, 'Last name');
    });
}

// Validate on form submission
document.querySelector('form button[type="submit"]')?.closest('form')?.addEventListener('submit', function(e) {
    const firstNameInput = this.querySelector('input[name="first_name"]');
    const lastNameInput = this.querySelector('input[name="last_name"]');
    
    if (firstNameInput && lastNameInput) {
        const firstNameValid = validateNameField(firstNameInput, 'First name').valid;
        const lastNameValid = validateNameField(lastNameInput, 'Last name').valid;
        
        if (!firstNameValid || !lastNameValid) {
            e.preventDefault();
            alert('Please fix the name field errors before submitting.');
            return false;
        }
    }
});
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
