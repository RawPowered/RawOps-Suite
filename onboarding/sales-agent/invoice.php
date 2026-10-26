<?php
$SEO = [
	'title' => 'Create Invoice — Sales Agent — Product',
	'description' => 'Build a customer invoice, auto-create accounts, and submit wholesale orders.',
	'type' => 'website'
];

require_once __DIR__ . '/../../required/header.php';
require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/commission-utils.php';

start_session();

$pdo = db();
$uid = current_user_id();
if (!$uid) redirect(path_with_base('/index.php'));

// Access control: sales_agent role or admin simulation
$isAdminSimulation = isset($_SESSION['admin_simulation']);
if (!$isAdminSimulation) {
    $stmt = $pdo->prepare('SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = "sales_agent"');
    try { $stmt->execute([$uid]); $hasRole = (bool)$stmt->fetch(); } catch (Throwable $e) { $hasRole = false; }
    if (!$hasRole && !is_training_complete($uid)) {
        redirect(path_with_base('/onboarding/trainee/index.php'));
    }
}

// Optional: prefill from lead
$prefill = [
	'business_name' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '',
	'address_line1' => '', 'city' => '', 'state_region' => '', 'postal_code' => '', 'country' => 'USA'
];
$leadId = intval($_GET['lead_id'] ?? ($_POST['lead_id'] ?? 0));
$accountPrefillId = intval($_GET['account_id'] ?? 0);
if ($leadId > 0) {
	try {
		$stmt = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
		$stmt->execute([$leadId]);
		$lead = $stmt->fetch();
		if ($lead) {
			$prefill['business_name'] = trim($lead['company_name'] ?? '') ?: '';
			$prefill['first_name'] = trim($lead['first_name'] ?? '');
			$prefill['last_name'] = trim($lead['last_name'] ?? '');
			$prefill['email'] = trim($lead['email'] ?? '');
			$prefill['phone'] = trim($lead['phone'] ?? '');
			// Address prefill from lead (map to invoice fields)
			$prefill['address_line1'] = trim(($lead['address_line1'] ?? '') !== '' ? $lead['address_line1'] : ($lead['address'] ?? ''));
			$prefill['city'] = trim($lead['city'] ?? '');
			$prefill['state_region'] = trim(($lead['state_region'] ?? ($lead['state'] ?? '')));
			$prefill['postal_code'] = trim(($lead['postal_code'] ?? ($lead['zip_code'] ?? '')));
			$prefill['country'] = trim($lead['country'] ?? 'USA');
		}
	} catch (Throwable $e) {}
}

// Optional: prefill from account
if ($accountPrefillId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT wa.*, u.first_name AS user_first_name, u.last_name AS user_last_name, u.email AS user_email, u.phone AS user_phone FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id WHERE wa.id = ?');
        $stmt->execute([$accountPrefillId]);
        $acc = $stmt->fetch();
        if ($acc) {
            $prefill['business_name'] = trim($acc['business_name'] ?? '') ?: '';
            // Prefer primary account contact; fallback to user, then leave as-is
            $prefill['first_name'] = trim($acc['user_first_name'] ?? '');
            $prefill['last_name'] = trim($acc['user_last_name'] ?? '');
            $prefill['email'] = trim($acc['user_email'] ?? '');
            // Prefer store-level phone if present; fallback to user phone
            $prefill['phone'] = trim(($acc['phone'] ?? '') !== '' ? $acc['phone'] : ($acc['user_phone'] ?? ''));
            $prefill['address_line1'] = trim($acc['address_line1'] ?? '');
            $prefill['city'] = trim($acc['city'] ?? '');
            $prefill['state_region'] = trim($acc['state_region'] ?? '');
            $prefill['postal_code'] = trim($acc['postal_code'] ?? '');
            $prefill['country'] = trim($acc['country'] ?? 'USA');
            // If primary contact exists, prefer their name/email/phone
            try {
                $pc = $pdo->prepare('SELECT c.first_name, c.last_name, c.email, c.phone FROM account_contacts ac JOIN crm_contacts c ON c.id = ac.contact_id WHERE ac.wholesale_account_id = ? ORDER BY ac.is_primary DESC, ac.id ASC LIMIT 1');
                $pc->execute([$accountPrefillId]);
                $contact = $pc->fetch();
                if ($contact) {
                    $prefill['first_name'] = trim($contact['first_name'] ?? $prefill['first_name']);
                    $prefill['last_name']  = trim($contact['last_name'] ?? $prefill['last_name']);
                    $prefill['email']      = trim(($prefill['email'] ?: ($contact['email'] ?? '')));
                    $prefill['phone']      = trim(($prefill['phone'] ?: ($contact['phone'] ?? '')));
                }
            } catch (Throwable $e) { /* ignore */ }
        }
    } catch (Throwable $e) {}
}

