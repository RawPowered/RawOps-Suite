<?php

require_once __DIR__ . '/../../required/config.php';

header('Content-Type: application/json');

start_session();
$userId = current_user_id();
if (!$userId || !user_has_role($userId, 'admin')) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = db();
    
    // Overall stats
    $overallStmt = $pdo->query('
        SELECT 
            COUNT(*) as total_sent,
            SUM(CASE WHEN status IN ("delivered","opened","clicked") THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status IN ("opened","clicked") THEN 1 ELSE 0 END) as opened,
            SUM(CASE WHEN status = "clicked" THEN 1 ELSE 0 END) as clicked,
            SUM(CASE WHEN status = "bounced" THEN 1 ELSE 0 END) as bounced
        FROM email_send_log
        WHERE sent_at IS NOT NULL
    ');
    $overall = $overallStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($overall['total_sent'] > 0) {
        $overall['delivery_rate'] = round(($overall['delivered'] / $overall['total_sent']) * 100, 1);
        $overall['open_rate'] = $overall['delivered'] > 0 ? round(($overall['opened'] / $overall['delivered']) * 100, 1) : 0;
        $overall['click_rate'] = $overall['delivered'] > 0 ? round(($overall['clicked'] / $overall['delivered']) * 100, 1) : 0;
        $overall['bounce_rate'] = round(($overall['bounced'] / $overall['total_sent']) * 100, 1);
    }
    
    // By event
    $byEventStmt = $pdo->query('
        SELECT 
            event_key,
            COUNT(*) as sent,
            SUM(CASE WHEN status IN ("delivered","opened","clicked") THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status IN ("opened","clicked") THEN 1 ELSE 0 END) as opened,
            SUM(CASE WHEN status = "clicked" THEN 1 ELSE 0 END) as clicked,
            SUM(CASE WHEN status = "bounced" THEN 1 ELSE 0 END) as bounced
        FROM email_send_log
        WHERE sent_at IS NOT NULL AND event_key IS NOT NULL
        GROUP BY event_key
        ORDER BY sent DESC
    ');
    $byEvent = $byEventStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($byEvent as &$row) {
        $row['open_rate'] = $row['delivered'] > 0 ? round(($row['opened'] / $row['delivered']) * 100, 1) : 0;
        $row['click_rate'] = $row['delivered'] > 0 ? round(($row['clicked'] / $row['delivered']) * 100, 1) : 0;
    }
    
    // By template
    $byTemplateStmt = $pdo->query('
        SELECT 
            e.template_id,
            t.name as template_name,
            COUNT(*) as sent,
            SUM(CASE WHEN e.status IN ("delivered","opened","clicked") THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN e.status IN ("opened","clicked") THEN 1 ELSE 0 END) as opened,
            SUM(CASE WHEN e.status = "clicked" THEN 1 ELSE 0 END) as clicked
        FROM email_send_log e
        LEFT JOIN email_templates t ON t.id = e.template_id
        WHERE e.sent_at IS NOT NULL AND e.template_id IS NOT NULL
        GROUP BY e.template_id, t.name
        ORDER BY sent DESC
    ');
    $byTemplate = $byTemplateStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($byTemplate as &$row) {
        $row['open_rate'] = $row['delivered'] > 0 ? round(($row['opened'] / $row['delivered']) * 100, 1) : 0;
        $row['click_rate'] = $row['delivered'] > 0 ? round(($row['clicked'] / $row['delivered']) * 100, 1) : 0;
    }
    
    // Recent emails
    $recentStmt = $pdo->query('
        SELECT 
            recipient_email,
            subject,
            event_key,
            status,
            sent_at,
            open_count,
            click_count
        FROM email_send_log
        WHERE sent_at IS NOT NULL
        ORDER BY sent_at DESC
        LIMIT 100
    ');
    $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'overall' => $overall,
        'by_event' => $byEvent,
        'by_template' => $byTemplate,
        'recent' => $recent
    ]);
    
} catch (Throwable $e) {
    error_log('[email/analytics] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}






