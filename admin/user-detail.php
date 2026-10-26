<?php
$SEO = [
    'title' => 'User Detail Editor — Admin',
    'description' => 'Comprehensive user management and editing interface.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/spam_prevention_utils.php';
require_admin_or_redirect('/admin/');

$pdo = db();
$userId = intval($_GET['id'] ?? 0);

if (!$userId) {
    flash_set('error', 'User ID required.');
    redirect('/admin/users.php');
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_profile') {
            $firstName = trim($_POST['first_name']);
            $lastName = trim($_POST['last_name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $status = $_POST['status'];
            
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
            
            // Check email uniqueness (excluding current user)
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                throw new Exception('Email address already exists.');
            }
            
            $stmt = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, status = ? WHERE id = ?');
            $stmt->execute([$firstName, $lastName, $email, $phone, $status, $userId]);
            
            flash_set('success', 'Profile updated successfully.');
        }
        
        elseif ($action === 'update_password') {
            $newPassword = $_POST['new_password'];
            if (strlen($newPassword) < 6) {
                throw new Exception('Password must be at least 6 characters.');
            }
            
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$passwordHash, $userId]);
            
            flash_set('success', 'Password updated successfully.');
        }
        
        elseif ($action === 'update_roles') {
            $roles = $_POST['roles'] ?? [];
            
            // Remove all current roles
            $stmt = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?');
            $stmt->execute([$userId]);
            
            // Add new roles
            foreach ($roles as $roleId) {
                $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                $stmt->execute([$userId, intval($roleId)]);
            }
            
            flash_set('success', 'User roles updated successfully.');
        }
        
        elseif ($action === 'update_wholesale') {
            $businessName = trim($_POST['business_name']);
            $approved = isset($_POST['approved']) ? 1 : 0;
            $creditLimit = floatval($_POST['credit_limit']);
            $netTerms = intval($_POST['net_terms']);
            
            // Check if wholesale account exists
            $stmt = $pdo->prepare('SELECT id FROM wholesale_accounts WHERE user_id = ?');
            $stmt->execute([$userId]);
            $existingAccount = $stmt->fetch();
            
            if ($existingAccount && !empty($businessName)) {
                // Update existing
                $stmt = $pdo->prepare('UPDATE wholesale_accounts SET business_name = ?, approved = ?, credit_limit = ?, net_terms = ? WHERE user_id = ?');
                $stmt->execute([$businessName, $approved, $creditLimit, $netTerms, $userId]);
            } elseif (!$existingAccount && !empty($businessName)) {
                // Create new
                $stmt = $pdo->prepare('INSERT INTO wholesale_accounts (user_id, business_name, approved, credit_limit, net_terms) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$userId, $businessName, $approved, $creditLimit, $netTerms]);
            } elseif ($existingAccount && empty($businessName)) {
                // Delete if business name cleared
                $stmt = $pdo->prepare('DELETE FROM wholesale_accounts WHERE user_id = ?');
                $stmt->execute([$userId]);
            }
            
            flash_set('success', 'Wholesale account updated successfully.');
        }
        
    } catch (Exception $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
    }
    
    redirect($_SERVER['REQUEST_URI']);
}

