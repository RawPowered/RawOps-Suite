<?php
// product/required/ai-knowledge.php
// Centralized AI knowledge builder for branding, products, pricing, navigation, and unit conversions

require_once __DIR__ . '/config.php';

// Prefer DB values but merge with canonical specs from product-data.php for mg/oz, servings, etc.
function ai_get_canonical_products(): array {
	$pdo = null;
	try { $pdo = db(); } catch (Throwable $e) { $pdo = null; }

	$canonical = [];
	try {
		require_once __DIR__ . '/product-data.php';
		foreach (($products ?? []) as $p) {
			$canonical[$p['sku']] = $p;
		}
	} catch (Throwable $e) {}

	$results = [];
	$dbRows = [];
	if ($pdo) {
		try {
			$stmt = $pdo->query('SELECT sku, name, description, wholesale_price, msrp FROM products WHERE active = 1 ORDER BY name');
			$dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (Throwable $e) { $dbRows = []; }
	}

	// Helper to infer product specification if missing in DB
	$inferattribute = function (string $nameOrSku): int {
		return (stripos($nameOrSku, '75') !== false) ? 75 : 20;
	};

	$rows = !empty($dbRows) ? $dbRows : array_map(function($p){
		return [
			'sku' => $p['sku'],
			'name' => $p['name'],
			'description' => $p['description'] ?? '',
			'wholesale_price' => $p['sale_price'] ?? 0,
			' msrp' => $p['msrp'] ?? 0,
		];
	}, array_values($canonical));

	foreach ($rows as $row) {
		$sku = $row['sku'] ?? '';
		$base = $canonical[$sku] ?? [];
		$name = $row['name'] ?? ($base['name'] ?? $sku);
		$bottleOunces = $base['bottle_size'] ?? (stripos($name, '128') !== false ? 128 : 32);
		$mgPerOz = $base['mg_per_oz'] ?? $inferattribute($sku . ' ' . $name);
		$totalMg = $base['total_mg'] ?? ($mgPerOz * $bottleOunces);
		$msrp = isset($row['msrp']) ? (float)$row['msrp'] : (float)($base['msrp'] ?? 0);
		$wholesale = isset($row['wholesale_price']) ? (float)$row['wholesale_price'] : (float)($base['sale_price'] ?? 0);
		$servings = $base['servings'] ?? null;
		$servingSizeOz = $base['serving_size_oz'] ?? null;

		// Conversions
		$ozToMl = 29.5735;
		$bottleMl = $bottleOunces * $ozToMl;
		$mgPerMl = $mgPerOz / $ozToMl; // mg per fl oz divided by ml per fl oz

		$results[] = [
			'sku' => $sku,
			'name' => $name,
			'description' => $row['description'] ?? ($base['description'] ?? ''),
			'wholesale_price' => round($wholesale, 2),
			'msrp' => round($msrp, 2),
			'bottle_oz' => (int)$bottleOunces,
			'bottle_ml' => round($bottleMl, 2),
			'mg_per_oz' => (int)$mgPerOz,
			'mg_per_ml' => round($mgPerMl, 3),
			'total_mg' => (int)$totalMg,
			'servings' => $servings,
			'serving_size_oz' => $servingSizeOz,
		];
	}

	return $results;
}

function ai_branding(): array {
	return [
		'brand' => 'Product',
		'short' => 'Product',
		'tagline' => 'product‑grade Products for consistent, profitable results.',
		'colors' => ['primary' => '#059669', 'accent' => '#dc2626'],
	];
}

function ai_urls(): array {
	return [
		'shop' => '/shop/',
		'login' => '/login.php',
		'account' => '/customer/',
		'calc' => '/tools/profit-calculator.php',
		'ref' => '/tools/sales-reference.php',
		'crm' => '/onboarding/sales-agent/',
		'training' => '/onboarding/trainee/',
	];
}

function ai_crm_map(): array {
	$base = '/onboarding/sales-agent/';
	return [
		'dashboard' => $base . 'index.php',
		'leads' => $base . 'leads.php',
		'calls' => $base . 'calls.php',
		'tasks' => $base . 'tasks.php',
		'invoice_new' => $base . 'invoice.php',
		'invoices' => $base . 'invoices.php',
		'dropoff' => $base . 'dropoff.php',
		'dropoffs' => $base . 'dropoffs.php',
	];
}

function ai_training_map(): array {
	$base = '/onboarding/trainee/';
	return [
		'home' => $base . 'index.php',
		'products' => $base . 'modules/products-product categories-new.php',
		'crm' => $base . 'modules/crm-mastery.php',
		'ten_step_close' => $base . 'modules/ten-step-close.php',
		'profit_calc' => $base . 'modules/profit-calculator-training.php',
	];
}

function ai_commission_tiers(): array {
	return [
		['range' => '1-5', 'rate' => 20],
		['range' => '6-10', 'rate' => 15],
		['range' => '11-15', 'rate' => 12],
		['range' => '16-20+', 'rate' => 10],
	];
}

function ai_bulk_discounts(): array {
	// Mirror `required/product-data.php`
	return [
		['qty' => 'X5', 'discount' => 5],
		['qty' => 'X10', 'discount' => 8],
		['qty' => 'X15', 'discount' => 11],
		['qty' => 'X20', 'discount' => 15],
	];
}

function ai_dual_unit_guideline(): string {
	return "When you mention volumes or dosages, always show BOTH units: fl oz and mL, and mg per oz and mg per mL (use 1 fl oz = 29.57 mL).";
}

function ai_build_system_prompt(array $userContext = []): string {
	$branding = ai_branding();
	$urls = ai_urls();
	$products = ai_get_canonical_products();
	$comm = ai_commission_tiers();
	$bulk = ai_bulk_discounts();

	$prompt = "You are the AI Sales Assistant for {$branding['brand']} wholesale platform.\n\n";
	$prompt .= "CORE CAPABILITIES:\n- Provide product information, prices, and recommendations\n- Give business tips and profit calculations\n- Direct users to relevant URLs and pages\n- Access user account and order information when available\n\n";
	$prompt .= "DUAL-UNIT RULE:\n- " . ai_dual_unit_guideline() . "\n\n";
	$prompt .= "PRODUCT CATALOG:";
	foreach ($products as $p) {
		$marginPct = ($p['msrp'] > 0) ? (($p['msrp'] - $p['wholesale_price']) / $p['msrp']) * 100 : 0;
		$prompt .= "\n- {$p['name']} (SKU: {$p['sku']})";
		$prompt .= "\n  Wholesale: $" . number_format($p['wholesale_price'],2) . " | MSRP: $" . number_format($p['msrp'],2) . " | Profit Margin: " . round($marginPct, 1) . "%";
		$prompt .= "\n  Bottle: {$p['bottle_oz']} fl oz (" . number_format($p['bottle_ml'],2) . " mL) | attribute: {$p['total_mg']} mg total | product specification: {$p['mg_per_oz']} mg/oz (" . number_format($p['mg_per_ml'],3) . " mg/mL)";
	}

	$prompt .= "\n\nBULK DISCOUNTS:";
	foreach ($bulk as $b) { $prompt .= "\n- {$b['qty']}: {$b['discount']}% off (per-unit price scales accordingly)"; }

	$prompt .= "\n\nCOMMISSION TIERS (per line item):";
	foreach ($comm as $c) { $prompt .= "\n- {$c['range']} items: {$c['rate']}%"; }

	$prompt .= "\n\nKEY URLS:\n- Shop: {$urls['shop']}\n- Login: {$urls['login']}\n- Account: {$urls['account']}\n- Profit Calculator: {$urls['calc']}\n- Sales Reference: {$urls['ref']}\n- CRM: {$urls['crm']}\n- Training: {$urls['training']}";

	$prompt .= "\n\nRESPONSE GUIDELINES:\n- Be conversational and helpful\n- Always include specific prices and margins when discussing products\n- Suggest relevant URLs when appropriate\n- For account questions, direct to login\n- Focus on business value and profitability\n- Keep responses concise but informative\n- Use actual data from the database when available\n- NEVER create forms or complex interactions — just provide information and links.";

	$prompt .= "\n\nUSER CONTEXT:";
	if (!empty($userContext['logged_in'])) {
		$user = $userContext['user_info'];
		$prompt .= "\n- User: {$user['first_name']} {$user['last_name']} ({$user['email']})";
		if (!empty($userContext['wholesale_account'])) {
			$acc = $userContext['wholesale_account'];
			$prompt .= "\n- Business: {$acc['business_name']} | Account: " . ($acc['approved'] ? 'Approved' : 'Pending');
		}
		if (!empty($userContext['recent_orders'])) {
			$prompt .= "\n- Recent Orders: " . count($userContext['recent_orders']);
			foreach ($userContext['recent_orders'] as $order) {
				$prompt .= "\n  * Order #{$order['order_number']}: $" . $order['total'] . " ({$order['status']})";
			}
		}
	} else {
		$prompt .= "\n- User: Not logged in (guest)";
	}

	return $prompt;
}

?>