// Load active products from DB (source of truth) with product group commission info
$rows = $pdo->query("
    SELECT 
        p.id, p.sku, p.name, p.wholesale_price, p.msrp, p.product_group_id,
        pg.use_custom_commission
    FROM products p
    LEFT JOIN product_groups pg ON pg.id = p.product_group_id
    WHERE p.active = 1 
    ORDER BY p.name
")->fetchAll();
$products = [];
$productGroupCommissions = []; // Map of product_id => commission_tiers
foreach ($rows as $r) {
	$idSlug = strtolower(str_replace(['ELIX-','BLEND-'], ['elix-','blend-'], $r['sku']));
	$productId = intval($r['id']);
	$products[] = [
		'product_db_id' => $productId,
		'sku' => $r['sku'],
		'id_slug' => $idSlug,
		'name' => $r['name'],
		'wholesale_price' => (float)$r['wholesale_price'],
		'retail_price' => (float)$r['msrp']
	];
	
	// Load product group commission tiers if applicable
	if ($r['product_group_id'] && $r['use_custom_commission']) {
		$groupTiers = commission_get_product_group_tiers($pdo, $productId);
		if ($groupTiers) {
			$productGroupCommissions[$productId] = $groupTiers;
		}
	}
}

$accounts = [];
try {
    if ($isAdminSimulation || current_admin_id()) {
        $stmt = $pdo->prepare('SELECT wa.id, wa.business_name, u.first_name, u.last_name, u.email, u.phone FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id ORDER BY wa.business_name');
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare('SELECT wa.id, wa.business_name, u.first_name, u.last_name, u.email, u.phone FROM wholesale_accounts wa JOIN users u ON u.id = wa.user_id WHERE wa.sales_agent_id = ? ORDER BY wa.business_name');
        $stmt->execute([$uid]);
    }
    $accounts = $stmt->fetchAll();
} catch (Throwable $e) { $accounts = []; }

// If requested, import quantities from current cart session
$defaultQtyBySku = [];
if (!empty($_GET['import_cart'])) {
	$cart = $_SESSION['cart'] ?? [];
	if (!empty($cart)) {
		// Map id_slug -> sku
		$slugToSku = [];
		foreach ($products as $p) { $slugToSku[$p['id_slug']] = $p['sku']; }
		foreach ($cart as $ci) {
			$cartSlug = $ci['product_id'] ?? '';
			$qty = intval($ci['quantity'] ?? 0);
			if ($cartSlug !== '' && $qty > 0 && isset($slugToSku[$cartSlug])) {
				$sku = $slugToSku[$cartSlug];
				$defaultQtyBySku[$sku] = ($defaultQtyBySku[$sku] ?? 0) + $qty;
			}
		}
	}
}

$successMsg = '';
$errorMsg = '';

// Handle invoice creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_invoice') {
	try {
        $itemsJson = trim($_POST['items_json'] ?? '');
		if ($itemsJson === '') throw new Exception('No items provided.');
		$items = json_decode($itemsJson, true);
		if (!is_array($items) || count($items) === 0) throw new Exception('No line items.');

        $customer = [
			'business_name' => trim($_POST['business_name'] ?? ''),
			'first_name' => trim($_POST['first_name'] ?? ''),
			'last_name' => trim($_POST['last_name'] ?? ''),
			'email' => trim($_POST['email'] ?? ''),
			'phone' => trim($_POST['phone'] ?? ''),
			'address_line1' => trim($_POST['address_line1'] ?? ''),
			'city' => trim($_POST['city'] ?? ''),
			'state_region' => trim($_POST['state_region'] ?? ''),
			'postal_code' => trim($_POST['postal_code'] ?? ''),
			'country' => trim($_POST['country'] ?? 'USA'),
		];
        if ($customer['email'] === '') {
            // Generate a safe placeholder if email is missing; required for user creation
            $customer['email'] = 'lead+' . substr(sha1(($customer['business_name'] ?: ($customer['first_name'].' '.$customer['last_name'])) . '|' . microtime(true)), 0, 10) . '@noemail.invalid';
        }

        $pdo->beginTransaction();

        $incomingAccountId = intval($_POST['account_id'] ?? 0);
        $userId = 0; $waId = 0;
        if ($incomingAccountId > 0) {
            // Use existing account and ensure a valid user exists
            $st = $pdo->prepare('SELECT wa.id, wa.user_id FROM wholesale_accounts wa WHERE wa.id = ?');
            $st->execute([$incomingAccountId]);
            $row = $st->fetch();
            if (!$row) throw new Exception('Account not found.');
            $waId = intval($row['id']);
            $userId = intval($row['user_id']);
            if ($userId <= 0) {
                // Fallback: find/create by provided email and attach to account
                $findU = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $findU->execute([$customer['email']]);
                $userId = intval($findU->fetchColumn());
                if ($userId <= 0) {
                    $insU = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, phone, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())');
                    $insU->execute([$customer['email'], password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), $customer['first_name'], $customer['last_name'], $customer['phone']]);
                    $userId = intval($pdo->lastInsertId());
                    // Assign customer role
                    $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, (SELECT id FROM roles WHERE name = "customer")) ON DUPLICATE KEY UPDATE role_id = role_id')->execute([$userId]);
                }
                // Attach user to account
                $pdo->prepare('UPDATE wholesale_accounts SET user_id = ?, sales_agent_id = COALESCE(sales_agent_id, ?) WHERE id = ?')->execute([$userId, $uid, $waId]);
                // Reconfirm persisted user id from account to avoid race/driver quirks
                try { $rr = $pdo->prepare('SELECT user_id FROM wholesale_accounts WHERE id = ?'); $rr->execute([$waId]); $userId = intval($rr->fetchColumn()); } catch (Throwable $e3) {}
            } else {
                // Ensure agent on account
                $pdo->prepare('UPDATE wholesale_accounts SET sales_agent_id = COALESCE(sales_agent_id, ?) WHERE id = ?')->execute([$uid, $waId]);
            }
            // Absolute last guard for existing accounts: if still missing, create a placeholder user and bind
            if ($userId <= 0) {
                $genEmail = $customer['email'] ?: ('auto+' . substr(sha1(microtime(true) . '|' . rand()), 0, 10) . '@noemail.invalid');
                $ins = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, phone, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())');
                $ins->execute([$genEmail, password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), ($customer['first_name'] ?: 'Store'), ($customer['last_name'] ?: 'Customer'), $customer['phone']]);
                $userId = intval($pdo->lastInsertId());
                $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, (SELECT id FROM roles WHERE name = "customer")) ON DUPLICATE KEY UPDATE role_id = role_id')->execute([$userId]);
                $pdo->prepare('UPDATE wholesale_accounts SET user_id = ? WHERE id = ?')->execute([$userId, $waId]);
            }
        } else {
            // Find or create customer user
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$customer['email']]);
            $userId = intval($stmt->fetchColumn());
            if (!$userId) {
                $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, phone, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())');
                $stmt->execute([$customer['email'], password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), $customer['first_name'], $customer['last_name'], $customer['phone']]);
                $userId = intval($pdo->lastInsertId());
                // Assign customer role by name (avoid hardcoded IDs)
                $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, (SELECT id FROM roles WHERE name = "customer")) ON DUPLICATE KEY UPDATE role_id = role_id')->execute([$userId]);
            }

            // Find or create wholesale account (approved)
            $stmt = $pdo->prepare('SELECT id FROM wholesale_accounts WHERE user_id = ?');
            $stmt->execute([$userId]);
            $waId = intval($stmt->fetchColumn());
            if (!$waId) {
                $stmt = $pdo->prepare('INSERT INTO wholesale_accounts (user_id, business_name, phone, address_line1, city, state_region, postal_code, country, approved, created_at, sales_agent_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)');
                $businessName = $customer['business_name'] ?: trim($customer['first_name'] . ' ' . $customer['last_name']);
                $stmt->execute([$userId, $businessName, $customer['phone'], $customer['address_line1'], $customer['city'], $customer['state_region'], $customer['postal_code'], $customer['country'], $uid]);
                $waId = intval($pdo->lastInsertId());
            } else {
                $pdo->prepare('UPDATE wholesale_accounts SET business_name = COALESCE(NULLIF(?, ""), business_name), phone = COALESCE(NULLIF(?, ""), phone), address_line1 = COALESCE(NULLIF(?, ""), address_line1), city = COALESCE(NULLIF(?, ""), city), state_region = COALESCE(NULLIF(?, ""), state_region), postal_code = COALESCE(NULLIF(?, ""), postal_code), country = COALESCE(NULLIF(?, ""), country), sales_agent_id = COALESCE(sales_agent_id, ?) WHERE id = ?')
                ->execute([$customer['business_name'], $customer['phone'], $customer['address_line1'], $customer['city'], $customer['state_region'], $customer['postal_code'], $customer['country'], $uid, $waId]);
            }
        }

        // Final guard: ensure a valid user exists
        if (empty($userId) || intval($userId) <= 0) {
            // As a hard safety, create or fetch by email; if that fails, fall back to a generated email
            try {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $stmt->execute([$customer['email']]);
                $userId = intval($stmt->fetchColumn());
                if ($userId <= 0) {
                    $ins = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, phone, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())');
                    $ins->execute([$customer['email'], password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), $customer['first_name'], $customer['last_name'], $customer['phone']]);
                    $userId = intval($pdo->lastInsertId());
                    $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, (SELECT id FROM roles WHERE name = "customer")) ON DUPLICATE KEY UPDATE role_id = role_id')->execute([$userId]);
                }
            } catch (Throwable $e) {
                // Fallback generated email to guarantee a user record
                $genEmail = 'auto+' . substr(sha1(microtime(true) . '|' . rand()), 0, 10) . '@noemail.invalid';
                $ins = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, phone, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())');
                $ins->execute([$genEmail, password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), ($customer['first_name'] ?: 'Store'), ($customer['last_name'] ?: 'Customer'), $customer['phone']]);
                $userId = intval($pdo->lastInsertId());
                $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, (SELECT id FROM roles WHERE name = "customer")) ON DUPLICATE KEY UPDATE role_id = role_id')->execute([$userId]);
            }
            if ($waId > 0) {
                $pdo->prepare('UPDATE wholesale_accounts SET user_id = COALESCE(user_id, ?), sales_agent_id = COALESCE(sales_agent_id, ?) WHERE id = ?')->execute([$userId, $uid, $waId]);
            }
        }
        // Absolute invariant: userId must be a positive integer here
        if (!is_numeric($userId) || intval($userId) <= 0) {
            throw new Exception('Could not resolve or create customer identity for invoice (email: ' . htmlspecialchars($customer['email']) . ').');
        }

        // Build order items from SKUs in DB and compute discounts
		$dbItems = [];
		$totalQty = 0;
		$subtotal = 0.0;
		$findProductStmt = $pdo->prepare('SELECT id, name, sku, wholesale_price FROM products WHERE sku = ? AND active = 1');
		foreach ($items as $it) {
			$sku = trim($it['sku'] ?? '');
			$qty = max(0, intval($it['qty'] ?? 0));
			if ($sku === '' || $qty <= 0) continue;
			$findProductStmt->execute([$sku]);
			$p = $findProductStmt->fetch();
			if ($p) {
				$dbItems[] = [
					'product_id' => intval($p['id']),
					'product_name' => $p['name'],
					'product_sku' => $p['sku'],
					'quantity' => $qty,
					'base_price' => (float)$p['wholesale_price']
				];
				$totalQty += $qty;
			}
		}
		if (count($dbItems) === 0) throw new Exception('No valid items provided.');

		// Bulk discount tiers (match shop)
		$bulkDiscount = 0;
		if ($totalQty >= 20) $bulkDiscount = 15; elseif ($totalQty >= 15) $bulkDiscount = 11; elseif ($totalQty >= 10) $bulkDiscount = 8; elseif ($totalQty >= 5) $bulkDiscount = 5;

		// Compute totals
		foreach ($dbItems as &$di) {
			$effectiveUnit = $di['base_price'] * (1 - $bulkDiscount / 100);
			$di['unit_price'] = $effectiveUnit;
			$di['line_total'] = $effectiveUnit * $di['quantity'];
			$subtotal += $di['line_total'];
		}
		unset($di);

		// Shipping — use selected quote or fallback to default rule
		$shippingOverride = isset($_POST['selected_shipping_cost']) ? trim((string)$_POST['selected_shipping_cost']) : 
		                    (isset($_POST['shipping_override']) ? trim((string)$_POST['shipping_override']) : '');
		$shippingCarrier = trim($_POST['selected_carrier'] ?? '');
		$shippingService = trim($_POST['selected_service'] ?? '');
		
		if ($shippingOverride !== '' && is_numeric($shippingOverride)) {
			$shipping = max(0, round((float)$shippingOverride, 2));
		} else {
			// Default flat rate rule
			$shipping = $subtotal >= 500 ? 0 : 25;
			$shippingCarrier = $shippingCarrier ?: 'USPS';
			$shippingService = $shippingService ?: ($shipping > 0 ? 'Standard' : 'Free Shipping');
		}
		
		// Build shipping method string for order
		$shippingMethod = $shippingCarrier ? ($shippingCarrier . ' ' . $shippingService) : ($shipping > 0 ? 'Standard' : 'Free Shipping');
		// B2B invoices: no tax
		$taxRate = 0.00;
		$tax = 0.00;
		$total = $subtotal + $shipping + $tax;

		// Commission — line-by-line using quantity per item with product group support
		$tiers = commission_get_agent_tiers($pdo, (int)$uid);
		$developerPct = commission_get_setting_float($pdo, 'developer_commission', 5.0);
		$aiPct = commission_get_setting_float($pdo, 'ai_assist_commission', 0.0);
		$agentCommissionTotal = 0.0;
		$developerCommissionTotal = 0.0;
		$aiCommissionTotal = 0.0;
		$weightedPctNumerator = 0.0;
		$weightedPctDenominator = 0.0;
		foreach ($dbItems as $di) {
			$lineSubtotal = (float)$di['line_total'];
			// Check for product group-specific commission tiers first
			$productGroupTiers = commission_get_product_group_tiers($pdo, (int)$di['product_id']);
			$tiersToUse = $productGroupTiers ?? $tiers;
			$agentPct = commission_resolve_percent($tiersToUse, (int)$di['quantity']);
			$agentCommissionTotal += round($lineSubtotal * ($agentPct / 100), 2);
			$developerCommissionTotal += round($lineSubtotal * ($developerPct / 100), 2);
			if ($aiPct > 0) { $aiCommissionTotal += round($lineSubtotal * ($aiPct / 100), 2); }
			$weightedPctNumerator += ($agentPct * $lineSubtotal);
			$weightedPctDenominator += $lineSubtotal;
		}
		$commissionRate = $weightedPctDenominator > 0 ? round($weightedPctNumerator / $weightedPctDenominator, 3) : null; // informational only

		// Final hard guard: resolve a valid user id one last time before inserting order
		$finalUserId = intval($userId);
		if ($finalUserId <= 0) {
			try { if ($waId > 0) { $st = $pdo->prepare('SELECT user_id FROM wholesale_accounts WHERE id = ?'); $st->execute([$waId]); $finalUserId = intval($st->fetchColumn()); } } catch (Throwable $e) {}
		}
		if ($finalUserId <= 0) {
			$genEmail = $customer['email'] ?: ('auto+' . substr(sha1(microtime(true) . '|' . rand()), 0, 10) . '@noemail.invalid');
			$ins = $pdo->prepare('INSERT INTO users (email, password_hash, first_name, last_name, phone, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())');
			$ins->execute([$genEmail, password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), ($customer['first_name'] ?: 'Store'), ($customer['last_name'] ?: 'Customer'), $customer['phone']]);
			$finalUserId = intval($pdo->lastInsertId());
			$pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, (SELECT id FROM roles WHERE name = "customer")) ON DUPLICATE KEY UPDATE role_id = role_id')->execute([$finalUserId]);
			if ($waId > 0) { $pdo->prepare('UPDATE wholesale_accounts SET user_id = COALESCE(user_id, ?) WHERE id = ?')->execute([$finalUserId, $waId]); }
		}
		if ($finalUserId <= 0) { throw new Exception('Invoice safety check failed to resolve customer user.'); }

		// Create order
		$orderNumber = 'INV-' . date('Y') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
		$notes = 'Created by Sales Agent ID ' . intval($uid) . '. Invoice generated via ACP.';
        $stmt = $pdo->prepare('INSERT INTO orders (order_number, user_id, wholesale_account_id, sales_agent_id, status, payment_status, payment_method, shipping_method, notes, subtotal, tax, shipping, total, commission_rate, created_at) VALUES (?, ?, ?, ?, "submitted", "pending", "Agent Invoice", ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$orderNumber, $finalUserId, ($waId ?: null), intval($uid), $shippingMethod, $notes, $subtotal, $tax, $shipping, $total, $commissionRate]);
		$orderId = $pdo->lastInsertId();

		// Insert items with error handling
		$stmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, product_sku, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)');
		$insertedItemIds = [];
		foreach ($dbItems as $di) {
			try {
				$stmt->execute([$orderId, $di['product_id'], $di['product_name'], $di['product_sku'], $di['quantity'], $di['unit_price'], $di['line_total']]);
				$itemId = $pdo->lastInsertId();
				if ($itemId <= 0) {
					error_log("Invoice creation: Failed to get order_item ID for order {$orderId}, product {$di['product_sku']}");
				} else {
					$insertedItemIds[] = $itemId;
				}
			} catch (Throwable $e) {
				error_log("Invoice creation: Order item insert failed for order {$orderId}: " . $e->getMessage());
				throw new Exception('Failed to insert order item: ' . $e->getMessage());
			}
		}
		
		// Verify all items were inserted
		if (count($insertedItemIds) !== count($dbItems)) {
			throw new Exception('Not all order items were inserted successfully');
		}

        // Check for recent AI-assisted emails to this customer that may have contributed to the sale
        $sourceEmailId = null;
        try {
            $emailCheckStmt = $pdo->prepare('SELECT id FROM agent_emails WHERE agent_id = ? AND recipient_email = ? AND is_ai_assisted = 1 AND status = "sent" AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY sent_at DESC LIMIT 1');
            $emailCheckStmt->execute([$uid, $customer['email']]);
            $recentEmail = $emailCheckStmt->fetch();
            if ($recentEmail) {
                $sourceEmailId = intval($recentEmail['id']);
                // Mark email as having contributed to sale
                $pdo->prepare('UPDATE agent_emails SET contributed_to_sale = 1, related_order_id = ? WHERE id = ?')->execute([$orderId, $sourceEmailId]);
            }
        } catch (Throwable $e) {
            // Non-blocking; continue even if email check fails
        }

        // Compute and persist line-by-line commissions (agent, developer, AI)
        try {
            commission_compute_for_order($pdo, (int)$orderId);
        } catch (Throwable $e) {
            error_log("Invoice creation: Commission calculation failed for order {$orderId}: " . $e->getMessage());
            throw new Exception('Commission calculation failed: ' . $e->getMessage());
        }
        
        // If AI-assisted email contributed, link it to the agent commission
        if ($sourceEmailId) {
            try {
                $pdo->prepare('UPDATE commissions SET source_email_id = ? WHERE order_id = ? AND commission_type = "sales_agent"')->execute([$sourceEmailId, $orderId]);
            } catch (Throwable $e) {
                error_log("Invoice creation: Failed to link AI email to commission: " . $e->getMessage());
            }
        }
        
        // Generate accounting journal entry for revenue recognition
        try {
            require_once __DIR__ . '/../../required/expense-automation.php';
            create_journal_entry_from_order($orderId, 'accrual');
        } catch (Exception $e) {
            error_log("Journal entry creation failed for order {$orderId}: " . $e->getMessage());
        }

        // If invoice was made for a lead, auto-convert lead into account/contact
        if ($leadId > 0) {
            try {
                // Fetch lead
                $stl = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
                $stl->execute([$leadId]);
                $lead = $stl->fetch();
                if ($lead) {
                    // Use existing account if this invoice was created against one; else create a pending account
                    $convertAccountId = $waId ?? null;
                    if (!$convertAccountId) {
                        $approved = 0; // pending
                        $accIns = $pdo->prepare('INSERT INTO wholesale_accounts (user_id, business_name, phone, approved, created_at, sales_agent_id) VALUES (?, ?, ?, ?, NOW(), ?)');
                        $accIns->execute([$userId, ($lead['company_name'] ?: trim(($lead['first_name'].' '.$lead['last_name']))), $lead['phone'] ?: null, $approved, $uid]);
                        $convertAccountId = $pdo->lastInsertId();
                        // Attempt to propagate address details from lead to account
                        try {
                            $addr1 = $lead['address_line1'] ?: ((trim((string)($lead['address'] ?? '')) !== '') ? trim((string)$lead['address']) : null);
                            $city = $lead['city'] ?: null; $stateR = $lead['state'] ?: null; $postal = ($lead['zip_code'] ?: ($lead['postal_code'] ?: null));
                            $pdo->prepare('UPDATE wholesale_accounts SET address_line1 = COALESCE(?, address_line1), city = COALESCE(?, city), state_region = COALESCE(?, state_region), postal_code = COALESCE(?, postal_code) WHERE id = ?')
                                ->execute([$addr1, $city, $stateR, $postal, $convertAccountId]);
                        } catch (Throwable $eAddr) {}
                    }
                    // Create contact and link
                    $cIns = $pdo->prepare('INSERT INTO crm_contacts (first_name,last_name,email,phone,created_at) VALUES (?,?,?,?,NOW())');
                    $cIns->execute([$lead['first_name'], $lead['last_name'], $lead['email'], $lead['phone']]);
                    $convertContactId = $pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO account_contacts (wholesale_account_id, contact_id, is_primary) VALUES (?, ?, 1)')->execute([$convertAccountId, $convertContactId]);
                    // Update lead to won + link
                    $pdo->prepare('UPDATE crm_leads SET converted_to_account_id = ?, converted_to_contact_id = ?, lead_status = "won", updated_at = NOW() WHERE id = ?')
                        ->execute([$convertAccountId, $convertContactId, $leadId]);
                    // Log operation
                    try {
                        $ctx = json_encode(['lead_id' => intval($leadId), 'account_id' => intval($convertAccountId), 'contact_id' => intval($convertContactId), 'by_user_id' => intval($uid), 'reason' => 'invoice_created']);
                        $pdo->prepare('INSERT INTO operations_log (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())')
                            ->execute([$uid, 'auto_convert_lead_on_invoice', $ctx]);
                    } catch (Throwable $e) {}
                }
            } catch (Throwable $e) {}
        }

        $pdo->commit();
        
        // --- Email Automation Triggers ---
        // Trigger email notifications after successful commit
        try {
            require_once __DIR__ . '/../../required/email-triggers.php';
            
            // Check if this is the customer's first order
            $orderCountStmt = $pdo->prepare("SELECT COUNT(id) FROM orders WHERE user_id = ?");
            $orderCountStmt->execute([$finalUserId]);
            $orderCount = $orderCountStmt->fetchColumn();
            
            if ($orderCount == 1) {
                $firstPurchaseResult = trigger_email_notification('first_purchase', $finalUserId, [
                    'order_number' => $orderNumber,
                    'order_total' => number_format($total, 2)
                ]);
                error_log("Invoice {$orderId}: first_purchase email result = {$firstPurchaseResult}");
            }
            
            // Always trigger a 'new_invoice' email for every order
            $emailSent = trigger_email_notification('new_invoice', $finalUserId, [
                'order_id' => $orderId,  // ADDED: for payment link in email template
                'order_number' => $orderNumber,
                'order_total' => number_format($total, 2),
                'subtotal' => number_format($subtotal, 2),
                'shipping' => number_format($shipping, 2),
                'tax' => number_format($tax, 2)
            ]);
            
            error_log("Invoice {$orderId}: new_invoice email to user {$finalUserId} result = {$emailSent}");
            
            // Create agent_emails record for CRM tracking REGARDLESS of email result
            // (so it shows in lead/account email history even if template is missing)
            try {
                // Get customer email from users table
                $customerEmailStmt = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
                $customerEmailStmt->execute([$finalUserId]);
                $customerData = $customerEmailStmt->fetch();
                
                if ($customerData && $customerData['email']) {
                    $recipientName = trim(($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? ''));
                    $subject = "Invoice #{$orderNumber} - \$" . number_format($total, 2);
                    $bodyHtml = "Your invoice for order {$orderNumber} has been created. Total: \$" . number_format($total, 2);
                    
                    // Insert into agent_emails table so it shows in CRM
                    $stmt = $pdo->prepare('
                        INSERT INTO agent_emails 
                        (agent_id, lead_id, account_id, recipient_email, recipient_name, subject, body_html, body_text, 
                         is_ai_assisted, status, sent_at, related_order_id, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), ?, NOW())
                    ');
                    $stmt->execute([
                        intval($uid),                    // agent_id (sales agent who created invoice)
                        $leadId > 0 ? $leadId : null,    // lead_id if from lead
                        $targetAccountId > 0 ? $targetAccountId : null,  // account_id if from account
                        $customerData['email'],          // recipient_email
                        $recipientName,                  // recipient_name
                        $subject,                        // subject
                        $bodyHtml,                       // body_html
                        strip_tags($bodyHtml),           // body_text
                        $emailSent > 0 ? 'sent' : 'failed',  // status based on actual send result
                        $orderId                         // related_order_id
                    ]);
                    error_log("Invoice {$orderId}: agent_emails record created for CRM tracking");
                    
                    // Evaluate workflow triggers for this email if it was sent successfully
                    if ($emailSent > 0) {
                        try {
                            require_once __DIR__ . '/../../api/email-workflow-processor.php';
                            
                            // Get the email record we just created
                            $emailStmt = $pdo->prepare('SELECT * FROM agent_emails WHERE related_order_id = ? AND agent_id = ? ORDER BY id DESC LIMIT 1');
                            $emailStmt->execute([$orderId, intval($uid)]);
                            $invoiceEmail = $emailStmt->fetch();
                            
                            if ($invoiceEmail && $invoiceEmail['status'] === 'sent') {
                                $result = evaluate_workflow_triggers($pdo, $invoiceEmail);
                                if ($result['scheduled']) {
                                    error_log("Invoice {$orderId}: Auto-scheduled workflow for email #{$invoiceEmail['id']}: workflow_id={$result['workflow_id']}, scheduled_for={$result['scheduled_for']}");
                                }
                            }
                        } catch (Throwable $e) {
                            error_log("Invoice {$orderId}: Failed to evaluate workflow triggers: " . $e->getMessage());
                            // Non-blocking
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log("Failed to create agent_emails record for invoice {$orderId}: " . $e->getMessage());
                // Non-blocking
            }
        } catch (Throwable $e) {
            error_log("Invoice email automation error for order {$orderId}: " . $e->getMessage());
            // Non-blocking: continue even if email fails
        }
        // --- End Email Automation Triggers ---
        
        $targetAccountId = $waId ?: (isset($convertAccountId) ? (int)$convertAccountId : 0);
        
        // Clear output buffers to prevent white screen on redirect
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        
        // Set success message
        try { flash_set('success', '✅ Invoice created successfully! Reference: ' . $orderNumber); } catch (Throwable $e) {}
        
        // Redirect based on context
        if ($leadId > 0) {
            // Return to lead detail page
            header('Location: leads.php?id=' . intval($leadId));
            exit;
        } elseif ($targetAccountId > 0) {
            // Return to account detail page
            header('Location: accounts.php?id=' . intval($targetAccountId));
            exit;
        } else {
            // Return to invoices list
            header('Location: invoices.php');
            exit;
        }
        
	} catch (Throwable $e) {
		if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
		$errorMsg = 'Error creating invoice: ' . $e->getMessage();
	}
}
?>

<style>
.invoice-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.reference-card { background: white; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); margin-bottom: 24px; border: 1px solid #e5e7eb; }
.card-header { background: linear-gradient(135deg, #065f46, #059669); color: white; padding: 20px 24px; border-radius: 12px 12px 0 0; }
.grid { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; }
.line { display:flex; justify-content:space-between; align-items:center; border:1px solid #e5e7eb; border-radius:8px; padding:10px; margin-bottom:8px; }
.qty-input { width: 90px; padding: 6px; border: 1px solid #e5e7eb; border-radius: 6px; }
.copy-button { background:#059669; color:white; border:none; padding:10px 14px; border-radius:6px; cursor:pointer; text-decoration:none; display:inline-block; }
.badge { background:#fef3c7; color:#b45309; padding:4px 8px; border-radius:6px; font-size:12px; }
</style>

<div class="container">
    <?php 
    // Include the enhanced Paladin navigation
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    require_once __DIR__ . '/../../required/sales-acp-nav.php';
    ?>
    
    <?php
    // Include simple breadcrumb navigation
    require_once __DIR__ . '/../../required/sales-acp-breadcrumb-simple.php';
    ?>
    
<div class="invoice-container">
	<?php if (!empty($successMsg)): ?>
	<div class="reference-card" style="border-left:4px solid #059669;">
		<div class="card-header" style="background:linear-gradient(135deg,#16a34a,#059669);">
			<h2 style="margin:0;">✅ <?php echo $successMsg; ?></h2>
		</div>
		<div style="padding:16px;">
			<a href="<?php echo outbound_path('/admin/orders.php'); ?>" class="copy-button">View in Admin</a>
		</div>
	</div>
	<?php endif; ?>
    <?php if (!empty($errorMsg)): ?>
    <div class="reference-card" style="border-left:4px solid #dc2626;">
        <div class="card-header" style="background:linear-gradient(135deg,#ef4444,#dc2626);">
            <h2 style="margin:0;">❌ <?php echo htmlspecialchars($errorMsg); ?></h2>
        </div>
        <?php if (!empty($_SESSION['sales_acp_admin_mode']) || current_admin_id()): ?>
        <div style="padding:12px; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12px; color:#111827; background:#fff7ed; border-top:1px solid #fde68a;">
            <div><strong>Debug context (admin‑only)</strong></div>
            <div>User: #<?php echo (int)($uid ?? 0); ?> • Lead: #<?php echo (int)$leadId; ?> • Account: #<?php echo (int)($accountPrefillId ?? 0); ?></div>
            <div>Resolved userId: <?php echo isset($userId) ? (int)$userId : -1; ?> • waId: <?php echo isset($waId) ? (int)$waId : -1; ?></div>
            <div>Customer email: <?php echo htmlspecialchars($prefill['email'] ?? ($_POST['email'] ?? '')); ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

	<div class="reference-card">
		<div class="card-header">
			<h1 style="margin:0; font-size:28px;">🧾 Create Invoice</h1>
			<p style="margin:8px 0 0 0; opacity:.9;">Build an invoice, auto-create the customer account, and submit to Operations.</p>
		</div>
		<div class="card-body" style="padding:24px;">
            <form method="post" id="invoice-form">
				<input type="hidden" name="action" value="create_invoice">
				<input type="hidden" name="items_json" id="items_json">
				<input type="hidden" name="shipping_override" id="shipping_override">
                <input type="hidden" name="lead_id" value="<?php echo intval($leadId); ?>">
				<div class="grid">
					<div>
						<h3 style="margin:0 0 12px 0;">Items <?php if (!empty($_GET['import_cart'])): ?><span class="badge">Imported from Cart</span><?php endif; ?></h3>
						<?php foreach ($products as $p): ?>
						<div class="line">
							<div>
								<strong><?php echo htmlspecialchars($p['name']); ?></strong>
								<div style="color:#6b7280; font-size:12px;">SKU: <?php echo $p['sku']; ?> • $<?php echo number_format($p['wholesale_price'],2); ?></div>
							</div>
							<div>
								<input type="number" min="0" step="1" value="<?php echo intval($defaultQtyBySku[$p['sku']] ?? 0); ?>" data-sku="<?php echo $p['sku']; ?>" class="qty-input">
							</div>
						</div>
						<?php endforeach; ?>
					</div>
					<div>
                        <h3 style="margin:0 0 12px 0;">Customer</h3>
                        <input type="hidden" name="account_id" value="<?php echo intval($accountPrefillId); ?>">
						<div style="margin-bottom:8px;">
							<label style="display:block; font-weight:600; color:#374151; margin-bottom:4px;">Select Existing Store</label>
							<select id="account_selector" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
								<option value="0">➕ New Store (create new)</option>
								<?php foreach ($accounts as $a): ?>
								<option value="<?php echo intval($a['id']); ?>" <?php echo $accountPrefillId === intval($a['id']) ? 'selected' : ''; ?>>
									<?php echo htmlspecialchars($a['business_name']); ?> — <?php echo htmlspecialchars(($a['first_name'].' '.$a['last_name']).' • '.$a['email']); ?>
								</option>
								<?php endforeach; ?>
							</select>
							<div style="color:#6b7280; font-size:12px; margin-top:4px;">Pick an existing store to auto-fill, or choose New Store to create a new account.</div>
						</div>
						<input name="business_name" placeholder="Business Name" value="<?php echo htmlspecialchars($prefill['business_name']); ?>" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:8px;">
						<div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
							<input name="first_name" placeholder="First Name" value="<?php echo htmlspecialchars($prefill['first_name']); ?>" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
							<input name="last_name" placeholder="Last Name" value="<?php echo htmlspecialchars($prefill['last_name']); ?>" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
						</div>
						<input name="email" type="email" placeholder="Email" value="<?php echo htmlspecialchars($prefill['email']); ?>" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; margin:8px 0;" required>
						<input name="phone" placeholder="Phone" value="<?php echo htmlspecialchars($prefill['phone']); ?>" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:8px;">
						<input name="address_line1" placeholder="Address" value="<?php echo htmlspecialchars($prefill['address_line1']); ?>" style="width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:8px;">
						<div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
							<input name="city" placeholder="City" value="<?php echo htmlspecialchars($prefill['city']); ?>" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
							<input name="state_region" placeholder="State/Region" value="<?php echo htmlspecialchars($prefill['state_region']); ?>" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
						</div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:8px 0;">
                            <input name="postal_code" placeholder="Postal Code" value="<?php echo htmlspecialchars($prefill['postal_code']); ?>" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
                            <input name="country" placeholder="Country" value="<?php echo htmlspecialchars($prefill['country']); ?>" style="padding:8px; border:1px solid #e5e7eb; border-radius:6px;">
                        </div>
                        <?php if ($accountPrefillId > 0): ?>
                        <div class="muted small">Auto-filled from account. You can edit fields before creating the invoice.</div>
                        <?php elseif ($leadId > 0): ?>
                        <div class="muted small">Auto-filled from lead. You can edit fields before creating the invoice.</div>
                        <?php endif; ?>
						
						<!-- Shipping Options -->
						<div style="margin-top:16px; padding:12px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px;">
							<h4 style="margin:0 0 8px 0; color:#0c4a6e; font-size:14px;">📦 Shipping</h4>
							<button type="button" class="btn small secondary" onclick="fetchInvoiceShippingRates()" id="fetch_invoice_rates_btn">
								Get Shipping Quotes
							</button>
							<div id="invoice_shipping_quotes_container" style="margin-top:12px;"></div>
							<input type="hidden" name="selected_carrier" id="invoice_selected_carrier">
							<input type="hidden" name="selected_service" id="invoice_selected_service">
							<input type="hidden" name="selected_shipping_cost" id="invoice_selected_shipping_cost">
							<div style="margin-top:8px; font-size:12px; color:#6b7280;">
								💡 Shipping is customer-paid and does not affect your commission
							</div>
						</div>
						
						<!-- Live Order Summary -->
						<div class="reference-card" style="margin-top:12px;">
							<div class="card-header" style="background:linear-gradient(135deg,#0ea5e9,#0369a1);">
								<h3 style="margin:0;">Order Summary</h3>
							</div>
							<div style="padding:12px; font-size:14px;">
								<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;"><span>Subtotal</span><strong id="sum_subtotal">$0.00</strong></div>
								<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; gap:8px;">
									<span>Shipping</span>
									<strong id="sum_shipping">$0.00</strong>
								</div>
								<div style="display:flex; justify-content:space-between; margin-bottom:6px;"><span>Tax (8%)</span><strong id="sum_tax">$0.00</strong></div>
								<hr style="border:none; border-top:1px solid #e5e7eb; margin:8px 0;">
								<div style="display:flex; justify-content:space-between; font-size:16px;"><span>Total</span><strong id="sum_total">$0.00</strong></div>
								<div style="margin-top:8px; color:#374151; font-weight:600;">Commission Breakdown</div>
								<div id="comm_breakdown" style="margin:6px 0 8px 0; color:#374151;"></div>
								<div style="display:flex; justify-content:space-between; color:#374151;"><span>Agent Rate (avg)</span><strong id="sum_comm_rate">—</strong></div>
								<div style="display:flex; justify-content:space-between; color:#059669; font-weight:700;"><span>Your Commission</span><strong id="sum_agent_comm">$0.00</strong></div>
								<div style="font-size:12px; color:#6b7280; margin-top:6px;">Final commission is computed server‑side.</div>
							</div>
						</div>
						<button class="copy-button" type="submit" style="width:100%; margin-top:12px;">Create Invoice</button>
						<div style="color:#6b7280; font-size:12px; margin-top:6px;">Order will appear in Admin → Orders with status Submitted.</div>
					</div>
				</div>
			</form>
		</div>
	</div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>

<script>
// Build items JSON on submit
document.getElementById('invoice-form')?.addEventListener('submit', function(){
	const items = [];
	document.querySelectorAll('.qty-input').forEach(inp => {
		const qty = parseInt(inp.value || '0');
		if (qty > 0) items.push({ sku: inp.getAttribute('data-sku'), qty });
	});
	document.getElementById('items_json').value = JSON.stringify(items);
});

// When selecting an existing account, auto-fill customer fields
(function(){
    const sel = document.getElementById('account_selector');
    if (!sel) return;
    sel.addEventListener('change', function(){
        const id = parseInt(this.value || '0');
        const inputId = document.querySelector('input[name="account_id"]');
        if (inputId) inputId.value = id;
        if (id === 0) return; // New Store
        // Fetch minimal account info via a lightweight request by reloading with query param
        const url = new URL(window.location.href);
        url.searchParams.set('account_id', String(id));
        url.searchParams.delete('lead_id');
        window.location.href = url.toString();
    });
})();

// Optional: import from cart when flag present
(function(){
	const params = new URLSearchParams(location.search);
	if (!params.has('import_cart')) return;
	try {
		// Server-side cart is PHP session; expose a minimal endpoint via inline JSON if present
		// We cannot access PHP session from JS directly; provide a graceful UX note instead.
		// Agents can quickly retype quantities if cart import is unavailable.
	} catch(e) {}
})();

// Live totals and commission preview
(function(){
    function compute() {
        var rows = document.querySelectorAll('.qty-input');
        var subtotal = 0, totalQty = 0;
        var priceBySku = {};
        try {
            <?php
            // Build a small map sku => wholesale_price for client calc
            $skuPrices = [];
            foreach ($products as $p) { $skuPrices[$p['sku']] = (float)$p['wholesale_price']; }
            ?>
            priceBySku = <?php echo json_encode($skuPrices); ?>;
        } catch(e) {}
        var lineRows = [];
        rows.forEach(function(inp){
            var sku = inp.getAttribute('data-sku') || '';
            var qty = parseInt(inp.value || '0', 10) || 0;
            if (qty <= 0) return;
            var unit = parseFloat(priceBySku[sku] || 0);
            totalQty += qty;
            var line = { sku: sku, qty: qty, unit: unit, lineSubtotal: unit * qty };
            subtotal += line.lineSubtotal;
            lineRows.push(line);
        });
        var bulk = 0; if (totalQty >= 20) bulk = 15; else if (totalQty >= 15) bulk = 11; else if (totalQty >= 10) bulk = 8; else if (totalQty >= 5) bulk = 5;
        // Apply bulk discount evenly on unit prices
        lineRows.forEach(function(l){ l.unitEff = l.unit * (1 - bulk/100); l.lineEff = l.unitEff * l.qty; });
        var discountedSubtotal = lineRows.reduce(function(s, l){ return s + l.lineEff; }, 0);
        // Editable shipping (from hidden field if previously set)
        var overrideStr = document.getElementById('shipping_override')?.value || '';
        var shipping = (overrideStr !== '' && !isNaN(parseFloat(overrideStr))) ? Math.max(0, parseFloat(overrideStr)) : (discountedSubtotal >= 500 ? 0 : 25);
        var tax = 0.00; // B2B: no tax
        var total = discountedSubtotal + shipping + tax;

        // Commission tiers per line by QUANTITY with product group support
        var productGroupCommissions = <?php echo json_encode($productGroupCommissions); ?>;
        var skuToProductId = {};
        <?php foreach ($products as $p): ?>
        skuToProductId[<?php echo json_encode($p['sku']); ?>] = <?php echo $p['product_db_id']; ?>;
        <?php endforeach; ?>
        
        function resolvePct(qty, productId){ 
            // Check for product group-specific tiers first
            if (productId && productGroupCommissions[productId]) {
                var tiers = productGroupCommissions[productId];
                for (var i = 0; i < tiers.length; i++) {
                    var t = tiers[i];
                    if (qty >= t.min && (t.max === null || qty <= t.max)) {
                        return t.pct;
                    }
                }
            }
            // Fall back to default tiers
            if (qty >= 20) return 10; 
            if (qty >= 15) return 12; 
            if (qty >= 10) return 15; 
            if (qty >= 5) return 20; 
            return 20; 
        }
        var weightedPctNumerator = 0, weightedDen = 0;
        var agentTotal = 0;
        var breakdownHtml = '';
        lineRows.forEach(function(l){
            var productId = skuToProductId[l.sku] || null;
            var pct = resolvePct(l.qty, productId);
            var agent = l.lineEff * (pct/100);
            agentTotal += agent;
            weightedPctNumerator += (pct * l.lineEff);
            weightedDen += l.lineEff;
            breakdownHtml += '<div style="display:flex; justify-content:space-between;">' +
                '<span>' + l.qty + ' × ' + l.sku + ' @ ' + pct + '%</span>' +
                '<strong>$' + agent.toFixed(2) + '</strong>' +
            '</div>';
        });
        var avgPct = weightedDen > 0 ? (weightedPctNumerator/weightedDen) : 0;

        function fmt(n){ return '$' + (n||0).toFixed(2); }
        var el = function(id){ return document.getElementById(id); };
        if (el('sum_subtotal')) el('sum_subtotal').textContent = fmt(discountedSubtotal);
        if (el('sum_shipping')) el('sum_shipping').textContent = fmt(shipping);
        if (el('sum_tax')) el('sum_tax').textContent = fmt(tax);
        if (el('sum_total')) el('sum_total').textContent = fmt(total);
        if (el('comm_breakdown')) el('comm_breakdown').innerHTML = breakdownHtml || '<div style="color:#6b7280;">No commission yet.</div>';
        if (el('sum_comm_rate')) el('sum_comm_rate').textContent = (avgPct ? avgPct.toFixed(2) : '—') + (avgPct ? '%' : '');
        if (el('sum_agent_comm')) el('sum_agent_comm').textContent = fmt(agentTotal);
    }
    document.addEventListener('input', function(e){ if (e.target && e.target.classList && e.target.classList.contains('qty-input')) compute(); });
    compute();
})();

// Shipping rate quote functions
async function fetchInvoiceShippingRates() {
    const btn = document.getElementById('fetch_invoice_rates_btn');
    const container = document.getElementById('invoice_shipping_quotes_container');
    
    // Validate address fields
    const address1 = document.querySelector('input[name="address_line1"]').value.trim();
    const city = document.querySelector('input[name="city"]').value.trim();
    const state = document.querySelector('input[name="state_region"]').value.trim();
    const zip = document.querySelector('input[name="postal_code"]').value.trim();
    const country = document.querySelector('input[name="country"]').value.trim() || 'USA';
    
    if (!address1 || !city || !state || !zip) {
        alert('Please complete the shipping address to get quotes');
        return;
    }
    
    btn.disabled = true;
    btn.textContent = 'Fetching Quotes...';
    container.innerHTML = '<div style="color:#6b7280;">Loading shipping rates...</div>';
    
    try {
        const response = await fetch('<?php echo api_url('/api/shipping-rate-api.php'); ?>?action=get_rates', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                recipient_name: document.querySelector('input[name="business_name"]').value || 
                               (document.querySelector('input[name="first_name"]').value + ' ' + 
                                document.querySelector('input[name="last_name"]').value),
                address_line1: address1,
                address_line2: '',
                city: city,
                state: state,
                zip: zip,
                country: country,
                phone: document.querySelector('input[name="phone"]').value,
                weight_oz: 32 // Estimated package weight (2 lbs for wholesale order)
            })
        });
        
        const data = await response.json();
        
        if (!data.ok) {
            throw new Error(data.error || 'Failed to fetch shipping rates');
        }
        
        if (data.rates.length === 0) {
            container.innerHTML = '<div style="color:#dc2626;">No shipping rates available for this address.</div>';
            return;
        }
        
        // Display mode notice
        let html = '';
        if (data.mode === 'flat_rate' || data.mode === 'flat_rate_fallback') {
            html += `
                <div style="background:#fef3c7;color:#92400e;padding:8px 10px;border-radius:6px;margin-bottom:10px;font-size:12px;">
                    ℹ️ ${data.notice || 'Using flat rate pricing'}
                </div>
            `;
        }
        
        // Display shipping options
        html += '<div style="display:grid;gap:10px;">';
        data.rates.forEach((rate, index) => {
            const isRecommended = index === 0;
            const flatRateBadge = rate.is_flat_rate ? '<span style="background:#e0e7ff;color:#4338ca;padding:2px 6px;border-radius:4px;font-size:10px;margin-left:4px;">Flat Rate</span>' : '';
            html += `
                <div class="shipping-quote-option" onclick="selectInvoiceShippingQuote('${rate.carrier}', '${rate.service_name}', ${rate.cost}, this)" 
                     style="border:2px solid #e5e7eb;border-radius:8px;padding:12px;cursor:pointer;transition:all 0.3s;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div style="font-weight:700;color:#1f2937;font-size:13px;">
                            ${rate.carrier} ${isRecommended ? '<span style="background:#dcfce7;color:#059669;padding:2px 6px;border-radius:4px;font-size:10px;">Recommended</span>' : ''} ${flatRateBadge}
                        </div>
                        <div style="font-size:12px;color:#6b7280;">${rate.service_name}</div>
                        ${rate.delivery_days ? `<div style="font-size:11px;color:#9ca3af;">Estimated: ${rate.delivery_days} business days</div>` : ''}
                    </div>
                    <div style="font-size:20px;font-weight:700;color:#0369a1;">$${rate.cost.toFixed(2)}</div>
                </div>
            `;
        });
        html += '</div>';
        
        container.innerHTML = html;
        
    } catch (error) {
        console.error('Shipping quote error:', error);
        container.innerHTML = `<div style="color:#dc2626;font-size:12px;">Error: ${error.message}</div>`;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Refresh Quotes';
    }
}

function selectInvoiceShippingQuote(carrier, service, cost, element) {
    // Remove previous selection
    document.querySelectorAll('.shipping-quote-option').forEach(opt => {
        opt.style.borderColor = '#e5e7eb';
        opt.style.background = 'white';
    });
    
    // Mark as selected
    element.style.borderColor = '#0369a1';
    element.style.background = '#dbeafe';
    
    // Store selection
    document.getElementById('invoice_selected_carrier').value = carrier;
    document.getElementById('invoice_selected_service').value = service;
    document.getElementById('invoice_selected_shipping_cost').value = cost;
    document.getElementById('shipping_override').value = cost;
    
    // Trigger recompute
    const evt = new Event('input');
    document.dispatchEvent(evt);
}
</script>

</div>
</div>

<?php require_once __DIR__ . '/../../required/footer.php'; ?>