// Get user data
try {
    $stmt = $pdo->prepare('
        SELECT u.*, 
               GROUP_CONCAT(r.name) as roles,
               GROUP_CONCAT(r.id) as role_ids,
               wa.business_name,
               wa.approved as wholesale_approved,
               wa.credit_limit,
               wa.net_terms,
               wa.created_at as wholesale_created_at
        FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        LEFT JOIN roles r ON r.id = ur.role_id
        LEFT JOIN wholesale_accounts wa ON wa.user_id = u.id
        WHERE u.id = ?
        GROUP BY u.id
    ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        flash_set('error', 'User not found.');
        redirect('/admin/users.php');
    }
    
    // Get training progress
    $stmt = $pdo->prepare('
        SELECT tm.title, tp.status, tp.quiz_score, tp.completed_at
        FROM training_modules tm
        LEFT JOIN training_progress tp ON tp.module_id = tm.id AND tp.user_id = ?
        ORDER BY tm.sort_order
    ');
    $stmt->execute([$userId]);
    $trainingProgress = $stmt->fetchAll();
    
    // Get recent orders
    $stmt = $pdo->prepare('
        SELECT o.*, wa.business_name
        FROM orders o
        JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
        WHERE o.user_id = ? OR wa.user_id = ?
        ORDER BY o.created_at DESC
        LIMIT 10
    ');
    $stmt->execute([$userId, $userId]);
    $recentOrders = $stmt->fetchAll();
    
    // Get available roles
    $availableRoles = $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
    
} catch (Exception $e) {
    flash_set('error', 'Error loading user data.');
    redirect('/admin/users.php');
}
?>

<style>
.user-detail-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.user-header {
    background: linear-gradient(135deg, #1e293b, #334155);
    color: white;
    padding: 40px 0;
    margin-bottom: 32px;
}

.user-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

.detail-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    overflow: hidden;
}

.card-header {
    background: #f9fafb;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.card-body {
    padding: 24px;
}

.form-section {
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 1px solid #f3f4f6;
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #374151;
}

.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
}

.role-checkbox-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
}

.role-option {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
}

.role-option:hover {
    background: #f3f4f6;
}

.role-option.selected {
    background: #dbeafe;
    border: 2px solid #3b82f6;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.stat-item {
    text-align: center;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
}

.stat-value {
    font-size: 20px;
    font-weight: bold;
    color: #059669;
}

.stat-label {
    font-size: 11px;
    color: #6b7280;
    text-transform: uppercase;
}

.admin-actions {
    background: #fef3c7;
    border: 2px solid #f59e0b;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
}

.simulation-controls {
    background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
    border: 2px solid #3b82f6;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
}

.danger-zone {
    background: #fef2f2;
    border: 2px solid #dc2626;
    border-radius: 12px;
    padding: 20px;
    margin-top: 24px;
}
</style>

<div class="user-header">
    <div class="container">
        <h1 style="font-size: 36px; margin-bottom: 8px;">
            <i class="fas fa-user-pen"></i> User Detail Editor
        </h1>
        <p style="font-size: 18px; opacity: 0.9;">
            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> 
            (<?php echo htmlspecialchars($user['email']); ?>)
        </p>
    </div>
</div>

