<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_admin();

$db = getDBConnection();

// Get table parameter
$table = $_GET['table'] ?? '';
$search = $_GET['search'] ?? '';

// Define table configurations
$table_configs = [
    'sub_ledger_groups' => [
        'title' => 'SUB LEDGERS GROUPS',
        'columns' => ['code', 'description', 'classification', 'priority'],
        'display_columns' => ['Code', 'Description', 'Classification', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'classification' => ['type' => 'text', 'label' => 'Classification', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'sub_ledger_categories' => [
        'title' => 'SUB LEDGERS CATEGORIES',
        'columns' => ['code', 'description', 'classification', 'priority'],
        'display_columns' => ['Code', 'Description', 'Classification', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'classification' => ['type' => 'select', 'label' => 'Classification', 'options' => ['LOCAL', 'FOREIGN'], 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'sub_ledger_related_parties' => [
        'title' => 'SUB LEDGERS RELATED PARTIES',
        'columns' => ['code', 'description', 'priority'],
        'display_columns' => ['Code', 'Description', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'sub_ledger_status' => [
        'title' => 'SUB LEDGERS STATUS',
        'columns' => ['code', 'description', 'inactivity_days_from', 'inactivity_days_upto', 'priority'],
        'display_columns' => ['Code', 'Description', 'From', 'Upto', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'inactivity_days_from' => ['type' => 'number', 'label' => 'Inactivity Days From', 'required' => true],
            'inactivity_days_upto' => ['type' => 'number', 'label' => 'Inactivity Days Upto', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'titles' => [
        'title' => 'TITLES',
        'columns' => ['code', 'description', 'priority'],
        'display_columns' => ['Code', 'Description', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'identity_types' => [
        'title' => 'IDENTITIES TYPES',
        'columns' => ['code', 'description', 'priority'],
        'display_columns' => ['Code', 'Description', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'companies' => [
        'title' => 'COMPANY INFORMATION',
        'columns' => ['company_code', 'name', 'branches', 'division', 'business_type', 'country', 'nationality', 'currency', 'language', 'exchange', 'priority'],
        'display_columns' => ['Code', 'Name', 'Branches', 'Division', 'Business', 'Country', 'Nationality', 'Currency', 'Language', 'Exchange', 'Priority'],
        'add_fields' => [
            'company_code' => ['type' => 'text', 'label' => 'Company Code', 'required' => true],
            'name' => ['type' => 'text', 'label' => 'Company Name', 'required' => true],
            'branches' => ['type' => 'text', 'label' => 'Branches', 'required' => false],
            'division' => ['type' => 'text', 'label' => 'Division', 'required' => false],
            'business_type' => ['type' => 'text', 'label' => 'Business Type', 'required' => false],
            'country' => ['type' => 'text', 'label' => 'Country', 'required' => false],
            'nationality' => ['type' => 'text', 'label' => 'Nationality', 'required' => false],
            'currency' => ['type' => 'text', 'label' => 'Currency', 'required' => false],
            'language' => ['type' => 'text', 'label' => 'Language', 'required' => false],
            'exchange' => ['type' => 'text', 'label' => 'Exchange', 'required' => false],
            'mobile' => ['type' => 'text', 'label' => 'Mobile', 'required' => false],
            'email' => ['type' => 'email', 'label' => 'Email', 'required' => false],
            'address' => ['type' => 'textarea', 'label' => 'Address', 'required' => false],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'custodians' => [
        'title' => 'CUSTODIANS',
        // Corrected columns and display_columns to match the add_fields
        'columns' => ['custodian_code', 'custodian_name', 'license_number', 'contact_person', 'phone', 'email', 'priority'],
        'display_columns' => ['Custodian Code', 'Custodian Name', 'License Number', 'Contact Person', 'Phone', 'Email', 'Priority'],
        'add_fields' => [
            'custodian_code' => ['type' => 'text', 'label' => 'Custodian Code', 'required' => true],
            'custodian_name' => ['type' => 'text', 'label' => 'Custodian Name', 'required' => true],
            'license_number' => ['type' => 'text', 'label' => 'License Number', 'required' => false],
            'contact_person' => ['type' => 'text', 'label' => 'Contact Person', 'required' => false],
            'phone' => ['type' => 'text', 'label' => 'Phone', 'required' => false],
            'email' => ['type' => 'email', 'label' => 'Email', 'required' => false],
            'address' => ['type' => 'textarea', 'label' => 'Address', 'required' => false],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'brokers' => [
        'title' => 'BROKERS',
        // Corrected columns and display_columns to match the add_fields
        'columns' => ['broker_code', 'broker_name', 'license_number', 'contact_person', 'phone', 'email', 'priority'],
        'display_columns' => ['Broker Code', 'Broker Name', 'License Number', 'Contact Person', 'Phone', 'Email', 'Priority'],
        'add_fields' => [
            'broker_code' => ['type' => 'text', 'label' => 'Broker Code', 'required' => true],
            'broker_name' => ['type' => 'text', 'label' => 'Broker Name', 'required' => true],
            'license_number' => ['type' => 'text', 'label' => 'License Number', 'required' => false],
            'contact_person' => ['type' => 'text', 'label' => 'Contact Person', 'required' => false],
            'phone' => ['type' => 'text', 'label' => 'Phone', 'required' => false],
            'email' => ['type' => 'email', 'label' => 'Email', 'required' => false],
            'address' => ['type' => 'textarea', 'label' => 'Address', 'required' => false],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'investment_asset_classes' => [
        'title' => 'INVESTMENTS ASSETS CLASSES',
        'columns' => ['code', 'description', 'min_quantity', 'lot_size', 'commission_rate', 'min_commission', 'return_commission', 'return_commission_days', 'costing_method', 'priority'],
        'display_columns' => ['Code', 'Description', 'Min Quantity', 'Lot Size', 'Comm Rate %', 'Min Comm', 'Return Comm', 'T+D', 'Costing', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Asset Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'min_quantity' => ['type' => 'number', 'label' => 'Minimum Quantity', 'required' => true, 'step' => '1'],
            'lot_size' => ['type' => 'number', 'label' => 'Lot Size', 'required' => true, 'step' => '1'],
            'commission_rate' => ['type' => 'number', 'label' => 'Commission Rate (%)', 'required' => true, 'step' => '0.01'],
            'min_commission' => ['type' => 'number', 'label' => 'Minimum Commission', 'required' => true, 'step' => '0.01'],
            'return_commission' => ['type' => 'number', 'label' => 'Return Commission', 'required' => true, 'step' => '0.01'],
            'return_commission_days' => ['type' => 'number', 'label' => 'Return Commission Days (T+D)', 'required' => true, 'step' => '1'],
            'costing_method' => ['type' => 'select', 'label' => 'Costing Method', 'options' => ['FIFO', 'WAUC', 'LIFO'], 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'transaction_types' => [
        'title' => 'TRANSACTIONS TYPES',
        'columns' => ['code', 'description', 'is_document_numbering_auto', 'is_post_dated', 'priority'],
        'display_columns' => ['Code', 'Description', 'Auto Numbering', 'Post Dated', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Transaction Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'is_document_numbering_auto' => ['type' => 'select', 'label' => 'Auto Document Numbering', 'options' => ['YES', 'NO'], 'required' => true],
            'is_post_dated' => ['type' => 'select', 'label' => 'Post Dated', 'options' => ['YES', 'NO'], 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'payment_methods' => [
        'title' => 'PAYMENTS METHODS',
        'columns' => ['code', 'description', 'cashbook', 'priority'],
        'display_columns' => ['Code', 'Description', 'CashBook', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Payment Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'cashbook' => ['type' => 'text', 'label' => 'CashBook', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'ledger_types' => [
        'title' => 'LEDGERS TYPES',
        'columns' => ['code', 'description', 'gl_account', 'is_account_no_auto', 'is_file_no_auto', 'is_csdn_required', 'is_bank_required', 'is_joint_holder_required', 'is_cashbook_disabled', 'priority'],
        'display_columns' => ['Code', 'Description', 'GL Account', 'Account Auto', 'File Auto', 'CSDN Req', 'Bank Req', 'Joint Holder', 'Cashbook', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Ledger Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'gl_account' => ['type' => 'text', 'label' => 'GL Account', 'required' => true],
            'is_account_no_auto' => ['type' => 'select', 'label' => 'Account No Auto', 'options' => ['YES', 'NO'], 'required' => true],
            'is_file_no_auto' => ['type' => 'select', 'label' => 'File No Auto', 'options' => ['YES', 'NO'], 'required' => true],
            'is_csdn_required' => ['type' => 'select', 'label' => 'CSDN Required', 'options' => ['YES', 'NO'], 'required' => true],
            'is_bank_required' => ['type' => 'select', 'label' => 'Bank Required', 'options' => ['YES', 'NO'], 'required' => true],
            'is_joint_holder_required' => ['type' => 'select', 'label' => 'Joint Holder Required', 'options' => ['YES', 'NO'], 'required' => true],
            'is_cashbook_disabled' => ['type' => 'select', 'label' => 'Cashbook Disabled', 'options' => ['YES', 'NO'], 'required' => true],
            'receipt_narrative' => ['type' => 'text', 'label' => 'Receipt Narrative', 'required' => false],
            'payment_narrative' => ['type' => 'text', 'label' => 'Payment Narrative', 'required' => false],
            'petty_cash_narrative' => ['type' => 'text', 'label' => 'Petty Cash Narrative', 'required' => false],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'payment_frequencies' => [
        'title' => 'PAYMENTS FREQUENCIES',
        'columns' => ['code', 'description', 'compounding_periods_per_year', 'cash_flow', 'nominal_interest_rate', 'discounting_factor', 'effective_annual_rate', 'priority'],
        'display_columns' => ['Code', 'Description', 'Compounding Periods', 'Cash Flow', 'Nominal Rate(%)', 'Discounting Factor', 'Effective Rate', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'compounding_periods_per_year' => ['type' => 'number', 'label' => 'Compounding Periods per Year', 'required' => true, 'step' => '1'],
            'cash_flow' => ['type' => 'number', 'label' => 'Cash Flow', 'required' => false, 'step' => '0.01'],
            'nominal_interest_rate' => ['type' => 'number', 'label' => 'Nominal Interest Rate (%)', 'required' => false, 'step' => '0.01'],
            'discounting_factor' => ['type' => 'number', 'label' => 'Discounting Factor', 'required' => false, 'step' => '0.0001'],
            'effective_annual_rate' => ['type' => 'number', 'label' => 'Effective Annual Rate', 'required' => false, 'step' => '0.0001'],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'bonds_economic_sectors' => [
        'title' => 'BONDS ECONOMIC SECTORS',
        'columns' => ['code', 'description', 'priority'],
        'display_columns' => ['Code', 'Description', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'share_types' => [
        'title' => 'SHARES TYPES',
        'columns' => ['code', 'description', 'priority'],
        'display_columns' => ['Code', 'Description', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'share_market_trends' => [
        'title' => 'SHARES MARKET TRENDS',
        'columns' => ['code', 'description', 'priority'],
        'display_columns' => ['Code', 'Description', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'gl_account_types' => [
        'title' => 'GL ACCOUNTS TYPES',
        'columns' => ['code', 'description', 'allocation_from', 'allocation_upto', 'closing_entry_type', 'priority'],
        'display_columns' => ['Code', 'Description', 'From', 'Upto', 'Closing Entry Type', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'allocation_from' => ['type' => 'number', 'label' => 'GL Account Allocation From', 'required' => true, 'step' => '1'],
            'allocation_upto' => ['type' => 'number', 'label' => 'GL Account Allocation Upto', 'required' => true, 'step' => '1'],
            'closing_entry_type' => ['type' => 'select', 'label' => 'Closing Entry Type', 'options' => ['TEMPORARY ACCOUNT', 'PERMANENT ACCOUNT'], 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'gl_account_formats' => [
        'title' => 'GL ACCOUNTS FORMATS',
        'columns' => ['code', 'description', 'priority'],
        'display_columns' => ['Code', 'Description', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'balance_sheet_reporting_formats' => [
        'title' => 'BALANCE SHEET REPORTING FORMATS',
        'columns' => ['code', 'description', 'priority'],
        'display_columns' => ['Code', 'Description', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'bond_types' => [
        'title' => 'BONDS TYPES',
        'columns' => ['code', 'description', 'ytm_spread', 'ex_coupon_days', 'priority'],
        'display_columns' => ['Code', 'Description', 'YTM Spread', 'Ex Coupon Days', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'ytm_spread' => ['type' => 'number', 'label' => 'YTM Spread', 'required' => true, 'step' => '0.0001'],
            'ex_coupon_days' => ['type' => 'number', 'label' => 'Ex Coupon Days', 'required' => true, 'step' => '1'],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'bond_issuers' => [
        'title' => 'BONDS ISSUERS',
        'columns' => ['code', 'description', 'priority'],
        'display_columns' => ['Code', 'Description', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'coupon_determiners' => [
        'title' => 'COUPONS DETERMINERS',
        'columns' => ['code', 'description', 'priority'],
        'display_columns' => ['Code', 'Description', 'Priority'],
        'add_fields' => [
            'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ],
    'equities_settings' => [
        'title' => 'EQUITIES & SETTINGS',
        'columns' => ['security_id', 'description', 'isin', 'costing_basis', 'market_price', 'valuation_price', 'share_type', 'market_segment', 'economic_sector', 'market_trend', 'priority'],
        'display_columns' => ['Code', 'Description', 'ISIN', 'Costing Basis', 'Market Price', 'Valuation Price', 'Share Type', 'Market Segment', 'Economic Sector', 'Market Trend', 'Priority'],
        'add_fields' => [
            'security_id' => ['type' => 'text', 'label' => 'Code', 'required' => true],
            'description' => ['type' => 'text', 'label' => 'Description', 'required' => true],
            'isin' => ['type' => 'text', 'label' => 'ISIN', 'required' => false],
            'costing_basis' => ['type' => 'select', 'label' => 'Costing Basis', 'options' => ['WAUC', 'FIFO', 'LIFO'], 'required' => true],
            'market_price' => ['type' => 'number', 'label' => 'Market Price', 'required' => true, 'step' => '0.01'],
            'valuation_price' => ['type' => 'number', 'label' => 'Valuation Price', 'required' => true, 'step' => '0.01'],
            'share_type' => ['type' => 'text', 'label' => 'Share Type', 'required' => false],
            'market_segment' => ['type' => 'text', 'label' => 'Market Segment', 'required' => false],
            'economic_sector' => ['type' => 'text', 'label' => 'Economic Sector', 'required' => false],
            'market_trend' => ['type' => 'text', 'label' => 'Market Trend', 'required' => false],
            'priority' => ['type' => 'text', 'label' => 'Priority', 'required' => true]
        ]
    ]
];

// Validate table
if (!isset($table_configs[$table])) {
    header('Location: master_data.php');
    exit;
}

$config = $table_configs[$table];

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $fields = [];
        $values = [];
        $placeholders = [];
        
        foreach ($config['add_fields'] as $field => $field_config) {
            if (isset($_POST[$field]) && !empty($_POST[$field])) {
                $fields[] = $field;
                $values[] = $_POST[$field];
                $placeholders[] = '?';
            }
        }
        
        if (!empty($fields)) {
            $sql = "INSERT INTO $table (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
            
            header("Location: master_data_detail.php?table=$table&success=added");
            exit;
        }
    } elseif ($action === 'delete' && isset($_POST['ids'])) {
        $ids = $_POST['ids'];
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $sql = "UPDATE $table SET is_active = 0 WHERE id IN ($placeholders)";
        $stmt = $db->prepare($sql);
        $stmt->execute($ids);
        
        header("Location: master_data_detail.php?table=$table&success=deleted");
        exit;
    } elseif ($action === 'edit' && isset($_POST['id'])) {
        $id = $_POST['id'];
        $updates = [];
        $values = [];
        
        foreach ($config['add_fields'] as $field => $field_config) {
            if (isset($_POST[$field])) {
                $updates[] = "$field = ?";
                $values[] = $_POST[$field];
            }
        }
        
        if (!empty($updates)) {
            $values[] = $id;
            $sql = "UPDATE $table SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
            
            header("Location: master_data_detail.php?table=$table&success=updated");
            exit;
        }
    }
}

// Build search query
$where_clause = "WHERE is_active = 1";
$search_params = [];

if (!empty($search)) {
    $search_conditions = [];
    foreach ($config['columns'] as $column) {
        $search_conditions[] = "$column LIKE ?";
        $search_params[] = "%$search%";
    }
    $where_clause .= " AND (" . implode(' OR ', $search_conditions) . ")";
}

// Set up the dynamic ORDER BY clause
$first_column = $config['columns'][0] ?? 'id';
$order_clause = "ORDER BY $first_column";
if (in_array('priority', $config['columns'])) {
    $order_clause = "ORDER BY priority, $first_column";
}

// Get data with utilization count
$sql = "SELECT *, 
        (SELECT COUNT(*) FROM $table WHERE is_active = 1) as total_count
        FROM $table $where_clause $order_clause";

$stmt = $db->prepare($sql);
$stmt->execute($search_params);
$records = $stmt->fetchAll();

// Calculate utilization percentages
$total_records = count($records);
foreach ($records as &$record) {
    $record['percentage'] = $total_records > 0 ? round((1 / $total_records) * 100, 2) : 0;
}

$page_title = $config['title'];
include '../includes/header.php';
?>

<style>
/* Custom styles for master data interface matching the screenshots */
.master-data-header {
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
    color: white;
    padding: 1rem 0;
    margin-bottom: 1rem;
}

.toolbar {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    padding: 0.5rem;
    margin-bottom: 1rem;
    border-radius: 0.375rem;
}

.toolbar .btn {
    margin-right: 0.25rem;
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

.data-table {
    font-size: 0.75rem;
}

.data-table th {
    background: #e9ecef;
    font-weight: 600;
    padding: 0.5rem;
    border: 1px solid #dee2e6;
}

.data-table td {
    padding: 0.5rem;
    border: 1px solid #dee2e6;
    vertical-align: middle;
}

.utilization-summary {
    background: #f8f9fa;
    padding: 0.5rem;
    margin-top: 1rem;
    border-radius: 0.375rem;
    font-size: 0.75rem;
    font-weight: 600;
}
</style>

<!-- Master data header matching screenshot style -->
<div class="master-data-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-0"><?php echo $config['title']; ?></h2>
            </div>
            <div class="col-md-4 text-end">
                <span class="small">Utilization</span>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <!-- Search and toolbar -->
    <div class="row mb-3">
        <div class="col-md-6">
            <form method="GET" class="d-flex">
                <input type="hidden" name="table" value="<?php echo htmlspecialchars($table); ?>">
                <input type="text" name="search" class="form-control form-control-sm me-2" 
                       placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
            </form>
        </div>
        <div class="col-md-6">
            <div class="toolbar d-flex justify-content-end">
                <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
                <button class="btn btn-outline-primary btn-sm" onclick="showAddModal()">
                    <i class="bi bi-plus"></i> New
                </button>
                <button class="btn btn-outline-info btn-sm" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print
                </button>
                <button class="btn btn-outline-success btn-sm" onclick="exportToExcel()">
                    <i class="bi bi-file-excel"></i> Excel
                </button>
                <button class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-graph-up"></i> Graphs
                </button>
                <button class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-shield-check"></i> Audit
                </button>
                <button class="btn btn-outline-info btn-sm">
                    <i class="bi bi-question-circle"></i> Help
                </button>
                <a href="master_data.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-house"></i> Home
                </a>
            </div>
        </div>
    </div>

    <!-- Success messages -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php
            switch ($_GET['success']) {
                case 'added': echo 'Record added successfully!'; break;
                case 'updated': echo 'Record updated successfully!'; break;
                case 'deleted': echo 'Record(s) deleted successfully!'; break;
            }
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Data table -->
    <div class="card">
        <div class="card-body p-0">
            <form id="bulkForm" method="POST">
                <input type="hidden" name="action" value="delete">
                <div class="table-responsive">
                    <table class="table table-bordered data-table mb-0">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th style="width: 40px;">Del</th>
                                <?php foreach ($config['display_columns'] as $column): ?>
                                    <th><?php echo $column; ?></th>
                                <?php endforeach; ?>
                                <th style="width: 80px;">Count</th>
                                <th style="width: 60px;">%</th>
                                <th style="width: 60px;">Edit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="<?php echo count($config['display_columns']) + 5; ?>" class="text-center py-4">
                                        <i class="bi bi-inbox text-muted" style="font-size: 2rem;"></i>
                                        <p class="text-muted mt-2 mb-0">No records found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $counter = 1; ?>
                                <?php foreach ($records as $record): ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td>
                                            <input type="checkbox" name="ids[]" value="<?php echo $record['id']; ?>" 
                                                   class="form-check-input record-checkbox">
                                        </td>
                                        <?php foreach ($config['columns'] as $column): ?>
                                            <td><?php echo htmlspecialchars($record[$column] ?? ''); ?></td>
                                        <?php endforeach; ?>
                                        <td class="text-center">1</td>
                                        <td class="text-center"><?php echo number_format($record['percentage'], 2); ?></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-outline-primary btn-sm" 
                                                    onclick="editRecord(<?php echo htmlspecialchars(json_encode($record)); ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>

    <!-- Utilization summary -->
    <div class="utilization-summary text-center">
        <?php echo number_format($total_records); ?> 100.00
    </div>

    <!-- Action buttons -->
    <div class="row mt-3">
        <div class="col-md-6">
            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="checkAll()">Check</button>
            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="uncheckAll()">Uncheck</button>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteSelected()">Delete</button>
        </div>
        <div class="col-md-6 text-end">
            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="checkAll()">Check</button>
            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="uncheckAll()">Uncheck</button>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="showAddModal()">Edit</button>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="recordModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="recordForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="recordId">
                    
                    <div class="row">
                        <?php foreach ($config['add_fields'] as $field => $field_config): ?>
                            <div class="<?php echo $field_config['type'] === 'textarea' ? 'col-12' : 'col-md-6'; ?> mb-3">
                                <label for="<?php echo $field; ?>" class="form-label">
                                    <?php echo $field_config['label']; ?>
                                    <?php if ($field_config['required']): ?>
                                        <span class="text-danger">*</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($field_config['type'] === 'select'): ?>
                                    <select name="<?php echo $field; ?>" id="<?php echo $field; ?>" 
                                            class="form-select" <?php echo $field_config['required'] ? 'required' : ''; ?>>
                                        <option value="">Select...</option>
                                        <?php foreach ($field_config['options'] as $option): ?>
                                            <option value="<?php echo $option; ?>"><?php echo $option; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($field_config['type'] === 'textarea'): ?>
                                    <textarea name="<?php echo $field; ?>" 
                                              id="<?php echo $field; ?>" 
                                              class="form-control" 
                                              rows="3"
                                              <?php echo $field_config['required'] ? 'required' : ''; ?>></textarea>
                                <?php else: ?>
                                    <input type="<?php echo $field_config['type']; ?>" 
                                           name="<?php echo $field; ?>" 
                                           id="<?php echo $field; ?>" 
                                           class="form-control"
                                           <?php echo $field_config['required'] ? 'required' : ''; ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showAddModal() {
    document.getElementById('modalTitle').textContent = 'Add Record';
    document.getElementById('formAction').value = 'add';
    document.getElementById('recordId').value = '';
    document.getElementById('recordForm').reset();
    new bootstrap.Modal(document.getElementById('recordModal')).show();
}

function editRecord(record) {
    document.getElementById('modalTitle').textContent = 'Edit Record';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('recordId').value = record.id;
    
    // Populate form fields
    <?php foreach ($config['add_fields'] as $field => $field_config): ?>
        const <?php echo $field; ?>Field = document.getElementById('<?php echo $field; ?>');
        if (<?php echo $field; ?>Field && record.<?php echo $field; ?>) {
            <?php echo $field; ?>Field.value = record.<?php echo $field; ?>;
        }
    <?php endforeach; ?>
    
    new bootstrap.Modal(document.getElementById('recordModal')).show();
}

function checkAll() {
    document.querySelectorAll('.record-checkbox').forEach(cb => cb.checked = true);
}

function uncheckAll() {
    document.querySelectorAll('.record-checkbox').forEach(cb => cb.checked = false);
}

function deleteSelected() {
    const selected = document.querySelectorAll('.record-checkbox:checked');
    if (selected.length === 0) {
        alert('Please select records to delete');
        return;
    }
    
    if (confirm(`Are you sure you want to delete ${selected.length} record(s)?`)) {
        document.getElementById('bulkForm').submit();
    }
}

function exportToExcel() {
    const table = document.querySelector('.data-table');
    const wb = XLSX.utils.table_to_book(table);
    XLSX.writeFile(wb, '<?php echo $config['title']; ?>.xlsx');
}
</script>

<!-- Include XLSX library for Excel export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<?php include '../includes/footer.php'; ?>
