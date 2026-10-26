<?php

// Admin Incinerator utility — centralized hard-delete helpers for users, leads, and wholesale accounts.
// Provides safe cascading removal of related entities without leaving dangling references.

if (!function_exists('incinerator_quote_identifier')) {
    function incinerator_quote_identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Invalid identifier: ' . $identifier);
        }
        return '`' . $identifier . '`';
    }

    /**
     * Clean up foreign-key references pointing at a target row before hard deletion.
     * - CASCADE rules are respected automatically; no manual action is required.
     * - SET NULL rules are applied explicitly to guarantee the column is cleared before delete.
     * - RESTRICT / NO ACTION rules result in the referencing rows being deleted entirely.
     *
     * @return array{tables_updated:int,tables_deleted:int}
     */
    function incinerator_cleanup_references(PDO $pdo, string $table, string $column, int $id, ?array $limitTables = null, array $options = []): array
    {
        $options += [
            'force_delete_tables' => [],
            'snapshot_columns' => [],
        ];
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!$schema) {
            throw new RuntimeException('Unable to determine current database schema.');
        }

        $tableQuoted = incinerator_quote_identifier($table);
        $columnQuoted = incinerator_quote_identifier($column);

        $sql = <<<'SQL'
SELECT
    kcu.TABLE_NAME,
    kcu.COLUMN_NAME,
    rc.DELETE_RULE,
    c.IS_NULLABLE
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
  ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
 AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = kcu.TABLE_SCHEMA
 AND c.TABLE_NAME = kcu.TABLE_NAME
 AND c.COLUMN_NAME = kcu.COLUMN_NAME
