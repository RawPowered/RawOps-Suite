<?php
$renderPartial = isset($_GET['partial']);

if (!$renderPartial) {
    $SEO = [
        'title' => 'Invoice Detail — Sales Agent — Product',
        'description' => 'View invoice details, items, totals and commissions.',
        'type' => 'website'
    ];
    require_once __DIR__ . '/../../required/header.php';
} else {
    require_once __DIR__ . '/../../required/config.php';
}

start_session();
$pdo = db();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

$isAdminSimulation = isset($_SESSION['admin_simulation']);
$isAdmin = current_admin_id() ? true : false;
$adminMode = !empty($_SESSION['sales_acp_admin_mode']);
$hasFullAccess = $isAdminSimulation || ($isAdmin && $adminMode);

$orderId = intval($_GET['id'] ?? 0);
if ($orderId <= 0) { redirect('invoices.php'); }

$detail = null; $items = []; $commissions = [];
try {
	$sql = 'SELECT o.*, wa.business_name, u.first_name, u.last_name, u.email
			FROM orders o
			LEFT JOIN wholesale_accounts wa ON wa.id = o.wholesale_account_id
			LEFT JOIN users u ON u.id = o.user_id
			WHERE o.id = ?';
	$st = $pdo->prepare($sql);
	$st->execute([$orderId]);
	$detail = $st->fetch();
	if (!$detail) throw new Exception('Invoice not found');

	if (!$hasFullAccess) {
		$allowed = (intval($detail['sales_agent_id']) === intval($uid));
		if (!$allowed && intval($detail['wholesale_account_id'])) {
			$st2 = $pdo->prepare('SELECT sales_agent_id FROM wholesale_accounts WHERE id = ?');
			$st2->execute([intval($detail['wholesale_account_id'])]);
			$aid = intval($st2->fetchColumn());
			$allowed = ($aid === intval($uid));
		}
		if (!$allowed) { flash_set('error', 'Access denied.'); redirect('invoices.php'); }
	}

	$it = $pdo->prepare('SELECT product_name, product_sku, quantity, unit_price, line_total FROM order_items WHERE order_id = ? ORDER BY id');
	$it->execute([$orderId]);
	$items = $it->fetchAll();

	$cm = $pdo->prepare('SELECT commission_type, user_id, amount, percentage FROM commissions WHERE order_id = ?');
	$cm->execute([$orderId]);
	while ($r = $cm->fetch()) { $commissions[] = $r; }

} catch (Throwable $e) {
	flash_set('error', 'Invoice not found.');
	redirect('invoices.php');
}

$agentCommission = 0.0; $developerCommission = 0.0; $aiCommission = 0.0; $sourceEmailId = null;
foreach ($commissions as $c) {
	if (($c['commission_type'] ?? '') === 'sales_agent' && intval($c['user_id']) === intval($detail['sales_agent_id'])) {
		$agentCommission += floatval($c['amount']);
		if (!empty($c['source_email_id'])) {
			$sourceEmailId = intval($c['source_email_id']);
		}
	} elseif (($c['commission_type'] ?? '') === 'developer') {
		$developerCommission += floatval($c['amount']);
	} elseif (($c['commission_type'] ?? '') === 'ai_assist') {
		$aiCommission += floatval($c['amount']);
	}
}

// Get source email details if available
$sourceEmail = null;
if ($sourceEmailId) {
	try {
		$emailStmt = $pdo->prepare('SELECT subject, is_ai_assisted, sent_at FROM agent_emails WHERE id = ?');
		$emailStmt->execute([$sourceEmailId]);
		$sourceEmail = $emailStmt->fetch();
	} catch (Throwable $e) {}
}
?>

<?php if (!$renderPartial): ?>
<div class="container">
    <?php 
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php';
    ?>
