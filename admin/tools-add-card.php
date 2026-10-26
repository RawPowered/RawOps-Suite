<?php
require_once __DIR__ . '/../required/config.php';
require_once __DIR__ . '/../required/admin/wholesale-permissions.php';
require_admin_or_redirect('/admin/');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId = intval($_POST['account_id'] ?? 0);
    $viewerId = current_user_id();
    if (!$accountId) { flash_set('error', 'Missing account.'); redirect('/admin/wholesale-accounts.php'); }
    if (!user_can_edit_full_payment($viewerId, $accountId)) { flash_set('error', 'Not authorized.'); redirect('/admin/wholesale-accounts.php'); }

    $cardHolder = trim($_POST['card_holder_name'] ?? '');
    $brand = trim($_POST['card_brand'] ?? 'Card');
    $pan = preg_replace('/\D+/', '', $_POST['card_number'] ?? '');
    $expMonth = substr(preg_replace('/\D+/', '', $_POST['exp_month'] ?? ''), 0, 2);
    $expYear = substr(preg_replace('/\D+/', '', $_POST['exp_year'] ?? ''), 0, 4);

    if (strlen($pan) < 12 || strlen($pan) > 19) {
        flash_set('error', 'Invalid card number.');
        redirect('/admin/wholesale-accounts.php?view=detail&id=' . $accountId);
    }

    $last4 = substr($pan, -4);
    $enc = secure_encrypt($pan);

    try {
        $stmt = $pdo->prepare('INSERT INTO payment_methods (user_id, wholesale_account_id, type, card_brand, last_four, exp_month, exp_year, token, card_holder_name, card_enc, is_default, created_at) VALUES ((SELECT user_id FROM wholesale_accounts WHERE id = ?), ?, "credit_card", ?, ?, ?, ?, NULL, ?, ?, 0, NOW())');
        $stmt->execute([$accountId, $accountId, $brand, $last4, $expMonth, $expYear, $cardHolder, $enc]);
        flash_set('success', 'Card saved securely.');
    } catch (Throwable $e) {
        flash_set('error', 'Failed to save card.');
    }
}

redirect('/admin/wholesale-accounts.php?view=detail&id=' . intval($_POST['account_id'] ?? 0));
?>


