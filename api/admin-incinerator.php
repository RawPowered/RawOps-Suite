<?php

require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/admin_user_utils.php';
require_once __DIR__ . '/../required/admin_incinerator.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

start_session();

if (!current_admin_id()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = db();
$action = $_GET['action'] ?? '';

$rawInput = file_get_contents('php://input');
$input = [];
if ($rawInput !== false && trim($rawInput) !== '') {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

try {
    switch ($action) {
        case 'incinerate_user': {
            $userIds = $input['user_ids'] ?? [$input['user_id'] ?? null];
            if (!is_array($userIds) || !$userIds) {
                throw new InvalidArgumentException('No user IDs provided.');
            }
            $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn ($id) => $id > 0)));
            if (!$userIds) {
                throw new InvalidArgumentException('No valid user IDs provided.');
            }

            $results = [];
            foreach ($userIds as $userId) {
                $results[] = admin_incinerate_user($pdo, $userId, [
                    'delete_accounts' => !empty($input['delete_accounts']),
                    'delete_assigned_leads' => !empty($input['delete_assigned_leads']),
                ]);
            }

            echo json_encode([
                'ok' => true,
                'count' => count($results),
                'results' => $results,
            ]);
            break;
        }

        case 'incinerate_lead': {
            $leadIds = $input['lead_ids'] ?? null;
            if (is_array($leadIds)) {
                $leadIds = array_values(array_unique(array_filter(array_map('intval', $leadIds), fn ($id) => $id > 0)));
            }

            if ($leadIds && count($leadIds) > 0) {
                $results = [];
                foreach ($leadIds as $leadId) {
                    $results[] = incinerator_delete_lead($pdo, $leadId, [
                        'incinerate_converted_account' => !empty($input['incinerate_account']),
                        'incinerate_converted_contact' => !empty($input['incinerate_contact']),
                    ]);
                }
                echo json_encode([
                    'ok' => true,
                    'count' => count($results),
                    'results' => $results,
                ]);
                break;
            }

            $leadId = intval($input['lead_id'] ?? 0);
            if ($leadId <= 0) {
                throw new InvalidArgumentException('Missing or invalid lead_id.');
            }

            $result = incinerator_delete_lead($pdo, $leadId, [
                'incinerate_converted_account' => !empty($input['incinerate_account']),
                'incinerate_converted_contact' => !empty($input['incinerate_contact']),
            ]);

            echo json_encode(['ok' => true, 'count' => 1, 'results' => [$result]]);
            break;
        }

        case 'incinerate_commission': {
            $ids = $input['commission_ids'] ?? [$input['commission_id'] ?? null];
            if (!is_array($ids) || !$ids) {
                throw new InvalidArgumentException('No commission IDs provided.');
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
            if (!$ids) {
                throw new InvalidArgumentException('No valid commission IDs provided.');
            }

            require_once __DIR__ . '/../required/admin_incinerator.php';
            $results = [];
            foreach ($ids as $cid) {
                $results[] = incinerator_delete_commission($pdo, $cid);
            }

            echo json_encode(['ok' => true, 'count' => count($results), 'results' => $results]);
            break;
        }

        case 'incinerate_account': {
            $accountId = intval($input['account_id'] ?? 0);
            if ($accountId <= 0) {
                throw new InvalidArgumentException('Missing or invalid account_id.');
            }

            $result = incinerator_delete_account($pdo, $accountId, [
                'incinerate_related_leads' => !empty($input['incinerate_related_leads']),
            ]);

            echo json_encode(['ok' => true, 'result' => $result]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}


