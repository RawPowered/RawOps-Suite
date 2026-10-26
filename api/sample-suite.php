<?php
// Start output buffering to catch any accidental output
ob_start();

// Helper function to send JSON response (ensures buffer is always clean)
function send_json_response(array $data, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        send_json_response(['ok' => false, 'error' => 'Fatal Error: ' . $error['message']], 500);
    }
});

try {
    if (!file_exists(__DIR__ . '/../required/config.php')) {
        throw new Exception('Config file missing');
    }
    require_once __DIR__ . '/../required/config.php';
    
    if (!file_exists(__DIR__ . '/../required/sample-suite-helpers.php')) {
        throw new Exception('Helpers file missing');
    }
    require_once __DIR__ . '/../required/sample-suite-helpers.php';

    // Clean any buffered output before setting headers
    ob_end_clean();
    ob_start();
    
    header('Content-Type: application/json');

    start_session();
    $pdo = db();
    $userId = current_user_id();
    $adminId = current_admin_id();

    if (!$userId) {
        send_json_response(['ok' => false, 'error' => 'Authentication required'], 401);
    }

    $isAdmin = $adminId !== null;
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
} catch (Throwable $e) {
    send_json_response(['ok' => false, 'error' => 'Initialization error: ' . $e->getMessage()], 500);
}

