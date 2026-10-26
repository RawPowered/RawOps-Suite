<?php
$SEO = [
    'title' => 'Notes — Sales Agent CRM — Product',
    'description' => 'Add and manage notes for leads and accounts.',
    'type' => 'website'
];

// Early JSON handler for POST (avoid any HTML output before JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../required/config.php';
    try { start_session(); } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $uid = current_user_id();
        if (!$uid) { echo json_encode(['ok' => false, 'message' => 'auth']); exit; }
        $pdo = db();
        $isAdminSimulation = isset($_SESSION['admin_simulation']);
        $action = $_POST['action'] ?? '';
        if ($action === 'add_note') {
            $leadId = !empty($_POST['lead_id']) ? intval($_POST['lead_id']) : null;
            $accountId = !empty($_POST['account_id']) ? intval($_POST['account_id']) : null;
            $content = trim($_POST['content'] ?? '');
            if ($content === '') throw new Exception('Please enter a note.');

            // Access check for lead ownership
            if (!$isAdminSimulation && $leadId) {
                $st = $pdo->prepare('SELECT 1 FROM crm_leads WHERE id = ? AND assigned_agent_id = ?');
                $st->execute([$leadId, $uid]);
                if (!$st->fetch()) throw new Exception('Access denied to this lead.');
            }

            $st = $pdo->prepare('INSERT INTO crm_notes (agent_id, lead_id, account_id, note_type, content, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $st->execute([$uid, $leadId, $accountId, $_POST['note_type'] ?: 'general', $content]);

            echo json_encode(['ok' => true]);
            exit;
        }
        echo json_encode(['ok' => false, 'message' => 'invalid_action']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

require_once __DIR__ . '/../../required/header.php';

start_session();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

$isAdminSimulation = isset($_SESSION['admin_simulation']);
$pdo = db();

$isAjaxFetch = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && stripos($_SERVER['HTTP_X_REQUESTED_WITH'], 'fetch') !== false);
$isModal = isset($_GET['modal']) || isset($_POST['modal']);

// Data for dropdowns
$leads = [];
$accounts = [];
try {
    $q = 'SELECT id, CONCAT(first_name, " ", last_name) as name, company_name FROM crm_leads';
    if (!$isAdminSimulation) {
        $q .= ' WHERE assigned_agent_id = ? ORDER BY first_name, last_name';
        $st = $pdo->prepare($q); $st->execute([$uid]);
    } else { $st = $pdo->prepare($q . ' ORDER BY first_name, last_name'); $st->execute(); }
    $leads = $st->fetchAll();

    $st = $pdo->prepare('SELECT wa.id, wa.business_name FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id WHERE wa.approved = 1 ORDER BY wa.business_name');
    $st->execute();
    $accounts = $st->fetchAll();
} catch (Throwable $e) {}

// Modal fragment
if ($isModal && ($_GET['action'] ?? '') === 'add') {
    $preLead = intval($_GET['lead_id'] ?? 0);
    $preAcc = intval($_GET['account_id'] ?? 0);
    ?>
    <div style="padding:20px">
        <h3 style="margin:0 0 16px 0;">Add Note</h3>
        <form method="post" data-ajax style="display:grid; gap:16px;">
            <input type="hidden" name="modal" value="1">
            <input type="hidden" name="action" value="add_note">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div>
                    <label>Lead</label>
                    <select name="lead_id" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                        <option value="">Select Lead (optional)</option>
                        <?php foreach ($leads as $l): ?>
                            <option value="<?php echo $l['id']; ?>" <?php echo ($preLead==$l['id']?'selected':''); ?>>
                                <?php echo htmlspecialchars($l['name']); ?><?php if ($l['company_name']): ?> - <?php echo htmlspecialchars($l['company_name']); ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Account</label>
                    <select name="account_id" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                        <option value="">Select Account (optional)</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?php echo $a['id']; ?>" <?php echo ($preAcc==$a['id']?'selected':''); ?>><?php echo htmlspecialchars($a['business_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label>Type</label>
                <select name="note_type" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                    <?php foreach (['general','status_change','internal'] as $t): ?>
                    <option value="<?php echo $t; ?>"><?php echo ucwords(str_replace('_',' ',$t)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Content</label>
                <textarea name="content" rows="5" placeholder="Write your note..." style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; resize:vertical;"></textarea>
            </div>
            <div style="display:flex; gap:12px; justify-content:end;">
                <button type="button" class="btn secondary" onclick="window.SalesACPModals && SalesACPModals.close()">Cancel</button>
                <button type="submit" class="btn" style="background:#059669;">Add Note</button>
            </div>
        </form>
    </div>
    <?php
    exit;
}

?>

<div class="container">
    <?php $currentPage = basename($_SERVER['PHP_SELF'] ?? ''); require_once __DIR__ . '/../../required/sales-acp-nav.php'; ?>
    <?php require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php'; ?>
    <div class="card" style="text-align:center; padding:40px;">
        <h2>Notes</h2>
        <p>Use the UI to add notes via modals from Leads or Accounts.</p>
    </div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>


