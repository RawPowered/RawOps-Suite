<?php
/**
 * Unified Role Simulation Handler
 * Handles role switching for admin users with proper redirects
 */

require_once __DIR__ . '/../required/config.php';

// Ensure this is only accessible to admins
start_session();
$currentUser = current_user_id();
$isAdmin = $currentUser && current_admin_id();

if (!$isAdmin) {
    // More informative error for debugging
    if (!$currentUser) {
        // Not logged in at all
        redirect('/login.php');
    } else {
        // Logged in but not admin
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>Access Denied</title></head><body>';
        echo '<h1>403 - Access Denied</h1>';
        echo '<p>Admin access required. Current user ID: ' . $currentUser . '</p>';
        echo '<p>Admin roles: ' . (user_has_role($currentUser, 'admin') ? 'Yes' : 'No') . '</p>';
        echo '<p><a href="/admin/">Back to Admin</a> | <a href="/logout.php">Logout</a></p>';
        echo '</body></html>';
        exit;
    }
}

start_session();

// Handle role simulation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['simulate_role','simulate_user','end_simulation'], true)) {
    $pdo = db();
    $action = $_POST['action'];
    
    try {
        if ($action === 'end_simulation') {
            // Log the simulation end
            try {
                $adminId = current_admin_id();
                $stmt = $pdo->prepare('UPDATE role_simulation_log SET ended_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()), actions_taken = ? WHERE admin_user_id = ? AND ended_at IS NULL');
                $stmt->execute(['Manual end by admin', $adminId]);
            } catch (Exception $e) {
                error_log("Role simulation end logging failed: " . $e->getMessage());
            }
            
            // Clear all simulation data
            unset($_SESSION['uid'], $_SESSION['admin_simulation']);
            flash_set('success', 'Role simulation ended. Returned to admin mode.');
            
            // Redirect to admin dashboard
            header('Location: /admin/');
            exit;
            
        } elseif ($action === 'simulate_role') {
            $role = trim($_POST['role'] ?? '');
            $userId = null;
            $redirectPath = '/admin/';
            
            // Find or create a user with the specified role
            if ($role === 'admin') {
                $stmt = $pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='admin' ORDER BY u.id LIMIT 1");
                $userId = (int)($stmt->fetchColumn() ?: 0);
                $redirectPath = '/admin/';
            } elseif ($role === 'sales_agent') {
                $stmt = $pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='sales_agent' ORDER BY u.id LIMIT 1");
                $userId = (int)($stmt->fetchColumn() ?: 0);
                $redirectPath = '/onboarding/sales-agent/index.php';
            } elseif ($role === 'trainee') {
                $stmt = $pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='trainee' ORDER BY u.id LIMIT 1");
                $userId = (int)($stmt->fetchColumn() ?: 0);
                $redirectPath = '/onboarding/trainee/index.php';
            } elseif ($role === 'operations') {
                $stmt = $pdo->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='operations' ORDER BY u.id LIMIT 1");
                $userId = (int)($stmt->fetchColumn() ?: 0);
                $redirectPath = '/operations/';
            } elseif ($role === 'customer') {
                $stmt = $pdo->query("SELECT u.id FROM users u JOIN wholesale_accounts wa ON wa.user_id=u.id AND wa.approved=1 ORDER BY u.id LIMIT 1");
                $userId = (int)($stmt->fetchColumn() ?: 0);
                $redirectPath = '/customer/';
            } elseif ($role === 'guest') {
                // Set up guest simulation
                $_SESSION['admin_simulation'] = [
                    'original_admin_id' => current_admin_id(),
                    'simulated_role' => 'guest',
                    'target_user_id' => null,
                    'started_at' => time()
                ];
                unset($_SESSION['uid']);
                flash_set('success', 'Now simulating Guest role.');
                header('Location: /');
                exit;
            }
            
            // If we found a user to simulate
            if ($userId && $role !== 'guest') {
                $adminId = current_admin_id();
                
                // Set up role simulation in session
                $_SESSION['admin_simulation'] = [
                    'original_admin_id' => $adminId,
                    'simulated_role' => $role,
                    'target_user_id' => $userId,
                    'started_at' => time()
                ];
                
                // Set the user ID for simulation
                $_SESSION['uid'] = $userId;
                
                // Log the role simulation start
                try {
                    // End any existing simulation for this admin
                    $stmt = $pdo->prepare('UPDATE role_simulation_log SET ended_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE admin_user_id = ? AND ended_at IS NULL');
                    $stmt->execute([$adminId]);
                    
                    // Start new simulation
                    $stmt = $pdo->prepare('INSERT INTO role_simulation_log (admin_user_id, simulated_role, target_user_id) VALUES (?, ?, ?)');
                    $stmt->execute([$adminId, $role, $userId]);
                } catch (Exception $e) {
                    // Continue even if logging fails
                    error_log("Role simulation logging failed: " . $e->getMessage());
                }
                
                flash_set('success', 'Now simulating ' . ucfirst(str_replace('_',' ',$role)) . ' role.');
                
                // Redirect to the appropriate area for this role
                header('Location: ' . $redirectPath);
                exit;
            } elseif ($role !== 'guest') {
                flash_set('error', 'No user found with ' . $role . ' role.');
                header('Location: /admin/');
                exit;
            }
            
        } elseif ($action === 'simulate_user') {
            $email = trim($_POST['email'] ?? '');
            if ($email !== '') {
                $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $adminId = current_admin_id();
                    
                    // Set up user simulation in session
                    $_SESSION['admin_simulation'] = [
                        'original_admin_id' => $adminId,
                        'simulated_role' => 'specific_user',
                        'target_user_id' => (int)$user['id'],
                        'started_at' => time()
                    ];
                    
                    $_SESSION['uid'] = (int)$user['id'];
                    
                    // Log the user simulation
                    try {
                        $stmt = $pdo->prepare('UPDATE role_simulation_log SET ended_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE admin_user_id = ? AND ended_at IS NULL');
                        $stmt->execute([$adminId]);
                        
                        $stmt = $pdo->prepare('INSERT INTO role_simulation_log (admin_user_id, simulated_role, target_user_id) VALUES (?, ?, ?)');
                        $stmt->execute([$adminId, 'specific_user', (int)$user['id']]);
                    } catch (Exception $e) {
                        error_log("User simulation logging failed: " . $e->getMessage());
                    }
                    
                    flash_set('success', 'Now simulating ' . htmlspecialchars($email)); 
                    
                    // Redirect to appropriate dashboard for this user
                    $userDashboard = user_dashboard_path((int)$user['id']);
                    header('Location: ' . $userDashboard);
                    exit;
                } else { 
                    flash_set('error', 'User not found'); 
                    header('Location: /admin/');
                    exit;
                }
            }
        }
        
    } catch (Throwable $e) {
        flash_set('error', 'Simulation error: ' . $e->getMessage());
    }
    
    // Fallback redirect
    header('Location: /admin/');
    exit;
}

// If accessed directly without POST, redirect to admin
header('Location: /admin/');
exit;
?>
