<?php
/**
 * Cash Flow Statement
 * 
 * Generates cash flow statement using the indirect method with hierarchical account structure
 * Supports PDF, Excel, and CSV exports
 */

// ========== INITIALIZATION ==========
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define functions only if they don't exist to prevent redeclaration errors
if (!function_exists('calculateCashFlowTotals')) {
    function calculateCashFlowTotals($data) {
        return [
            'operating_total' => $data['operating']['total'] ?? 0,
            'investing_total' => $data['investing']['total'] ?? 0,
            'financing_total' => $data['financing']['total'] ?? 0,
            'net_cash_flow' => ($data['operating']['total'] ?? 0) + 
                              ($data['investing']['total'] ?? 0) + 
                              ($data['financing']['total'] ?? 0)
        ];
    }
}

// Now include configuration files
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net");

// Require finance officer access
require_finance_officer();

// Get database connection
$db = getDBConnection();

// ========== HELPER FUNCTIONS ==========
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function formatAmount($amount, $company_currency = 'TZS') {
    return number_format($amount, 2);
}

// ========== CASH FLOW CALCULATION FUNCTIONS ==========
function getCashFlowHierarchicalData($db, $start_date, $end_date) {
    $data = [
        'operating' => [
            'total' => 0,
            'net_income' => ['total' => 0],
            'adjustments' => ['total' => 0, 'children' => []],
            'working_capital' => ['total' => 0, 'children' => []]
        ],
        'investing' => [
            'total' => 0,
            'children' => []
        ],
        'financing' => [
            'total' => 0,
            'children' => []
        ]
    ];
    
    // Get period start and end balances for all cash accounts
    $cash_balances = getCashAccountBalances($db, $start_date, $end_date);
    
    // 1. OPERATING ACTIVITIES
    // Get net income (from income statement)
    $net_income = calculateNetIncome($db, $start_date, $end_date);
    $data['operating']['net_income']['total'] = $net_income;
    $data['operating']['total'] += $net_income;
    
    // Get non-cash adjustments (depreciation, amortization)
    $adjustments = getNonCashAdjustments($db, $start_date, $end_date);
    $data['operating']['adjustments']['total'] = $adjustments['total'];
    $data['operating']['adjustments']['children'] = $adjustments['details'];
    $data['operating']['total'] += $adjustments['total'];
    
    // Get working capital changes
    $working_capital = getWorkingCapitalChanges($db, $start_date, $end_date);
    $data['operating']['working_capital']['total'] = $working_capital['total'];
    $data['operating']['working_capital']['children'] = $working_capital['changes'];
    $data['operating']['total'] += $working_capital['total'];
    
    // 2. INVESTING ACTIVITIES
    $investing_activities = getInvestingActivities($db, $start_date, $end_date);
    $data['investing']['total'] = $investing_activities['total'];
    $data['investing']['children'] = $investing_activities['details'];
    
    // 3. FINANCING ACTIVITIES
    $financing_activities = getFinancingActivities($db, $start_date, $end_date);
    $data['financing']['total'] = $financing_activities['total'];
    $data['financing']['children'] = $financing_activities['details'];
    
    return $data;
}