<div class="user-detail-container">
    <?php include __DIR__ . '/admin-menu.php'; ?>
    
    <!-- Admin Simulation Controls -->
    <div class="simulation-controls">
        <h3 style="color: #1e40af; margin: 0 0 16px 0;"><i class="fas fa-screwdriver-wrench"></i> Admin Debugging Tools</h3>
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <button onclick="simulateRole('admin')" class="btn" style="background: #dc2626;" title="View as Admin"><i class="fas fa-shield-halved"></i> View as Admin</button>
            <button onclick="simulateRole('sales_agent')" class="btn" style="background: #3b82f6;" title="View as Sales Agent"><i class="fas fa-user-tie"></i> View as Sales Agent</button>
            <button onclick="simulateRole('trainee')" class="btn" style="background: #f59e0b;" title="View as Trainee"><i class="fas fa-graduation-cap"></i> View as Trainee</button>
            <button onclick="simulateRole('customer')" class="btn" style="background: #059669;" title="View as Customer"><i class="fas fa-shopping-cart"></i> View as Customer</button>
            <button onclick="loginAsUser(<?php echo $userId; ?>)" class="btn" style="background: #7c3aed;" title="Login as This User"><i class="fas fa-right-to-bracket"></i> Login as This User</button>
            <button onclick="clearSimulation()" class="btn secondary" title="Return to Admin"><i class="fas fa-arrow-rotate-left"></i> Return to Admin</button>
        </div>
        <div style="margin-top: 12px; font-size: 12px; color: #6b7280;">
            <i class="fas fa-lightbulb"></i> <strong>Debug Mode:</strong> Simulate different user roles to test functionality without affecting the actual user account.
        </div>
    </div>
    
    <div class="user-grid">
        <!-- Main User Details -->
        <div class="detail-card">
            <div class="card-header">
                <h2 style="margin: 0; color: #1f2937;"><i class="fas fa-user"></i> User Information</h2>
            </div>
            <div class="card-body">
                <!-- Profile Information -->
                <div class="form-section">
                    <h3 style="color: #059669; margin-bottom: 16px;"><i class="fas fa-address-card"></i> Profile Information</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>First Name</label>
                                <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Last Name</label>
                                <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Account Status</label>
                            <select name="status">
                                <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="pending" <?php echo $user['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="suspended" <?php echo $user['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn">Update Profile</button>
                    </form>
                </div>
                
                <!-- Role Management -->
                <div class="form-section">
                    <h3 style="color: #059669; margin-bottom: 16px;"><i class="fas fa-masks-theater"></i> Role Management</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="update_roles">
                        
                        <div class="role-checkbox-grid">
                            <?php 
                            $userRoleIds = explode(',', $user['role_ids'] ?? '');
                            foreach ($availableRoles as $role): 
                            ?>
                                <label class="role-option <?php echo in_array($role['id'], $userRoleIds) ? 'selected' : ''; ?>">
                                    <input type="checkbox" name="roles[]" value="<?php echo $role['id']; ?>" 
                                           <?php echo in_array($role['id'], $userRoleIds) ? 'checked' : ''; ?>
                                           onchange="toggleRoleSelection(this)">
                                    <span style="font-weight: 600;">
                                        <?php echo ucfirst(str_replace('_', ' ', $role['name'])); ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="margin-top: 16px; padding: 12px; background: #f0f9ff; border-radius: 8px;">
                            <p style="margin: 0; font-size: 14px; color: #1e40af;">
                                <strong>Current Roles:</strong> <?php echo htmlspecialchars($user['roles'] ?? 'None'); ?>
                            </p>
                        </div>
                        
                        <button type="submit" class="btn" style="margin-top: 16px;">Update Roles</button>
                    </form>
                </div>
                
                <!-- Password Reset -->
                <div class="form-section">
                    <h3 style="color: #dc2626; margin-bottom: 16px;"><i class="fas fa-key"></i> Password Management</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="update_password">
                        
                        <div class="form-group">
                            <label>New Password (minimum 6 characters)</label>
                            <input type="password" name="new_password" required minlength="6" placeholder="Enter new password">
                        </div>
                        
                        <button type="submit" class="btn" style="background: #dc2626;">Reset Password</button>
                        <button type="button" onclick="generatePassword()" class="btn secondary">Generate Random</button>
                    </form>
                </div>
                
                <!-- Wholesale Account -->
                <?php if (strpos($user['roles'] ?? '', 'sales_agent') !== false || strpos($user['roles'] ?? '', 'customer') !== false): ?>
                <div class="form-section">
                    <h3 style="color: #059669; margin-bottom: 16px;"><i class="fas fa-building"></i> Wholesale Account</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="update_wholesale">
                        
                        <div class="form-group">
                            <label>Business Name</label>
                            <input type="text" name="business_name" value="<?php echo htmlspecialchars($user['business_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Credit Limit ($)</label>
                                <input type="number" name="credit_limit" value="<?php echo $user['credit_limit'] ?? 5000; ?>" step="0.01">
                            </div>
                            <div class="form-group">
                                <label>Net Terms (days)</label>
                                <input type="number" name="net_terms" value="<?php echo $user['net_terms'] ?? 30; ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="approved" <?php echo $user['wholesale_approved'] ? 'checked' : ''; ?>>
                                <span>Approved for Wholesale Purchasing</span>
                            </label>
                        </div>
                        
                        <button type="submit" class="btn">Update Wholesale Account</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Sidebar -->
        <div>
            <!-- User Statistics -->
            <div class="detail-card" style="margin-bottom: 20px;">
                <div class="card-header">
                    <h3 style="margin: 0; color: #1f2937;"><i class="fas fa-chart-line"></i> User Statistics</h3>
                </div>
                <div class="card-body">
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo count($recentOrders); ?></div>
                            <div class="stat-label">Orders</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo count(array_filter($trainingProgress, fn($p) => $p['status'] === 'completed')); ?></div>
                            <div class="stat-label">Modules</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo date('M d', strtotime($user['created_at'])); ?></div>
                            <div class="stat-label">Joined</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" style="color: <?php echo $user['status'] === 'active' ? '#059669' : '#dc2626'; ?>">
                                <?php echo ucfirst($user['status']); ?>
                            </div>
                            <div class="stat-label">Status</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="detail-card" style="margin-bottom: 20px;">
                <div class="card-header">
                    <h3 style="margin: 0; color: #1f2937;"><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="card-body">
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <a href="<?php echo path_with_base('/admin/trainee-detail.php'); ?>?id=<?php echo $userId; ?>" class="btn secondary" title="View Training Details">
                            <i class="fas fa-book-open"></i> View Training Details
                        </a>
                        <?php if ($user['business_name']): ?>
                            <a href="<?php echo path_with_base('/admin/order-details.php'); ?>?user_id=<?php echo $userId; ?>" class="btn secondary" title="View Orders">
                                <i class="fas fa-box"></i> View Orders
                            </a>
                        <?php endif; ?>
                        <button onclick="sendMessage()" class="btn secondary" title="Send Message">
                            <i class="fas fa-message"></i> Send Message
                        </button>
                        <button onclick="viewAuditLog()" class="btn secondary" title="View Audit Log">
                            <i class="fas fa-clipboard-list"></i> Audit Log
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Training Progress -->
            <?php if (!empty($trainingProgress)): ?>
            <div class="detail-card">
                <div class="card-header">
                    <h3 style="margin: 0; color: #1f2937;"><i class="fas fa-graduation-cap"></i> Training Progress</h3>
                </div>
                <div class="card-body">
                    <?php foreach ($trainingProgress as $progress): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                            <span style="font-size: 14px;"><?php echo htmlspecialchars($progress['title']); ?></span>
                            <span style="font-size: 12px; padding: 2px 8px; border-radius: 12px; <?php
                                echo $progress['status'] === 'completed' ? 'background: #dcfce7; color: #14532d;' :
                                    ($progress['status'] === 'in_progress' ? 'background: #fef3c7; color: #92400e;' :
                                    'background: #f3f4f6; color: #6b7280;');
                            ?>">
                                <?php echo $progress['status'] ?? 'not_started'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Danger Zone -->
    <div class="danger-zone">
        <h3 style="color: #dc2626; margin: 0 0 16px 0;"><i class="fas fa-triangle-exclamation"></i> Danger Zone</h3>
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <button onclick="resetTraining()" class="btn" style="background: #f59e0b;" title="Reset Training Progress"><i class="fas fa-arrow-rotate-right"></i> Reset Training Progress</button>
            <button onclick="deleteUser()" class="btn" style="background: #dc2626;" title="Delete User Account"><i class="fas fa-trash-can"></i> Delete User Account</button>
            <button onclick="suspendUser()" class="btn" style="background: #6b7280;" title="Suspend Account"><i class="fas fa-ban"></i> Suspend Account</button>
        </div>
        <p style="margin: 12px 0 0 0; font-size: 12px; color: #6b7280;">
            <i class="fas fa-circle-exclamation"></i> These actions are irreversible. Use with caution.
        </p>
    </div>
</div>

<script>
function toggleRoleSelection(checkbox) {
    const roleOption = checkbox.closest('.role-option');
    if (checkbox.checked) {
        roleOption.classList.add('selected');
    } else {
        roleOption.classList.remove('selected');
    }
}

function generatePassword() {
    const chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    let password = '';
    for (let i = 0; i < 12; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.querySelector('input[name="new_password"]').value = password;
    alert(`Generated password: ${password}\n\nPlease save this password securely.`);
}

function simulateRole(role) {
    // Set session variable for role simulation
    fetch('/admin/simulate-role.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `role=${role}&user_id=<?php echo $userId; ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show simulation indicator
            showSimulationIndicator(role);
            alert(`Now simulating ${role} role. Navigate to any page to test functionality.`);
        }
    });
}

