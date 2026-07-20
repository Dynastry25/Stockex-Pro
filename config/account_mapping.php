<?php
/**
 * Centralized Account Mapping Configuration
 * Maps trade upload data to accounting accounts for automated ledger posting.
 * 
 * All account codes here must exist in the chart_of_accounts table.
 */

// --- Account Codes ---
define('TRADE_RECEIVABLE_ACCOUNT_CODE', '1121');
define('BROKERAGE_COMMISSION_INCOME_CODE', '411');
define('CASH_AT_BANK_CODE', '1112');
define('VAT_PAYABLE_CODE', '213');
define('CMSA_PAYABLE_CODE', '2111');
define('DSE_PAYABLE_CODE', '2112');
define('CSDR_PAYABLE_CODE', '2113');
define('VRF_PAYABLE_CODE', '2114');

// --- HR Payroll Account Codes ---
define('SALARIES_EXPENSE_CODE', '511');
define('STAFF_BENEFITS_EXPENSE_CODE', '512');
define('NSSF_EXPENSE_CODE', '5121');
define('SDL_EXPENSE_CODE', '5122');
define('WCF_EXPENSE_CODE', '5123');
define('OSHA_EXPENSE_CODE', '5124');
define('HI_EXPENSE_CODE', '5125');
define('PAYROLL_CONTROL_CODE', '216');

// --- HR / Payroll Payable Account Map ---
function getHrPayableAccountMap() {
    return [
        'nssf'             => '2121',
        'sdl'              => '2122',
        'wcf'              => '2123',
        'osha'             => '2124',
        'health_insurance' => '2125',
        'paye'             => '2126',
    ];
}

// --- HR / Payroll Expense Account Map ---
function getHrExpenseAccountMap() {
    return [
        'nssf'             => NSSF_EXPENSE_CODE,
        'sdl'              => SDL_EXPENSE_CODE,
        'wcf'              => WCF_EXPENSE_CODE,
        'osha'             => OSHA_EXPENSE_CODE,
        'health_insurance' => HI_EXPENSE_CODE,
    ];
}

function getHrPayableAccountCode($statutory_key) {
    $map = getHrPayableAccountMap();
    $key = strtolower(trim($statutory_key));
    return $map[$key] ?? null;
}

function getHrExpenseAccountCode($statutory_key) {
    $map = getHrExpenseAccountMap();
    $key = strtolower(trim($statutory_key));
    return $map[$key] ?? null;
}

// --- Charge Payable Account Map ---
// Maps internal fee keys to account codes
function getChargePayableAccountMap() {
    return [
        'cmsa' => '2111',
        'dse'  => '2112',
        'csdr' => '2113',
        'vrf'  => '2114',
    ];
}

// --- Charge Alias Map ---
// Maps various charge name inputs to internal keys
function getChargeAliasMap() {
    return [
        'cmsa' => 'cmsa',
        'cmsa fee' => 'cmsa',
        'cmsa fees' => 'cmsa',
        'cmsa charge' => 'cmsa',
        'dse' => 'dse',
        'dse fee' => 'dse',
        'dse fees' => 'dse',
        'dse charge' => 'dse',
        'dsf' => 'dse',
        'dsf fee' => 'dse',
        'dsf fees' => 'dse',
        'dsf charge' => 'dse',
        'csdr' => 'csdr',
        'csdr fee' => 'csdr',
        'csdr fees' => 'csdr',
        'csdr charge' => 'csdr',
        'csd' => 'csdr',
        'csd fee' => 'csdr',
        'csd fees' => 'csdr',
        'vrf' => 'vrf',
        'value retention fee' => 'vrf',
        'value retention fees' => 'vrf',
        'value retention fees payable' => 'vrf',
    ];
}

// --- Commission Alias Map ---
function getCommissionAliasMap() {
    return [
        'brokerage commission' => BROKERAGE_COMMISSION_INCOME_CODE,
        'brokerage fee' => BROKERAGE_COMMISSION_INCOME_CODE,
        'broker commission' => BROKERAGE_COMMISSION_INCOME_CODE,
        'commission income' => BROKERAGE_COMMISSION_INCOME_CODE,
        'brokerage_commission' => BROKERAGE_COMMISSION_INCOME_CODE,
        'broker_commission' => BROKERAGE_COMMISSION_INCOME_CODE,
        'brokerage' => BROKERAGE_COMMISSION_INCOME_CODE,
        'commission' => BROKERAGE_COMMISSION_INCOME_CODE,
    ];
}

/**
 * Normalize a name for comparison:
 * - uppercase
 * - trim spaces
 * - collapse multiple spaces
 * - remove punctuation
 * - LTD/LIMITED treated as equivalent
 */
function normalizeName($name) {
    if (empty($name)) return '';
    $name = mb_strtoupper(trim($name), 'UTF-8');
    $name = preg_replace('/\s+/', ' ', $name);
    $name = preg_replace('/[^\w\s]/', '', $name);
    return trim($name);
}

