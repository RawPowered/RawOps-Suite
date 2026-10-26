<?php
$SEO = [
    'title' => 'AI Chat Log Details — Product Admin',
    'description' => 'Review a specific conversation with the AI Sales Assistant.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect();

$sessionId = $_GET['session_id'] ?? null;
if (!$sessionId) {
    redirect(path_with_base('/admin/ai-chat-logs.php'));
}

$pdo = db();
$stmt = $pdo->prepare("
    SELECT l.*, u.first_name, u.last_name, u.email
    FROM ai_chat_logs l
    LEFT JOIN users u ON u.id = l.user_id
    WHERE l.session_id = ?
    ORDER BY l.created_at ASC
");
$stmt->execute([$sessionId]);
$logs = $stmt->fetchAll();

if (empty($logs)) {
    flash_set('error', 'Chat session not found.');
    redirect(path_with_base('/admin/ai-chat-logs.php'));
}

$userInfo = $logs[0]['email']
    ? htmlspecialchars($logs[0]['first_name'] . ' ' . $logs[0]['last_name'] . ' (' . $logs[0]['email'] . ')')
    : 'Guest (' . htmlspecialchars($logs[0]['ip_address']) . ')';
?>

<style>
    .chat-transcript { max-width: 900px; margin: 0 auto; }
    .message-bubble { padding: 12px 16px; border-radius: 18px; max-width: 80%; margin-bottom: 12px; }
    .user-message { background-color: #dbeafe; color: #1e40af; align-self: flex-end; border-bottom-right-radius: 4px; margin-left: auto; }
    .ai-message { background-color: #f0fdf4; color: #14532d; align-self: flex-start; border-bottom-left-radius: 4px; }
    .feedback-icon { font-size: 10px; margin-left: 8px; }
    .context-box { background-color: #f9fafb; border: 1px solid #e5e7eb; padding: 12px; margin-top: 8px; border-radius: 8px; font-size: 12px; white-space: pre-wrap; max-height: 200px; overflow-y: auto; }
</style>

<div class="container" style="padding-top: 30px; padding-bottom: 30px;">
    <p><a href="<?php echo path_with_base('/admin/ai-chat-logs.php'); ?>">&larr; Back to All Logs</a></p>
    <h1 style="margin-bottom: 5px;">Chat Transcript</h1>
    <p style="color: #6b7280; margin-bottom: 20px;">
        <strong>User:</strong> <?= $userInfo ?><br>
        <strong>Session ID:</strong> <code><?= htmlspecialchars($sessionId) ?></code>
    </p>

    <div class="card chat-transcript">
        <div style="padding: 20px; display: flex; flex-direction: column;">
            <?php foreach ($logs as $log): ?>
                <?php if ($log['user_message']): ?>
                    <div class="message-bubble user-message">
                        <?= htmlspecialchars($log['user_message']) ?>
                        <div style="font-size: 10px; color: #6b7280; text-align: right; margin-top: 4px;"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($log['ai_response']): ?>
                    <div class="message-bubble ai-message">
                        <?= htmlspecialchars($log['ai_response']) ?>
                        <?php if ($log['feedback'] == 1): ?><span class="feedback-icon">👍</span><?php endif; ?>
                        <?php if ($log['feedback'] == -1): ?><span class="feedback-icon">👎</span><?php endif; ?>
                        
                        <?php if ($log['prompt_context']): ?>
                            <details class="context-box">
                                <summary style="cursor: pointer; font-weight: bold; font-size: 12px;">View Context Provided to AI</summary>
                                <pre><?= htmlspecialchars($log['prompt_context']) ?></pre>
                            </details>
                        <?php endif; ?>
                        <div style="font-size: 10px; color: #6b7280; margin-top: 4px;"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