try {
    switch ($action) {
        case 'start':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $requestId = intval($input['sample_request_id'] ?? 0);
            if ($requestId <= 0) {
                throw new Exception('sample_request_id is required');
            }

            $stmt = $pdo->prepare('SELECT id, agent_id FROM sample_requests WHERE id = ? LIMIT 1');
            $stmt->execute([$requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$request) {
                throw new Exception('Sample request not found');
            }
            if (!$isAdmin && intval($request['agent_id']) !== $userId) {
                throw new Exception('Access denied for this sample request');
            }

            $result = sample_suite_start_chain($pdo, $requestId, intval($request['agent_id']));
            send_json_response(['ok' => true, 'result' => $result]);
            break;

        case 'status':
            $leadId = intval($_GET['lead_id'] ?? 0);
            $accountId = intval($_GET['account_id'] ?? 0);
            $requestId = intval($_GET['sample_request_id'] ?? 0);

            $where = [];
            $params = [];

            if ($requestId > 0) {
                $where[] = 'sr.id = ?';
                $params[] = $requestId;
            } elseif ($leadId > 0) {
                $where[] = 'sr.lead_id = ?';
                $params[] = $leadId;
            } elseif ($accountId > 0) {
                $where[] = 'sr.account_id = ?';
                $params[] = $accountId;
            }

            if (!$isAdmin) {
                $where[] = 'sr.agent_id = ?';
                $params[] = $userId;
            }

            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $stmt = $pdo->prepare("
                SELECT 
                    sr.id AS sample_request_id,
                    sr.id AS id,
                    sr.status AS sample_status,
                    sr.suite_status,
                    sr.suite_started_at,
                    sr.suite_completed_at,
                    sr.recipient_name,
                    sr.recipient_city,
                    sr.recipient_state,
                    e.id AS enrollment_id,
                    e.status AS enrollment_status,
                    e.current_step_key,
                    e.current_step_number,
                    e.next_step_scheduled_for,
                    e.kickoff_email_id,
                    e.attempt_count,
                    e.max_attempts,
                    e.rotation_cursor,
                    e.sale_detected_at,
                    e.unsubscribe_at,
                    e.behavior_state,
                    e.last_engaged_at,
                    e.finalized_reason,
                    e.created_at,
                    e.updated_at
                FROM sample_requests sr
                LEFT JOIN sample_suite_enrollments e ON e.sample_request_id = sr.id
                $whereSql
                ORDER BY sr.created_at DESC
                LIMIT 25
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $enrollmentIds = array_filter(array_map(fn($row) => intval($row['enrollment_id']), $rows));
            $logs = [];
            if ($enrollmentIds) {
                $in = implode(',', array_fill(0, count($enrollmentIds), '?'));
                $logStmt = $pdo->prepare("
                    SELECT enrollment_id, step_key, step_number, status, scheduled_for, sent_at, notes
                    FROM sample_suite_step_logs
                    WHERE enrollment_id IN ($in)
                    ORDER BY step_number ASC, id ASC
                ");
                $logStmt->execute($enrollmentIds);
                while ($log = $logStmt->fetch(PDO::FETCH_ASSOC)) {
                    $logs[intval($log['enrollment_id'])][] = $log;
                }
            }

            foreach ($rows as &$row) {
                $eid = intval($row['enrollment_id']);
                $row['steps'] = $logs[$eid] ?? [];
                if (!empty($row['behavior_state'])) {
                    $decoded = json_decode($row['behavior_state'], true);
                    $row['behavior_state'] = is_array($decoded) ? $decoded : null;
                } else {
                    $row['behavior_state'] = null;
                }
            }

            send_json_response(['ok' => true, 'items' => $rows]);
            break;

        case 'cancel':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $enrollmentId = intval($input['enrollment_id'] ?? 0);
            if ($enrollmentId <= 0) {
                throw new Exception('enrollment_id is required');
            }

            $stmt = $pdo->prepare("
                SELECT e.*, sr.agent_id, sr.id AS sample_request_id
                FROM sample_suite_enrollments e
                JOIN sample_requests sr ON sr.id = e.sample_request_id
                WHERE e.id = ?
                LIMIT 1
            ");
            $stmt->execute([$enrollmentId]);
            $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$enrollment) {
                throw new Exception('Enrollment not found');
            }
            if (!$isAdmin && intval($enrollment['agent_id']) !== $userId) {
                throw new Exception('Access denied');
            }

            $pdo->prepare("UPDATE sample_suite_enrollments SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
                ->execute([$enrollmentId]);
            $pdo->prepare("
                UPDATE sample_requests 
                SET suite_status = 'cancelled', suite_completed_at = NOW()
                WHERE id = ?
            ")->execute([$enrollment['sample_request_id']]);
            $pdo->prepare("
                UPDATE email_followup_schedule 
                SET status = 'cancelled', cancel_reason = 'sample_suite_cancelled', processed_at = NOW()
                WHERE chain_id = ? AND status = 'pending'
            ")->execute([$enrollment['chain_id']]);

            send_json_response(['ok' => true]);
            break;

        case 'mark_sale':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $requestId = intval($input['sample_request_id'] ?? 0);
            if ($requestId <= 0) {
                throw new Exception('sample_request_id is required');
            }

            $stmt = $pdo->prepare('SELECT agent_id FROM sample_requests WHERE id = ? LIMIT 1');
            $stmt->execute([$requestId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Sample request not found');
            }
            if (!$isAdmin && intval($row['agent_id']) !== $userId) {
                throw new Exception('Access denied');
            }

            $result = sample_suite_mark_sale($pdo, $requestId, ['source' => 'api']);
            send_json_response(['ok' => true, 'result' => $result]);
            break;

        case 'reset':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $requestId = intval($input['sample_request_id'] ?? 0);
            if ($requestId <= 0) {
                throw new Exception('sample_request_id is required');
            }

            $stmt = $pdo->prepare("
                SELECT e.id AS enrollment_id, e.chain_id, sr.agent_id
                FROM sample_requests sr
                LEFT JOIN sample_suite_enrollments e ON e.sample_request_id = sr.id
                WHERE sr.id = ?
                LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Sample request not found');
            }
            if (!$isAdmin && intval($row['agent_id']) !== $userId) {
                throw new Exception('Access denied');
            }

            $enrollmentId = intval($row['enrollment_id'] ?? 0);
            if ($enrollmentId > 0) {
                sample_suite_cancel_pending_steps($pdo, $enrollmentId);
                $pdo->prepare("DELETE FROM sample_suite_step_logs WHERE enrollment_id = ?")->execute([$enrollmentId]);
                $pdo->prepare("DELETE FROM sample_suite_engagements WHERE sample_request_id = ?")->execute([$requestId]);
                $pdo->prepare("DELETE FROM sample_suite_enrollments WHERE id = ?")->execute([$enrollmentId]);
            }

            $pdo->prepare("
                UPDATE sample_requests
                SET suite_enrollment_id = NULL,
                    suite_status = 'not_started',
                    suite_started_at = NULL,
                    suite_completed_at = NULL
                WHERE id = ?
            ")->execute([$requestId]);

            send_json_response(['ok' => true]);
            break;

        case 'quick_start':
            // Create minimal placeholder request, send email, hide from admin until customer completes portal
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $entityType = $input['entity_type'] ?? 'lead';
            $entityId = intval($input['entity_id'] ?? 0);
            if ($entityType !== 'lead' || $entityId <= 0) {
                throw new Exception('Only lead quick-start is supported.');
            }

            $leadStmt = $pdo->prepare("SELECT * FROM crm_leads WHERE id = ? LIMIT 1");
            $leadStmt->execute([$entityId]);
            $lead = $leadStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lead) {
                throw new Exception('Lead not found.');
            }
            if (!$isAdmin && intval($lead['assigned_agent_id']) !== $userId) {
                throw new Exception('You are not assigned to this lead.');
            }
            if (empty($lead['email'])) {
                throw new Exception('Lead is missing an email address.');
            }

            // Check for existing active enrollment for this lead
            $existingStmt = $pdo->prepare("
                SELECT e.id, e.status, e.sample_request_id
                FROM sample_suite_enrollments e
                WHERE e.lead_id = ? AND e.status IN ('pending','active')
                ORDER BY e.created_at DESC
                LIMIT 1
            ");
            $existingStmt->execute([$entityId]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                throw new Exception('Sample Suite is already running for this lead.');
            }

            // Create placeholder sample request (hidden from admin view until customer completes portal)
            $placeholderId = sample_suite_create_placeholder_request($pdo, $lead, intval($lead['assigned_agent_id'] ?: $userId));
            $result = sample_suite_start_chain($pdo, $placeholderId, intval($lead['assigned_agent_id'] ?: $userId));
            send_json_response(['ok' => true, 'sample_request_id' => $placeholderId, 'result' => $result]);
            break;

        default:
            throw new Exception('Invalid action. Supported actions: start, status, cancel, mark_sale, reset, quick_start.');
    }
    
    // Success - flush output buffer
    ob_end_flush();
} catch (Throwable $e) {
    send_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}



