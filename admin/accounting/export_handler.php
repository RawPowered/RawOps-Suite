<?php
/**
 * Export Handler - Multi-format Report Export Engine
 * Handles PDF, CSV, Excel, QuickBooks, and JSON exports
 */

require_once __DIR__ . '/../../required/config.php';

start_session();

// Authentication check
$userId = current_user_id();
if (!$userId || (!user_has_role($userId, 'accountant') && !user_has_role($userId, 'admin'))) {
    http_response_code(403);
    die('Access denied');
}

$format = $_GET['format'] ?? 'csv';
$reportType = $_GET['report_type'] ?? '';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

if (empty($reportType)) {
    http_response_code(400);
    die('Report type required');
}

$pdo = db();

try {
    switch ($format) {
        case 'csv':
            export_as_csv($reportType, $startDate, $endDate);
            break;
        case 'pdf':
            export_as_pdf($reportType, $startDate, $endDate);
            break;
        case 'excel':
            export_as_excel($reportType, $startDate, $endDate);
            break;
        case 'qbo':
            export_as_quickbooks($reportType, $startDate, $endDate);
            break;
        case 'json':
            export_as_json($reportType, $startDate, $endDate);
            break;
        default:
            throw new Exception('Invalid export format');
    }
} catch (Exception $e) {
    http_response_code(500);
    die('Export error: ' . $e->getMessage());
}

/**
 * Export as CSV
 */
