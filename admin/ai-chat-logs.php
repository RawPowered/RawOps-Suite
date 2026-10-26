<?php
$SEO = [
    'title' => 'AI Chat Logs — Product Admin',
    'description' => 'Review and analyze conversations with the AI Sales Assistant.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect();

$pdo = db();
$sessions = $pdo->query("
    SELECT 
        l.session_id,
        MIN(l.created_at) as start_time,
        COUNT(l.id) as message_count,
        u.first_name,
        u.last_name,
        u.email,
        l.ip_address
    FROM ai_chat_logs l
    LEFT JOIN users u ON u.id = l.user_id
    GROUP BY l.session_id
    ORDER BY start_time DESC
")->fetchAll();

?>

<div class="container" style="padding-top: 30px; padding-bottom: 30px;">
    <h1 style="margin-bottom: 20px;">AI Assistant Chat Logs</h1>

    <div class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>Session ID</th>
                    <th>User</th>
                    <th>Started At</th>
                    <th>Messages</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sessions)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center;">No chat sessions found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sessions as $session): ?>
                        <tr>
                            <td>
                                <code title="<?= htmlspecialchars($session['session_id']) ?>">
                                    <?= substr($session['session_id'], 0, 8) ?>...
                                </code>
                            </td>
                            <td>
                                <?php if ($session['email']): ?>
                                    <strong><?= htmlspecialchars($session['first_name'] . ' ' . $session['last_name']) ?></strong><br>
                                    <small><?= htmlspecialchars($session['email']) ?></small>
                                <?php else: ?>
                                    <strong>Guest</strong><br>
                                    <small>IP: <?= htmlspecialchars($session['ip_address']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= date('Y-m-d H:i:s', strtotime($session['start_time'])) ?></td>
                            <td><?= $session['message_count'] ?></td>
                            <td>
                                <a href="ai-chat-log-view.php?session_id=<?= urlencode($session['session_id']) ?>" class="btn btn-sm">
                                    View Log
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>
