<?php
$SEO = [
    'title' => 'Tools Access — Admin',
    'description' => 'Manage passwords for public tools access',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_once __DIR__ . '/../required/config.php';

require_admin_or_redirect('/login.php');

$pdo = db();
$msg = '';

// Handle create/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $label = trim($_POST['label'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $note = trim($_POST['note'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if ($password === '') throw new Exception('Password required');
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO tools_access_keys (label, password_hash, note, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$label, $hash, $note, $is_active]);
            $msg = 'Access key created';
        } elseif ($action === 'toggle') {
            $id = intval($_POST['id'] ?? 0);
            $is_active = intval($_POST['is_active'] ?? 0);
            $pdo->prepare('UPDATE tools_access_keys SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([$is_active, $id]);
            $msg = 'Access key updated';
        } elseif ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM tools_access_keys WHERE id = ?')->execute([$id]);
            $msg = 'Access key deleted';
        }
        if ($msg) flash_set('success', $msg);
        header('Location: /admin/tools-access-keys.php');
        exit;
    } catch (Throwable $e) {
        flash_set('error', 'Error: ' . $e->getMessage());
        header('Location: /admin/tools-access-keys.php');
        exit;
    }
}

$keys = $pdo->query('SELECT * FROM tools_access_keys ORDER BY id DESC')->fetchAll();
?>

<div class="container">
    <?php require __DIR__ . '/admin-menu.php'; ?>
    <div class="reference-card" style="background:white; border-radius:12px; box-shadow:0 4px 16px rgba(0,0,0,0.1);">
        <div class="card-header" style="background:linear-gradient(135deg,#0ea5e9,#0284c7); color:white; padding:20px 24px; border-radius:12px 12px 0 0;">
            <h1 style="margin:0;">🔑 Tools Access Keys</h1>
            <p style="margin:8px 0 0 0; opacity:.9;">Create and manage passwords to unlock public tools on the homepage.</p>
        </div>
        <div class="card-body" style="padding:24px;">
            <h3>Create New Key</h3>
            <form method="post" style="display:grid; grid-template-columns:1fr 1fr 2fr auto; gap:12px; align-items:end;">
                <input type="hidden" name="action" value="create">
                <label><span style="display:block; font-size:12px; color:#6b7280;">Label</span><input name="label"></label>
                <label><span style="display:block; font-size:12px; color:#6b7280;">Password</span><input name="password" required></label>
                <label><span style="display:block; font-size:12px; color:#6b7280;">Note</span><input name="note"></label>
                <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" name="is_active" checked> Active</label>
                <div style="grid-column: 1 / -1;"><button class="btn" style="background:#059669; color:white;">Create</button></div>
            </form>

            <h3 style="margin-top:24px;">Existing Keys</h3>
            <table class="bulk-table" style="width:100%;">
                <thead><tr><th>ID</th><th>Label</th><th>Note</th><th>Active</th><th>Created</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($keys as $k): ?>
                    <tr>
                        <td><?php echo $k['id']; ?></td>
                        <td><?php echo htmlspecialchars($k['label']); ?></td>
                        <td><?php echo htmlspecialchars($k['note']); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
                                <input type="hidden" name="is_active" value="<?php echo $k['is_active'] ? 0 : 1; ?>">
                                <button class="btn secondary" type="submit"><?php echo $k['is_active'] ? 'Disable' : 'Enable'; ?></button>
                            </form>
                        </td>
                        <td><?php echo htmlspecialchars($k['created_at']); ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Delete this key?');" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
                                <button class="btn" style="background:#dc2626; color:white;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>