function getCashAccountBalances($db, $start_date, $end_date) {
    $stmt = $db->prepare("
        SELECT 
            coa.account_code,
            coa.account_name,
            coa.account_type,
            (
                SELECT COALESCE(SUM(gl.debit_amount - gl.credit_amount), 0)
                FROM general_ledger gl
                WHERE gl.account_code = coa.account_code
                AND gl.transaction_date < ?
                AND gl.status = 'active'
            ) as opening_balance,
            (
                SELECT COALESCE(SUM(gl.debit_amount - gl.credit_amount), 0)
                FROM general_ledger gl
                WHERE gl.account_code = coa.account_code
                AND gl.transaction_date BETWEEN ? AND ?
                AND gl.status = 'active'
            ) as period_change,
            (
                SELECT COALESCE(SUM(gl.debit_amount - gl.credit_amount), 0)
                FROM general_ledger gl
                WHERE gl.account_code = coa.account_code
                AND gl.transaction_date <= ?
                AND gl.status = 'active'
            ) as closing_balance
        FROM chart_of_accounts coa
        WHERE coa.account_type = 'asset'
        AND coa.account_code LIKE '11%'
        AND coa.is_active = 1
        ORDER BY coa.account_code
    ");
    
    $stmt->execute([$start_date, $start_date, $end_date, $end_date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function calculateNetIncome($db, $start_date, $end_date) {
    // Get income accounts total (4xxx)
    $income_stmt = $db->prepare("
        SELECT COALESCE(SUM(gl.credit_amount - gl.debit_amount), 0) as net_income
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_type = 'income'
        AND coa.is_active = 1
    ");
    $income_stmt->execute([$start_date, $end_date]);
    $income_total = $income_stmt->fetchColumn();
    
    // Get expense accounts total (5xxx)
    $expense_stmt = $db->prepare("
        SELECT COALESCE(SUM(gl.debit_amount - gl.credit_amount), 0) as total_expenses
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_type = 'expense'
        AND coa.is_active = 1
    ");
    $expense_stmt->execute([$start_date, $end_date]);
    $expense_total = $expense_stmt->fetchColumn();
    
    return $income_total - $expense_total;
}

function getNonCashAdjustments($db, $start_date, $end_date) {
    $result = ['total' => 0, 'details' => []];
    
    // Get depreciation and amortization (53xxx)
    $stmt = $db->prepare("
        SELECT 
            'Depreciation & Amortization' as description,
            COALESCE(SUM(gl.debit_amount), 0) as amount
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '53%'
        AND coa.is_active = 1
    ");
    $stmt->execute([$start_date, $end_date]);
    $depreciation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($depreciation && $depreciation['amount'] > 0) {
        $result['details'][] = [
            'description' => $depreciation['description'],
            'amount' => $depreciation['amount']
        ];
        $result['total'] += $depreciation['amount'];
    }
    
    return $result;
}

function getWorkingCapitalChanges($db, $start_date, $end_date) {
    $result = ['total' => 0, 'changes' => []];
    
    // Calculate changes in current assets and liabilities
    $categories = [
        'accounts_receivable' => ['12%', 'decrease'],
        'inventory' => ['13%', 'decrease'],
        'prepaid_expenses' => ['14%', 'decrease'],
        'accounts_payable' => ['21%', 'increase'],
        'accrued_expenses' => ['22%', 'increase'],
        'deferred_revenue' => ['23%', 'increase']
    ];
    
    $period_start = date('Y-m-d', strtotime($start_date . ' -1 day'));
    
    foreach ($categories as $category => [$pattern, $effect]) {
        $stmt = $db->prepare("
            SELECT 
                ? as description,
                (
                    SELECT COALESCE(SUM(gl.debit_amount - gl.credit_amount), 0)
                    FROM general_ledger gl
                    JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
                    WHERE gl.transaction_date <= ?
                    AND gl.status = 'active'
                    AND coa.account_code LIKE ?
                    AND coa.is_active = 1
                ) as end_balance,
                (
                    SELECT COALESCE(SUM(gl.debit_amount - gl.credit_amount), 0)
                    FROM general_ledger gl
                    JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
                    WHERE gl.transaction_date <= ?
                    AND gl.status = 'active'
                    AND coa.account_code LIKE ?
                    AND coa.is_active = 1
                ) as start_balance
        ");
        
        $description = ucwords(str_replace('_', ' ', $category));
        $stmt->execute([$description, $end_date, $pattern, $period_start, $pattern]);
        $balance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $change = $balance['end_balance'] - $balance['start_balance'];
        
        // Adjust sign based on effect (increase/decrease in working capital)
        $amount = ($effect == 'increase') ? $change : -$change;
        
        if ($amount != 0) {
            $result['changes'][] = [
                'description' => $description,
                'change' => $change,
                'amount' => $amount
            ];
            $result['total'] += $amount;
        }
    }
    
    return $result;
}

function getInvestingActivities($db, $start_date, $end_date) {
    $result = ['total' => 0, 'details' => []];
    
    // Fixed asset purchases (16xxx) - exclude depreciation accounts (165xx)
    $stmt = $db->prepare("
        SELECT 
            'Purchase of Property & Equipment' as description,
            COALESCE(SUM(gl.debit_amount), 0) as amount
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '16%'
        AND coa.account_code NOT LIKE '165%'
        AND coa.is_active = 1
    ");
    $stmt->execute([$start_date, $end_date]);
    $purchases = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($purchases && $purchases['amount'] > 0) {
        $result['details'][] = [
            'description' => $purchases['description'],
            'amount' => -$purchases['amount']
        ];
        $result['total'] -= $purchases['amount'];
    }
    
    // Proceeds from sale of assets
    $sale_stmt = $db->prepare("
        SELECT 
            'Proceeds from Sale of Assets' as description,
            COALESCE(SUM(gl.credit_amount), 0) as amount
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '16%'
        AND (gl.description LIKE '%sale%' OR gl.description LIKE '%disposal%')
        AND coa.is_active = 1
    ");
    $sale_stmt->execute([$start_date, $end_date]);
    $sales = $sale_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sales && $sales['amount'] > 0) {
        $result['details'][] = [
            'description' => $sales['description'],
            'amount' => $sales['amount']
        ];
        $result['total'] += $sales['amount'];
    }
    
    // Also check for investment purchases (17xxx)
    $investment_stmt = $db->prepare("
        SELECT 
            'Purchase of Investments' as description,
            COALESCE(SUM(gl.debit_amount), 0) as amount
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '17%'
        AND coa.is_active = 1
    ");
    $investment_stmt->execute([$start_date, $end_date]);
    $investments = $investment_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($investments && $investments['amount'] > 0) {
        $result['details'][] = [
            'description' => $investments['description'],
            'amount' => -$investments['amount']
        ];
        $result['total'] -= $investments['amount'];
    }
    
    return $result;
}

function getFinancingActivities($db, $start_date, $end_date) {
    $result = ['total' => 0, 'details' => []];
    
    // Loan proceeds (31xxx)
    $loan_stmt = $db->prepare("
        SELECT 
            'Proceeds from Loans' as description,
            COALESCE(SUM(gl.credit_amount), 0) as amount
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '31%'
        AND coa.is_active = 1
    ");
    $loan_stmt->execute([$start_date, $end_date]);
    $loans = $loan_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($loans && $loans['amount'] > 0) {
        $result['details'][] = [
            'description' => $loans['description'],
            'amount' => $loans['amount']
        ];
        $result['total'] += $loans['amount'];
    }
    
    // Loan repayments
    $repayment_stmt = $db->prepare("
        SELECT 
            'Repayment of Loans' as description,
            COALESCE(SUM(gl.debit_amount), 0) as amount
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '31%'
        AND coa.is_active = 1
    ");
    $repayment_stmt->execute([$start_date, $end_date]);
    $repayments = $repayment_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($repayments && $repayments['amount'] > 0) {
        $result['details'][] = [
            'description' => $repayments['description'],
            'amount' => -$repayments['amount']
        ];
        $result['total'] -= $repayments['amount'];
    }
    
    // Dividends paid (33xxx)
    $dividend_stmt = $db->prepare("
        SELECT 
            'Dividends Paid' as description,
            COALESCE(SUM(gl.debit_amount), 0) as amount
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '33%'
        AND coa.is_active = 1
    ");
    $dividend_stmt->execute([$start_date, $end_date]);
    $dividends = $dividend_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dividends && $dividends['amount'] > 0) {
        $result['details'][] = [
            'description' => $dividends['description'],
            'amount' => -$dividends['amount']
        ];
        $result['total'] -= $dividends['amount'];
    }
    
    // Equity contributions (32xxx)
    $equity_stmt = $db->prepare("
        SELECT 
            'Equity Contributions' as description,
            COALESCE(SUM(gl.credit_amount), 0) as amount
        FROM general_ledger gl
        JOIN chart_of_accounts coa ON gl.account_code = coa.account_code
        WHERE gl.transaction_date BETWEEN ? AND ?
        AND gl.status = 'active'
        AND coa.account_code LIKE '32%'
        AND coa.is_active = 1
    ");
    $equity_stmt->execute([$start_date, $end_date]);
    $equity = $equity_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($equity && $equity['amount'] > 0) {
        $result['details'][] = [
            'description' => $equity['description'],
            'amount' => $equity['amount']
        ];
        $result['total'] += $equity['amount'];
    }
    
    return $result;
}

// ========== EXPORT FUNCTIONALITY ==========
if (isset($_GET['export'])) {
    $export_type = sanitizeInput($_GET['export']);
    $start_date = $_GET['start_date'] ?? date('Y-01-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    
    // Validate dates
    if (!validateDate($start_date) || !validateDate($end_date)) {
        die('Invalid date format');
    }
    
    // CSRF validation
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }
    
    // Get company information
    $company_stmt = $db->prepare("SELECT * FROM companies WHERE status = 'active' ORDER BY id ASC LIMIT 1");
    $company_stmt->execute();
    $company = $company_stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'company_name' => 'Neovam LTD',
        'address' => 'P.O Box, Dar es Salaam, Tanzania',
        'currency' => 'TZS'
    ];
    
    // Get cash flow data
    $cash_flow_data = getCashFlowHierarchicalData($db, $start_date, $end_date);
    $totals = calculateCashFlowTotals($cash_flow_data);
    $cash_balances = getCashAccountBalances($db, $start_date, $end_date);
    
    switch ($export_type) {
        case 'pdf':
            exportCashFlowToPDF($company, $start_date, $end_date, $cash_flow_data, $totals, $cash_balances);
            break;
        case 'excel':
            exportCashFlowToExcel($company, $start_date, $end_date, $cash_flow_data, $totals, $cash_balances);
            break;
        case 'csv':
            exportCashFlowToCSV($company, $start_date, $end_date, $cash_flow_data, $totals, $cash_balances);
            break;
    }
}

// ========== EXPORT FUNCTIONS ==========
function exportCashFlowToPDF($company, $start_date, $end_date, $cash_flow_data, $totals, $cash_balances) {
    require_once('../tcpdf/tcpdf.php');
    
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'STATEMENT OF CASH FLOWS', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, '(' . $company['company_name'] . ')', 0, 1, 'C');
    $pdf->Cell(0, 6, 'For the Period Ended ' . date('F d, Y', strtotime($end_date)), 0, 1, 'C');
    $pdf->Cell(0, 6, '(Indirect Method - In ' . $company['currency'] . ')', 0, 1, 'C');
    $pdf->Ln(8);
    
    // Table Header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(140, 7, 'DESCRIPTION', 1, 0, 'L');
    $pdf->Cell(40, 7, 'AMOUNT', 1, 1, 'R');
    
    // CASH FLOWS FROM OPERATING ACTIVITIES
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(140, 6, 'CASH FLOWS FROM OPERATING ACTIVITIES', 1, 0, 'L');
    $pdf->Cell(40, 6, '', 1, 1, 'R');
    
    // Net Income
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(5, 5, '', 0, 0);
    $pdf->Cell(135, 5, 'Net Income', 0, 0);
    $pdf->Cell(40, 5, formatAmount($cash_flow_data['operating']['net_income']['total']), 0, 1, 'R');
    
    // Adjustments to Reconcile Net Income to Net Cash
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(130, 5, 'Adjustments to Reconcile Net Income:', 0, 1);
    
    foreach ($cash_flow_data['operating']['adjustments']['children'] as $adj) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(15, 5, '', 0, 0);
        $pdf->Cell(125, 5, $adj['description'], 0, 0);
        $pdf->Cell(40, 5, formatAmount($adj['amount']), 0, 1, 'R');
    }
    
    // Changes in Working Capital
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(130, 5, 'Changes in Working Capital:', 0, 1);
    
    foreach ($cash_flow_data['operating']['working_capital']['children'] as $change) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(15, 5, '', 0, 0);
        $pdf->Cell(125, 5, $change['description'], 0, 0);
        $pdf->Cell(40, 5, formatAmount($change['amount']), 0, 1, 'R');
    }
    
    // Net Cash from Operating Activities
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(140, 6, 'Net Cash Provided by Operating Activities', 0, 0, 'R');
    $pdf->Cell(40, 6, formatAmount($cash_flow_data['operating']['total']), 0, 1, 'R');
    
    $pdf->Ln(3);
    
    // CASH FLOWS FROM INVESTING ACTIVITIES
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(140, 6, 'CASH FLOWS FROM INVESTING ACTIVITIES', 1, 0, 'L');
    $pdf->Cell(40, 6, '', 1, 1, 'R');
    
    foreach ($cash_flow_data['investing']['children'] as $item) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(5, 5, '', 0, 0);
        $pdf->Cell(135, 5, $item['description'], 0, 0);
        $pdf->Cell(40, 5, formatAmount($item['amount']), 0, 1, 'R');
    }
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(140, 6, 'Net Cash Used in Investing Activities', 0, 0, 'R');
    $pdf->Cell(40, 6, formatAmount($cash_flow_data['investing']['total']), 0, 1, 'R');
    
    $pdf->Ln(3);
    
    // CASH FLOWS FROM FINANCING ACTIVITIES
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(140, 6, 'CASH FLOWS FROM FINANCING ACTIVITIES', 1, 0, 'L');
    $pdf->Cell(40, 6, '', 1, 1, 'R');
    
    foreach ($cash_flow_data['financing']['children'] as $item) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(5, 5, '', 0, 0);
        $pdf->Cell(135, 5, $item['description'], 0, 0);
        $pdf->Cell(40, 5, formatAmount($item['amount']), 0, 1, 'R');
    }
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(140, 6, 'Net Cash Provided by Financing Activities', 0, 0, 'R');
    $pdf->Cell(40, 6, formatAmount($cash_flow_data['financing']['total']), 0, 1, 'R');
    
    $pdf->Ln(3);
    
    // NET INCREASE/DECREASE IN CASH
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(0, 100, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(140, 8, 'NET INCREASE (DECREASE) IN CASH AND CASH EQUIVALENTS', 1, 0, 'C', true);
    $pdf->Cell(40, 8, formatAmount($totals['net_cash_flow']), 1, 1, 'R', true);
    
    // CASH AT BEGINNING OF PERIOD
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(140, 6, 'Cash and Cash Equivalents at Beginning of Period', 0, 0, 'R');
    
    $opening_total = array_sum(array_column($cash_balances, 'opening_balance'));
    $pdf->Cell(40, 6, formatAmount($opening_total), 0, 1, 'R');
    
    // CASH AT END OF PERIOD
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(70, 130, 180);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(140, 8, 'CASH AND CASH EQUIVALENTS AT END OF PERIOD', 1, 0, 'C', true);
    
    $closing_total = array_sum(array_column($cash_balances, 'closing_balance'));
    $pdf->Cell(40, 8, formatAmount($closing_total), 1, 1, 'R', true);
    
    // Footer
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Ln(8);
    $pdf->Cell(0, 5, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1);
    $pdf->Cell(0, 5, 'Generated by: ' . $_SESSION['username'], 0, 1);
    
    $pdf->Output('Cash_Flow_Statement_' . date('Y-m-d') . '.pdf', 'I');
    exit;
}

function exportCashFlowToExcel($company, $start_date, $end_date, $cash_flow_data, $totals, $cash_balances) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="Cash_Flow_Statement_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    $opening_total = array_sum(array_column($cash_balances, 'opening_balance'));
    $closing_total = array_sum(array_column($cash_balances, 'closing_balance'));
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; }
            table { border-collapse: collapse; width: 100%; margin-top: 20px; }
            th, td { border: 1px solid #000; padding: 6px; }
            .header { text-align: center; font-weight: bold; font-size: 16px; }
            .total { font-weight: bold; background-color: #f0f0f0; }
            .section { background-color: #e8e8e8; font-weight: bold; }
            .subtotal { padding-left: 20px !important; }
            .detail { padding-left: 40px !important; }
            .positive { color: green; }
            .negative { color: red; }
        </style>
    </head>
    <body>";
    
    echo "<h3 class='header'>STATEMENT OF CASH FLOWS</h3>";
    echo "<h4 class='header'>" . htmlspecialchars($company['company_name']) . "</h4>";
    echo "<p class='header'>For the Period Ended " . date('F d, Y', strtotime($end_date)) . "</p>";
    echo "<p class='header'>(Indirect Method - In " . htmlspecialchars($company['currency']) . ")</p>";
    echo "<br>";
    
    echo "<table>
        <tr>
            <th width='70%'>DESCRIPTION</th>
            <th width='30%'>AMOUNT</th>
        </tr>
        <tr class='section'>
            <td colspan='2'>CASH FLOWS FROM OPERATING ACTIVITIES</td>
        </tr>
        <tr>
            <td>Net Income</td>
            <td class='positive'>" . formatAmount($cash_flow_data['operating']['net_income']['total']) . "</td>
        </tr>
        <tr class='subtotal'>
            <td>Adjustments to Reconcile Net Income:</td>
            <td></td>
        </tr>";
    
    foreach ($cash_flow_data['operating']['adjustments']['children'] as $adj) {
        echo "<tr class='detail'>
                <td>" . htmlspecialchars($adj['description']) . "</td>
                <td class='positive'>" . formatAmount($adj['amount']) . "</td>
            </tr>";
    }
    
    echo "<tr class='subtotal'>
            <td>Changes in Working Capital:</td>
            <td></td>
        </tr>";
    
    foreach ($cash_flow_data['operating']['working_capital']['children'] as $change) {
        $class = $change['amount'] >= 0 ? 'positive' : 'negative';
        echo "<tr class='detail'>
                <td>" . htmlspecialchars($change['description']) . "</td>
                <td class='$class'>" . formatAmount($change['amount']) . "</td>
            </tr>";
    }
    
    echo "<tr class='total'>
            <td align='right'><strong>Net Cash Provided by Operating Activities</strong></td>
            <td><strong class='positive'>" . formatAmount($cash_flow_data['operating']['total']) . "</strong></td>
        </tr>
        <tr class='section'>
            <td colspan='2'>CASH FLOWS FROM INVESTING ACTIVITIES</td>
        </tr>";
    
    foreach ($cash_flow_data['investing']['children'] as $item) {
        $class = $item['amount'] >= 0 ? 'positive' : 'negative';
        echo "<tr class='detail'>
                <td>" . htmlspecialchars($item['description']) . "</td>
                <td class='$class'>" . formatAmount($item['amount']) . "</td>
            </tr>";
    }
    
    echo "<tr class='total'>
            <td align='right'><strong>Net Cash Used in Investing Activities</strong></td>
            <td><strong class='" . ($cash_flow_data['investing']['total'] >= 0 ? 'positive' : 'negative') . "'>" . formatAmount($cash_flow_data['investing']['total']) . "</strong></td>
        </tr>
        <tr class='section'>
            <td colspan='2'>CASH FLOWS FROM FINANCING ACTIVITIES</td>
        </tr>";
    
    foreach ($cash_flow_data['financing']['children'] as $item) {
        $class = $item['amount'] >= 0 ? 'positive' : 'negative';
        echo "<tr class='detail'>
                <td>" . htmlspecialchars($item['description']) . "</td>
                <td class='$class'>" . formatAmount($item['amount']) . "</td>
            </tr>";
    }
    
    echo "<tr class='total'>
            <td align='right'><strong>Net Cash Provided by Financing Activities</strong></td>
            <td><strong class='" . ($cash_flow_data['financing']['total'] >= 0 ? 'positive' : 'negative') . "'>" . formatAmount($cash_flow_data['financing']['total']) . "</strong></td>
        </tr>
        <tr class='total' style='background-color: #e8f5e8;'>
            <td align='right'><strong>NET INCREASE (DECREASE) IN CASH AND CASH EQUIVALENTS</strong></td>
            <td class='" . ($totals['net_cash_flow'] >= 0 ? 'positive' : 'negative') . "'><strong>" . formatAmount($totals['net_cash_flow']) . "</strong></td>
        </tr>
        <tr class='total'>
            <td align='right'><strong>Cash and Cash Equivalents at Beginning of Period</strong></td>
            <td><strong>" . formatAmount($opening_total) . "</strong></td>
        </tr>
        <tr class='total' style='background-color: #e0f0ff;'>
            <td align='right'><strong>CASH AND CASH EQUIVALENTS AT END OF PERIOD</strong></td>
            <td class='positive'><strong>" . formatAmount($closing_total) . "</strong></td>
        </tr>
        <tr><td colspan='2'>&nbsp;</td></tr>
        <tr>
            <td colspan='2'><em>Generated on: " . date('Y-m-d H:i:s') . "</em></td>
        </tr>
        <tr>
            <td colspan='2'><em>Generated by: " . htmlspecialchars($_SESSION['username']) . "</em></td>
        </tr>
    </table>";
    
    echo "</body></html>";
    exit;
}

function exportCashFlowToCSV($company, $start_date, $end_date, $cash_flow_data, $totals, $cash_balances) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="Cash_Flow_Statement_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['STATEMENT OF CASH FLOWS']);
    fputcsv($output, [$company['company_name']]);
    fputcsv($output, ['For the Period Ended ' . date('F d, Y', strtotime($end_date))]);
    fputcsv($output, ['(Indirect Method - In ' . $company['currency'] . ')']);
    fputcsv($output, []);
    fputcsv($output, ['DESCRIPTION', 'AMOUNT']);
    
    // Operating Activities
    fputcsv($output, ['CASH FLOWS FROM OPERATING ACTIVITIES', '']);
    fputcsv($output, ['Net Income', $cash_flow_data['operating']['net_income']['total']]);
    fputcsv($output, ['Adjustments to Reconcile Net Income:', '']);
    
    foreach ($cash_flow_data['operating']['adjustments']['children'] as $adj) {
        fputcsv($output, ['  ' . $adj['description'], $adj['amount']]);
    }
    
    fputcsv($output, ['Changes in Working Capital:', '']);
    
    foreach ($cash_flow_data['operating']['working_capital']['children'] as $change) {
        fputcsv($output, ['  ' . $change['description'], $change['amount']]);
    }
    
    fputcsv($output, ['Net Cash Provided by Operating Activities', $cash_flow_data['operating']['total']]);
    fputcsv($output, []);
    
    // Investing Activities
    fputcsv($output, ['CASH FLOWS FROM INVESTING ACTIVITIES', '']);
    
    foreach ($cash_flow_data['investing']['children'] as $item) {
        fputcsv($output, ['  ' . $item['description'], $item['amount']]);
    }
    
    fputcsv($output, ['Net Cash Used in Investing Activities', $cash_flow_data['investing']['total']]);
    fputcsv($output, []);
    
    // Financing Activities
    fputcsv($output, ['CASH FLOWS FROM FINANCING ACTIVITIES', '']);
    
    foreach ($cash_flow_data['financing']['children'] as $item) {
        fputcsv($output, ['  ' . $item['description'], $item['amount']]);
    }
    
    fputcsv($output, ['Net Cash Provided by Financing Activities', $cash_flow_data['financing']['total']]);
    fputcsv($output, []);
    
    $opening_total = array_sum(array_column($cash_balances, 'opening_balance'));
    $closing_total = array_sum(array_column($cash_balances, 'closing_balance'));
    
    fputcsv($output, ['NET INCREASE (DECREASE) IN CASH AND CASH EQUIVALENTS', $totals['net_cash_flow']]);
    fputcsv($output, ['Cash and Cash Equivalents at Beginning of Period', $opening_total]);
    fputcsv($output, ['CASH AND CASH EQUIVALENTS AT END OF PERIOD', $closing_total]);
    
    fputcsv($output, []);
    fputcsv($output, ['Generated on:', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Generated by:', $_SESSION['username']]);
    
    fclose($output);
    exit;
}

// ========== MAIN PAGE ==========
// Validate and sanitize period parameters
$selected_period = $_GET['period'] ?? 'all_time';
$start_date = $_GET['start_date'] ?? '2020-01-01';
$end_date = $_GET['end_date'] ?? date('Y-m-d');

if (!validateDate($start_date) || !validateDate($end_date)) {
    $start_date = '2020-01-01';
    $end_date = date('Y-m-d');
}

if (strtotime($end_date) < strtotime($start_date)) {
    $end_date = $start_date;
}

// Get company information
$company_stmt = $db->prepare("SELECT * FROM companies WHERE status = 'active' ORDER BY id ASC LIMIT 1");
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    $company = [
        'company_name' => 'Neovam LTD',
        'address' => 'P.O Box, Dar es Salaam, Tanzania',
        'currency' => 'TZS'
    ];
}

// Sanitize company data
foreach ($company as $key => $value) {
    $company[$key] = sanitizeInput($value);
}

// Get cash flow data
$cash_flow_data = getCashFlowHierarchicalData($db, $start_date, $end_date);
$totals = calculateCashFlowTotals($cash_flow_data);
$cash_balances = getCashAccountBalances($db, $start_date, $end_date);

// Calculate totals
$opening_total = array_sum(array_column($cash_balances, 'opening_balance'));
$closing_total = array_sum(array_column($cash_balances, 'closing_balance'));

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$page_title = 'Cash Flow Statement';
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Cash Flow Statement</h4>
                        <p class="mb-0">For Period: <?php echo htmlspecialchars(date('F d, Y', strtotime($start_date)) . ' - ' . date('F d, Y', strtotime($end_date))); ?></p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#periodModal">
                            <i class="bi bi-calendar me-1"></i>Period
                        </button>
                        <button type="button" class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="bi bi-download me-1"></i>Export
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="?export=pdf&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&csrf_token=<?php echo $csrf_token; ?>">
                                <i class="bi bi-file-pdf me-2"></i>PDF
                            </a></li>
                            <li><a class="dropdown-item" href="?export=excel&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&csrf_token=<?php echo $csrf_token; ?>">
                                <i class="bi bi-file-excel me-2"></i>Excel
                            </a></li>
                            <li><a class="dropdown-item" href="?export=csv&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&csrf_token=<?php echo $csrf_token; ?>">
                                <i class="bi bi-file-text me-2"></i>CSV
                            </a></li>
                        </ul>
                        <a href="javascript:window.print()" class="btn btn-light btn-sm">
                            <i class="bi bi-printer me-1"></i>Print
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Company Header -->
                    <div class="text-center mb-4">
                        <h3><?php echo htmlspecialchars($company['company_name']); ?></h3>
                        <h5>STATEMENT OF CASH FLOWS</h5>
                        <p class="mb-0">For the Period Ended <?php echo htmlspecialchars(date('F d, Y', strtotime($end_date))); ?></p>
                        <p class="text-muted mb-1">
                            (Indirect Method) - Currency: <?php echo htmlspecialchars($company['currency']); ?>
                        </p>
                    </div>

                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <!-- OPERATING ACTIVITIES -->
                            <div class="card border-0 mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold text-primary">CASH FLOWS FROM OPERATING ACTIVITIES</h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="d-flex justify-content-between py-2 px-3 border-bottom">
                                        <span>Net Income</span>
                                        <span class="text-success"><?php echo htmlspecialchars($company['currency']); ?> <?php echo formatAmount($cash_flow_data['operating']['net_income']['total']); ?></span>
                                    </div>
                                    
                                    <div class="py-2 px-3 border-bottom">
                                        <div class="fw-bold text-muted small">Adjustments to Reconcile Net Income:</div>
                                    </div>
                                    
                                    <?php foreach ($cash_flow_data['operating']['adjustments']['children'] as $adj): ?>
                                    <div class="d-flex justify-content-between py-2 px-3 border-bottom" style="padding-left: 40px !important;">
                                        <span><?php echo htmlspecialchars($adj['description']); ?></span>
                                        <span class="text-success"><?php echo htmlspecialchars($company['currency']); ?> <?php echo formatAmount($adj['amount']); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <div class="py-2 px-3 border-bottom">
                                        <div class="fw-bold text-muted small">Changes in Working Capital:</div>
                                    </div>
                                    
                                    <?php foreach ($cash_flow_data['operating']['working_capital']['children'] as $change): 
                                        $text_class = $change['amount'] >= 0 ? 'text-success' : 'text-danger';
                                    ?>
                                    <div class="d-flex justify-content-between py-2 px-3 border-bottom" style="padding-left: 40px !important;">
                                        <span><?php echo htmlspecialchars($change['description']); ?></span>
                                        <span class="<?php echo $text_class; ?>"><?php echo htmlspecialchars($company['currency']); ?> <?php echo formatAmount($change['amount']); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <div class="d-flex justify-content-between py-2 px-3 bg-light fw-bold">
                                        <span>Net Cash Provided by Operating Activities</span>
                                        <span class="text-success"><?php echo htmlspecialchars($company['currency']); ?> <?php echo formatAmount($cash_flow_data['operating']['total']); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- INVESTING ACTIVITIES -->
                            <div class="card border-0 mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold text-primary">CASH FLOWS FROM INVESTING ACTIVITIES</h6>
                                </div>
                                <div class="card-body p-0">
                                    <?php foreach ($cash_flow_data['investing']['children'] as $item): 
                                        $text_class = $item['amount'] >= 0 ? 'text-success' : 'text-danger';
                                    ?>
                                    <div class="d-flex justify-content-between py-2 px-3 border-bottom">
                                        <span><?php echo htmlspecialchars($item['description']); ?></span>
                                        <span class="<?php echo $text_class; ?>"><?php echo htmlspecialchars($company['currency']); ?> <?php echo formatAmount($item['amount']); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <div class="d-flex justify-content-between py-2 px-3 bg-light fw-bold">
                                        <span>Net Cash Used in Investing Activities</span>
                                        <?php $investing_class = $cash_flow_data['investing']['total'] >= 0 ? 'text-success' : 'text-danger'; ?>
                                        <span class="<?php echo $investing_class; ?>"><?php echo htmlspecialchars($company['currency']); ?> <?php echo formatAmount($cash_flow_data['investing']['total']); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- FINANCING ACTIVITIES -->
                            <div class="card border-0 mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0 fw-bold text-primary">CASH FLOWS FROM FINANCING ACTIVITIES</h6>
                                </div>
                                <div class="card-body p-0">
                                    <?php foreach ($cash_flow_data['financing']['children'] as $item): 
                                        $text_class = $item['amount'] >= 0 ? 'text-success' : 'text-danger';
                                    ?>
                                    <div class="d-flex justify-content-between py-2 px-3 border-bottom">
                                        <span><?php echo htmlspecialchars($item['description']); ?></span>
                                        <span class="<?php echo $text_class; ?>"><?php echo htmlspecialchars($company['currency']); ?> <?php echo formatAmount($item['amount']); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <div class="d-flex justify-content-between py-2 px-3 bg-light fw-bold">
                                        <span>Net Cash Provided by Financing Activities</span>
                                        <?php $financing_class = $cash_flow_data['financing']['total'] >= 0 ? 'text-success' : 'text-danger'; ?>
                                        <span class="<?php echo $financing_class; ?>"><?php echo htmlspecialchars($company['currency']); ?> <?php echo formatAmount($cash_flow_data['financing']['total']); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- SUMMARY SECTION -->
                            <div class="card border-0">
                                <div class="card-body p-0">
                                    <!-- Net Increase/Decrease -->
                                    <div class="d-flex justify-content-between py-3 px-3 bg-<?php echo $totals['net_cash_flow'] >= 0 ? 'success' : 'danger'; ?> text-white fw-bold">
                                        <h5 class="mb-0">NET INCREASE (DECREASE) IN CASH</h5>
                                        <h5 class="mb-0"><?php echo htmlspecialchars($company['currency']); ?> <?php echo formatAmount($totals['net_cash_flow']); ?></h5>
                                    </div>
                                    
                                    <!-- Opening Cash -->
                                    <div class="d-flex justify-content-between py-2 px-3 border-bottom bg-light fw-bold">
                                        <span>Cash at Beginning of Period</span>
                                        <span><?php echo htmlspecialchars($company['currency']); ?> <?php echo formatAmount($opening_total); ?></span>
                                    </div>
                                    
                                    <!-- Closing Cash -->
                                    <div class="d-flex justify-content-between py-3 px-3 bg-info text-white fw-bold">
                                        <h5 class="mb-0">CASH AT END OF PERIOD</h5>
                                        <h5 class="mb-0"><?php echo htmlspecialchars($company['currency']); ?> <?php echo formatAmount($closing_total); ?></h5>
                                    </div>
                                </div>
                            </div>

                            <!-- CASH ACCOUNTS DETAIL -->
                            
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PERIOD SELECTION MODAL -->
<div class="modal fade" id="periodModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select Reporting Period</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="GET" id="periodForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="periodForm" class="btn btn-primary">Apply Period</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const periodForm = document.getElementById('periodForm');
    
    if (periodForm) {
        periodForm.addEventListener('submit', function(e) {
            const startDate = new Date(this.start_date.value);
            const endDate = new Date(this.end_date.value);
            
            if (startDate > endDate) {
                e.preventDefault();
                alert('Error: Start date cannot be after end date.');
                return false;
            }
        });
    }
    
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
});
</script>

<?php include '../includes/footer.php'; ?>