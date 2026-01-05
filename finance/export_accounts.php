<?php
// export_accounts.php - Export Chart of Accounts to CSV
session_start();
require_once '../config/config.php';

// Check authentication
$user = get_logged_in_user();
if (!$user) {
    redirect('auth/login.php');
}

$allowed_roles = ['finance_officer', 'finance_manager', 'accountant', 'system_admin', 'ceo', 'admin'];
if (!in_array($user['role'], $allowed_roles)) {
    show_alert('You do not have permission to export chart of accounts.', 'danger');
    redirect('chart_of_accounts.php');
}

$db = getDBConnection();

// Get filter parameters (same as chart_of_accounts.php)
$search = $_GET['search'] ?? '';
$account_type_filter = $_GET['account_type'] ?? '';
$status_filter = $_GET['status'] ?? 'active';
$format = $_GET['format'] ?? 'csv';

// Build query with filters (same as chart_of_accounts.php)
$query = "
    SELECT c.*,
           p.account_code as parent_account_code,
           p.account_name as parent_account_name
    FROM chart_of_accounts c
    LEFT JOIN chart_of_accounts p ON c.parent_id = p.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $query .= " AND (c.account_code LIKE ? OR c.account_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($account_type_filter)) {
    $query .= " AND c.account_type = ?";
    $params[] = $account_type_filter;
}

if ($status_filter === 'active') {
    $query .= " AND c.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $query .= " AND c.is_active = 0";
}

$query .= " ORDER BY c.account_code";

// Get accounts data
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accounts = [];
}

// Get company info
$company_stmt = $db->prepare("SELECT * FROM companies WHERE status = 'active' ORDER BY id ASC LIMIT 1");
$company_stmt->execute();
$company = $company_stmt->fetch(PDO::FETCH_ASSOC) ?? [
    'company_name' => 'VICTORY FINANCIAL SERVICES LIMITED',
    'currency' => 'TZS'
];

// Export to CSV
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chart_of_accounts_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    // Company header
    fputcsv($output, [$company['company_name']]);
    fputcsv($output, ['Chart of Accounts Export']);
    fputcsv($output, ['Generated on:', date('F d, Y h:i A')]);
    fputcsv($output, ['Filters:']);
    if (!empty($search)) fputcsv($output, ['Search:', $search]);
    if (!empty($account_type_filter)) fputcsv($output, ['Account Type:', ucfirst($account_type_filter)]);
    fputcsv($output, ['Status:', ucfirst($status_filter)]);
    fputcsv($output, []); // Empty row

    // Column headers
    fputcsv($output, [
        'Account Code',
        'Account Name',
        'Account Type',
        'Normal Balance',
        'Level',
        'Parent Account',
        'Is Group Account',
        'Status',
        'Created Date',
        'Description'
    ]);

    // Data rows
    foreach ($accounts as $account) {
        fputcsv($output, [
            $account['account_code'] ?? '',
            $account['account_name'] ?? '',
            ucfirst($account['account_type'] ?? ''),
            ucfirst($account['normal_balance'] ?? ''),
            $account['level'] ?? '',
            ($account['parent_account_code'] ?? '') . ' - ' . ($account['parent_account_name'] ?? ''),
            $account['is_group_account'] ? 'Yes' : 'No',
            $account['is_active'] ? 'Active' : 'Inactive',
            date('Y-m-d', strtotime($account['created_at'] ?? '')),
            $account['description'] ?? ''
        ]);
    }

    // Summary
    fputcsv($output, []); // Empty row
    fputcsv($output, ['Summary']);
    fputcsv($output, ['Total Accounts:', count($accounts)]);

    // Count by type
    $type_counts = [];
    foreach ($accounts as $account) {
        $type = $account['account_type'] ?? 'unknown';
        $type_counts[$type] = ($type_counts[$type] ?? 0) + 1;
    }

    foreach ($type_counts as $type => $count) {
        fputcsv($output, [ucfirst($type) . ' Accounts:', $count]);
    }

    fclose($output);
} else {
    // Default to CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chart_of_accounts_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    // Simple CSV without headers
    fputcsv($output, [
        'Account Code',
        'Account Name',
        'Account Type',
        'Normal Balance',
        'Level',
        'Parent Account',
        'Is Group Account',
        'Status',
        'Created Date',
        'Description'
    ]);

    foreach ($accounts as $account) {
        fputcsv($output, [
            $account['account_code'] ?? '',
            $account['account_name'] ?? '',
            ucfirst($account['account_type'] ?? ''),
            ucfirst($account['normal_balance'] ?? ''),
            $account['level'] ?? '',
            ($account['parent_account_code'] ?? '') . ' - ' . ($account['parent_account_name'] ?? ''),
            $account['is_group_account'] ? 'Yes' : 'No',
            $account['is_active'] ? 'Active' : 'Inactive',
            date('Y-m-d', strtotime($account['created_at'] ?? '')),
            $account['description'] ?? ''
        ]);
    }

    fclose($output);
}
?>