function loginAsUser(userId) {
    if (confirm('Login as this user? You will be logged out of admin and logged in as this user.')) {
        fetch('/admin/login-as-user.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `user_id=${userId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = data.redirect_url;
            } else {
                alert('Error: ' + data.error);
            }
        });
    }
}

function clearSimulation() {
    fetch('/admin/clear-simulation.php', {method: 'POST'})
    .then(() => {
        hideSimulationIndicator();
        alert('Simulation cleared. You are now viewing as admin.');
    });
}

function showSimulationIndicator(role) {
    // Remove existing indicator
    const existing = document.querySelector('.simulation-indicator');
    if (existing) existing.remove();
    
    // Add new indicator
    const indicator = document.createElement('div');
    indicator.className = 'simulation-indicator';
    indicator.innerHTML = `🎭 Simulating: ${role.toUpperCase()}`;
    indicator.style.cssText = `
        position: fixed; top: 0; left: 0; right: 0; 
        background: #dc2626; color: white; 
        padding: 8px; text-align: center; 
        font-weight: bold; z-index: 9999;
    `;
    document.body.appendChild(indicator);
}

function hideSimulationIndicator() {
    const indicator = document.querySelector('.simulation-indicator');
    if (indicator) indicator.remove();
}

function sendMessage() {
    const message = prompt('Enter message to send to this user:');
    if (message) {
        fetch('/admin/send-user-message.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `user_id=<?php echo $userId; ?>&message=${encodeURIComponent(message)}`
        })
        .then(response => response.json())
        .then(data => {
            alert(data.success ? 'Message sent successfully!' : 'Error: ' + data.error);
        });
    }
}

