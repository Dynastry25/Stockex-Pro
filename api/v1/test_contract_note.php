<?php
/**
 * Test script for contract note endpoint
 * This helps diagnose issues before full PDF generation
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Contract Note Endpoint Diagnostics</h2>";
echo "<hr>";

// Test 1: Check database config
echo "<h3>1. Database Configuration</h3>";
$db_config_path = __DIR__ . '/../../config/database.php';
if (file_exists($db_config_path)) {
    echo "✓ Database config exists<br>";
    require_once $db_config_path;
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        if ($db) {
            echo "✓ Database connection successful<br>";
        } else {
            echo "✗ Database connection failed<br>";
        }
    } catch (Exception $e) {
        echo "✗ Database error: " . $e->getMessage() . "<br>";
    }
} else {
    echo "✗ Database config not found at: $db_config_path<br>";
}

// Test 2: Check TCPDF
echo "<h3>2. TCPDF Library</h3>";
$tcpdf_path = __DIR__ . '/../../tcpdf/tcpdf.php';
if (file_exists($tcpdf_path)) {
    echo "✓ TCPDF file exists<br>";
    require_once $tcpdf_path;
    
    if (class_exists('TCPDF')) {
        echo "✓ TCPDF class loaded<br>";
        
        // Check if constants are defined
        if (defined('PDF_PAGE_ORIENTATION')) {
            echo "✓ TCPDF constants defined<br>";
        } else {
            echo "✗ TCPDF constants not defined<br>";
        }
    } else {
        echo "✗ TCPDF class not found<br>";
    }
} else {
    echo "✗ TCPDF not found at: $tcpdf_path<br>";
}

// Test 3: Check if trade exists
if (isset($_GET['trade_id']) && $db) {
    echo "<h3>3. Trade Data</h3>";
    $trade_id = intval($_GET['trade_id']);
    echo "Trade ID: $trade_id<br>";
    
    try {
        $stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
        $stmt->execute([$trade_id]);
        $trade = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($trade) {
            echo "✓ Trade found<br>";
            echo "<pre>";
            print_r($trade);
            echo "</pre>";
        } else {
            echo "✗ Trade not found<br>";
        }
    } catch (Exception $e) {
        echo "✗ Query error: " . $e->getMessage() . "<br>";
    }
}

// Test 4: Check company data
if ($db) {
    echo "<h3>4. Company Data</h3>";
    try {
        $stmt = $db->query("SELECT * FROM companies WHERE status = 'active' ORDER BY id LIMIT 1");
        $company = $stmt->fetch();
        if ($company) {
            echo "✓ Company found: " . $company['company_name'] . "<br>";
        } else {
            echo "⚠ No active company found<br>";
        }
    } catch (Exception $e) {
        echo "✗ Company query error: " . $e->getMessage() . "<br>";
    }
}

// Test 5: Check fee_configurations table
if ($db) {
    echo "<h3>5. Fee Configurations</h3>";
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM fee_configurations WHERE is_active = TRUE");
        $result = $stmt->fetch();
        echo "✓ Active fee configurations: " . $result['count'] . "<br>";
    } catch (Exception $e) {
        echo "✗ Fee config query error: " . $e->getMessage() . "<br>";
    }
}

// Test 6: Check header logo
echo "<h3>6. Assets</h3>";
$logo_path = __DIR__ . '/../../assets/HeaderLogoVfsl.jpg';
if (file_exists($logo_path)) {
    echo "✓ Header logo exists<br>";
} else {
    echo "⚠ Header logo not found (will use text fallback)<br>";
}

echo "<hr>";
echo "<h3>Test Complete</h3>";
if (isset($_GET['trade_id'])) {
    echo "<a href='contract_note.php?trade_id=" . intval($_GET['trade_id']) . "'>Try generating PDF now</a>";
} else {
    echo "Add ?trade_id=15089 to URL to test with specific trade";
}
?>
