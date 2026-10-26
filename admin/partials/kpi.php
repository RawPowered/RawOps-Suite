<?php
require_once __DIR__ . '/../../required/config.php';
require_once __DIR__ . '/../../required/cache.php';

// Inputs
$metric = $_GET['metric'] ?? 'revenue_today';
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$end   = $_GET['end'] ?? date('Y-m-d');

$cacheKey = 'kpi_' . md5(json_encode([$metric,$start,$end]));

$data = cache_get_or_set_json($cacheKey, 300, function() use ($metric, $start, $end) {
    $pdo = db();
    $out = ['value' => 0, 'delta' => 0, 'series' => []];

    if ($metric === 'revenue_today') {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> "cancelled" AND DATE(created_at) BETWEEN ? AND ?');
        $stmt->execute([$start, $end]);
        $value = (float)$stmt->fetchColumn();
        // Compare previous window
        $days = (strtotime($end) - strtotime($start)) / 86400 + 1;
        $prevStart = date('Y-m-d', strtotime($start . " -{$days} days"));
        $prevEnd   = date('Y-m-d', strtotime($start . ' -1 day'));
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> "cancelled" AND DATE(created_at) BETWEEN ? AND ?');
        $stmt->execute([$prevStart, $prevEnd]);
        $prev = (float)$stmt->fetchColumn();
        $delta = ($prev > 0) ? round((($value - $prev) / $prev) * 100, 1) : 100.0;

        // Series for sparkline
        $seriesStmt = $pdo->prepare('SELECT DATE(created_at) d, COALESCE(SUM(total),0) v FROM orders WHERE status <> "cancelled" AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d');
        $seriesStmt->execute([$start, $end]);
        $series = $seriesStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $out = [ 'value' => $value, 'delta' => $delta, 'series' => $series ];
    }

    return $out;
});

// Render minimal card HTML
?>
<div class="kpi-card" style="background: linear-gradient(135deg,#ecfdf5,#d1fae5); padding: 16px; border-radius: 8px; border-left: 4px solid #10b981;">
    <div style="font-size: 12px; color: #065f46; text-transform: uppercase;">Revenue</div>
    <div style="font-size: 24px; font-weight: 700; color: #059669;">$<?php echo number_format($data['value'], 2); ?></div>
    <div style="font-size: 12px; color: #047857;">Δ <?php echo ($data['delta']>=0?'+':''); echo $data['delta']; ?>%</div>
</div>