/**
 * Check if a trade qualifies for Trade Receivables (1121) posting.
 * Qualifies if client/counterparty/broker name matches Victory Financial Services LTD
 * OR if any code field (sca_code, security_id, client_cds) starts with B13.
 */
function isVictoryOrB13Trade($mapped_data) {
    $victory_names = [
        normalizeName('Victory Financial Services LTD'),
        normalizeName('Victory Financial Services LIMITED'),
    ];

    $name_fields = [
        $mapped_data['client_name'] ?? '',
        $mapped_data['counterparty_name'] ?? '',
        $mapped_data['broker_name'] ?? '',
        $mapped_data['counterparty_broker'] ?? '',
    ];

    foreach ($name_fields as $field) {
        $normalized = normalizeName($field);
        foreach ($victory_names as $v_name) {
            $cleaned_victory = preg_replace('/\s*LTD\s*/i', ' LIMITED ', $v_name);
            $cleaned_victory = preg_replace('/\s+/', ' ', trim($cleaned_victory));
            $cleaned_input = preg_replace('/\s*LTD\s*/i', ' LIMITED ', $normalized);
            $cleaned_input = preg_replace('/\s+/', ' ', trim($cleaned_input));
            if ($cleaned_input === $cleaned_victory) {
                return true;
            }
        }
    }

    $code_fields = [
        $mapped_data['sca_code'] ?? '',
        $mapped_data['security_id'] ?? '',
        $mapped_data['client_cds'] ?? '',
    ];

    foreach ($code_fields as $code) {
        $code = strtoupper(trim($code));
        if (strpos($code, 'B13') === 0 || $code === 'B13') {
            return true;
        }
    }

    return false;
}

/**
 * Get the normalized name match result (for logging/debugging).
 */
function getVictoryOrB13MatchReason($mapped_data) {
    $victory_names = [
        normalizeName('Victory Financial Services LTD'),
        normalizeName('Victory Financial Services LIMITED'),
    ];

    $name_field_labels = [
        'client_name' => 'client_name',
        'counterparty_name' => 'counterparty_name',
        'broker_name' => 'broker_name',
        'counterparty_broker' => 'counterparty_broker',
    ];

    foreach ($name_field_labels as $field => $label) {
        $value = $mapped_data[$field] ?? '';
        if (empty($value)) continue;
        $normalized = normalizeName($value);
        foreach ($victory_names as $v_name) {
            $cleaned_victory = preg_replace('/\s*LTD\s*/i', ' LIMITED ', $v_name);
            $cleaned_victory = preg_replace('/\s+/', ' ', trim($cleaned_victory));
            $cleaned_input = preg_replace('/\s*LTD\s*/i', ' LIMITED ', $normalized);
            $cleaned_input = preg_replace('/\s+/', ' ', trim($cleaned_input));
            if ($cleaned_input === $cleaned_victory) {
                return "Name match: {$label} = '{$value}'";
            }
        }
    }

    $code_field_labels = [
        'sca_code' => 'sca_code',
        'security_id' => 'security_id',
        'client_cds' => 'client_cds',
    ];

    foreach ($code_field_labels as $field => $label) {
        $value = $mapped_data[$field] ?? '';
        if (empty($value)) continue;
        $code = strtoupper(trim($value));
        if (strpos($code, 'B13') === 0 || $code === 'B13') {
            return "Code match: {$label} = '{$value}' starts with B13";
        }
    }

    return null;
}

/**
 * Get the payable account code for a given charge name/key.
 */
function getChargeAccountCode($charge_key) {
    $map = getChargePayableAccountMap();
    $key = strtolower(trim($charge_key));
    if (isset($map[$key])) {
        return $map[$key];
    }
    $aliases = getChargeAliasMap();
    if (isset($aliases[$key])) {
        $internal_key = $aliases[$key];
        return $map[$internal_key] ?? null;
    }
    return null;
}

/**
 * Get the income account code for a given commission name/key.
 */
function getCommissionAccountCode($commission_key) {
    $aliases = getCommissionAliasMap();
    $key = strtolower(trim($commission_key));
    return $aliases[$key] ?? BROKERAGE_COMMISSION_INCOME_CODE;
}

/**
 * Generate a duplicate-prevention key for GL entries.
 */
function generateGLDuplicateKey($upload_id, $trade_reference, $account_code, $posting_type) {
    return md5(($upload_id ?: '0') . '|' . $trade_reference . '|' . $account_code . '|' . $posting_type);
}

/**
 * Check if a GL entry already exists for a given duplicate key.
 * Uses reference_no LIKE for pattern matching on description.
 */
function isGLDuplicateEntry($db, $trade_reference, $account_code, $description_pattern) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt FROM general_ledger 
            WHERE reference_no = ? 
            AND account_code = ? 
            AND description LIKE ?
        ");
        $stmt->execute([$trade_reference, $account_code, $description_pattern . '%']);
        return $stmt->fetch()['cnt'] > 0;
    } catch (Exception $e) {
        error_log("Error checking GL duplicate: " . $e->getMessage());
        return false;
    }
}
