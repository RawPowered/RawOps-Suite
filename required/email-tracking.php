<?php

require_once __DIR__ . '/config.php';

/**
 * Log email send to database
 * @param array $emailData Email details
 * @return int|null Email log ID
 */
function log_email_send(array $emailData): ?int {
    try {
        $pdo = db();
        
        $stmt = $pdo->prepare('
            INSERT INTO email_send_log (
                message_id, user_id, recipient_email, recipient_name,
                sender_email, sender_name, subject, template_id, event_key,
                status, sendgrid_status_code, metadata, sent_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        
        $stmt->execute([
            $emailData['message_id'] ?? null,
            $emailData['user_id'] ?? null,
            $emailData['recipient_email'],
            $emailData['recipient_name'] ?? null,
            $emailData['sender_email'],
            $emailData['sender_name'] ?? null,
            $emailData['subject'],
            $emailData['template_id'] ?? null,
            $emailData['event_key'] ?? null,
            $emailData['status'] ?? 'sent',
            $emailData['status_code'] ?? null,
            isset($emailData['metadata']) ? json_encode($emailData['metadata']) : null
        ]);
        
        return (int)$pdo->lastInsertId();
        
    } catch (Throwable $e) {
        error_log('[email-tracking] log_email_send error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Update email status (delivered, opened, clicked, bounced)
 * @param string $messageId SendGrid message ID
 * @param string $status New status
 * @param array $metadata Additional data
 * @return bool Success
 */
function update_email_status(string $messageId, string $status, array $metadata = []): bool {
    try {
        $pdo = db();
        
        // Find email by message ID
        $stmt = $pdo->prepare('SELECT id FROM email_send_log WHERE message_id = ? LIMIT 1');
        $stmt->execute([$messageId]);
        $emailLogId = $stmt->fetchColumn();
        
        if (!$emailLogId) {
            return false;
        }
        
        // Update status and timestamp
        $updates = ['status = ?'];
        $params = [$status];
        
        switch ($status) {
            case 'delivered':
                $updates[] = 'delivered_at = NOW()';
                break;
            case 'opened':
                $updates[] = 'opened_at = COALESCE(opened_at, NOW())';
                $updates[] = 'open_count = open_count + 1';
                break;
            case 'clicked':
                $updates[] = 'first_clicked_at = COALESCE(first_clicked_at, NOW())';
                $updates[] = 'click_count = click_count + 1';
                break;
            case 'bounced':
                $updates[] = 'bounced_at = NOW()';
                break;
        }
        
        $params[] = $emailLogId;
        $sql = 'UPDATE email_send_log SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute($params);
        
        // Log interaction
        if (in_array($status, ['opened', 'clicked', 'bounced', 'spam'], true)) {
            $pdo->prepare('
                INSERT INTO email_interactions (email_log_id, interaction_type, ip_address, user_agent, clicked_url, metadata)
                VALUES (?, ?, ?, ?, ?, ?)
            ')->execute([
                $emailLogId,
                $status === 'spam' ? 'spam_report' : $status,
                $metadata['ip'] ?? null,
                $metadata['user_agent'] ?? null,
                $metadata['url'] ?? null,
                json_encode($metadata)
            ]);
        }
        
        return true;
        
    } catch (Throwable $e) {
        error_log('[email-tracking] update_email_status error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get email analytics for a template or event
 * @param string $type 'template' or 'event'
 * @param int|string $identifier Template ID or event key
 * @return array Analytics data
 */
function get_email_analytics(string $type, $identifier): array {
    try {
        $pdo = db();
        
        if ($type === 'template') {
            $stmt = $pdo->prepare('
                SELECT 
                    COUNT(*) as total_sent,
                    SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status = "opened" THEN 1 ELSE 0 END) as opened,
                    SUM(CASE WHEN status = "clicked" THEN 1 ELSE 0 END) as clicked,
                    SUM(CASE WHEN status = "bounced" THEN 1 ELSE 0 END) as bounced,
                    SUM(open_count) as total_opens,
                    SUM(click_count) as total_clicks,
                    ROUND(AVG(CASE WHEN opened_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, sent_at, opened_at) END), 2) as avg_minutes_to_open
                FROM email_send_log
                WHERE template_id = ? AND sent_at IS NOT NULL
            ');
            $stmt->execute([$identifier]);
        } else {
            $stmt = $pdo->prepare('
                SELECT 
                    COUNT(*) as total_sent,
                    SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status = "opened" THEN 1 ELSE 0 END) as opened,
                    SUM(CASE WHEN status = "clicked" THEN 1 ELSE 0 END) as clicked,
                    SUM(CASE WHEN status = "bounced" THEN 1 ELSE 0 END) as bounced,
                    SUM(open_count) as total_opens,
                    SUM(click_count) as total_clicks,
                    ROUND(AVG(CASE WHEN opened_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, sent_at, opened_at) END), 2) as avg_minutes_to_open
                FROM email_send_log
                WHERE event_key = ? AND sent_at IS NOT NULL
            ');
            $stmt->execute([$identifier]);
        }
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['total_sent'] > 0) {
            $result['delivery_rate'] = round(($result['delivered'] / $result['total_sent']) * 100, 2);
            $result['open_rate'] = $result['delivered'] > 0 ? round(($result['opened'] / $result['delivered']) * 100, 2) : 0;
            $result['click_rate'] = $result['delivered'] > 0 ? round(($result['clicked'] / $result['delivered']) * 100, 2) : 0;
            $result['bounce_rate'] = round(($result['bounced'] / $result['total_sent']) * 100, 2);
        }
        
        return $result ?: [];
        
    } catch (Throwable $e) {
        error_log('[email-tracking] get_email_analytics error: ' . $e->getMessage());
        return [];
    }
}