WHERE kcu.REFERENCED_TABLE_SCHEMA = :schema
  AND kcu.REFERENCED_TABLE_NAME = :table
  AND kcu.REFERENCED_COLUMN_NAME = :column
ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME
SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'schema' => $schema,
            'table' => $table,
            'column' => $column,
        ]);

        $updates = 0;
        $deletes = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $refTable = $row['TABLE_NAME'];
            if ($limitTables && !in_array($refTable, $limitTables, true)) {
                continue;
            }

            $refColumn = $row['COLUMN_NAME'];
            $deleteRule = strtoupper((string)$row['DELETE_RULE']);

            // CASCADE is handled by MySQL automatically when the parent row is deleted.
            if ($deleteRule === 'CASCADE') {
                continue;
            }

            $refTableQuoted = incinerator_quote_identifier($refTable);
            $refColumnQuoted = incinerator_quote_identifier($refColumn);

            $forceDelete = in_array($refTable, $options['force_delete_tables'], true);

            if ($deleteRule === 'SET NULL' && !$forceDelete) {
                $sqlUpdate = "UPDATE {$refTableQuoted} SET {$refColumnQuoted} = NULL WHERE {$refColumnQuoted} = ?";
                $pdo->prepare($sqlUpdate)->execute([$id]);
                $updates++;
                continue;
            }

            // Default to deleting referencing rows for RESTRICT / NO ACTION
            if (!empty($options['snapshot_columns'][$refTable])) {
                incinerator_snapshot_table($pdo, $refTable, $refColumn, $id, $options['snapshot_columns'][$refTable]);
            }

            $sqlDelete = "DELETE FROM {$refTableQuoted} WHERE {$refColumnQuoted} = ?";
            $pdo->prepare($sqlDelete)->execute([$id]);
            $deletes++;
        }

        return ['tables_updated' => $updates, 'tables_deleted' => $deletes];
    }

    function incinerator_snapshot_table(PDO $pdo, string $table, string $column, int $id, array $columns): void
    {
        if (!$columns) {
            return;
        }

        $includeChildren = [];
        $selectedColumns = [];
        foreach ($columns as $key => $value) {
            if (is_string($key) && is_array($value)) {
                $includeChildren[] = ['table' => $key, 'columns' => $value];
            } else {
                $selectedColumns[] = is_string($key) ? $key : $value;
            }
        }

        $tableQuoted = incinerator_quote_identifier($table);
        $columnQuoted = incinerator_quote_identifier($column);
        $selectCols = $selectedColumns ? implode(', ', array_map('incinerator_quote_identifier', $selectedColumns)) : '*';

        $stmt = $pdo->prepare("SELECT {$selectCols} FROM {$tableQuoted} WHERE {$columnQuoted} = ?");
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return;
        }

        $snapshotTable = incinerator_quote_identifier('incinerator_snapshots');
        $insert = $pdo->prepare("INSERT INTO {$snapshotTable} (entity_type, source_table, source_column, source_id, snapshot_data, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        foreach ($rows as $row) {
            $payload = ['row' => $row];
            if ($includeChildren) {
                $children = [];
                foreach ($includeChildren as $child) {
                    $children[$child['table']] = incinerator_collect_related($pdo, $child['table'], $column, $id, $child['columns']);
                }
                if ($children) {
                    $payload['related'] = $children;
                }
            }
            $insert->execute([
                $table,
                $table,
                $column,
                $id,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    function incinerator_collect_related(PDO $pdo, string $table, string $column, int $id, array $columns): array
    {
        $tableQuoted = incinerator_quote_identifier($table);
        $columnQuoted = incinerator_quote_identifier($column);
        $selectCols = $columns ? implode(', ', array_map('incinerator_quote_identifier', $columns)) : '*';
        $stmt = $pdo->prepare("SELECT {$selectCols} FROM {$tableQuoted} WHERE {$columnQuoted} = ?");
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Permanently remove a user and optionally cascade to associated entities.
     */
    function incinerator_delete_user(PDO $pdo, int $userId, array $options = []): array
    {
        $options += [
            // Remove wholesale accounts owned by the user before deleting the user record.
            'delete_accounts' => true,
            // Remove CRM leads assigned to the user.
            'delete_assigned_leads' => true,
        ];

        $pdo->beginTransaction();

        try {
            // Optionally purge wholesale accounts owned by the user.
            if (!empty($options['delete_accounts'])) {
                $stmtAccounts = $pdo->prepare('SELECT id FROM wholesale_accounts WHERE user_id = ?');
                $stmtAccounts->execute([$userId]);
                $accounts = $stmtAccounts->fetchAll(PDO::FETCH_COLUMN, 0);
                foreach ($accounts as $accountId) {
                    incinerator_delete_account($pdo, (int)$accountId, [
                        'within_transaction' => true,
                        'incinerate_related_leads' => false,
                    ]);
                }
            }

            // Optionally purge leads assigned to this user (sales agent) to avoid reassigning.
            if (!empty($options['delete_assigned_leads'])) {
                $stmtLeads = $pdo->prepare('SELECT id FROM crm_leads WHERE assigned_agent_id = ?');
                $stmtLeads->execute([$userId]);
                $leads = $stmtLeads->fetchAll(PDO::FETCH_COLUMN, 0);
                foreach ($leads as $leadId) {
                    incinerator_delete_lead($pdo, (int)$leadId, ['within_transaction' => true]);
                }
            }

            // Clean up referencing tables that do not cascade.
            incinerator_cleanup_references($pdo, 'users', 'id', $userId);

            // Final hard delete.
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('User not found.');
            }

            $pdo->commit();
            return ['ok' => true, 'mode' => 'deleted'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Permanently purge a CRM lead and any related artifacts not covered by cascades.
     */
    function incinerator_delete_lead(PDO $pdo, int $leadId, array $options = []): array
    {
        $options += [
            'incinerate_converted_account' => false,
            'incinerate_converted_contact' => false,
            'within_transaction' => false,
            'snapshot_orders' => true,
        ];

        $ownsTransaction = !$options['within_transaction'];

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $detailStmt = $pdo->prepare('SELECT converted_to_account_id, converted_to_contact_id FROM crm_leads WHERE id = ?');
            $detailStmt->execute([$leadId]);
            $leadRow = $detailStmt->fetch(PDO::FETCH_ASSOC);
            if (!$leadRow) {
                throw new RuntimeException('Lead not found.');
            }

            if (!empty($options['incinerate_converted_account'])) {
                $accountId = (int)($leadRow['converted_to_account_id'] ?? 0);
                if ($accountId) {
                    incinerator_delete_account($pdo, $accountId, [
                        'within_transaction' => true,
                        'incinerate_related_leads' => false,
                    ]);
                }
            }

            if (!empty($options['incinerate_converted_contact'])) {
                $contactId = (int)($leadRow['converted_to_contact_id'] ?? 0);
                if ($contactId) {
                    incinerator_delete_contact($pdo, $contactId, ['within_transaction' => true]);
                }
            }

            incinerator_cleanup_references(
                $pdo,
                'crm_leads',
                'id',
                $leadId,
                null,
                [
                    'force_delete_tables' => ['crm_call_logs', 'crm_tasks', 'crm_opportunities'],
                    'snapshot_columns' => $options['snapshot_orders'] ? ['orders' => ['id','order_number','user_id','wholesale_account_id','sales_agent_id','status','shipping_status','payment_status','subtotal','tax','shipping','total','created_at','updated_at','notes'], 'order_items' => ['id','order_id','product_id','product_name','product_sku','quantity','unit_price','line_total']] : [],
                ]
            );

            $stmt = $pdo->prepare('DELETE FROM crm_leads WHERE id = ?');
            $stmt->execute([$leadId]);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return ['ok' => true, 'deleted' => 1];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Permanently purge a wholesale account and dependent data.
     */
    function incinerator_delete_account(PDO $pdo, int $accountId, array $options = []): array
    {
        $options += [
            'within_transaction' => false,
            'incinerate_related_leads' => true,
        ];

        $ownsTransaction = !$options['within_transaction'];

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            if (!empty($options['incinerate_related_leads'])) {
                $stmtLeads = $pdo->prepare('SELECT id FROM crm_leads WHERE converted_to_account_id = ?');
                $stmtLeads->execute([$accountId]);
                $relatedLeads = $stmtLeads->fetchAll(PDO::FETCH_COLUMN, 0);
                foreach ($relatedLeads as $leadId) {
                    incinerator_delete_lead($pdo, (int)$leadId, [
                        'within_transaction' => true,
                        'incinerate_converted_account' => false,
                        'incinerate_converted_contact' => true,
                    ]);
                }
            }

            incinerator_cleanup_references(
                $pdo,
                'wholesale_accounts',
                'id',
                $accountId,
                null,
                ['force_delete_tables' => ['crm_call_logs', 'crm_tasks', 'crm_opportunities']]
            );

            $stmt = $pdo->prepare('DELETE FROM wholesale_accounts WHERE id = ?');
            $stmt->execute([$accountId]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Wholesale account not found.');
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return ['ok' => true, 'deleted' => 1];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Permanently delete a CRM contact and any dependencies.
     */
    function incinerator_delete_contact(PDO $pdo, int $contactId, array $options = []): array
    {
        $options += [
            'within_transaction' => false,
        ];

        $ownsTransaction = !$options['within_transaction'];

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            incinerator_cleanup_references($pdo, 'crm_contacts', 'id', $contactId);

            $stmt = $pdo->prepare('DELETE FROM crm_contacts WHERE id = ?');
            $stmt->execute([$contactId]);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return ['ok' => true, 'deleted' => 1];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    function incinerator_delete_commission(PDO $pdo, int $commissionId, array $options = []): array
    {
        $options += ['within_transaction' => false];
        $owns = !$options['within_transaction'];
        if ($owns) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare('SELECT c.*, o.payment_status, o.status as order_status FROM commissions c LEFT JOIN orders o ON o.id = c.order_id WHERE c.id = ?');
            $stmt->execute([$commissionId]);
            $commission = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$commission) {
                if ($owns) $pdo->commit();
                return ['ok' => true, 'mode' => 'missing'];
            }

            require_once __DIR__ . '/commission-utils.php';
            if (!commission_should_incinerate($commission)) {
                if ($owns) $pdo->commit();
                return ['ok' => true, 'mode' => 'skipped'];
            }

            // Snapshot associated order if necessary
            $orderId = (int)($commission['order_id'] ?? 0);
            if ($orderId > 0) {
                commission_snapshot_for_commission($pdo, $commissionId);
            }

            $del = $pdo->prepare('DELETE FROM commissions WHERE id = ?');
            $del->execute([$commissionId]);

            if ($owns) {
                $pdo->commit();
            }

            return ['ok' => true, 'mode' => 'deleted'];
        } catch (Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}


