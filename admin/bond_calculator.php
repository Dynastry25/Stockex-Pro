<?php
// bond_calculator.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if config files exist
if (!file_exists('../config/config.php')) {
    die('Configuration file not found. Please check the path to config.php');
}

require_once '../config/config.php';

// Debug: Check if we can connect to database
try {
    $db = getDBConnection();
    if (!$db) {
        die('Database connection failed');
    }
} catch (Exception $e) {
    die('Database Error: ' . $e->getMessage());
}

// Simple session check (adjust as needed)
session_start();
if (!isset($_SESSION['user_id'])) {
    echo '<div style="padding: 20px; text-align: center;">
            <h3>Access Required</h3>
            <p>Please login to access the bond calculator.</p>
          </div>';
    exit;
}

// Get company details
try {
    $company_stmt = $db->query("SELECT name as company_name FROM companies WHERE is_active = 1 LIMIT 1");
    $company = $company_stmt->fetch();
    $company_name = $company ? $company['company_name'] : 'Bond Calculator';
} catch (Exception $e) {
    $company_name = 'Bond Calculator';
}

// Get all active bonds for dropdown
try {
    $bonds_stmt = $db->query("
        SELECT id, security_id, bond_name, coupon_rate, maturity_date, 
               issue_date, face_value, payment_frequency, ytm
        FROM bonds 
        WHERE status = 'active' 
        ORDER BY security_id
    ");
    $all_bonds = $bonds_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all_bonds = [];
}

// Get specific bond if requested
$selected_bond = null;
if (isset($_GET['bond_id'])) {
    try {
        $bond_id = intval($_GET['bond_id']);
        $stmt = $db->prepare("
            SELECT * FROM bonds 
            WHERE id = ? AND status = 'active'
        ");
        $stmt->execute([$bond_id]);
        $selected_bond = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Silently fail, $selected_bond remains null
    }
}

// Calculate bond if form submitted
$calculation_results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate_bond'])) {
    $calculation_results = calculateBondFromPost($db, $_POST);
}

function calculateBondFromPost($db, $post_data) {
    $results = [];
    
    // Get bond details from database
    $bond_id = intval($post_data['bond_id']);
    $stmt = $db->prepare("SELECT * FROM bonds WHERE id = ?");
    $stmt->execute([$bond_id]);
    $bond = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bond) {
        return ['error' => 'Bond not found'];
    }
    
    // Get input values with defaults
    $face_value_input = $post_data['face_value'] ?? ($bond['face_value'] ?? '1000');
    $face_value = floatval(str_replace([',', ' '], '', $face_value_input));
    
    $bond_quantity = intval($post_data['bond_quantity'] ?? 1);
    $clean_price = floatval($post_data['clean_price'] ?? ($bond['price'] ?? 100));
    $coupon_rate = floatval($bond['coupon_rate'] ?? 0) / 100;
    
    // Dates
    $last_coupon_date = $post_data['last_coupon_date'] ?? date('Y-m-d', strtotime('-6 months'));
    $next_coupon_date = $post_data['next_coupon_date'] ?? date('Y-m-d', strtotime('+6 months'));
    $value_date = date('Y-m-d'); // Current date as settlement date
    
    // Validate dates
    if (!strtotime($last_coupon_date) || !strtotime($next_coupon_date)) {
        return ['error' => 'Invalid date format'];
    }
    
    // Calculate time periods
    $days_in_coupon_period = 182.5; // Standard semi-annual period
    $days_accrued = max(0, floor((strtotime($value_date) - strtotime($last_coupon_date)) / (60 * 60 * 24)));
    
    // Calculations
    $total_face_value = $face_value * $bond_quantity;
    $annual_coupon_payment = $total_face_value * $coupon_rate;
    $semi_annual_coupon = $annual_coupon_payment / 2;
    $daily_coupon_rate = $annual_coupon_payment / 365;
    
    // Accrued interest
    $accrued_interest = ($annual_coupon_payment / $days_in_coupon_period) * $days_accrued;
    
    // Clean price value
    $clean_price_decimal = $clean_price / 100;
    $clean_price_value = $total_face_value * $clean_price_decimal;
    
    // Dirty price
    $accrued_interest_percent = ($days_accrued > 0) ? ($accrued_interest / $total_face_value) * 100 : 0;
    $dirty_price = $clean_price + $accrued_interest_percent;
    $dirty_price_value = $clean_price_value + $accrued_interest;
    
    // Total cost (Dirty price value)
    $total_cost = $dirty_price_value;
    
    // Format for display
    $results = [
        'bond_info' => $bond,
        'calculation_date' => date('d/m/Y H:i:s'),
        'inputs' => [
            'face_value' => $face_value,
            'bond_quantity' => $bond_quantity,
            'clean_price' => $clean_price,
            'last_coupon_date' => $last_coupon_date,
            'next_coupon_date' => $next_coupon_date,
            'value_date' => $value_date
        ],
        'calculations' => [
            'total_face_value' => $total_face_value,
            'annual_coupon_payment' => $annual_coupon_payment,
            'semi_annual_coupon' => $semi_annual_coupon,
            'daily_coupon_rate' => $daily_coupon_rate,
            'days_accrued' => $days_accrued,
            'days_in_coupon_period' => $days_in_coupon_period,
            'accrued_interest' => $accrued_interest,
            'accrued_interest_percent' => $accrued_interest_percent,
            'clean_price_value' => $clean_price_value,
            'dirty_price' => $dirty_price,
            'dirty_price_value' => $dirty_price_value,
            'total_cost' => $total_cost
        ]
    ];
    
    return $results;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bond Calculator - <?php echo htmlspecialchars($company_name); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .bond-calculator-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .bond-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        
        .card {
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .result-highlight {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-left: 4px solid #3498db;
            padding: 20px;
            border-radius: 8px;
            margin: 15px 0;
        }
        
        .calculation-step {
            padding: 8px 0;
            border-bottom: 1px dashed #dee2e6;
        }
        
        .important-note {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-top: 20px;
            border-radius: 5px;
        }
        
        .bond-info-box {
            background: #e8f4fd;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="bond-calculator-container">
        <!-- Header Section -->
        <div class="bond-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="bi bi-calculator"></i> Bond Calculator</h1>
                    <p class="lead mb-0">Calculate bond prices and accrued interest</p>
                    <small><?php echo htmlspecialchars($company_name); ?></small>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Bond Selection and Input -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-search"></i> Select Bond
                    </div>
                    <div class="card-body">
                        <form id="bondSelectionForm" method="GET" action="">
                            <div class="mb-3">
                                <label for="bond_id" class="form-label">Choose Bond</label>
                                <select class="form-select" id="bond_id" name="bond_id" required onchange="this.form.submit()">
                                    <option value="">-- Select a Bond --</option>
                                    <?php foreach ($all_bonds as $bond): ?>
                                        <option value="<?php echo $bond['id']; ?>" 
                                                <?php echo ($selected_bond && $selected_bond['id'] == $bond['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($bond['security_id'] . ' - ' . $bond['bond_name']); ?>
                                            (<?php echo $bond['coupon_rate']; ?>%)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>

                        <?php if ($selected_bond): ?>
                        <div class="bond-info-box">
                            <h6>Selected Bond Details:</h6>
                            <p class="mb-1"><strong>Security ID:</strong> <?php echo htmlspecialchars($selected_bond['security_id']); ?></p>
                            <p class="mb-1"><strong>Issuer:</strong> <?php echo htmlspecialchars($selected_bond['issuer']); ?></p>
                            <p class="mb-1"><strong>Coupon Rate:</strong> <?php echo $selected_bond['coupon_rate']; ?>%</p>
                            <p class="mb-1"><strong>Maturity Date:</strong> <?php echo date('d/m/Y', strtotime($selected_bond['maturity_date'])); ?></p>
                            <p class="mb-0"><strong>Face Value:</strong> TZS <?php echo number_format($selected_bond['face_value'] ?? 1000); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($selected_bond): ?>
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-input-cursor-text"></i> Calculation Parameters
                    </div>
                    <div class="card-body">
                        <form id="bondCalculatorForm" method="POST" action="">
                            <input type="hidden" name="bond_id" value="<?php echo $selected_bond['id']; ?>">
                            
                            <div class="mb-3">
                                <label for="face_value" class="form-label">Face Value per Bond (TZS)</label>
                                <div class="input-group">
                                    <span class="input-group-text">TZS</span>
                                    <input type="text" class="form-control" id="face_value" name="face_value" 
                                           value="<?php echo number_format($selected_bond['face_value'] ?? 1000); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="bond_quantity" class="form-label">Number of Bonds</label>
                                <input type="number" class="form-control" id="bond_quantity" name="bond_quantity" 
                                       value="113" min="1" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="clean_price" class="form-label">Clean Price (%)</label>
                                <div class="input-group">
                                    <input type="number" step="0.0001" class="form-control" id="clean_price" 
                                           name="clean_price" value="111.9749" required>
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="last_coupon_date" class="form-label">Last Coupon Date</label>
                                    <input type="date" class="form-control" id="last_coupon_date" 
                                           name="last_coupon_date" value="<?php echo date('Y-m-d', strtotime('-3 months')); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="next_coupon_date" class="form-label">Next Coupon Date</label>
                                    <input type="date" class="form-control" id="next_coupon_date" 
                                           name="next_coupon_date" value="<?php echo date('Y-m-d', strtotime('+3 months')); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Settlement Date</label>
                                <input type="text" class="form-control" value="<?php echo date('d/m/Y'); ?>" readonly>
                                <small class="text-muted">Current date is used as settlement date</small>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" name="calculate_bond" class="btn btn-primary">
                                    <i class="bi bi-calculator-fill"></i> Calculate Bond
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column: Results Display -->
            <div class="col-md-8">
                <?php if ($calculation_results && isset($calculation_results['error'])): ?>
                    <div class="alert alert-danger">
                        <?php echo $calculation_results['error']; ?>
                    </div>
                <?php elseif ($calculation_results): ?>
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <i class="bi bi-check-circle"></i> Calculation Results
                            <span class="float-end"><?php echo $calculation_results['calculation_date']; ?></span>
                        </div>
                        <div class="card-body">
                            <!-- Summary Section -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="text-center p-3 bg-light rounded">
                                        <h6 class="text-muted">Total Face Value</h6>
                                        <h4 class="text-primary">TZS <?php echo number_format($calculation_results['calculations']['total_face_value'], 2); ?></h4>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center p-3 bg-light rounded">
                                        <h6 class="text-muted">Dirty Price</h6>
                                        <h4 class="text-success"><?php echo number_format($calculation_results['calculations']['dirty_price'], 4); ?>%</h4>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center p-3 bg-light rounded">
                                        <h6 class="text-muted">Total Cost</h6>
                                        <h4 class="text-danger">TZS <?php echo number_format($calculation_results['calculations']['total_cost'], 2); ?></h4>
                                    </div>
                                </div>
                            </div>

                            <!-- Detailed Results -->
                            <div class="result-highlight">
                                <h5><i class="bi bi-cash-stack"></i> Financial Summary</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Total Face Value:</strong> TZS <?php echo number_format($calculation_results['calculations']['total_face_value'], 2); ?></p>
                                        <p><strong>Annual Coupon Payment:</strong> TZS <?php echo number_format($calculation_results['calculations']['annual_coupon_payment'], 2); ?></p>
                                        <p><strong>Semi-Annual Coupon:</strong> TZS <?php echo number_format($calculation_results['calculations']['semi_annual_coupon'], 2); ?></p>
                                        <p><strong>Daily Coupon Rate:</strong> TZS <?php echo number_format($calculation_results['calculations']['daily_coupon_rate'], 2); ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Days Accrued:</strong> <?php echo $calculation_results['calculations']['days_accrued']; ?> days</p>
                                        <p><strong>Accrued Interest:</strong> TZS <?php echo number_format($calculation_results['calculations']['accrued_interest'], 2); ?></p>
                                        <p><strong>Clean Price Value:</strong> TZS <?php echo number_format($calculation_results['calculations']['clean_price_value'], 2); ?></p>
                                        <p><strong>Dirty Price Value:</strong> TZS <?php echo number_format($calculation_results['calculations']['dirty_price_value'], 2); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Calculation Steps -->
                            <div class="mt-4">
                                <h6><i class="bi bi-calculator"></i> Calculation Steps</h6>
                                <div class="calculation-step">
                                    1. Total Face Value = TZS <?php echo number_format($calculation_results['inputs']['face_value']); ?> × <?php echo $calculation_results['inputs']['bond_quantity']; ?> = TZS <?php echo number_format($calculation_results['calculations']['total_face_value'], 2); ?>
                                </div>
                                <div class="calculation-step">
                                    2. Annual Coupon = TZS <?php echo number_format($calculation_results['calculations']['total_face_value'], 2); ?> × <?php echo $calculation_results['bond_info']['coupon_rate']; ?>% = TZS <?php echo number_format($calculation_results['calculations']['annual_coupon_payment'], 2); ?>
                                </div>
                                <div class="calculation-step">
                                    3. Days Accrued = <?php echo $calculation_results['calculations']['days_accrued']; ?> days (from <?php echo date('d/m/Y', strtotime($calculation_results['inputs']['last_coupon_date'])); ?> to <?php echo date('d/m/Y'); ?>)
                                </div>
                                <div class="calculation-step">
                                    4. Accrued Interest = (TZS <?php echo number_format($calculation_results['calculations']['annual_coupon_payment'], 2); ?> ÷ <?php echo $calculation_results['calculations']['days_in_coupon_period']; ?>) × <?php echo $calculation_results['calculations']['days_accrued']; ?> = TZS <?php echo number_format($calculation_results['calculations']['accrued_interest'], 2); ?>
                                </div>
                                <div class="calculation-step">
                                    5. Clean Price Value = TZS <?php echo number_format($calculation_results['calculations']['total_face_value'], 2); ?> × (<?php echo $calculation_results['inputs']['clean_price']; ?>% ÷ 100) = TZS <?php echo number_format($calculation_results['calculations']['clean_price_value'], 2); ?>
                                </div>
                                <div class="calculation-step">
                                    6. Dirty Price = <?php echo $calculation_results['inputs']['clean_price']; ?>% + <?php echo number_format($calculation_results['calculations']['accrued_interest_percent'], 4); ?>% = <?php echo number_format($calculation_results['calculations']['dirty_price'], 4); ?>%
                                </div>
                                <div class="calculation-step">
                                    7. Total Cost (Dirty Price Value) = TZS <?php echo number_format($calculation_results['calculations']['clean_price_value'], 2); ?> + TZS <?php echo number_format($calculation_results['calculations']['accrued_interest'], 2); ?> = TZS <?php echo number_format($calculation_results['calculations']['total_cost'], 2); ?>
                                </div>
                            </div>

                            <!-- Important Notes -->
                            <div class="important-note mt-4">
                                <h6><i class="bi bi-info-circle"></i> Important Notes</h6>
                                <ul class="mb-0">
                                    <li>Settlement date: <?php echo date('d/m/Y'); ?> (current date)</li>
                                    <li>Accrued interest calculated using Actual/365 day count convention</li>
                                    <li>Standard coupon period: 182.5 days (semi-annual)</li>
                                    <li>Clean price excludes accrued interest</li>
                                    <li>Dirty price includes accrued interest</li>
                                    <li>Total cost = Clean price value + Accrued interest</li>
                                </ul>
                            </div>

                            <!-- Action Buttons -->
                            <div class="mt-4 text-end">
                                <button onclick="window.print()" class="btn btn-outline-primary me-2">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                                <button onclick="copyResults()" class="btn btn-outline-secondary">
                                    <i class="bi bi-clipboard"></i> Copy Results
                                </button>
                            </div>
                        </div>
                    </div>
                <?php elseif ($selected_bond): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-calculator display-1 text-muted mb-3"></i>
                            <h4>Ready to Calculate</h4>
                            <p class="text-muted">Enter parameters on the left and click "Calculate Bond"</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-bond display-1 text-muted mb-3"></i>
                            <h4>Select a Bond</h4>
                            <p class="text-muted">Choose a bond from the dropdown to begin</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-format face value input
        document.getElementById('face_value')?.addEventListener('blur', function() {
            let value = this.value.replace(/,/g, '');
            if (!isNaN(value) && value !== '') {
                this.value = parseInt(value).toLocaleString('en-US');
            }
        });
        
        // Set default dates
        document.addEventListener('DOMContentLoaded', function() {
            // Set default dates for coupon inputs
            const today = new Date();
            const threeMonthsAgo = new Date();
            threeMonthsAgo.setMonth(today.getMonth() - 3);
            const threeMonthsFuture = new Date();
            threeMonthsFuture.setMonth(today.getMonth() + 3);
            
            // Format dates as YYYY-MM-DD
            function formatDate(date) {
                return date.toISOString().split('T')[0];
            }
            
            // Set defaults if inputs exist and are empty
            if (document.getElementById('last_coupon_date') && !document.getElementById('last_coupon_date').value) {
                document.getElementById('last_coupon_date').value = formatDate(threeMonthsAgo);
            }
            if (document.getElementById('next_coupon_date') && !document.getElementById('next_coupon_date').value) {
                document.getElementById('next_coupon_date').value = formatDate(threeMonthsFuture);
            }
        });
        
        // Copy results function
        function copyResults() {
            let textToCopy = "Bond Calculation Results\n";
            textToCopy += "========================\n";
            
            <?php if ($calculation_results): ?>
            textToCopy += "Bond: <?php echo $calculation_results['bond_info']['security_id']; ?>\n";
            textToCopy += "Total Face Value: TZS <?php echo number_format($calculation_results['calculations']['total_face_value'], 2); ?>\n";
            textToCopy += "Dirty Price: <?php echo number_format($calculation_results['calculations']['dirty_price'], 4); ?>%\n";
            textToCopy += "Accrued Interest: TZS <?php echo number_format($calculation_results['calculations']['accrued_interest'], 2); ?>\n";
            textToCopy += "Total Cost: TZS <?php echo number_format($calculation_results['calculations']['total_cost'], 2); ?>\n";
            textToCopy += "Calculation Date: <?php echo $calculation_results['calculation_date']; ?>\n";
            <?php endif; ?>
            
            navigator.clipboard.writeText(textToCopy).then(function() {
                alert('Results copied to clipboard!');
            }).catch(function(err) {
                alert('Failed to copy: ' + err);
            });
        }
    </script>
</body>
</html>