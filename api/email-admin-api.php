<?php
/**
 * Email Admin API - pure JSON endpoint for email automation operations
 * No HTML ever rendered from this file
 */

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/sendgrid.php';
require_once __DIR__ . '/../required/admin_user_utils.php';
require_once __DIR__ . '/../required/email-triggers.php';
require_once __DIR__ . '/../required/json_sanitizer.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

start_session();
if (!current_admin_id()) {
    http_response_code(403);
    die(json_encode(['ok' => false, 'error' => 'Unauthorized']));
}

$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$requestId = bin2hex(random_bytes(8));
header('X-Debug-Request-ID: ' . $requestId);

$rawInput = file_get_contents('php://input');
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SERVER['CONTENT_TYPE']) || stripos($_SERVER['CONTENT_TYPE'], 'application/json') === false) {
        http_response_code(415);
        echo json_encode(['ok' => false, 'error' => 'Expected application/json payload', 'request_id' => $requestId]);
        exit;
    }
    $input = json_decode($rawInput, true);
    if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'Malformed JSON: ' . json_last_error_msg(),
            'raw' => substr($rawInput ?? '', 0, 200),
            'request_id' => $requestId
        ]);
        exit;
    }
    if (!is_array($input)) { $input = []; }
}

try {
    switch ($action) {
        case 'get_sendgrid_status':
            $cfg = site_read_config();
            $hasKey = !empty($cfg['sendgrid_api_key']);
            $keySnippet = $hasKey ? substr($cfg['sendgrid_api_key'], 0, 8) . '…' : null;

            $ping = [
                'ok' => false,
                'status' => null,
                'error' => null
            ];
            if ($hasKey) {
                try {
                    $res = sendgrid_send([
                        'to' => 'sample-008@example.com',
                        'subject' => 'SendGrid heartbeat',
                        'html' => '<p>Heartbeat</p>',
                        'text' => 'Heartbeat',
                        'sandbox' => true
                    ]);
                    $ping = [
                        'ok' => (bool)($res['ok'] ?? false),
                        'status' => $res['status'] ?? null,
                        'error' => $res['error'] ?? null,
                        'used' => $res['used'] ?? null
                    ];
                } catch (Throwable $e) {
                    $ping['error'] = $e->getMessage();
                }
            }

            $env = [
                'php_version' => PHP_VERSION,
                'default_socket_timeout' => ini_get('default_socket_timeout'),
                'curl_available' => function_exists('curl_version'),
                'sdk_available' => class_exists('SendGrid\\Mail\\Mail'),
                'request_id' => $requestId
            ];
            if ($hasKey) {
                $env['key_length'] = strlen((string)$cfg['sendgrid_api_key']);
            }

            echo json_encode([
                'ok' => true,
                'request_id' => $requestId,
                'has_key' => $hasKey,
                'key_snippet' => $keySnippet,
                'ping' => $ping,
                'environment' => $env
            ]);
            break;

        case 'set_sendgrid_key':
            $key = trim((string)($input['api_key'] ?? ''));
            $diagnostics = [
                'request_id' => $requestId,
                'key_provided' => !empty($key),
                'key_length' => strlen($key),
                'key_starts_with_sg' => stripos($key, 'SG.') === 0
            ];
            
            $cfg = site_read_config();
            $diagnostics['config_before'] = [
                'has_sendgrid_key' => isset($cfg['sendgrid_api_key']),
                'sendgrid_key_length' => strlen((string)($cfg['sendgrid_api_key'] ?? '')),
                'config_file_path' => site_config_file_path(),
                'config_file_exists' => file_exists(site_config_file_path()),
                'config_file_writable' => is_writable(site_config_file_path()) || is_writable(dirname(site_config_file_path()))
            ];
            
            if ($key === '') {
                unset($cfg['sendgrid_api_key']);
                $diagnostics['action'] = 'remove_key';
            } else {
                if (stripos($key, 'SG.') !== 0) {
                    throw new InvalidArgumentException('Key must start with SG.');
                }
                if (strlen($key) < 20) {
                    throw new InvalidArgumentException('Key looks too short.');
                }
                $cfg['sendgrid_api_key'] = $key;
                $diagnostics['action'] = 'set_key';
            }
            
            $writeResult = site_write_config($cfg);
            $diagnostics['write_result'] = $writeResult;
            
            if (!$writeResult) {
                $diagnostics['write_error'] = 'site_write_config returned false';
                $diagnostics['directory_permissions'] = substr(sprintf('%o', fileperms(dirname(site_config_file_path()))), -4);
                throw new RuntimeException('Failed to persist configuration. Check file permissions on ' . site_config_file_path());
            }
            
            // Verify the write
            $cfgVerify = site_read_config();
            $diagnostics['config_after'] = [
                'has_sendgrid_key' => isset($cfgVerify['sendgrid_api_key']),
                'sendgrid_key_length' => strlen((string)($cfgVerify['sendgrid_api_key'] ?? '')),
                'key_matches' => ($cfgVerify['sendgrid_api_key'] ?? '') === $key
            ];
            
            // Reload config cache for current request
            if ($key === '') {
                putenv('SENDGRID_API_KEY');
                unset($_ENV['SENDGRID_API_KEY'], $_SERVER['SENDGRID_API_KEY']);
            } else {
                putenv('SENDGRID_API_KEY=' . $cfg['sendgrid_api_key']);
                $_ENV['SENDGRID_API_KEY'] = $cfg['sendgrid_api_key'];
                $_SERVER['SENDGRID_API_KEY'] = $cfg['sendgrid_api_key'];
            }
            
            $diagnostics['ok'] = true;
            echo json_encode($diagnostics);
            break;

        case 'bulk_user_action':
            $ids = $input['user_ids'] ?? [];
            $bulkAction = (string)($input['bulk_action'] ?? '');
            $roleId = intval($input['role_id'] ?? 0);
            if (!$ids || !$bulkAction) {
                throw new InvalidArgumentException('Missing parameters for bulk action.');
            }
            $result = admin_process_bulk_action($pdo, $bulkAction, $ids, $roleId);
            echo json_encode(['ok' => true, 'request_id' => $requestId] + $result);
            break;
        
        case 'sendgrid_test':
            $sandbox = isset($input['sandbox']) ? (bool)$input['sandbox'] : true;
            $payload = $input + ['sandbox' => $sandbox, 'debug' => true];
            $context = [
                'request_id' => $requestId,
                'submitted_at' => gmdate('c'),
                'payload_preview' => json_sanitize_for_encoding($payload),
                'environment' => [
                    'has_env_key' => (bool)readEnv('SENDGRID_API_KEY'),
                    'site_config_key_length' => strlen((string)(site_read_config()['sendgrid_api_key'] ?? '')),
                    'php_version' => PHP_VERSION,
                    'default_socket_timeout' => ini_get('default_socket_timeout'),
                    'curl_available' => function_exists('curl_version'),
                    'sdk_available' => class_exists('SendGrid\\Mail\\Mail')
                ]
            ];
            $start = microtime(true);
            try {
                $res = sendgrid_send($payload);
                $context['duration_ms'] = round((microtime(true) - $start) * 1000, 2);
                $context['sendgrid'] = $res;
                $context['ok'] = (bool)($res['ok'] ?? false);
                echo json_encode($context);
            } catch (Throwable $e) {
                $context['duration_ms'] = round((microtime(true) - $start) * 1000, 2);
                $context['ok'] = false;
                $context['exception'] = [
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString())
                ];
                log_error_to_database([
                    'error_type' => 'email_admin_api',
                    'error_code' => 'sendgrid_test_exception',
                    'error_message' => $e->getMessage(),
                    'error_severity' => 'error',
                    'file_path' => $e->getFile(),
                    'line_number' => $e->getLine(),
                    'stack_trace' => $e->getTraceAsString(),
                    'url' => $_SERVER['REQUEST_URI'] ?? null,
                    'context_json' => json_encode(['input' => json_sanitize_for_encoding($input), 'request_id' => $requestId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]);
                http_response_code(500);
                echo json_encode($context);
            }
            break;

        case 'event_probe':
            $eventKey = $input['event_key'] ?? '';
            $userId = intval($input['user_id'] ?? 0);
            $dryRun = !empty($input['dry_run']);
            if (!$eventKey || $userId <= 0) {
                throw new InvalidArgumentException('event_key and user_id are required');
            }

            $pdo->beginTransaction();
            try {
                if ($dryRun) {
                    $probe = probe_notification_event($eventKey, $userId, $pdo, $input['context'] ?? []);
                    $pdo->rollBack();
                    echo json_encode([
                        'ok' => true,
                        'request_id' => $requestId,
                        'dry_run' => true,
                        'result' => $probe,
                        'metrics' => ['rule_count' => count($probe['rules'] ?? [])]
                    ]);
                } else {
                    $count = trigger_email_notification($eventKey, $userId, $input['context'] ?? []);
                    $pdo->commit();
                    echo json_encode([
                        'ok' => true,
                        'request_id' => $requestId,
                        'dry_run' => false,
                        'sent' => $count
                    ]);
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            break;
            
        case 'sync_from_disk':
            $base = realpath(__DIR__ . '/../admin/emails');
            if (!$base) { throw new RuntimeException('Templates directory not found'); }
            $files = glob($base . '/*.html');
            $inserted = 0; $updated = 0; $errors = [];
            foreach ($files as $f) {
                $slug = basename($f, '.html');
                $html = file_get_contents($f);
                $subject = $slug;
                if (preg_match('/<title>(.*?)<\/title>/i', $html, $m)) {
                    $subject = trim(strip_tags($m[1]));
                }
                $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
                try {
                    $exists = $pdo->prepare('SELECT id FROM email_templates WHERE slug = ?');
                    $exists->execute([$slug]);
                    $row = $exists->fetch();
                    if ($row) {
                        $st = $pdo->prepare('UPDATE email_templates SET subject=?,html_body=?,text_body=?,updated_at=NOW() WHERE id=?');
                        $st->execute([$subject, $html, $text, $row['id']]);
                        $updated++;
                    } else {
                        $st = $pdo->prepare('INSERT INTO email_templates (slug,name,subject,html_body,text_body,is_active,created_at) VALUES (?,?,?,?,?,1,NOW())');
                        $st->execute([$slug, ucwords(str_replace('-', ' ', $slug)), $subject, $html, $text]);
                        $inserted++;
                    }
                } catch (Throwable $e) {
                    $errors[] = $slug . ': ' . $e->getMessage();
                }
            }
            echo json_encode(['ok' => true, 'request_id' => $requestId, 'inserted' => $inserted, 'updated' => $updated, 'errors' => $errors]);
            break;
            
        case 'toggle_event':
            $eventKey = $input['event_key'] ?? '';
            $enable = intval($input['enable'] ?? 0);
            if (!$eventKey) throw new Exception('Missing event_key');
            $r = $pdo->prepare('SELECT id FROM email_notification_rules WHERE event_key=? LIMIT 1');
            $r->execute([$eventKey]);
            $row = $r->fetch();
            if ($row) {
                $pdo->prepare('UPDATE email_notification_rules SET is_active=?,updated_at=NOW() WHERE id=?')->execute([$enable, $row['id']]);
            } else {
                $pdo->prepare('INSERT INTO email_notification_rules (event_key,is_active,created_at) VALUES (?,?,NOW())')->execute([$eventKey, $enable]);
            }
            echo json_encode(['ok' => true, 'request_id' => $requestId]);
            break;
            
        case 'assign_template':
            $eventKey = $input['event_key'] ?? '';
            $templateId = intval($input['template_id'] ?? 0) ?: null;
            if (!$eventKey) throw new Exception('Missing event_key');
            $r = $pdo->prepare('SELECT id FROM email_notification_rules WHERE event_key=? LIMIT 1');
            $r->execute([$eventKey]);
            $row = $r->fetch();
            if ($row) {
                $pdo->prepare('UPDATE email_notification_rules SET template_id=?,updated_at=NOW() WHERE id=?')->execute([$templateId, $row['id']]);
            } else {
                $pdo->prepare('INSERT INTO email_notification_rules (event_key,template_id,is_active,created_at) VALUES (?,?,1,NOW())')->execute([$eventKey, $templateId]);
            }
            echo json_encode(['ok' => true, 'request_id' => $requestId]);
            break;
            
        case 'get_per_agent_stats':
            // Get email statistics grouped by agent
            $sql = "SELECT 
                        ae.agent_id,
                        CONCAT(u.first_name, ' ', u.last_name) as agent_name,
                        COUNT(*) as total_sent,
                        SUM(CASE WHEN ae.status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                        SUM(CASE WHEN ae.status = 'bounced' THEN 1 ELSE 0 END) as bounced,
                        SUM(CASE WHEN ae.open_count > 0 THEN 1 ELSE 0 END) as opened,
                        SUM(ae.open_count) as total_opens,
                        SUM(ae.click_count) as total_clicks,
                        SUM(CASE WHEN ae.is_ai_assisted = 1 THEN 1 ELSE 0 END) as ai_count,
                        SUM(CASE WHEN ae.contributed_to_sale = 1 THEN 1 ELSE 0 END) as converted
                    FROM agent_emails ae
                    JOIN users u ON u.id = ae.agent_id
                    WHERE ae.status IN ('sent', 'delivered', 'bounced')
                    GROUP BY ae.agent_id
                    ORDER BY total_sent DESC";
            
            $stmt = $pdo->query($sql);
            $agents = $stmt->fetchAll();
            
            // Calculate rates
            foreach ($agents as &$agent) {
                $totalSent = intval($agent['total_sent']);
                $agent['open_rate'] = $totalSent > 0 ? round((intval($agent['opened']) / $totalSent) * 100, 1) : 0;
                $agent['click_rate'] = $totalSent > 0 ? round((intval($agent['total_clicks']) / $totalSent) * 100, 1) : 0;
                $agent['delivery_rate'] = $totalSent > 0 ? round((intval($agent['delivered']) / $totalSent) * 100, 1) : 0;
            }
            
            echo json_encode(['ok' => true, 'agents' => $agents, 'request_id' => $requestId]);
            break;
        
        case 'get_agent_emails':
            // Get all agent emails for admin review
            $agentFilter = $_GET['agent'] ?? '';
            $statusFilter = $_GET['status'] ?? '';
            $aiAssistedFilter = $_GET['ai_assisted'] ?? '';
            $reviewedFilter = $_GET['reviewed'] ?? '';
            
            $where = ['1=1'];
            $params = [];
            
            if ($agentFilter !== '') {
                $where[] = 'ae.agent_id = ?';
                $params[] = intval($agentFilter);
            }
            if ($statusFilter !== '') {
                $where[] = 'ae.status = ?';
                $params[] = $statusFilter;
            }
            if ($aiAssistedFilter !== '') {
                $where[] = 'ae.is_ai_assisted = ?';
                $params[] = intval($aiAssistedFilter);
            }
            if ($reviewedFilter !== '') {
                $where[] = 'ae.admin_reviewed = ?';
                $params[] = intval($reviewedFilter);
            }
            
            $sql = "SELECT ae.*, 
                           CONCAT(u.first_name, ' ', u.last_name) as agent_name,
                           l.company_name as lead_company,
                           wa.business_name as account_company
                    FROM agent_emails ae
                    JOIN users u ON u.id = ae.agent_id
                    LEFT JOIN crm_leads l ON l.id = ae.lead_id
                    LEFT JOIN wholesale_accounts wa ON wa.id = ae.account_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY ae.created_at DESC
                    LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $emails = $stmt->fetchAll();
            
            echo json_encode(['ok' => true, 'emails' => $emails, 'request_id' => $requestId]);
            break;
            
        case 'get_agent_email_detail':
            // Get single email detail for review
            $emailId = intval($_GET['id'] ?? 0);
            
            $stmt = $pdo->prepare("SELECT ae.*, 
                                          CONCAT(u.first_name, ' ', u.last_name) as agent_name,
                                          u.email as agent_email
                                   FROM agent_emails ae
                                   JOIN users u ON u.id = ae.agent_id
                                   WHERE ae.id = ?");
            $stmt->execute([$emailId]);
            $email = $stmt->fetch();
            
            if (!$email) {
                throw new Exception('Email not found');
            }
            
            echo json_encode(['ok' => true, 'email' => $email, 'request_id' => $requestId]);
            break;
            
        case 'mark_email_reviewed':
            // Admin marks email as reviewed
            $emailId = intval($input['email_id'] ?? 0);
            $adminNotes = $input['notes'] ?? null;
            
            if ($emailId <= 0) {
                throw new Exception('Email ID required');
            }
            
            $adminId = current_admin_id();
            $stmt = $pdo->prepare('UPDATE agent_emails SET admin_reviewed = 1, admin_reviewed_by = ?, admin_reviewed_at = NOW(), admin_notes = ? WHERE id = ?');
            $stmt->execute([$adminId, $adminNotes, $emailId]);
            
            echo json_encode(['ok' => true, 'request_id' => $requestId]);
            break;
            
        case 'sync_templates_from_disk':
            // Sync Sample Suite email templates from admin/emails/ to database
            $emailsDir = __DIR__ . '/../admin/emails';
            if (!is_dir($emailsDir)) {
                throw new Exception('Email templates directory not found');
            }
            
            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];
            
            // Template metadata mapping
            $templateMeta = [
                'sample-suite-kickoff' => ['name' => 'Sample Suite · Kickoff', 'subject' => 'Claim the Blue / Gold kit + premium product format dash'],
                'sample-suite-kickoff-reminder' => ['name' => 'Sample Suite · Kickoff Reminder', 'subject' => 'Just making sure you saw this sample offer'],
                'sample-suite-offer-reminder-1' => ['name' => 'Sample Suite · Offer Reminder 1', 'subject' => 'Blue 60 + Gold 120 kit still reserved for {{company_name}}'],
                'sample-suite-offer-reminder-2' => ['name' => 'Sample Suite · Offer Reminder 2', 'subject' => 'Before I recycle this sample slot—do you still want it?'],
                'sample-suite-offer-day5' => ['name' => 'Sample Suite · Day 5 Touch', 'subject' => 'Still tagged for {{company_name}} — want shelf assets with it?'],
                'sample-suite-rotation-a' => ['name' => 'Sample Suite · Rotation A', 'subject' => 'Want the shelf playbook we drop with most stores?'],
                'sample-suite-rotation-b' => ['name' => 'Sample Suite · Rotation B', 'subject' => 'Margin breakdown ready when you are'],
                'sample-suite-rotation-c' => ['name' => 'Sample Suite · Rotation C', 'subject' => 'Need the staff training bundle to go with the kit?'],
                'sample-suite-click-probe' => ['name' => 'Sample Suite · Click Follow-up', 'subject' => 'Saw you peeked at the details—anything holding you back?'],
                'sample-suite-shipped' => ['name' => 'Sample Suite · Shipped', 'subject' => 'Your Product sample kit is officially on the move'],
                'sample-suite-delivered-checkin' => ['name' => 'Sample Suite · Delivered Check-in', 'subject' => 'Sample landed—here\'s how shops test it fast'],
                'sample-suite-day5-nurture' => ['name' => 'Sample Suite · Day 5 Nurture', 'subject' => 'Ready for the plug-and-play reorder builder?'],
                'sample-suite-feedback' => ['name' => 'Sample Suite · Feedback', 'subject' => 'How did the kit land with your team + VIPs?'],
                'sample-suite-last-call' => ['name' => 'Sample Suite · Last Call', 'subject' => 'Before I recycle this thread, anything slip through?'],
                'sample-suite-human-escalation' => ['name' => 'Sample Suite · Human Escalation', 'subject' => 'We\'ll pause automation and have a teammate call you']
            ];
            
            foreach ($templateMeta as $slug => $meta) {
                try {
                    $htmlFile = $emailsDir . '/' . $slug . '.html';
                    
                    if (!file_exists($htmlFile)) {
                        $skipped++;
                        $errors[] = "File not found: {$slug}.html";
                        continue;
                    }
                    
                    $htmlContent = file_get_contents($htmlFile);
                    if ($htmlContent === false) {
                        $errors[] = "Failed to read: {$slug}.html";
                        $skipped++;
                        continue;
                    }
                    
                    // Check if template exists
                    $checkStmt = $pdo->prepare('SELECT id FROM email_templates WHERE slug = ? LIMIT 1');
                    $checkStmt->execute([$slug]);
                    $existingId = $checkStmt->fetchColumn();
                    
                    $textBody = strip_tags($htmlContent);
                    
                    if ($existingId) {
                        // Update existing
                        $updateStmt = $pdo->prepare('
                            UPDATE email_templates
                            SET name = ?,
                                subject = ?,
                                html_body = ?,
                                text_body = ?,
                                is_active = 1,
                                updated_at = NOW()
                            WHERE id = ?
                        ');
                        $updateStmt->execute([
                            $meta['name'],
                            $meta['subject'],
                            $htmlContent,
                            $textBody,
                            $existingId
                        ]);
                        $updated++;
                    } else {
                        // Insert new
                        $insertStmt = $pdo->prepare('
                            INSERT INTO email_templates (
                                slug, name, subject, html_body, text_body,
                                category, scope, is_active, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
                        ');
                        $insertStmt->execute([
                            $slug,
                            $meta['name'],
                            $meta['subject'],
                            $htmlContent,
                            $textBody,
                            'sample_suite',
                            'platform'
                        ]);
                        $inserted++;
                    }
                    
                } catch (Throwable $e) {
                    $errors[] = "{$slug}: " . $e->getMessage();
                    $skipped++;
                }
            }
            
            echo json_encode([
                'ok' => true,
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'total' => count($templateMeta),
                'errors' => $errors,
                'request_id' => $requestId
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action, 'request_id' => $requestId]);
    }
} catch (Throwable $e) {
    try {
        log_error_to_database([
            'error_type' => 'email_admin_api',
            'error_code' => $action ?: 'unknown_action',
            'error_message' => $e->getMessage(),
            'error_severity' => 'error',
            'file_path' => $e->getFile(),
            'line_number' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
            'url' => $_SERVER['REQUEST_URI'] ?? null,
            'context_json' => json_encode([
                'input' => json_sanitize_for_encoding($input),
                'request_id' => $requestId
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    } catch (Throwable $logErr) {
        // ignore logging failure
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'request_id' => $requestId,
        'exception' => [
            'type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}