function resetTraining() {
    if (confirm('Reset all training progress for this user? This cannot be undone.')) {
        fetch('/admin/reset-user-training.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `user_id=<?php echo $userId; ?>`
        })
        .then(response => response.json())
        .then(data => {
            alert(data.success ? 'Training progress reset!' : 'Error: ' + data.error);
            if (data.success) location.reload();
        });
    }
}

function deleteUser() {
    const userName = '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>';
    if (confirm(`DELETE USER: ${userName}?\n\nThis will mark the account as deleted. This action cannot be undone.`)) {
        if (confirm('Are you absolutely sure? This is permanent.')) {
            window.location.href = '/admin/users.php?action=delete&user_id=<?php echo $userId; ?>';
        }
    }
}

function suspendUser() {
    if (confirm('Suspend this user account? They will lose access until reactivated.')) {
        window.location.href = '/admin/users.php?action=suspend&user_id=<?php echo $userId; ?>';
    }
}

function viewAuditLog() {
    window.open('/admin/audit-log.php?user_id=<?php echo $userId; ?>', '_blank');
}

// Initialize role selections
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.role-option input[type="checkbox"]').forEach(checkbox => {
        toggleRoleSelection(checkbox);
    });
    
    // Attach validation to profile update form name fields
    attachNameValidation();
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

function attachNameValidation() {
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

    // Validate on form submission for profile update
    const profileForm = document.querySelector('form input[name="action"][value="update_profile"]')?.closest('form');
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
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
    }
}
</script>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