<?php endif; ?>

    <div class="sacp_card" style="margin-bottom: 16px; border-left:4px solid #3b82f6;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h1 style="margin:0; display:flex; align-items:center; gap:8px;">
                    <span>🧾</span> <?php echo htmlspecialchars($detail['order_number']); ?>
                </h1>
                <div class="muted" style="margin-top:4px;">
                    <?php echo date('M j, Y g:i A', strtotime($detail['created_at'])); ?> • 
                    <?php echo ucfirst($detail['status']); ?>
                </div>
            </div>
            <?php if (!$renderPartial): ?>
            <div style="display:flex; gap:10px;">
                <a href="invoices.php" class="btn secondary">← Back to Invoices</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

	<div class="sacp_card">
		<div style="display:grid; grid-template-columns: 2fr 1fr; gap: 20px;">
			<div>
				<h3 style="margin:0 0 12px 0;">Items</h3>
				<div style="border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
					<table style="width:100%; border-collapse:collapse;">
						<thead>
							<tr style="background:#f9fafb; border-bottom:1px solid #e5e7eb;">
								<th style="text-align:left; padding:10px;">Product</th>
								<th style="text-align:left; padding:10px;">SKU</th>
								<th style="text-align:right; padding:10px;">Qty</th>
								<th style="text-align:right; padding:10px;">Unit</th>
								<th style="text-align:right; padding:10px;">Line Total</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($items as $it): ?>
							<tr style="border-bottom:1px solid #e5e7eb;">
								<td style="padding:10px;">&nbsp;<?php echo htmlspecialchars($it['product_name']); ?></td>
								<td style="padding:10px;">&nbsp;<?php echo htmlspecialchars($it['product_sku']); ?></td>
								<td style="padding:10px; text-align:right;"><?php echo intval($it['quantity']); ?></td>
								<td style="padding:10px; text-align:right;">$<?php echo number_format((float)$it['unit_price'], 2); ?></td>
								<td style="padding:10px; text-align:right;">$<?php echo number_format((float)$it['line_total'], 2); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
            <div>
                <h3 style="margin:0 0 12px 0;">Summary</h3>
				<div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
					<div class="muted">Subtotal</div><div style="text-align:right; font-weight:600;">$<?php echo number_format((float)$detail['subtotal'], 2); ?></div>
					<div class="muted">Tax</div><div style="text-align:right; font-weight:600;">$<?php echo number_format((float)$detail['tax'], 2); ?></div>
					<div class="muted">Shipping</div><div style="text-align:right; font-weight:600;">$<?php echo number_format((float)$detail['shipping'], 2); ?></div>
					<div style="border-top:1px solid #e5e7eb; margin-top:6px;"></div><div style="border-top:1px solid #e5e7eb; margin-top:6px;"></div>
					<div>Total</div><div style="text-align:right; font-weight:700;">$<?php echo number_format((float)$detail['total'], 2); ?></div>
				</div>

                <h3 style="margin:16px 0 8px 0;">Commission</h3>
                <div style="border:1px solid #e5e7eb; border-radius:8px; padding:10px; background:#f9fafb;">
					<?php if ($sourceEmail && $sourceEmail['is_ai_assisted']): ?>
					<div style="background:#dbeafe; border:1px solid #93c5fd; border-radius:6px; padding:8px; margin-bottom:8px;">
						<div style="font-size:12px; color:#1e40af; font-weight:600;">
							🤖 AI-Assisted Sale
						</div>
						<div style="font-size:11px; color:#1e3a8a; margin-top:2px;">
							Email: "<?php echo htmlspecialchars(substr($sourceEmail['subject'], 0, 50)); ?>" • 
							Sent: <?php echo date('M j, Y', strtotime($sourceEmail['sent_at'])); ?>
						</div>
					</div>
					<?php endif; ?>
					<div style="display:flex; justify-content:space-between;">
						<div>Your Commission</div>
						<div style="font-weight:700; color:#059669; font-size:18px;">$<?php echo number_format($agentCommission, 2); ?></div>
					</div>
					
					<?php 
					// Check if agent has AI charges for this order
					try {
						$stmt = $pdo->prepare('SELECT total_ai_cost, charged_to_agent, ai_call_count, ai_email_count, ai_sms_count FROM order_ai_charges WHERE order_id = ? AND charged_to_agent = 1');
						$stmt->execute([$invoice['id']]);
						$aiCharge = $stmt->fetch();
						if ($aiCharge && $aiCharge['total_ai_cost'] > 0):
					?>
					<div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb;">
						<div style="font-size:12px; font-weight:600; color:#92400e; margin-bottom:4px;">AI Usage Charge</div>
						<div style="display:flex; justify-content:space-between; font-size:12px; color:#6b7280;">
							<div>AI Calls: <?php echo $aiCharge['ai_call_count']; ?> • Emails: <?php echo $aiCharge['ai_email_count']; ?> • SMS: <?php echo $aiCharge['ai_sms_count']; ?></div>
							<div style="color:#92400e; font-weight:600;">-$<?php echo number_format($aiCharge['total_ai_cost'], 2); ?></div>
						</div>
					</div>
					<?php 
						endif;
					} catch (Throwable $e) {}
					?>
					
					<?php if ($hasFullAccess): ?>
					<!-- Admin-only view: Show full breakdown -->
					<div style="margin-top:12px; padding-top:12px; border-top:1px solid #e5e7eb;">
						<div style="font-size:11px; font-weight:600; color:#6b7280; margin-bottom:6px;">ADMIN VIEW - CASCADING BREAKDOWN</div>
						<div style="display:flex; justify-content:space-between; font-size:12px;">
							<div>Agent Commission (from subtotal)</div>
							<div style="font-weight:600;">$<?php echo number_format($agentCommission, 2); ?></div>
						</div>
						<?php if ($developerCommission > 0): ?>
						<div style="display:flex; justify-content:space-between; margin-top:4px; font-size:12px;">
							<div>Developer Fee (from remaining)</div>
							<div style="font-weight:600;">$<?php echo number_format($developerCommission, 2); ?></div>
						</div>
						<?php endif; ?>
						<?php if ($aiCommission > 0): ?>
						<div style="display:flex; justify-content:space-between; margin-top:4px; font-size:12px;">
							<div>AI Commission (from remaining)</div>
							<div style="font-weight:600;">$<?php echo number_format($aiCommission, 2); ?></div>
						</div>
						<?php endif; ?>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
<?php if (!$renderPartial): ?>
</div>
<?php require_once __DIR__ . '/../../required/footer.php'; ?>
<?php endif; ?>
