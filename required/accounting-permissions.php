<?php
/**
 * Accounting Permissions System
 * Granular role-based access control for accounting operations
 * Includes audit logging of all permission checks
 */

if (!function_exists('can_view_accounting')) {
    /**
     * Check if user can view accounting data
     */
    function can_view_accounting(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant', 'operations'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_create_journal_entry')) {
    /**
     * Check if user can create journal entries
     */
    function can_create_journal_entry(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_post_journal_entry')) {
    /**
     * Check if user can post (finalize) journal entries
     */
    function can_post_journal_entry(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_void_journal_entry')) {
    /**
     * Check if user can void posted journal entries
     */
    function can_void_journal_entry(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_manage_expenses')) {
    /**
     * Check if user can create/edit expenses
     */
    function can_manage_expenses(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant', 'operations'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_approve_expenses')) {
    /**
     * Check if user can approve expenses
     */
    function can_approve_expenses(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_negate_expenses')) {
    /**
     * Check if user can negate (reverse) expenses
     */
    function can_negate_expenses(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_manage_allocations')) {
    /**
     * Check if user can manage expense allocations
     */
    function can_manage_allocations(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant', 'operations'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_view_agent_books')) {
    /**
     * Check if user can view agent financial books
     */
    function can_view_agent_books(int $userId, int $targetAgentId = null): bool {
        $roles = get_user_roles($userId);
        
        // Admins and accountants can view all agent books
        if (in_array('admin', $roles) || in_array('accountant', $roles)) {
            return true;
        }
        
        // Agents can view their own books
        if ($targetAgentId && $userId === $targetAgentId) {
            return in_array('sales_agent', $roles);
        }
        
        return false;
    }
}

if (!function_exists('can_generate_1099')) {
    /**
     * Check if user can generate and manage 1099 forms
     */
    function can_generate_1099(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_view_own_1099')) {
    /**
     * Check if user can view their own 1099 data
     */
    function can_view_own_1099(int $userId): bool {
        $roles = get_user_roles($userId);
        return in_array('sales_agent', $roles);
    }
}

if (!function_exists('can_manage_tax_config')) {
    /**
     * Check if user can manage tax configuration
     */
    function can_manage_tax_config(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_run_reconciliation')) {
    /**
     * Check if user can run reconciliation reports
     */
    function can_run_reconciliation(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_run_data_sync')) {
    /**
     * Check if user can trigger data sync operations
     */
    function can_run_data_sync(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_use_incinerator')) {
    /**
     * Check if user can use the test data incinerator
     */
    function can_use_incinerator(int $userId): bool {
        $roles = get_user_roles($userId);
        // Only admins can incinerate data
        return in_array('admin', $roles);
    }
}

if (!function_exists('can_manage_chart_of_accounts')) {
    /**
     * Check if user can modify chart of accounts
     */
    function can_manage_chart_of_accounts(int $userId): bool {
        $roles = get_user_roles($userId);
        $allowedRoles = ['admin', 'accountant'];
        return !empty(array_intersect($roles, $allowedRoles));
    }
}

if (!function_exists('can_close_fiscal_period')) {
    /**
     * Check if user can close fiscal periods
     */
    function can_close_fiscal_period(int $userId): bool {
        $roles = get_user_roles($userId);
        // Only admins can close periods (irreversible)
        return in_array('admin', $roles);
    }
}

if (!function_exists('log_accounting_permission_check')) {
    /**
     * Log permission check for audit trail
     */
    function log_accounting_permission_check(int $userId, string $permission, bool $granted, array $context = []): void {
        try {
            $pdo = db();
            
            $stmt = $pdo->prepare("
                INSERT INTO admin_history 
                (user_id, action_type, details, ip_address, user_agent)
                VALUES (?, 'permission_check', ?, ?, ?)
            ");
            
            $details = json_encode([
                'permission' => $permission,
                'granted' => $granted,
                'context' => $context,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $stmt->execute([
                $userId,
                $details,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            // Silent fail - don't break app flow for logging
            error_log("Permission check logging failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('require_accounting_permission')) {
    /**
     * Require permission or redirect/exit
     * @throws Exception if permission denied
     */
    function require_accounting_permission(int $userId, string $permission, callable $checkFunction): void {
        $granted = $checkFunction($userId);
        
        log_accounting_permission_check($userId, $permission, $granted, [
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
        ]);
        
        if (!$granted) {
            if (headers_sent()) {
                throw new Exception("Access denied: {$permission} permission required");
            }
            
            flash_set('error', 'Access denied. You do not have permission to perform this action.');
            
            // If JSON request, return JSON error
            if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'Permission denied',
                    'permission' => $permission
                ]);
                exit;
            }
            
            // Otherwise redirect
            redirect('/admin/accounting/');
        }
    }
}

if (!function_exists('get_accounting_settings')) {
    /**
     * Get all accounting settings as associative array
     */
    function get_accounting_settings(): array {
        static $settings = null;
        
        if ($settings !== null) {
            return $settings;
        }
        
        try {
            $pdo = db();
            $stmt = $pdo->query("SELECT setting_key, setting_value, setting_type FROM accounting_settings");
            
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $value = $row['setting_value'];
                
                // Type conversion
                switch ($row['setting_type']) {
                    case 'number':
                        $value = floatval($value);
                        break;
                    case 'boolean':
                        $value = (bool)intval($value);
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }
                
                $settings[$row['setting_key']] = $value;
            }
            
            return $settings;
        } catch (Exception $e) {
            error_log("Failed to load accounting settings: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('get_accounting_setting')) {
    /**
     * Get single accounting setting
     */
    function get_accounting_setting(string $key, $default = null) {
        $settings = get_accounting_settings();
        return $settings[$key] ?? $default;
    }
}

if (!function_exists('update_accounting_setting')) {
    /**
     * Update accounting setting
     */
    function update_accounting_setting(string $key, $value, int $userId): bool {
        try {
            $pdo = db();
            
            $stmt = $pdo->prepare("
                UPDATE accounting_settings 
                SET setting_value = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                WHERE setting_key = ?
            ");
            
            return $stmt->execute([
                is_array($value) || is_object($value) ? json_encode($value) : $value,
                $userId,
                $key
            ]);
        } catch (Exception $e) {
            error_log("Failed to update accounting setting: " . $e->getMessage());
            return false;
        }
    }
}












