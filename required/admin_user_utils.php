<?php

require_once __DIR__ . '/admin_incinerator.php';

// Shared helper functions for admin user management (bulk actions, hard deletes, etc.)
if (!function_exists('admin_clear_user_associations')) {
    function admin_clear_user_associations(PDO $pdo, int $userId): void {
        $cleanupStatements = [
            'DELETE FROM user_roles WHERE user_id = ?',
            'DELETE FROM auth_tokens WHERE user_id = ?',
            'DELETE FROM user_sessions WHERE user_id = ?',
            'DELETE FROM google_oauth_tokens WHERE user_id = ?',
            'DELETE FROM wholesale_accounts WHERE user_id = ?',
            'DELETE FROM auth_events WHERE user_id = ?',
            'DELETE FROM site_active_sessions WHERE user_id = ?',
        ];
        foreach ($cleanupStatements as $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId]);
            } catch (Throwable $e) {
                // Ignore missing tables/columns; continue cleanup
            }
        }
    }

    function admin_soft_delete_user(PDO $pdo, int $userId, ?string $reason = null): array {
        $emailPlaceholder = 'deleted+' . $userId . '@productproduct.invalid';
        $warning = $reason ?? 'User retained for historical records; email released.';
        try {
            $pdo->beginTransaction();
            admin_clear_user_associations($pdo, $userId);
            $stmt = $pdo->prepare('UPDATE users SET status = "deleted", email = ?, password_hash = NULL, first_name = "Deleted", last_name = "User", phone = NULL WHERE id = ?');
            $stmt->execute([$emailPlaceholder, $userId]);
            $pdo->commit();
            return ['ok' => true, 'mode' => 'archived', 'warning' => $warning];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    function admin_delete_user(PDO $pdo, int $userId): array {
        $result = ['ok' => true, 'mode' => 'deleted', 'warning' => null];
        try {
            $pdo->beginTransaction();
            admin_clear_user_associations($pdo, $userId);
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $pdo->commit();
            if ($stmt->rowCount() === 0) {
                $result['mode'] = 'missing';
            }
            return $result;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                return admin_soft_delete_user($pdo, $userId, 'User has linked records and was anonymized.');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    function admin_incinerate_user(PDO $pdo, int $userId, array $options = []): array
    {
        try {
            $res = incinerator_delete_user($pdo, $userId, $options);
            return ['ok' => true, 'mode' => 'incinerated', 'warning' => $res['warning'] ?? null];
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to incinerate user #' . $userId . ': ' . $e->getMessage(), 0, $e);
        }
    }

    function admin_set_user_status(PDO $pdo, int $userId, string $status): bool {
        $allowed = ['active', 'suspended', 'pending', 'deleted'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid status value.');
        }
        $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$status, $userId]);
        return $stmt->rowCount() > 0;
    }

    function admin_assign_role(PDO $pdo, int $userId, int $roleId): bool {
        $check = $pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?');
        $check->execute([$userId, $roleId]);
        if ($check->fetch()) {
            return false;
        }
        $insert = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $insert->execute([$userId, $roleId]);
        return true;
    }

    function admin_remove_role(PDO $pdo, int $userId, int $roleId): bool {
        $stmt = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?');
        $stmt->execute([$userId, $roleId]);
        return $stmt->rowCount() > 0;
    }

    function admin_process_bulk_action(PDO $pdo, string $bulkAction, array $ids, int $roleId = 0): array {
        if (!$ids) {
            throw new InvalidArgumentException('No user IDs provided.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) {
            throw new InvalidArgumentException('No valid user IDs supplied.');
        }

        $summary = [
            'total' => count($ids),
            'updated' => 0,
            'deleted' => 0,
            'archived' => 0,
            'activated' => 0,
            'suspended' => 0,
            'pending' => 0,
            'role_added' => 0,
            'role_removed' => 0,
            'incinerated' => 0,
            'warnings' => [],
            'message' => '',
        ];
        $details = [];

        foreach ($ids as $userId) {
            switch ($bulkAction) {
                case 'activate':
                    if (admin_set_user_status($pdo, $userId, 'active')) {
                        $summary['activated']++;
                        $summary['updated']++;
                    }
                    $details[] = ['user_id' => $userId, 'action' => 'activate', 'status' => 'active'];
                    break;
                case 'suspend':
                    if (admin_set_user_status($pdo, $userId, 'suspended')) {
                        $summary['suspended']++;
                        $summary['updated']++;
                    }
                    $details[] = ['user_id' => $userId, 'action' => 'suspend', 'status' => 'suspended'];
                    break;
                case 'pending':
                    if (admin_set_user_status($pdo, $userId, 'pending')) {
                        $summary['pending']++;
                        $summary['updated']++;
                    }
                    $details[] = ['user_id' => $userId, 'action' => 'pending', 'status' => 'pending'];
                    break;
                case 'delete':
                    $res = admin_delete_user($pdo, $userId);
                    $summary['updated']++;
                    if ($res['mode'] === 'deleted' || $res['mode'] === 'missing') {
                        $summary['deleted']++;
                    } elseif ($res['mode'] === 'archived') {
                        $summary['archived']++;
                        if (!empty($res['warning'])) {
                            $summary['warnings'][] = $res['warning'] . ' (User #' . $userId . ')';
                        }
                    }
                    $details[] = ['user_id' => $userId, 'action' => 'delete', 'mode' => $res['mode'], 'warning' => $res['warning']];
                    break;
                case 'incinerate':
                    $res = admin_incinerate_user($pdo, $userId, ['delete_accounts' => true, 'delete_assigned_leads' => true]);
                    $summary['updated']++;
                    $summary['incinerated']++;
                    if (!empty($res['warning'])) {
                        $summary['warnings'][] = $res['warning'] . ' (User #' . $userId . ')';
                    }
                    $details[] = ['user_id' => $userId, 'action' => 'incinerate', 'mode' => $res['mode']];
                    break;
                case 'role_add':
                    if ($roleId <= 0) {
                        throw new InvalidArgumentException('Role is required for role assignments.');
                    }
                    if (admin_assign_role($pdo, $userId, $roleId)) {
                        $summary['role_added']++;
                        $summary['updated']++;
                        $details[] = ['user_id' => $userId, 'action' => 'role_add', 'role_id' => $roleId, 'changed' => true];
                    } else {
                        $details[] = ['user_id' => $userId, 'action' => 'role_add', 'role_id' => $roleId, 'changed' => false];
                    }
                    break;
                case 'role_remove':
                    if ($roleId <= 0) {
                        throw new InvalidArgumentException('Role is required for role removal.');
                    }
                    $changed = admin_remove_role($pdo, $userId, $roleId);
                    if ($changed) {
                        $summary['role_removed']++;
                        $summary['updated']++;
                    }
                    $details[] = ['user_id' => $userId, 'action' => 'role_remove', 'role_id' => $roleId, 'changed' => $changed];
                    break;
                default:
                    throw new InvalidArgumentException('Unsupported bulk action: ' . $bulkAction);
            }
        }

        $parts = [];
        if ($summary['deleted']) $parts[] = $summary['deleted'] . ' deleted';
        if ($summary['archived']) $parts[] = $summary['archived'] . ' archived';
        if ($summary['activated']) $parts[] = $summary['activated'] . ' activated';
        if ($summary['pending']) $parts[] = $summary['pending'] . ' marked pending';
        if ($summary['suspended']) $parts[] = $summary['suspended'] . ' suspended';
        if ($summary['role_added']) $parts[] = $summary['role_added'] . ' role added';
        if ($summary['role_removed']) $parts[] = $summary['role_removed'] . ' role removed';
        if ($summary['incinerated']) $parts[] = $summary['incinerated'] . ' incinerated';

        if ($parts) {
            $summary['message'] = 'Bulk update completed: ' . implode(', ', $parts) . '.';
        } else {
            $summary['message'] = 'No changes applied.';
        }

        return ['summary' => $summary, 'details' => $details];
    }
}


