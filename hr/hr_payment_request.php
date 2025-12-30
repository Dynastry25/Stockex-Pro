<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net");

// Only HR staff can access
require_hr();

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = getDBConnection();
$success_message = '';
$error_message = '';

// Helper functions
function generateRequestNo($db) {
    $prefix = 'HRPAY';
    $year = date('Y');
    $month = date('m');
    
    // Get last request number for this month
    $stmt = $db->prepare("
        SELECT request_no FROM pending_pay 
        WHERE request_no LIKE ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(["$prefix$year$month%"]);
    $last = $stmt->fetch();
    
    if ($last) {
        $last_no = intval(substr($last['request_no'], -4));
        $new_no = str_pad($last_no + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_no = '0001';
    }
    
    return $prefix . $year . $month . $new_no;
}

function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function validateAmount($amount) {
    return is_numeric($amount) && $amount > 0 && $amount <= 999999999.99;
}

function validateCurrency($currency) {
    $allowed_currencies = ['Tsh', 'Ksh', 'USD', 'UGsh'];
    return in_array($currency, $allowed_currencies);
}

// Fetch ledger types for Pay To dropdown
try {
    $ledger_types_stmt = $db->query("SELECT code, description FROM ledger_types WHERE status = 'active' ORDER BY description");
    $ledger_types = $ledger_types_stmt->fetchAll();
} catch (PDOException $e) {
    $ledger_types = [];
    $error_message = "Error fetching ledger types: " . $e->getMessage();
}

// Handle AJAX request for fetching entities based on ledger type
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_entities') {
    $ledger_type = $_GET['ledger_type'] ?? '';
    $search = $_GET['search'] ?? '';
    
    $entities = [];
    
    try {
        switch ($ledger_type) {
            case 'A': // Agent
                $query = "SELECT id, name as display_name FROM agents WHERE status = 'active'";
                if (!empty($search)) {
                    $query .= " AND (name LIKE ? OR id LIKE ?)";
                    $params = ["%$search%", "%$search%"];
                }
                $query .= " ORDER BY name LIMIT 100";
                
                $stmt = $db->prepare($query);
                if (!empty($search)) {
                    $stmt->execute($params);
                } else {
                    $stmt->execute();
                }
                $entities = $stmt->fetchAll();
                break;
                
            case 'B': // Broker
                $query = "SELECT id, broker_name as display_name FROM brokers WHERE status = 'active'";
                if (!empty($search)) {
                    $query .= " AND (broker_name LIKE ? OR id LIKE ?)";
                    $params = ["%$search%", "%$search%"];
                }
                $query .= " ORDER BY broker_name LIMIT 100";
                
                $stmt = $db->prepare($query);
                if (!empty($search)) {
                    $stmt->execute($params);
                } else {
                    $stmt->execute();
                }
                $entities = $stmt->fetchAll();
                break;
                
            case 'C': // Supplier
                $query = "SELECT id, supplier_name as display_name FROM suppliers WHERE status = 'active'";
                if (!empty($search)) {
                    $query .= " AND (supplier_name LIKE ? OR id LIKE ?)";
                    $params = ["%$search%", "%$search%"];
                }
                $query .= " ORDER BY supplier_name LIMIT 100";
                
                $stmt = $db->prepare($query);
                if (!empty($search)) {
                    $stmt->execute($params);
                } else {
                    $stmt->execute();
                }
                $entities = $stmt->fetchAll();
                break;
                
            case 'D': // Customer
                $query = "SELECT id, client_name as display_name FROM clients WHERE status = 'active'";
                if (!empty($search)) {
                    $query .= " AND (client_name LIKE ? OR id LIKE ?)";
                    $params = ["%$search%", "%$search%"];
                }
                $query .= " ORDER BY client_name LIMIT 100";
                
                $stmt = $db->prepare($query);
                if (!empty($search)) {
                    $stmt->execute($params);
                } else {
                    $stmt->execute();
                }
                $entities = $stmt->fetchAll();
                break;
                
            case 'O': // Nominal Client (Chart of Accounts)
                $query = "
                    SELECT 
                        account_code as id,
                        CONCAT(account_code, ' - ', account_name) as display_name,
                        account_type,
                        level,
                        is_group_account
                    FROM chart_of_accounts 
                    WHERE is_active = 1 
                    AND (is_group_account = 0 OR level IN (2, 3, 4, 5))
                ";
                
                if (!empty($search)) {
                    $query .= " AND (account_code LIKE ? OR account_name LIKE ?)";
                    $params = ["%$search%", "%$search%"];
                }
                
                $query .= " ORDER BY account_code LIMIT 100";
                
                $stmt = $db->prepare($query);
                if (!empty($search)) {
                    $stmt->execute($params);
                } else {
                    $stmt->execute();
                }
                $entities = $stmt->fetchAll();
                break;
                
            case 'U': // Custodian
                $query = "SELECT id, custodian_name as display_name FROM custodians WHERE status = 'active'";
                if (!empty($search)) {
                    $query .= " AND (custodian_name LIKE ? OR id LIKE ?)";
                    $params = ["%$search%", "%$search%"];
                }
                $query .= " ORDER BY custodian_name LIMIT 100";
                
                $stmt = $db->prepare($query);
                if (!empty($search)) {
                    $stmt->execute($params);
                } else {
                    $stmt->execute();
                }
                $entities = $stmt->fetchAll();
                break;
        }
        
        header('Content-Type: application/json');
        echo json_encode($entities);
        exit;
        
    } catch (PDOException $e) {
        error_log("Error fetching entities for ledger type $ledger_type: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }
}

// Handle AJAX request for getting entity details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_entity_details') {
    $ledger_type = $_GET['ledger_type'] ?? '';
    $entity_id = $_GET['entity_id'] ?? '';
    
    try {
        $entity_details = [];
        
        switch ($ledger_type) {
            case 'A': // Agent
                $stmt = $db->prepare("SELECT id, name, email, phone, address FROM agents WHERE id = ? AND status = 'active'");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch();
                if ($entity) {
                    $entity_details = [
                        'id' => $entity['id'],
                        'name' => $entity['name'],
                        'email' => $entity['email'] ?? '',
                        'phone' => $entity['phone'] ?? '',
                        'address' => $entity['address'] ?? ''
                    ];
                }
                break;
                
            case 'B': // Broker
                $stmt = $db->prepare("SELECT id, broker_name, email, phone, address FROM brokers WHERE id = ? AND status = 'active'");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch();
                if ($entity) {
                    $entity_details = [
                        'id' => $entity['id'],
                        'name' => $entity['broker_name'],
                        'email' => $entity['email'] ?? '',
                        'phone' => $entity['phone'] ?? '',
                        'address' => $entity['address'] ?? ''
                    ];
                }
                break;
                
            case 'C': // Supplier
                $stmt = $db->prepare("SELECT id, supplier_name, email, phone, address FROM suppliers WHERE id = ? AND status = 'active'");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch();
                if ($entity) {
                    $entity_details = [
                        'id' => $entity['id'],
                        'name' => $entity['supplier_name'],
                        'email' => $entity['email'] ?? '',
                        'phone' => $entity['phone'] ?? '',
                        'address' => $entity['address'] ?? ''
                    ];
                }
                break;
                
            case 'D': // Customer
                $stmt = $db->prepare("SELECT id, client_name, email, phone, address FROM clients WHERE id = ? AND status = 'active'");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch();
                if ($entity) {
                    $entity_details = [
                        'id' => $entity['id'],
                        'name' => $entity['client_name'],
                        'email' => $entity['email'] ?? '',
                        'phone' => $entity['phone'] ?? '',
                        'address' => $entity['address'] ?? ''
                    ];
                }
                break;
                
            case 'O': // Chart of Accounts
                $stmt = $db->prepare("SELECT account_code, account_name, account_type, level, is_group_account FROM chart_of_accounts WHERE account_code = ? AND is_active = 1");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch();
                if ($entity) {
                    $entity_details = [
                        'id' => $entity['account_code'],
                        'name' => $entity['account_name'],
                        'account_type' => $entity['account_type'] ?? '',
                        'level' => $entity['level'] ?? '',
                        'is_group_account' => $entity['is_group_account'] ?? 0
                    ];
                }
                break;
                
            case 'U': // Custodian
                $stmt = $db->prepare("SELECT id, custodian_name, email, phone, address FROM custodians WHERE id = ? AND status = 'active'");
                $stmt->execute([$entity_id]);
                $entity = $stmt->fetch();
                if ($entity) {
                    $entity_details = [
                        'id' => $entity['id'],
                        'name' => $entity['custodian_name'],
                        'email' => $entity['email'] ?? '',
                        'phone' => $entity['phone'] ?? '',
                        'address' => $entity['address'] ?? ''
                    ];
                }
                break;
        }
        
        header('Content-Type: application/json');
        echo json_encode($entity_details);
        exit;
        
    } catch (PDOException $e) {
        error_log("Error fetching entity details: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error']);
        exit;
    }
}

// Handle AJAX request for getting payment request details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_payment_request') {
    $request_id = $_GET['request_id'] ?? '';
    
    try {
        // First, get the basic request
        $stmt = $db->prepare("SELECT * FROM pending_pay WHERE id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if (!$request) {
            echo json_encode(['error' => 'Request not found']);
            exit;
        }
        
        // Get ledger type description
        $ledger_stmt = $db->prepare("SELECT description FROM ledger_types WHERE code = ?");
        $ledger_stmt->execute([$request['pay_to_type']]);
        $ledger = $ledger_stmt->fetch();
        $request['pay_to_desc'] = $ledger['description'] ?? $request['pay_to_type'];
        
        // Get user information
        $user_stmt = $db->prepare("SELECT username, full_name FROM users WHERE id = ?");
        
        // Requested by
        $user_stmt->execute([$request['requested_by']]);
        $requester = $user_stmt->fetch();
        $request['requested_by_name'] = $requester['username'] ?? '';
        $request['requested_by_fullname'] = $requester['full_name'] ?? '';
        
        // CEO approved by
        if ($request['ceo_approved_by']) {
            $user_stmt->execute([$request['ceo_approved_by']]);
            $ceo = $user_stmt->fetch();
            $request['ceo_approved_by_name'] = $ceo['username'] ?? '';
            $request['ceo_approved_by_fullname'] = $ceo['full_name'] ?? '';
        } else {
            $request['ceo_approved_by_name'] = '';
            $request['ceo_approved_by_fullname'] = '';
        }
        
        // Finance approved by
        if ($request['finance_approved_by']) {
            $user_stmt->execute([$request['finance_approved_by']]);
            $finance = $user_stmt->fetch();
            $request['finance_approved_by_name'] = $finance['username'] ?? '';
            $request['finance_approved_by_fullname'] = $finance['full_name'] ?? '';
        } else {
            $request['finance_approved_by_name'] = '';
            $request['finance_approved_by_fullname'] = '';
        }
        
        // Paid by
        if ($request['paid_by']) {
            $user_stmt->execute([$request['paid_by']]);
            $payer = $user_stmt->fetch();
            $request['paid_by_name'] = $payer['username'] ?? '';
            $request['paid_by_fullname'] = $payer['full_name'] ?? '';
        } else {
            $request['paid_by_name'] = '';
            $request['paid_by_fullname'] = '';
        }
        
        header('Content-Type: application/json');
        echo json_encode($request);
        exit;
        
    } catch (PDOException $e) {
        error_log("Error fetching request: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Handle AJAX request for tracking payment request
if (isset($_GET['ajax']) && $_GET['ajax'] == 'track_payment_request') {
    $request_id = $_GET['request_id'] ?? '';
    
    try {
        // Get request details
        $stmt = $db->prepare("SELECT * FROM pending_pay WHERE id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if (!$request) {
            echo json_encode(['error' => 'Request not found']);
            exit;
        }
        
        // Get user information
        $user_stmt = $db->prepare("SELECT username, full_name FROM users WHERE id = ?");
        
        // Calculate progress
        $progress = 0;
        $next_step = '';
        $estimated_completion = 'N/A';
        
        if ($request['status'] == 'pending') {
            $progress = 0;
            $next_step = 'CEO Approval';
            $estimated_completion = '2-3 business days';
        } elseif ($request['status'] == 'approved_ceo') {
            $progress = 50;
            $next_step = 'Finance Approval';
            $estimated_completion = '1-2 business days';
        } elseif ($request['status'] == 'approved_finance') {
            $progress = 75;
            $next_step = 'Payment Processing';
            $estimated_completion = '1 business day';
        } elseif ($request['status'] == 'paid') {
            $progress = 100;
            $next_step = 'Completed';
            $estimated_completion = 'Completed';
        } elseif ($request['status'] == 'rejected') {
            $progress = 0;
            $next_step = 'Request Rejected';
            $estimated_completion = 'N/A';
        }
        
        // Build timeline steps
        $timeline = [
            'request_no' => $request['request_no'],
            'progress' => $progress,
            'next_step' => $next_step,
            'estimated_completion' => $estimated_completion,
            'steps' => [
                'created' => [
                    'completed' => true,
                    'date' => $request['requested_at'],
                    'by' => '',
                    'current' => false
                ],
                'ceo_approval' => [
                    'completed' => !empty($request['ceo_approved_at']),
                    'date' => $request['ceo_approved_at'],
                    'by' => '',
                    'notes' => $request['rejection_reason'] ?? '',
                    'current' => $request['status'] == 'pending'
                ],
                'finance_approval' => [
                    'completed' => !empty($request['finance_approved_at']),
                    'date' => $request['finance_approved_at'],
                    'by' => '',
                    'notes' => '',
                    'current' => $request['status'] == 'approved_ceo'
                ],
                'payment' => [
                    'completed' => !empty($request['paid_at']),
                    'date' => $request['paid_at'],
                    'by' => '',
                    'current' => $request['status'] == 'approved_finance'
                ]
            ]
        ];
        
        // Get user names for each step
        if ($request['requested_by']) {
            $user_stmt->execute([$request['requested_by']]);
            $user = $user_stmt->fetch();
            $timeline['steps']['created']['by'] = $user['full_name'] ?? $user['username'] ?? 'Unknown';
        }
        
        if ($request['ceo_approved_by']) {
            $user_stmt->execute([$request['ceo_approved_by']]);
            $user = $user_stmt->fetch();
            $timeline['steps']['ceo_approval']['by'] = $user['full_name'] ?? $user['username'] ?? 'Unknown';
        }
        
        if ($request['finance_approved_by']) {
            $user_stmt->execute([$request['finance_approved_by']]);
            $user = $user_stmt->fetch();
            $timeline['steps']['finance_approval']['by'] = $user['full_name'] ?? $user['username'] ?? 'Unknown';
        }
        
        if ($request['paid_by']) {
            $user_stmt->execute([$request['paid_by']]);
            $user = $user_stmt->fetch();
            $timeline['steps']['payment']['by'] = $user['full_name'] ?? $user['username'] ?? 'Unknown';
        }
        
        header('Content-Type: application/json');
        echo json_encode($timeline);
        exit;
        
    } catch (PDOException $e) {
        error_log("Error tracking request: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Handle payment request creation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Security token validation failed. Please refresh the page and try again.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    elseif (isset($_POST['create_payment_request'])) {
        $subject = $_POST['subject'] ?? '';
        $pay_to_type = $_POST['pay_to_type'] ?? '';
        $payee_id = $_POST['payee_id'] ?? '';
        $payee_name = $_POST['payee_name'] ?? '';
        $payee_bank_name = $_POST['payee_bank_name'] ?? '';
        $payee_branch = $_POST['payee_branch'] ?? '';
        $payee_account_name = $_POST['payee_account_name'] ?? '';
        $payee_account_no = $_POST['payee_account_no'] ?? '';
        $currency = $_POST['currency'] ?? '';
        $amount_paid = $_POST['amount_paid'] ?? '';
        $cheque_no = $_POST['cheque_no'] ?? '';
        $payment_description = $_POST['payment_description'] ?? '';
        
        // Validation
        $validation_errors = [];
        
        if (empty($subject)) {
            $validation_errors[] = 'Subject is required';
        }
        
        $allowed_account_types = array_column($ledger_types, 'code');
        if (!in_array($pay_to_type, $allowed_account_types)) {
            $validation_errors[] = 'Invalid Pay To type';
        }
        
        if (empty($payee_id)) {
            $validation_errors[] = 'Payee ID is required';
        }
        
        if (empty($payee_name)) {
            $validation_errors[] = 'Payee name is required';
        }
        
        if (empty($payee_account_no)) {
            $validation_errors[] = 'Payee account number is required';
        }
        
        if (!validateCurrency($currency)) {
            $validation_errors[] = 'Invalid currency';
        }
        
        if (!validateAmount($amount_paid)) {
            $validation_errors[] = 'Invalid amount';
        }
        
        if (!empty($validation_errors)) {
            $error_message = 'Validation errors: ' . implode(', ', $validation_errors);
        } else {
            try {
                $db->beginTransaction();
                
                // Generate request number
                $request_no = generateRequestNo($db);
                
                // Get current user's ID
                $user_id = 1; // Default
                if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
                    $user_id = (int)$_SESSION['user_id'];
                } else if (isset($_SESSION['username'])) {
                    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$_SESSION['username']]);
                    $user = $stmt->fetch();
                    $user_id = $user ? (int)$user['id'] : 1;
                }
                
                // Insert into pending_pay table
                $stmt = $db->prepare("
                    INSERT INTO pending_pay (
                        request_no, subject, pay_to_type, payee_id, payee_name, 
                        payee_bank_name, payee_branch, payee_account_name,
                        payee_account_no, currency, amount_paid, cheque_no,
                        payment_description, requested_by, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                
                $stmt->execute([
                    $request_no,
                    sanitizeInput($subject),
                    $pay_to_type,
                    $payee_id,
                    sanitizeInput($payee_name),
                    sanitizeInput($payee_bank_name),
                    sanitizeInput($payee_branch),
                    sanitizeInput($payee_account_name),
                    sanitizeInput($payee_account_no),
                    $currency,
                    $amount_paid,
                    sanitizeInput($cheque_no),
                    sanitizeInput($payment_description),
                    $user_id
                ]);
                
                $request_id = $db->lastInsertId();
                
                // Insert into audit trail
                $audit_stmt = $db->prepare("
                    INSERT INTO audit_trail (
                        user_id, action, description, ip_address, user_agent
                    ) VALUES (?, 'create_payment_request', ?, ?, ?)
                ");
                
                $audit_stmt->execute([
                    $user_id,
                    "Created payment request: $request_no for " . number_format($amount_paid, 2) . " $currency",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                ]);
                
                $db->commit();
                
                $success_message = "Payment request created successfully! Request Number: " . htmlspecialchars($request_no) . 
                                 ". The request has been sent to CEO for approval.";
                
                // Clear form on success
                $_POST = [];
                
                // Regenerate CSRF token
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Error creating payment request: " . $e->getMessage();
                error_log("Payment request creation error: " . $e->getMessage());
            }
        }
    }
}

// Fetch user's pending requests (with simplified query to avoid collation issues)
$user_requests = [];
try {
    $user_id = 1; // Default
    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        $user_id = (int)$_SESSION['user_id'];
    }
    
    // First get all pending_pay records for this user
    $requests_stmt = $db->prepare("
        SELECT pp.*
        FROM pending_pay pp
        WHERE pp.requested_by = ?
        ORDER BY pp.requested_at DESC
        LIMIT 50
    ");
    
    $requests_stmt->execute([$user_id]);
    $user_requests = $requests_stmt->fetchAll();
    
    // For each request, manually get the ledger type description and user info
    foreach ($user_requests as &$request) {
        // Get ledger type description
        $ledger_stmt = $db->prepare("
            SELECT description 
            FROM ledger_types 
            WHERE code = ?
        ");
        $ledger_stmt->execute([$request['pay_to_type']]);
        $ledger = $ledger_stmt->fetch();
        $request['pay_to_desc'] = $ledger['description'] ?? $request['pay_to_type'];
        
        // Get user name
        $user_stmt = $db->prepare("
            SELECT username, full_name 
            FROM users 
            WHERE id = ?
        ");
        $user_stmt->execute([$request['requested_by']]);
        $user = $user_stmt->fetch();
        $request['requested_by_name'] = $user['username'] ?? '';
        $request['requested_by_fullname'] = $user['full_name'] ?? '';
        
        // Get CEO approval info if exists
        if ($request['ceo_approved_by']) {
            $ceo_stmt = $db->prepare("SELECT username, full_name FROM users WHERE id = ?");
            $ceo_stmt->execute([$request['ceo_approved_by']]);
            $ceo = $ceo_stmt->fetch();
            $request['ceo_approved_by_name'] = $ceo['username'] ?? '';
            $request['ceo_approved_by_fullname'] = $ceo['full_name'] ?? '';
        }
        
        // Get Finance approval info if exists
        if ($request['finance_approved_by']) {
            $finance_stmt = $db->prepare("SELECT username, full_name FROM users WHERE id = ?");
            $finance_stmt->execute([$request['finance_approved_by']]);
            $finance = $finance_stmt->fetch();
            $request['finance_approved_by_name'] = $finance['username'] ?? '';
            $request['finance_approved_by_fullname'] = $finance['full_name'] ?? '';
        }
    }
    
} catch (PDOException $e) {
    $user_requests = [];
    $error_message = "Error fetching requests: " . $e->getMessage();
    error_log("Error fetching user requests: " . $e->getMessage());
}

$page_title = 'HR Payment Request System';
include '../includes/header.php';
?>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">
                            <i class="bi bi-cash-stack text-white" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">HR Payment Request</h1>
                        <p class="page-subtitle">Create payment requests for CEO and Finance approval</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="d-flex flex-column align-items-end">
                    <span class="text-muted small">Human Resources Department</span>
                    <span class="fw-semibold">Payment Authorization Portal</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Create New Payment Request</h5>
                    <button type="button" class="btn btn-sm btn-light" id="toggleFormBtn">
                        <i class="bi bi-dash-lg" id="toggleFormIcon"></i>
                    </button>
                </div>
                <div class="card-body" id="paymentFormContainer">
                    <form method="POST" id="paymentRequestForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="payee_id" id="payee_id" value="">
                        
                        <div class="alert alert-info mb-4">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Approval Process:</strong> This request requires two approvals - CEO approval first, then Finance approval. After both approvals, payment will be processed.
                        </div>
                        
                        <div class="row g-3">
                            <!-- Subject -->
                            <div class="col-md-12">
                                <label class="form-label">Subject / Purpose <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="subject" id="subject" 
                                       value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>" 
                                       placeholder="Enter payment purpose or subject" required>
                                <small class="text-muted">Brief description of why this payment is needed</small>
                            </div>

                            <!-- Pay To Type -->
                            <div class="col-md-6">
                                <label class="form-label">Pay To Type <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <select class="form-select" name="pay_to_type" id="pay_to_type" required>
                                        <option value="">Select Payee Type</option>
                                        <?php foreach ($ledger_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type['code']); ?>" 
                                                    <?php echo ($_POST['pay_to_type'] ?? '') == $type['code'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($type['description']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#accountTypesModal">
                                        <i class="bi bi-list-ul"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Payee Name -->
                            <div class="col-md-6">
                                <label class="form-label">Payee Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="payee_name" id="payee_name" 
                                           value="<?php echo htmlspecialchars($_POST['payee_name'] ?? ''); ?>" 
                                           placeholder="Payee name" required readonly>
                                    <button type="button" class="btn btn-outline-primary" id="openPayeeModal" data-bs-toggle="modal" data-bs-target="#payeeModal" disabled>
                                        <i class="bi bi-search"></i> Browse
                                    </button>
                                </div>
                                <small class="text-muted" id="payee_indicator"></small>
                            </div>

                            <!-- Bank Details -->
                            <div class="col-md-6">
                                <label class="form-label">Payee Bank Name</label>
                                <input type="text" class="form-control" name="payee_bank_name" id="payee_bank_name" 
                                       value="<?php echo htmlspecialchars($_POST['payee_bank_name'] ?? ''); ?>" 
                                       placeholder="Bank name (e.g., CRDB, NMB)">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Bank Branch</label>
                                <input type="text" class="form-control" name="payee_branch" id="payee_branch" 
                                       value="<?php echo htmlspecialchars($_POST['payee_branch'] ?? ''); ?>" 
                                       placeholder="Branch location">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Account Name</label>
                                <input type="text" class="form-control" name="payee_account_name" id="payee_account_name" 
                                       value="<?php echo htmlspecialchars($_POST['payee_account_name'] ?? ''); ?>" 
                                       placeholder="Account holder name">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Account Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="payee_account_no" id="payee_account_no" 
                                       value="<?php echo htmlspecialchars($_POST['payee_account_no'] ?? ''); ?>" 
                                       placeholder="Account number" required>
                            </div>

                            <!-- Currency and Amount -->
                            <div class="col-md-4">
                                <label class="form-label">Currency <span class="text-danger">*</span></label>
                                <select class="form-select" name="currency" id="currency" required>
                                    <option value="">Select Currency</option>
                                    <option value="Tsh" <?php echo ($_POST['currency'] ?? '') === 'Tsh' ? 'selected' : ''; ?>>Tanzanian Shilling (Tsh)</option>
                                    <option value="Ksh" <?php echo ($_POST['currency'] ?? '') === 'Ksh' ? 'selected' : ''; ?>>Kenyan Shilling (Ksh)</option>
                                    <option value="USD" <?php echo ($_POST['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>US Dollar (USD)</option>
                                    <option value="UGsh" <?php echo ($_POST['currency'] ?? '') === 'UGsh' ? 'selected' : ''; ?>>Ugandan Shilling (UGsh)</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Amount <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="amount_paid" id="amount_paid" 
                                       value="<?php echo htmlspecialchars($_POST['amount_paid'] ?? ''); ?>" 
                                       step="0.01" min="0.01" max="999999999.99" placeholder="0.00" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Cheque No (if applicable)</label>
                                <input type="text" class="form-control" name="cheque_no" id="cheque_no" 
                                       value="<?php echo htmlspecialchars($_POST['cheque_no'] ?? ''); ?>" 
                                       placeholder="If paying by cheque">
                            </div>

                            <!-- Payment Description -->
                            <div class="col-md-12">
                                <label class="form-label">Payment Description</label>
                                <textarea class="form-control" name="payment_description" id="payment_description" 
                                          rows="3" placeholder="Detailed description of the payment"><?php echo htmlspecialchars($_POST['payment_description'] ?? ''); ?></textarea>
                            </div>

                            <!-- Submit Button -->
                            <div class="col-12">
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <button type="reset" class="btn btn-outline-secondary me-md-2" id="resetFormBtn">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Reset Form
                                    </button>
                                    <button type="submit" name="create_payment_request" class="btn btn-primary" id="createRequestBtn">
                                        <i class="bi bi-send-check me-1"></i>Submit for CEO Approval
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- My Payment Requests -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>My Payment Requests</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover" id="requestsTable">
                            <thead>
                                <tr>
                                    <th>Request No</th>
                                    <th>Date</th>
                                    <th>Subject</th>
                                    <th>Payee Name</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Approval Progress</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($user_requests)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">
                                            <i class="bi bi-inbox display-4"></i>
                                            <p class="mt-2">No payment requests found</p>
                                        </td>
                                    </tr>
                                <?php else:
                                    foreach ($user_requests as $request): 
                                        // Determine status badge color
                                        $status_badge = '';
                                        switch ($request['status']) {
                                            case 'pending':
                                                $status_badge = 'bg-warning';
                                                break;
                                            case 'approved_ceo':
                                                $status_badge = 'bg-info';
                                                break;
                                            case 'approved_finance':
                                                $status_badge = 'bg-success';
                                                break;
                                            case 'rejected':
                                                $status_badge = 'bg-danger';
                                                break;
                                            case 'paid':
                                                $status_badge = 'bg-primary';
                                                break;
                                            default:
                                                $status_badge = 'bg-secondary';
                                        }
                                        
                                        // Calculate approval progress
                                        $progress = 0;
                                        if ($request['status'] == 'pending') {
                                            $progress = 0;
                                            $progress_text = 'CEO Approval Pending';
                                        } elseif ($request['status'] == 'approved_ceo') {
                                            $progress = 50;
                                            $progress_text = 'Finance Approval Pending';
                                        } elseif ($request['status'] == 'approved_finance') {
                                            $progress = 75;
                                            $progress_text = 'Payment Processing';
                                        } elseif ($request['status'] == 'paid') {
                                            $progress = 100;
                                            $progress_text = 'Completed';
                                        } elseif ($request['status'] == 'rejected') {
                                            $progress = 0;
                                            $progress_text = 'Rejected';
                                        }
                                    ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($request['request_no']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($request['requested_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($request['subject']); ?></td>
                                            <td><?php echo htmlspecialchars($request['payee_name']); ?></td>
                                            <td class="fw-bold">
                                                <?php echo number_format($request['amount_paid'], 2); ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($request['currency']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $status_badge; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar progress-bar-striped progress-bar-animated 
                                                        <?php echo $progress == 100 ? 'bg-success' : 'bg-info'; ?>" 
                                                        role="progressbar" 
                                                        style="width: <?php echo $progress; ?>%;" 
                                                        aria-valuenow="<?php echo $progress; ?>" 
                                                        aria-valuemin="0" 
                                                        aria-valuemax="100">
                                                        <?php echo $progress; ?>%
                                                    </div>
                                                </div>
                                                <small class="text-muted"><?php echo $progress_text; ?></small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-outline-primary view-request" 
                                                            data-request-id="<?php echo (int)$request['id']; ?>">
                                                        <i class="bi bi-eye"></i> View
                                                    </button>
                                                    <?php if ($request['status'] == 'pending'): ?>
                                                        <button type="button" class="btn btn-outline-warning edit-request" 
                                                                data-request-id="<?php echo (int)$request['id']; ?>">
                                                            <i class="bi bi-pencil"></i> Edit
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger cancel-request" 
                                                                data-request-id="<?php echo (int)$request['id']; ?>"
                                                                data-request-no="<?php echo htmlspecialchars($request['request_no']); ?>">
                                                            <i class="bi bi-x-circle"></i> Cancel
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-outline-info track-request" 
                                                            data-request-id="<?php echo (int)$request['id']; ?>">
                                                        <i class="bi bi-diagram-3"></i> Track
                                                    </button>
                                                    <button type="button" class="btn btn-outline-success download-pdf" 
                                                            data-request-id="<?php echo (int)$request['id']; ?>"
                                                            data-request-no="<?php echo htmlspecialchars($request['request_no']); ?>">
                                                        <i class="bi bi-file-pdf"></i> PDF
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Request Modal -->
    <div class="modal fade" id="viewRequestModal" tabindex="-1" aria-labelledby="viewRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="viewRequestModalLabel">
                        <i class="bi bi-receipt me-2"></i>Payment Request Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="requestDetailsContent">
                    <!-- Request details will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Track Request Modal -->
    <div class="modal fade" id="trackRequestModal" tabindex="-1" aria-labelledby="trackRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="trackRequestModalLabel">
                        <i class="bi bi-diagram-3 me-2"></i>Request Approval Tracking
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="trackRequestContent">
                    <!-- Tracking timeline will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Account Types Modal -->
    <div class="modal fade" id="accountTypesModal" tabindex="-1" aria-labelledby="accountTypesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="accountTypesModalLabel">Select Account Type</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="accountTypesFilter" placeholder="Filter account types...">
                    </div>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-hover" id="accountTypesTable">
                            <thead class="sticky-top bg-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ledger_types as $type): ?>
                                <tr class="clickable-row" 
                                    data-value="<?php echo htmlspecialchars($type['code']); ?>"
                                    data-text="<?php echo htmlspecialchars($type['description']); ?>">
                                    <td><strong><?php echo htmlspecialchars($type['code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($type['description']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Payee Selection Modal -->
    <div class="modal fade" id="payeeModal" tabindex="-1" aria-labelledby="payeeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="payeeModalLabel">Select Payee</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="row g-2">
                            <div class="col-md-8">
                                <input type="text" class="form-control" id="payeeSearch" placeholder="Search payees...">
                            </div>
                            <div class="col-md-4">
                                <select class="form-select" id="payeeTypeFilter">
                                    <!-- Options will be populated dynamically -->
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-sm table-hover" id="payeeTable">
                            <thead class="sticky-top bg-light">
                                <tr>
                                    <th>ID/Code</th>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Payees will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const payToTypeSelect = document.getElementById('pay_to_type');
    const payeeNameInput = document.getElementById('payee_name');
    const payeeIdInput = document.getElementById('payee_id');
    const payeeModalBtn = document.getElementById('openPayeeModal');
    const payeeIndicator = document.getElementById('payee_indicator');
    const toggleFormBtn = document.getElementById('toggleFormBtn');
    const toggleFormIcon = document.getElementById('toggleFormIcon');
    const paymentFormContainer = document.getElementById('paymentFormContainer');
    const resetFormBtn = document.getElementById('resetFormBtn');
    const createRequestBtn = document.getElementById('createRequestBtn');
    const currencySelect = document.getElementById('currency');
    const amountInput = document.getElementById('amount_paid');
    
    let isFormMinimized = false;
    let currentLedgerType = '';

    // Toggle form minimize/maximize
    toggleFormBtn.addEventListener('click', function() {
        if (isFormMinimized) {
            paymentFormContainer.style.display = 'block';
            toggleFormIcon.className = 'bi bi-dash-lg';
            isFormMinimized = false;
        } else {
            paymentFormContainer.style.display = 'none';
            toggleFormIcon.className = 'bi bi-plus-lg';
            isFormMinimized = true;
        }
    });

    // Reset form
    resetFormBtn.addEventListener('click', function() {
        document.getElementById('paymentRequestForm').reset();
        payeeNameInput.value = '';
        payeeIdInput.value = '';
        payeeModalBtn.disabled = true;
        payeeIndicator.textContent = '';
        currentLedgerType = '';
        showToast('Form reset successfully', 'info');
    });

    // Event listener for account type change
    payToTypeSelect.addEventListener('change', function() {
        currentLedgerType = this.value;
        if (currentLedgerType) {
            payeeModalBtn.disabled = false;
            payeeNameInput.value = '';
            payeeIdInput.value = '';
            payeeIndicator.textContent = 'Click Browse to select payee';
        } else {
            payeeModalBtn.disabled = true;
            payeeNameInput.value = '';
            payeeIdInput.value = '';
            payeeIndicator.textContent = '';
        }
    });

    // Account Types Modal functionality
    const accountTypesFilter = document.getElementById('accountTypesFilter');
    const accountTypesTable = document.getElementById('accountTypesTable');
    
    if (accountTypesFilter) {
        accountTypesFilter.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = accountTypesTable.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
        
        // Make rows clickable
        accountTypesTable.querySelectorAll('tbody tr.clickable-row').forEach(row => {
            row.addEventListener('click', function() {
                const value = this.getAttribute('data-value');
                const text = this.getAttribute('data-text');
                
                document.getElementById('pay_to_type').value = value;
                currentLedgerType = value;
                payeeModalBtn.disabled = false;
                payeeNameInput.value = '';
                payeeIdInput.value = '';
                payeeIndicator.textContent = 'Click Browse to select payee';
                
                // Close modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('accountTypesModal'));
                modal.hide();
            });
        });
    }

    // Payee Modal functionality
    const payeeSearch = document.getElementById('payeeSearch');
    const payeeTable = document.getElementById('payeeTable');
    const payeeTypeFilter = document.getElementById('payeeTypeFilter');
    let allPayees = [];

    // Load payees for modal
    function loadPayeesForModal(ledgerType, search = '') {
        if (!ledgerType) return;
        
        fetch(`?ajax=get_entities&ledger_type=${encodeURIComponent(ledgerType)}&search=${encodeURIComponent(search)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(payees => {
                const tableBody = payeeTable.querySelector('tbody');
                tableBody.innerHTML = '';
                allPayees = payees;
                
                if (payees.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="4" class="text-center">No payees found</td></tr>';
                    return;
                }
                
                payees.forEach(payee => {
                    const row = document.createElement('tr');
                    row.className = 'clickable-row';
                    row.setAttribute('data-id', payee.id);
                    row.setAttribute('data-name', payee.display_name);
                    
                    let typeText = '';
                    switch (ledgerType) {
                        case 'A': typeText = 'Agent'; break;
                        case 'B': typeText = 'Broker'; break;
                        case 'C': typeText = 'Supplier'; break;
                        case 'D': typeText = 'Customer'; break;
                        case 'O': typeText = 'Chart Account'; break;
                        case 'U': typeText = 'Custodian'; break;
                        default: typeText = ledgerType;
                    }
                    
                    row.innerHTML = `
                        <td><strong>${payee.id}</strong></td>
                        <td>${payee.display_name}</td>
                        <td><span class="badge bg-secondary">${typeText}</span></td>
                        <td>
                            ${payee.account_type ? `<small class="text-muted">${payee.account_type}</small>` : ''}
                            ${payee.level ? `<small class="text-muted"> | Level ${payee.level}</small>` : ''}
                        </td>
                    `;
                    
                    // Make row clickable
                    row.addEventListener('click', function() {
                        const id = this.getAttribute('data-id');
                        const name = this.getAttribute('data-name');
                        
                        // Set the value in the form
                        document.getElementById('payee_id').value = id;
                        document.getElementById('payee_name').value = name;
                        
                        // Get additional details
                        fetch(`?ajax=get_entity_details&ledger_type=${encodeURIComponent(currentLedgerType)}&entity_id=${encodeURIComponent(id)}`)
                            .then(response => response.json())
                            .then(entity => {
                                if (entity.error) {
                                    payeeIndicator.textContent = `Selected: ${name}`;
                                } else {
                                    let indicatorText = `Selected: ${entity.name}`;
                                    if (currentLedgerType === 'O') {
                                        indicatorText += ` | Type: ${entity.account_type} | Level: ${entity.level} | ${entity.is_group_account ? 'Group Account' : 'Detail Account'}`;
                                    } else if (entity.email || entity.phone) {
                                        indicatorText += ` | ${entity.email || ''} ${entity.phone ? '| ' + entity.phone : ''}`;
                                    }
                                    payeeIndicator.textContent = indicatorText;
                                }
                            })
                            .catch(error => {
                                console.error('Error loading entity details:', error);
                                payeeIndicator.textContent = `Selected: ${name}`;
                            });
                        
                        // Close modal
                        const modal = bootstrap.Modal.getInstance(document.getElementById('payeeModal'));
                        modal.hide();
                    });
                    
                    tableBody.appendChild(row);
                });
            })
            .catch(error => {
                console.error('Error loading payees for modal:', error);
                const tableBody = payeeTable.querySelector('tbody');
                tableBody.innerHTML = '<tr><td colspan="4" class="text-center">Error loading payees</td></tr>';
            });
    }

    // Open payee modal
    document.getElementById('payeeModal').addEventListener('show.bs.modal', function() {
        const ledgerType = payToTypeSelect.value;
        if (!ledgerType) {
            showToast('Please select a Pay To type first', 'warning');
            return;
        }
        
        // Update modal title
        document.getElementById('payeeModalLabel').textContent = `Select ${getLedgerTypeName(ledgerType)}`;
        
        // Load payees
        loadPayeesForModal(ledgerType);
        
        // Clear search
        payeeSearch.value = '';
    });

    // Search functionality for payee modal
    payeeSearch.addEventListener('keyup', function() {
        const ledgerType = payToTypeSelect.value;
        if (ledgerType) {
            loadPayeesForModal(ledgerType, this.value);
        }
    });

    // Helper function to get ledger type name
    function getLedgerTypeName(code) {
        const types = {
            'A': 'Agent',
            'B': 'Broker',
            'C': 'Supplier',
            'D': 'Customer',
            'O': 'Chart Account',
            'U': 'Custodian'
        };
        return types[code] || code;
    }

    // Form validation
    document.getElementById('paymentRequestForm').addEventListener('submit', function(e) {
        const amount = parseFloat(amountInput.value);
        if (amount <= 0 || amount > 999999999.99) {
            e.preventDefault();
            alert('Amount must be between 0.01 and 999,999,999.99');
            return;
        }
        
        const subject = document.getElementById('subject').value.trim();
        if (subject.length < 5) {
            e.preventDefault();
            alert('Subject must be at least 5 characters long');
            return;
        }
        
        const accountNo = document.getElementById('payee_account_no').value.trim();
        if (accountNo.length < 3) {
            e.preventDefault();
            alert('Account number must be at least 3 characters long');
            return;
        }
        
        if (!payeeIdInput.value) {
            e.preventDefault();
            alert('Please select a payee');
            return;
        }
        
        if (!payToTypeSelect.value) {
            e.preventDefault();
            alert('Please select a Pay To type');
            return;
        }
    });

    // Edit request functionality
    document.querySelectorAll('.edit-request').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            
            fetch(`?ajax=get_payment_request&request_id=${encodeURIComponent(requestId)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(request => {
                    if (request.error) {
                        alert('Error: ' + request.error);
                        return;
                    }

                    // Check if request can be edited (only pending requests)
                    if (request.status !== 'pending') {
                        alert('Only pending requests can be edited.');
                        return;
                    }

                    // Populate form with request data
                    document.getElementById('subject').value = request.subject;
                    document.getElementById('pay_to_type').value = request.pay_to_type;
                    
                    // Set payee information
                    document.getElementById('payee_id').value = request.payee_id;
                    document.getElementById('payee_name').value = request.payee_name;
                    
                    // Enable browse button
                    payeeModalBtn.disabled = false;
                    currentLedgerType = request.pay_to_type;
                    
                    // Set payee indicator
                    payeeIndicator.textContent = `Selected: ${request.payee_name}`;
                    
                    // Fill other fields
                    document.getElementById('payee_bank_name').value = request.payee_bank_name || '';
                    document.getElementById('payee_branch').value = request.payee_branch || '';
                    document.getElementById('payee_account_name').value = request.payee_account_name || '';
                    document.getElementById('payee_account_no').value = request.payee_account_no;
                    document.getElementById('currency').value = request.currency;
                    document.getElementById('amount_paid').value = request.amount_paid;
                    document.getElementById('cheque_no').value = request.cheque_no || '';
                    document.getElementById('payment_description').value = request.payment_description || '';

                    // Change form to update mode
                    createRequestBtn.innerHTML = '<i class="bi bi-pencil me-1"></i>Update Request';
                    createRequestBtn.name = 'update_payment_request';
                    
                    // Add hidden field for request ID
                    let requestIdField = document.querySelector('input[name="request_id"]');
                    if (!requestIdField) {
                        requestIdField = document.createElement('input');
                        requestIdField.type = 'hidden';
                        requestIdField.name = 'request_id';
                        requestIdField.value = requestId;
                        document.getElementById('paymentRequestForm').appendChild(requestIdField);
                    } else {
                        requestIdField.value = requestId;
                    }

                    // Scroll to form
                    document.getElementById('paymentFormContainer').scrollIntoView({ behavior: 'smooth' });
                    
                    showToast('Request loaded for editing', 'info');
                })
                .catch(error => {
                    console.error('Error loading request:', error);
                    alert('Error loading request data: ' + error.message);
                });
        });
    });

    // Cancel request functionality
    document.querySelectorAll('.cancel-request').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            const requestNo = this.dataset.requestNo;
            
            if (confirm(`Are you sure you want to cancel request ${requestNo}?`)) {
                // Create form data
                const formData = new FormData();
                formData.append('request_id', requestId);
                formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
                
                fetch(`?ajax=cancel_payment_request`, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showToast(data.error, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error cancelling request:', error);
                    showToast('Error cancelling request', 'danger');
                });
            }
        });
    });

    // View request functionality
    document.querySelectorAll('.view-request').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            
            fetch(`?ajax=get_payment_request&request_id=${encodeURIComponent(requestId)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(request => {
                    if (request.error) {
                        document.getElementById('requestDetailsContent').innerHTML = 
                            `<div class="alert alert-danger">${request.error}</div>`;
                    } else {
                        const details = `
                            <div class="request-details">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h6 class="text-primary">Request Information</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="fw-bold" style="width: 40%;">Request No:</td>
                                                <td><strong>${request.request_no}</strong></td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Subject:</td>
                                                <td>${request.subject}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Requested Date:</td>
                                                <td>${new Date(request.requested_at).toLocaleString()}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Requested By:</td>
                                                <td>${request.requested_by_name || 'N/A'}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Status:</td>
                                                <td>
                                                    <span class="badge ${getStatusBadgeClass(request.status)}">
                                                        ${request.status.replace('_', ' ').toUpperCase()}
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-primary">Payment Details</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="fw-bold" style="width: 40%;">Pay To:</td>
                                                <td>${request.pay_to_desc || request.pay_to_type}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Payee Name:</td>
                                                <td>${request.payee_name}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Payee ID:</td>
                                                <td>${request.payee_id || 'N/A'}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Amount:</td>
                                                <td class="fw-bold text-success">
                                                    ${parseFloat(request.amount_paid).toLocaleString()} ${request.currency}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Cheque No:</td>
                                                <td>${request.cheque_no || '-'}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Description:</td>
                                                <td>${request.payment_description || '-'}</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <h6 class="text-primary">Bank Details</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="fw-bold" style="width: 40%;">Bank Name:</td>
                                                <td>${request.payee_bank_name || '-'}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Branch:</td>
                                                <td>${request.payee_branch || '-'}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Account Name:</td>
                                                <td>${request.payee_account_name || '-'}</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Account No:</td>
                                                <td>${request.payee_account_no}</td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-primary">Approval Status</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="fw-bold" style="width: 40%;">CEO Approval:</td>
                                                <td>
                                                    ${request.ceo_approved_at ? 
                                                        `<span class="badge bg-success">Approved on ${new Date(request.ceo_approved_at).toLocaleDateString()} by ${request.ceo_approved_by_name || 'N/A'}</span>` : 
                                                        `<span class="badge bg-warning">Pending</span>`}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Finance Approval:</td>
                                                <td>
                                                    ${request.finance_approved_at ? 
                                                        `<span class="badge bg-success">Approved on ${new Date(request.finance_approved_at).toLocaleDateString()} by ${request.finance_approved_by_name || 'N/A'}</span>` : 
                                                        `<span class="badge bg-warning">Pending</span>`}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Payment Status:</td>
                                                <td>
                                                    ${request.paid_at ? 
                                                        `<span class="badge bg-primary">Paid on ${new Date(request.paid_at).toLocaleDateString()}</span>` : 
                                                        `<span class="badge bg-secondary">Not Paid</span>`}
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        `;
                        document.getElementById('requestDetailsContent').innerHTML = details;
                    }
                    
                    // Show the modal
                    const viewModal = new bootstrap.Modal(document.getElementById('viewRequestModal'));
                    viewModal.show();
                })
                .catch(error => {
                    console.error('Error loading request:', error);
                    document.getElementById('requestDetailsContent').innerHTML = 
                        '<div class="alert alert-danger">Error loading request details: ' + error.message + '</div>';
                    const viewModal = new bootstrap.Modal(document.getElementById('viewRequestModal'));
                    viewModal.show();
                });
        });
    });

    // Track request functionality
    document.querySelectorAll('.track-request').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            
            fetch(`?ajax=track_payment_request&request_id=${encodeURIComponent(requestId)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        document.getElementById('trackRequestContent').innerHTML = 
                            `<div class="alert alert-danger">${data.error}</div>`;
                    } else {
                        const timeline = `
                            <div class="tracking-timeline">
                                <h6 class="text-primary mb-3">Approval Timeline for Request: ${data.request_no}</h6>
                                
                                <div class="timeline">
                                    <!-- Step 1: Request Created -->
                                    <div class="timeline-step ${data.steps.created.completed ? 'completed' : ''}">
                                        <div class="timeline-step-header">
                                            <div class="timeline-step-icon">
                                                <i class="bi bi-file-earmark-plus"></i>
                                            </div>
                                            <h6 class="mb-0">Request Created</h6>
                                            <small class="text-muted">${new Date(data.steps.created.date).toLocaleString()}</small>
                                        </div>
                                        <div class="timeline-step-body">
                                            <p class="mb-0">Created by: ${data.steps.created.by}</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Step 2: CEO Approval -->
                                    <div class="timeline-step ${data.steps.ceo_approval.completed ? 'completed' : ''} ${data.steps.ceo_approval.current ? 'current' : ''}">
                                        <div class="timeline-step-header">
                                            <div class="timeline-step-icon">
                                                <i class="bi bi-person-badge"></i>
                                            </div>
                                            <h6 class="mb-0">CEO Approval</h6>
                                            <small class="text-muted">
                                                ${data.steps.ceo_approval.completed ? 
                                                    new Date(data.steps.ceo_approval.date).toLocaleString() : 
                                                    'Pending'}
                                            </small>
                                        </div>
                                        <div class="timeline-step-body">
                                            <p class="mb-0">
                                                ${data.steps.ceo_approval.completed ? 
                                                    `Approved by: ${data.steps.ceo_approval.by}` : 
                                                    'Waiting for CEO approval'}
                                            </p>
                                            ${data.steps.ceo_approval.notes ? 
                                                `<small class="text-muted">Notes: ${data.steps.ceo_approval.notes}</small>` : ''}
                                        </div>
                                    </div>
                                    
                                    <!-- Step 3: Finance Approval -->
                                    <div class="timeline-step ${data.steps.finance_approval.completed ? 'completed' : ''} ${data.steps.finance_approval.current ? 'current' : ''}">
                                        <div class="timeline-step-header">
                                            <div class="timeline-step-icon">
                                                <i class="bi bi-cash-coin"></i>
                                            </div>
                                            <h6 class="mb-0">Finance Approval</h6>
                                            <small class="text-muted">
                                                ${data.steps.finance_approval.completed ? 
                                                    new Date(data.steps.finance_approval.date).toLocaleString() : 
                                                    'Pending'}
                                            </small>
                                        </div>
                                        <div class="timeline-step-body">
                                            <p class="mb-0">
                                                ${data.steps.finance_approval.completed ? 
                                                    `Approved by: ${data.steps.finance_approval.by}` : 
                                                    'Waiting for Finance approval'}
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <!-- Step 4: Payment -->
                                    <div class="timeline-step ${data.steps.payment.completed ? 'completed' : ''} ${data.steps.payment.current ? 'current' : ''}">
                                        <div class="timeline-step-header">
                                            <div class="timeline-step-icon">
                                                <i class="bi bi-check-circle"></i>
                                            </div>
                                            <h6 class="mb-0">Payment Processing</h6>
                                            <small class="text-muted">
                                                ${data.steps.payment.completed ? 
                                                    new Date(data.steps.payment.date).toLocaleString() : 
                                                    'Not yet processed'}
                                            </small>
                                        </div>
                                        <div class="timeline-step-body">
                                            <p class="mb-0">
                                                ${data.steps.payment.completed ? 
                                                    `Paid by: ${data.steps.payment.by}` : 
                                                    'Payment will be processed after all approvals'}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Status Summary -->
                                <div class="card mt-4">
                                    <div class="card-body">
                                        <h6 class="card-title">Current Status Summary</h6>
                                        <div class="progress mb-3" style="height: 25px;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated 
                                                ${data.progress == 100 ? 'bg-success' : 'bg-info'}" 
                                                role="progressbar" 
                                                style="width: ${data.progress}%;" 
                                                aria-valuenow="${data.progress}" 
                                                aria-valuemin="0" 
                                                aria-valuemax="100">
                                                ${data.progress}% Complete
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p><strong>Next Step:</strong> ${data.next_step}</p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Estimated Completion:</strong> ${data.estimated_completion}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        document.getElementById('trackRequestContent').innerHTML = timeline;
                    }
                    
                    // Show the modal
                    const trackModal = new bootstrap.Modal(document.getElementById('trackRequestModal'));
                    trackModal.show();
                })
                .catch(error => {
                    console.error('Error loading tracking:', error);
                    document.getElementById('trackRequestContent').innerHTML = 
                        '<div class="alert alert-danger">Error loading tracking information: ' + error.message + '</div>';
                    const trackModal = new bootstrap.Modal(document.getElementById('trackRequestModal'));
                    trackModal.show();
                });
        });
    });

    // Download PDF functionality
    document.querySelectorAll('.download-pdf').forEach(button => {
        button.addEventListener('click', function() {
            const requestId = this.dataset.requestId;
            const requestNo = this.dataset.requestNo;
            
            showToast(`Generating PDF for request: ${requestNo}`, 'info');
            
            // Open PDF generation in new tab
            window.open(`generate_payment_request_pdf.php?request_id=${requestId}`, '_blank');
        });
    });

    // Helper function for status badge class
    function getStatusBadgeClass(status) {
        switch (status) {
            case 'pending': return 'bg-warning';
            case 'approved_ceo': return 'bg-info';
            case 'approved_finance': return 'bg-success';
            case 'rejected': return 'bg-danger';
            case 'paid': return 'bg-primary';
            default: return 'bg-secondary';
        }
    }

    // Toast notification function
    function showToast(message, type = 'info') {
        // Create toast container if it doesn't exist
        let toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            document.body.appendChild(toastContainer);
        }

        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-bg-${type} border-0`;
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;

        toastContainer.appendChild(toast);
        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();

        // Remove toast from DOM after it's hidden
        toast.addEventListener('hidden.bs.toast', () => {
            toast.remove();
        });
    }
});
</script>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-step {
    position: relative;
    margin-bottom: 30px;
}

.timeline-step:not(:last-child):before {
    content: '';
    position: absolute;
    left: -30px;
    top: 30px;
    bottom: -30px;
    width: 2px;
    background-color: #dee2e6;
}

.timeline-step.completed:not(:last-child):before {
    background-color: #198754;
}

.timeline-step-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}

.timeline-step-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background-color: #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    border: 2px solid #dee2e6;
}

.timeline-step.completed .timeline-step-icon {
    background-color: #d1e7dd;
    border-color: #198754;
    color: #198754;
}

.timeline-step.current .timeline-step-icon {
    background-color: #cfe2ff;
    border-color: #0d6efd;
    color: #0d6efd;
    animation: pulse 2s infinite;
}

.timeline-step-body {
    margin-left: 65px;
}

@keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.4); }
    70% { box-shadow: 0 0 0 10px rgba(13, 110, 253, 0); }
    100% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0); }
}

.clickable-row {
    cursor: pointer;
}

.clickable-row:hover {
    background-color: #f8f9fa;
}
</style>

<?php include '../includes/footer.php'; ?>