function export_as_csv($reportType, $startDate, $endDate) {
    global $pdo;
    
    $filename = "{$reportType}_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    switch ($reportType) {
        case 'journal':
            // Export journal entries
            fputcsv($output, ['Date', 'Entry Number', 'Description', 'Account Code', 'Account Name', 'Debit', 'Credit', 'Status']);
            
            $stmt = $pdo->prepare("
                SELECT je.entry_date, je.entry_number, je.description, je.status,
                       coa.account_code, coa.account_name,
                       jel.debit_amount, jel.credit_amount
                FROM journal_entries je
                JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                JOIN chart_of_accounts coa ON coa.id = jel.account_id
                WHERE je.entry_date BETWEEN ? AND ?
                ORDER BY je.entry_date, je.id, jel.line_number
            ");
            $stmt->execute([$startDate, $endDate]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            break;
            
        case 'expenses':
            // Export expenses
            fputcsv($output, ['Date', 'Category', 'Vendor', 'Description', 'Amount', 'Payment Status', 'Reference']);
            
            $stmt = $pdo->prepare("
                SELECT e.expense_date, ec.category_name, e.vendor_name, e.description,
                       e.amount, e.payment_status, e.reference_type, e.reference_id
                FROM expenses e
                JOIN expense_categories ec ON ec.id = e.expense_category_id
                WHERE e.expense_date BETWEEN ? AND ?
                ORDER BY e.expense_date DESC
            ");
            $stmt->execute([$startDate, $endDate]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $row['reference'] = ($row['reference_type'] ?? '') . ' ' . ($row['reference_id'] ?? '');
                unset($row['reference_type'], $row['reference_id']);
                fputcsv($output, $row);
            }
            break;
            
        case 'chart_of_accounts':
            // Export chart of accounts
            fputcsv($output, ['Account Code', 'Account Name', 'Type', 'Normal Balance', 'Description', 'Active']);
            
            $stmt = $pdo->query("
                SELECT account_code, account_name, account_type, normal_balance, description, is_active
                FROM chart_of_accounts
                ORDER BY account_code
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $row['is_active'] = $row['is_active'] ? 'Yes' : 'No';
                fputcsv($output, $row);
            }
            break;
            
        default:
            fputcsv($output, ['Error', 'Unknown report type']);
    }
    
    fclose($output);
    exit;
}

/**
 * Export as PDF (simplified version - would use a PDF library in production)
 */
function export_as_pdf($reportType, $startDate, $endDate) {
    // Placeholder: In production, use libraries like TCPDF, FPDF, or mPDF
    // For now, we'll output HTML that can be printed to PDF
    
    header('Content-Type: text/html');
    
    echo "<!DOCTYPE html><html><head>";
    echo "<title>{$reportType} Report</title>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .text-right { text-align: right; }
        .print-only { display: none; }
        @media print { .no-print { display: none; } .print-only { display: block; } }
    </style>";
    echo "</head><body>";
    
    echo "<h1>" . ucwords(str_replace('_', ' ', $reportType)) . " Report</h1>";
    echo "<p>Period: " . date('M j, Y', strtotime($startDate)) . " to " . date('M j, Y', strtotime($endDate)) . "</p>";
    echo "<p class='no-print'><button onclick='window.print()'>Print / Save as PDF</button></p>";
    
    // Output would mirror the CSV structure but formatted as HTML table
    echo "<p><em>PDF export functionality requires PDF library integration. Please use CSV or Excel export, or print this page to PDF.</em></p>";
    
    echo "</body></html>";
    exit;
}

/**
 * Export as Excel (simplified - would use PHPSpreadsheet in production)
 */
function export_as_excel($reportType, $startDate, $endDate) {
    // Placeholder: In production, use PHPSpreadsheet library
    // For now, export as CSV with .xls extension (Excel will open it)
    
    $filename = "{$reportType}_" . date('Y-m-d') . ".xls";
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    export_as_csv($reportType, $startDate, $endDate);
}

/**
 * Export as QuickBooks format
 */
function export_as_quickbooks($reportType, $startDate, $endDate) {
    global $pdo;
    
    // QuickBooks IIF format for journal entries
    $filename = "{$reportType}_qb_" . date('Y-m-d') . ".iif";
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    echo "!TRNS\tTRNSID\tTRNSTYPE\tDATE\tACCNT\tNAME\tCLASS\tAMOUNT\tDOCNUM\tMEMO\tCLEAR\tTOPRINT\tADDR1\tADDR2\tADDR3\tADDR4\tADDR5\n";
    echo "!SPL\tSPLID\tTRNSTYPE\tDATE\tACCNT\tNAME\tCLASS\tAMOUNT\tDOCNUM\tMEMO\tCLEAR\tQNTY\tPRICE\tINVITEM\tTAXABLE\n";
    echo "!ENDTRNS\n";
    
    // Export journal entries in IIF format
    $stmt = $pdo->prepare("
        SELECT je.*, jel.*,
               coa.account_name
        FROM journal_entries je
        JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON coa.id = jel.account_id
        WHERE je.entry_date BETWEEN ? AND ?
        AND je.status = 'posted'
        ORDER BY je.id, jel.line_number
    ");
    $stmt->execute([$startDate, $endDate]);
    
    $currentEntry = null;
    $lines = [];
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($currentEntry !== $row['id']) {
            // Output previous entry
            if ($currentEntry && count($lines) > 0) {
                echo "TRNS\t\tGENERAL JOURNAL\t" . $lines[0]['date'] . "\t" . $lines[0]['account'] . "\t\t\t" . 
                     $lines[0]['amount'] . "\t" . $lines[0]['docnum'] . "\t" . $lines[0]['memo'] . "\tN\tN\n";
                
                foreach ($lines as $line) {
                    echo "SPL\t\tGENERAL JOURNAL\t" . $line['date'] . "\t" . $line['account'] . "\t\t\t" . 
                         $line['amount'] . "\t" . $line['docnum'] . "\t" . $line['memo'] . "\tN\n";
                }
                
                echo "ENDTRNS\n";
            }
            
            $currentEntry = $row['id'];
            $lines = [];
        }
        
        $amount = $row['debit_amount'] > 0 ? $row['debit_amount'] : -$row['credit_amount'];
        
        $lines[] = [
            'date' => date('m/d/Y', strtotime($row['entry_date'])),
            'account' => $row['account_name'],
            'amount' => $amount,
            'docnum' => $row['entry_number'],
            'memo' => substr($row['description'], 0, 50)
        ];
    }
    
    exit;
}

/**
 * Export as JSON
 */
function export_as_json($reportType, $startDate, $endDate) {
    global $pdo;
    
    header('Content-Type: application/json');
    $filename = "{$reportType}_" . date('Y-m-d') . ".json";
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $data = [
        'report_type' => $reportType,
        'period' => [
            'start_date' => $startDate,
            'end_date' => $endDate
        ],
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => current_user_id(),
        'data' => []
    ];
    
    switch ($reportType) {
        case 'journal':
            $stmt = $pdo->prepare("
                SELECT je.*, 
                       (SELECT JSON_ARRAYAGG(
                           JSON_OBJECT(
                               'account_code', coa.account_code,
                               'account_name', coa.account_name,
                               'debit_amount', jel.debit_amount,
                               'credit_amount', jel.credit_amount,
                               'description', jel.description
                           )
                       )
                       FROM journal_entry_lines jel
                       JOIN chart_of_accounts coa ON coa.id = jel.account_id
                       WHERE jel.journal_entry_id = je.id
                       ) as lines
                FROM journal_entries je
                WHERE je.entry_date BETWEEN ? AND ?
                ORDER BY je.entry_date DESC
            ");
            $stmt->execute([$startDate, $endDate]);
            $data['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'expenses':
            $stmt = $pdo->prepare("
                SELECT e.*, ec.category_name, coa.account_code, coa.account_name
                FROM expenses e
                JOIN expense_categories ec ON ec.id = e.expense_category_id
                JOIN chart_of_accounts coa ON coa.id = ec.account_id
                WHERE e.expense_date BETWEEN ? AND ?
                ORDER BY e.expense_date DESC
            ");
            $stmt->execute([$startDate, $endDate]);
            $data['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}





