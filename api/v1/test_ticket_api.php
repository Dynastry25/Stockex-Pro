<?php
/**
 * Test endpoint to diagnose ticket API issues
 * Access: https://stockex.neovam.com/api/v1/test_ticket_api.php
 * 
 * This endpoint provides diagnostic information about the ticket API
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

$errors = [];
$warnings = [];
$info = [];
$test_results = [];

// Include API config for helper functions
try {
    require_once __DIR__ . '/../config.php';
    $info[] = 'API config loaded successfully: ✓';
} catch (Exception $e) {
    $errors[] = 'Failed to load API config: ' . $e->getMessage();
}

// Test 1: Database Connection
try {
    if (!isset($db) || !$db) {
        // Try to get connection directly
        require_once __DIR__ . '/../../config/database.php';
        $db = getDBConnection();
    }
    
    if (!$db) {
        $errors[] = 'Database connection returned null';
    } else {
        
        if (!$db) {
            $errors[] = 'Database connection returned null';
        } else {
            $info[] = 'Database connection: ✓ SUCCESSFUL';
            
            // Test 2: Check if tickets table exists
            try {
                $result = $db->query("SELECT 1 FROM tickets LIMIT 1");
                $info[] = 'Tickets table: ✓ EXISTS';
                
                // Get table structure
                try {
                    $columns = $db->query("SHOW COLUMNS FROM tickets")->fetchAll(PDO::FETCH_ASSOC);
                    $column_names = array_column($columns, 'Field');
                    $test_results['tickets_table_columns'] = $column_names;
                } catch (Exception $e) {
                    $warnings[] = 'Could not fetch column info: ' . $e->getMessage();
                }
                
                // Count tickets
                $count = $db->query("SELECT COUNT(*) as count FROM tickets")->fetch(PDO::FETCH_ASSOC)['count'];
                $test_results['total_tickets'] = $count;
                $info[] = "Total tickets in database: $count";
                
            } catch (Exception $e) {
                $errors[] = 'Tickets table does not exist: ' . $e->getMessage();
                $errors[] = '⚠️ FIX: Run database schema at /database/ticketing_system_schema.sql';
            }
            
            // Test 3: Check ticket_comments table
            try {
                $db->query("DESCRIBE ticket_comments LIMIT 0");
                $info[] = 'Ticket comments table: ✓ EXISTS';
            } catch (Exception $e) {
                $warnings[] = 'Ticket comments table missing: ' . $e->getMessage();
            }
        }
    }
} catch (Exception $e) {
    $errors[] = 'Database initialization error: ' . $e->getMessage();
}

// Test 4: Check required functions
$functions_to_check = ['sendResponse', 'sendError', 'sanitizeInput', 'logAPIRequest'];
$missing_functions = [];
foreach ($functions_to_check as $func) {
    if (function_exists($func)) {
        $info[] = "Function $func: ✓ EXISTS";
    } else {
        $missing_functions[] = $func;
        $warnings[] = "Function $func: ✗ NOT FOUND (Check if /api/config.php loaded properly)";
    }
}

if (!empty($missing_functions)) {
    $errors[] = 'Missing critical functions from config.php: ' . implode(', ', $missing_functions);
}

// Test 5: Check JSON input handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_input = file_get_contents('php://input');
    
    if (empty($raw_input)) {
        $warnings[] = 'No POST data received';
    } else {
        $input = json_decode($raw_input, true);
        
        if (!$input) {
            $errors[] = 'JSON parsing failed: ' . json_last_error_msg();
            $test_results['raw_input_size'] = strlen($raw_input);
        } else {
            $info[] = 'JSON input parsed successfully: ✓';
            
            // Check required fields
            $required_fields = ['title', 'description', 'priority', 'category'];
            $missing = [];
            
            foreach ($required_fields as $field) {
                if (empty($input[$field])) {
                    $missing[] = $field;
                }
            }
            
            if (!empty($missing)) {
                $warnings[] = 'Missing required fields: ' . implode(', ', $missing);
            } else {
                $info[] = 'All required fields present: ✓';
            }
            
            $test_results['provided_fields'] = array_keys($input);
            $test_results['values'] = $input;
        }
    }
}

// Test 6: Check file permissions and existence
$test_files = [
    __DIR__ . '/../../config/database.php',
    __DIR__ . '/tickets.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../../logs/api_errors.log',
];

foreach ($test_files as $file) {
    $filename = basename($file);
    $dir = basename(dirname($file));
    
    if (file_exists($file)) {
        if (is_readable($file)) {
            $info[] = "{$dir}/{$filename}: ✓ EXISTS & READABLE";
        } else {
            $errors[] = "{$dir}/{$filename}: EXISTS but NOT READABLE";
        }
    } else {
        $errors[] = "{$dir}/{$filename}: ✗ NOT FOUND";
    }
}

// Test 7: PHP Configuration
$info[] = 'PHP Version: ' . phpversion();
$info[] = 'PHP Error Reporting: ' . (ini_get('error_reporting') ? 'ON' : 'OFF');
$info[] = 'Display Errors: ' . (ini_get('display_errors') ? 'ON' : 'OFF');
$info[] = 'Log Errors: ' . (ini_get('log_errors') ? 'ON' : 'OFF');
$info[] = 'JSON Extension: ' . (extension_loaded('json') ? 'YES' : 'NO');
$info[] = 'MySQLi Extension: ' . (extension_loaded('mysqli') ? 'YES' : 'NO');
$info[] = 'PDO Extension: ' . (extension_loaded('pdo') ? 'YES' : 'NO');

// Test 8: Try to create a test ticket (if POST with full data)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($db) && !empty($errors) === false) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!empty($input['title']) && !empty($input['description'])) {
            $info[] = 'Attempting to create test ticket...';
            
            // Check for duplicate test tickets first
            $test_count = $db->query("SELECT COUNT(*) as count FROM tickets WHERE title LIKE 'TEST%' AND created_by = 'API_TEST_ENDPOINT'")->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($test_count > 10) {
                $warnings[] = 'Too many test tickets exist. Please clean up old test records.';
            } else {
                // Generate ticket number
                $count = $db->query("SELECT COUNT(*) as count FROM tickets WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC)['count'];
                $ticket_number = 'TEST-' . date('Y-m-d-') . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
                
                $stmt = $db->prepare("
                    INSERT INTO tickets (
                        ticket_number, title, description, priority, category, 
                        created_by
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                $result = $stmt->execute([
                    $ticket_number,
                    'TEST: ' . substr($input['title'], 0, 50),
                    'TEST TICKET FROM API DIAGNOSTIC: ' . substr($input['description'], 0, 100),
                    isset($input['priority']) ? $input['priority'] : 'medium',
                    isset($input['category']) ? $input['category'] : 'Test',
                    'API_TEST_ENDPOINT'
                ]);
                
                if ($result) {
                    $ticket_id = $db->lastInsertId();
                    $info[] = "✓ Test ticket created successfully with ID: $ticket_id and number: $ticket_number";
                    $test_results['created_ticket'] = [
                        'id' => $ticket_id,
                        'ticket_number' => $ticket_number
                    ];
                } else {
                    $errors[] = 'Failed to execute INSERT statement';
                }
            }
        }
    } catch (Exception $e) {
        $errors[] = 'Test ticket creation error: ' . $e->getMessage();
    }
}

// Return diagnostic report
$diagnostic_report = [
    'status' => empty($errors) ? 'HEALTHY' : 'ISSUES_FOUND',
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'errors' => $errors,
    'warnings' => $warnings,
    'info' => $info,
    'test_results' => $test_results
];

http_response_code(empty($errors) ? 200 : 500);
echo json_encode($diagnostic_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
