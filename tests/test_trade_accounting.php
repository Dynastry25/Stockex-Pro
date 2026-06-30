<?php
/**
 * Test script for Trade Upload Accounting Logic
 * Tests account mapping, normalization, charge aliases, and Victory/B13 detection.
 * 
 * Run: php tests/test_trade_accounting.php
 */

// Include the mapping config
require_once __DIR__ . '/../config/account_mapping.php';

$passed = 0;
$failed = 0;
$tests = [];

function assert_eq($expected, $actual, $label) {
    global $passed, $failed, $tests;
    if ($expected === $actual) {
        $passed++;
        $tests[] = "PASS: {$label}";
    } else {
        $failed++;
        $tests[] = "FAIL: {$label}";
        $tests[] = "  Expected: " . var_export($expected, true);
        $tests[] = "  Actual:   " . var_export($actual, true);
    }
}

function assert_true($actual, $label) {
    assert_eq(true, $actual, $label);
}

function assert_false($actual, $label) {
    assert_eq(false, $actual, $label);
}

echo "========================================\n";
echo "  Trade Upload Accounting Test Suite\n";
echo "========================================\n\n";

// =============================================
// 1. Name Normalization Tests
// =============================================
echo "--- Name Normalization ---\n";

// Basic trimming and uppercase
assert_eq('VICTORY FINANCIAL SERVICES LTD', normalizeName('  Victory Financial Services LTD  '), 'normalizeName: trim+uppercase');

// Extra spaces collapsed
assert_eq('VICTORY FINANCIAL SERVICES LTD', normalizeName('Victory   Financial   Services   LTD'), 'normalizeName: collapse spaces');

// Punctuation removed
assert_eq('VICTORY FINANCIAL SERVICES LTD', normalizeName('Victory Financial Services, LTD.'), 'normalizeName: remove punctuation');

// Empty/null
assert_eq('', normalizeName(''), 'normalizeName: empty string');
assert_eq('', normalizeName(null), 'normalizeName: null');

echo "\n";

// =============================================
// 2. Victory/B13 Detection Tests
// =============================================
echo "--- Victory/B13 Detection ---\n";

// Test with client_name matching Victory
$data = ['client_name' => 'Victory Financial Services LTD', 'counterparty_name' => '', 'broker_name' => '', 'counterparty_broker' => '', 'sca_code' => '', 'security_id' => '', 'client_cds' => ''];
assert_true(isVictoryOrB13Trade($data), 'Victory match by client_name');

// Test with counterparty name
$data = ['client_name' => 'Some Client', 'counterparty_name' => 'Victory Financial Services LTD', 'broker_name' => '', 'counterparty_broker' => '', 'sca_code' => '', 'security_id' => '', 'client_cds' => ''];
assert_true(isVictoryOrB13Trade($data), 'Victory match by counterparty_name');

// Test with broker name (case insensitive)
$data = ['client_name' => '', 'counterparty_name' => '', 'broker_name' => 'victory financial services ltd', 'counterparty_broker' => '', 'sca_code' => '', 'security_id' => '', 'client_cds' => ''];
assert_true(isVictoryOrB13Trade($data), 'Victory match by broker_name (lowercase)');

// Test with B13 prefix in sca_code
$data = ['client_name' => 'Client A', 'counterparty_name' => '', 'broker_name' => '', 'counterparty_broker' => '', 'sca_code' => 'B13/C', 'security_id' => '', 'client_cds' => ''];
assert_true(isVictoryOrB13Trade($data), 'B13 match by sca_code');

// Test with B13 in security_id
$data = ['client_name' => '', 'counterparty_name' => '', 'broker_name' => '', 'counterparty_broker' => '', 'sca_code' => '', 'security_id' => 'B13-001', 'client_cds' => ''];
assert_true(isVictoryOrB13Trade($data), 'B13 match by security_id');

// Test with B13 in client_cds
$data = ['client_name' => '', 'counterparty_name' => '', 'broker_name' => '', 'counterparty_broker' => '', 'sca_code' => '', 'security_id' => '', 'client_cds' => 'B13-ACCOUNT'];
assert_true(isVictoryOrB13Trade($data), 'B13 match by client_cds');

// Test non-matching
$data = ['client_name' => 'John Doe', 'counterparty_name' => '', 'broker_name' => '', 'counterparty_broker' => '', 'sca_code' => 'OTHER', 'security_id' => 'TBL', 'client_cds' => ''];
assert_false(isVictoryOrB13Trade($data), 'Non-matching trade: not Victory or B13');

// Test with LIMITED
$data = ['client_name' => 'Victory Financial Services LIMITED', 'counterparty_name' => '', 'broker_name' => '', 'counterparty_broker' => '', 'sca_code' => '', 'security_id' => '', 'client_cds' => ''];
assert_true(isVictoryOrB13Trade($data), 'Victory match with LIMITED');

