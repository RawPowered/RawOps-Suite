<?php
require_once __DIR__ . '/../required/config.php';

header('Content-Type: application/json');

try {
    start_session();
} catch (Throwable $e) {
    // ignore
}

if (!current_admin_id()) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$pdo = db();

$input = $_GET + ($_POST ?? []);

$userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
$days = isset($input['days']) ? max(1, min(90, (int)$input['days'])) : 7;
$endDate = new DateTime('today');
$startDate = (clone $endDate)->modify('-' . ($days - 1) . ' day');

$params = [':start' => $startDate->format('Y-m-d 00:00:00'), ':end' => $endDate->format('Y-m-d 23:59:59')];

if ($userId) {
    $params[':uid'] = $userId;
}

// Time aggregates
$timeSql = 'SELECT user_id, DATE(started_at) AS day,
                   SUM(engaged_ms) AS engaged_ms,
                   SUM(idle_ms) AS idle_ms,
                   COUNT(*) AS sessions
            FROM site_page_sessions
            WHERE started_at BETWEEN :start AND :end';

if ($userId) {
    $timeSql .= ' AND user_id = :uid';
}

$timeSql .= ' GROUP BY user_id, DATE(started_at)';

$stmt = $pdo->prepare($timeSql);
$stmt->execute($params);
$timeRows = $stmt->fetchAll();

$perUserTotals = [];

foreach ($timeRows as $row) {
    $uid = (int)$row['user_id'];
    if (!$uid) {
        continue;
    }

    if (!isset($perUserTotals[$uid])) {
        $perUserTotals[$uid] = [
            'user_id' => $uid,
            'days' => [],
            'engaged_ms' => 0,
            'idle_ms' => 0,
            'sessions' => 0
        ];
    }

    $perUserTotals[$uid]['days'][$row['day']] = [
        'engaged_ms' => (int)$row['engaged_ms'],
        'idle_ms' => (int)$row['idle_ms'],
        'sessions' => (int)$row['sessions']
    ];
    $perUserTotals[$uid]['engaged_ms'] += (int)$row['engaged_ms'];
    $perUserTotals[$uid]['idle_ms'] += (int)$row['idle_ms'];
    $perUserTotals[$uid]['sessions'] += (int)$row['sessions'];
}

// Top pages
$pageSql = 'SELECT path,
                   COUNT(*) AS sessions,
                   SUM(engaged_ms) AS total_engaged_ms,
                   AVG(engaged_ms) AS avg_engaged_ms
            FROM site_page_sessions
            WHERE started_at BETWEEN :start AND :end';

if ($userId) {
    $pageSql .= ' AND user_id = :uid';
}

$pageSql .= ' GROUP BY path ORDER BY total_engaged_ms DESC LIMIT 25';

$stmt = $pdo->prepare($pageSql);
$stmt->execute($params);
$topPages = $stmt->fetchAll();

// Lead attribution
$attrSql = 'SELECT agent_id, method, status, COUNT(*) AS cnt
            FROM crm_lead_attribution
            WHERE created_at BETWEEN :start AND :end';

if ($userId) {
    $attrSql .= ' AND agent_id = :uid';
}

$attrSql .= ' GROUP BY agent_id, method, status';

$stmt = $pdo->prepare($attrSql);
$stmt->execute($params);
$attrRows = $stmt->fetchAll();

$leadAttribution = [];

foreach ($attrRows as $row) {
    $aid = (int)$row['agent_id'];
    if (!$aid) {
        continue;
    }
    if (!isset($leadAttribution[$aid])) {
        $leadAttribution[$aid] = [];
    }
    $key = $row['method'] . ':' . $row['status'];
    $leadAttribution[$aid][$key] = (int)$row['cnt'];
}

// Pull user info for convenience
$userIds = array_keys($perUserTotals + $leadAttribution);
$users = [];

if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, email FROM users WHERE id IN (' . $placeholders . ')');
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $row) {
        $users[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email']
        ];
    }
}

echo json_encode([
    'range' => [
        'start' => $startDate->format('Y-m-d'),
        'end' => $endDate->format('Y-m-d'),
        'days' => $days
    ],
    'time' => array_values($perUserTotals),
    'pages' => $topPages,
    'lead_attribution' => $leadAttribution,
    'users' => $users
]);