// Test with punctuation tolerance
$data = ['client_name' => 'Victory Financial Services Ltd.', 'counterparty_name' => '', 'broker_name' => '', 'counterparty_broker' => '', 'sca_code' => '', 'security_id' => '', 'client_cds' => ''];
assert_true(isVictoryOrB13Trade($data), 'Victory match with punctuation in LTD');

// Test counterparty_broker
$data = ['client_name' => '', 'counterparty_name' => '', 'broker_name' => '', 'counterparty_broker' => 'Victory Financial Services LTD', 'sca_code' => '', 'security_id' => '', 'client_cds' => ''];
assert_true(isVictoryOrB13Trade($data), 'Victory match by counterparty_broker');

echo "\n";

// =============================================
// 3. Charge Alias Mapping Tests
// =============================================
echo "--- Charge Mapping ---\n";

assert_eq('2111', getChargeAccountCode('cmsa'), 'CMSA -> 2111');
assert_eq('2111', getChargeAccountCode('CMSA Fee'), 'CMSA Fee -> 2111');
assert_eq('2111', getChargeAccountCode('CMSA fees'), 'CMSA fees -> 2111');
assert_eq('2111', getChargeAccountCode('CMSA Charge'), 'CMSA Charge -> 2111');

assert_eq('2112', getChargeAccountCode('dse'), 'DSE -> 2112');
assert_eq('2112', getChargeAccountCode('DSF'), 'DSF -> 2112');
assert_eq('2112', getChargeAccountCode('DSF Fee'), 'DSF Fee -> 2112');
assert_eq('2112', getChargeAccountCode('DSE fees'), 'DSE fees -> 2112');

assert_eq('2113', getChargeAccountCode('csdr'), 'CSDR -> 2113');
assert_eq('2113', getChargeAccountCode('CSDR Fee'), 'CSDR Fee -> 2113');
assert_eq('2113', getChargeAccountCode('CSDR Charge'), 'CSDR Charge -> 2113');
assert_eq('2113', getChargeAccountCode('CSD'), 'CSD -> 2113');

assert_eq('2114', getChargeAccountCode('vrf'), 'VRF -> 2114');
assert_eq('2114', getChargeAccountCode('Value Retention Fee'), 'Value Retention Fee -> 2114');
assert_eq('2114', getChargeAccountCode('Value Retention Fees Payable'), 'Value Retention Fees Payable -> 2114');

echo "\n";

// =============================================
// 4. Commission Mapping Tests
// =============================================
echo "--- Commission Mapping ---\n";

assert_eq('411', getCommissionAccountCode('brokerage commission'), 'brokerage commission -> 411');
assert_eq('411', getCommissionAccountCode('Brokerage Fee'), 'Brokerage Fee -> 411');
assert_eq('411', getCommissionAccountCode('broker_commission'), 'broker_commission -> 411');
assert_eq('411', getCommissionAccountCode('Commission Income'), 'Commission Income -> 411');
assert_eq('411', getCommissionAccountCode('unknown'), 'unknown -> 411 (default)');

echo "\n";

// =============================================
// 5. Charge Payable Account Map Tests
// =============================================
echo "--- Charge Payable Account Map ---\n";

$charge_map = getChargePayableAccountMap();
assert_eq('2111', $charge_map['cmsa'], 'cmsa -> 2111');
assert_eq('2112', $charge_map['dse'], 'dse -> 2112');
assert_eq('2113', $charge_map['csdr'], 'csdr -> 2113');
assert_eq('2114', $charge_map['vrf'], 'vrf -> 2114');

echo "\n";

// =============================================
// 6. Duplicate Key Generation Tests
// =============================================
echo "--- Duplicate Key Generation ---\n";

$key1 = generateGLDuplicateKey('1', 'TRD-001', '1121', 'receivable');
$key2 = generateGLDuplicateKey('1', 'TRD-001', '1121', 'receivable');
$key3 = generateGLDuplicateKey('1', 'TRD-001', '2111', 'charge');

assert_eq($key1, $key2, 'Same inputs produce same key');
assert_true($key1 !== $key3, 'Different inputs produce different keys');
assert_true(strlen($key1) === 32, 'Key is MD5 (32 chars)');

echo "\n";

// =============================================
// SUMMARY
// =============================================
echo "========================================\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

if ($failed > 0) {
    echo "\n--- FAILED TESTS ---\n";
    foreach ($tests as $t) {
        if (strpos($t, 'FAIL:') === 0) {
            echo "  {$t}\n";
        }
    }
    exit(1);
}

exit(0);
