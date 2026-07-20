<?php
// bond_calculator_excel_like.php
session_start();

// Initialize variables
$results = null;
$buy_face_value = 350000000;
$sell_face_value = 320000000;
$buy_yield = 0.127581;
$sell_yield = 0.127581;
$buy_coupon = 0.1275;
$sell_coupon = 0.1275;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form values
    $buy_face_value = floatval(str_replace(',', '', $_POST['buy_face_value']));
    $sell_face_value = floatval(str_replace(',', '', $_POST['sell_face_value']));
    $buy_yield = floatval($_POST['buy_yield']) / 100;
    $sell_yield = floatval($_POST['sell_yield']) / 100;
    $buy_coupon = floatval($_POST['buy_coupon']) / 100;
    $sell_coupon = floatval($_POST['sell_coupon']) / 100;
    $bond_number = $_POST['bond_number'];
    
    // Dates
    $auction_date = $_POST['auction_date'];
    $maturity_date = $_POST['maturity_date'];
    $next_coupon = $_POST['next_coupon'];
    $last_coupon = $_POST['last_coupon'];
    $settlement_date = $_POST['settlement_date'];
    
    // Calculations
    function calculateCleanPrice($coupon, $yield, $settlement, $maturity) {
        // Simplified calculation - replace with actual PRICE formula
        $years = (strtotime($maturity) - strtotime($settlement)) / (365 * 24 * 3600);
        $clean_price = 100 * (1 - ($yield - $coupon) * $years * 0.5);
        return max(80, min(120, $clean_price)); // Keep realistic range
    }
    
    function calculateAccruedInterest($settlement, $last_coupon, $coupon) {
        $days_accrued = (strtotime($settlement) - strtotime($last_coupon)) / (24 * 3600);
        $days_in_period = 182.5;
        return (($coupon / 2) * ($days_accrued / $days_in_period)) * 100;
    }
    
    // Calculate values
    $buy_clean_price = calculateCleanPrice($buy_coupon, $buy_yield, $settlement_date, $maturity_date);
    $sell_clean_price = calculateCleanPrice($sell_coupon, $sell_yield, $settlement_date, $maturity_date);
    
    $buy_accrued_interest = calculateAccruedInterest($settlement_date, $last_coupon, $buy_coupon);
    $sell_accrued_interest = calculateAccruedInterest($settlement_date, $last_coupon, $sell_coupon);
    
    $buy_dirty_price = $buy_clean_price + $buy_accrued_interest;
    $sell_dirty_price = $sell_clean_price + $sell_accrued_interest;
    
    $buy_actual_cost = ($buy_dirty_price / 100) * $buy_face_value;
    $sell_actual_cost = ($sell_dirty_price / 100) * $sell_face_value;
    
    // Store results
    $results = [
        'buy' => [
            'face_value' => $buy_face_value,
            'clean_price' => $buy_clean_price,
            'accrued_interest' => $buy_accrued_interest,
            'dirty_price' => $buy_dirty_price,
            'actual_cost' => $buy_actual_cost
        ],
        'sell' => [
            'face_value' => $sell_face_value,
            'clean_price' => $sell_clean_price,
            'accrued_interest' => $sell_accrued_interest,
            'dirty_price' => $sell_dirty_price,
            'actual_cost' => $sell_actual_cost
        ],
        'dates' => [
            'auction_date' => $auction_date,
            'maturity_date' => $maturity_date,
            'next_coupon' => $next_coupon,
            'last_coupon' => $last_coupon,
            'settlement_date' => $settlement_date
        ],
        'bond_number' => $bond_number
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bond Calculator - Excel-like Interface</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        
        body {
            background: #f0f0f0;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: #2c3e50;
            color: white;
            padding: 15px 20px;
            border-bottom: 1px solid #34495e;
        }
        
        .header h1 {
            font-size: 20px;
            font-weight: normal;
        }
        
        .sub-header {
            background: #34495e;
            color: #ecf0f1;
            padding: 8px 20px;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
        }
        
        .calculator-grid {
            display: grid;
            grid-template-columns: 250px 1fr 1fr;
            gap: 0;
        }
        
        .descriptions-column {
            background: #f8f9fa;
            border-right: 1px solid #dee2e6;
        }
        
        .buy-column, .sell-column {
            padding: 0;
        }
        
        .section-header {
            background: #e9ecef;
            padding: 8px 15px;
            font-weight: bold;
            font-size: 13px;
            border-bottom: 1px solid #dee2e6;
            color: #495057;
        }
        
        .input-group {
            padding: 10px 15px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            min-height: 40px;
        }
        
        .input-group:nth-child(even) {
            background: #f8f9fa;
        }
        
        .description {
            font-weight: 500;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .input-field {
            width: 100%;
            padding: 5px 8px;
            border: 1px solid #ced4da;
            border-radius: 3px;
            font-size: 13px;
            text-align: right;
            background: white;
        }
        
        .input-field:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        .readonly {
            background: #f8f9fa;
            font-weight: 500;
            color: #2c3e50;
        }
        
        .result-value {
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            color: #2c3e50;
            text-align: right;
        }
        
        .date-field {
            font-family: 'Consolas', 'Monaco', monospace;
            text-align: center;
        }
        
        .percentage::after {
            content: '%';
        }
        
        .currency::before {
            content: 'TZS ';
        }
        
        .actions {
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            text-align: center;
        }
        
        .calculate-btn {
            background: #27ae60;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .calculate-btn:hover {
            background: #219653;
        }
        
        .reset-btn {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            margin-left: 10px;
            transition: background 0.3s;
        }
        
        .reset-btn:hover {
            background: #7f8c8d;
        }
        
        .footer {
            padding: 15px 20px;
            background: #ecf0f1;
            border-top: 1px solid #bdc3c7;
            font-size: 12px;
            color: #7f8c8d;
            text-align: center;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .calculator-grid {
                grid-template-columns: 1fr;
            }
            
            .descriptions-column {
                border-right: none;
                border-bottom: 1px solid #dee2e6;
            }
        }
        
        /* Excel-like styling */
        .excel-cell {
            border: none;
            border-radius: 0;
            height: 30px;
        }
        
        .grid-line {
            position: relative;
        }
        
        .grid-line::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: #dee2e6;
        }
        
        .last-grid-line {
            border-bottom: 1px solid #bdc3c7;
        }
        
        /* Highlight important values */
        .important-value {
            font-weight: bold;
            color: #2c3e50;
            font-size: 14px;
        }
        
        /* Coupon payout section */
        .coupon-section {
            margin-top: 20px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .coupon-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .coupon-table th {
            background: #2c3e50;
            color: white;
            padding: 8px;
            font-weight: normal;
            font-size: 12px;
        }
        
        .coupon-table td {
            padding: 6px 8px;
            border: 1px solid #dee2e6;
            font-size: 12px;
        }
        
        .coupon-table tr:nth-child(even) {
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="bi bi-calculator"></i> BOND CALCULATOR</h1>
        </div>
        
        <div class="sub-header">
            <span>Transaction Analysis Tool</span>
            <span>Bond No. <?php echo $_POST['bond_number'] ?? '673'; ?></span>
        </div>
        
        <form method="post" action="">
            <div class="calculator-grid">
                <!-- Descriptions Column -->
                <div class="descriptions-column">
                    <div class="section-header">DESCRIPTIONS</div>
                    <div class="input-group grid-line">
                        <span class="description">Face Value</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Yield</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Coupon</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Auction Date</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Maturity Date</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Next Coupon</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Last Coupon</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Value/Settlement Date</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Clean Price</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Difference btw "x & y"</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Full coupon period (6 months)</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Accrued Interest</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Dirty price</span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description"></span>
                    </div>
                    <div class="input-group grid-line">
                        <span class="description">Actual Cost</span>
                    </div>
                    <div class="input-group grid-line last-grid-line">
                        <span class="description">Bond number</span>
                    </div>
                    <div class="input-group grid-line last-grid-line">
                        <span class="description">Cost</span>
                    </div>
                </div>
                
                <!-- Buy Column -->
                <div class="buy-column">
                    <div class="section-header" style="background: #d4edda; color: #155724;">BUY</div>
                    <div class="input-group grid-line">
                        <input type="text" name="buy_face_value" class="input-field excel-cell" 
                               value="<?php echo number_format($buy_face_value, 0); ?>" required>
                    </div>
                    <div class="input-group grid-line">
                        <input type="number" step="0.0001" name="buy_yield" class="input-field excel-cell percentage" 
                               value="<?php echo number_format($buy_yield * 100, 4); ?>" required>
                    </div>
                    <div class="input-group grid-line">
                        <input type="number" step="0.0001" name="buy_coupon" class="input-field excel-cell percentage" 
                               value="<?php echo number_format($buy_coupon * 100, 4); ?>" required>
                    </div>
                    <div class="input-group grid-line">
                        <input type="date" name="auction_date" class="input-field excel-cell" 
                               value="<?php echo $_POST['auction_date'] ?? '2025-07-16'; ?>" required>
                    </div>
                    <div class="input-group grid-line">
                        <input type="date" name="maturity_date" class="input-field excel-cell" 
                               value="<?php echo $_POST['maturity_date'] ?? '2030-07-17'; ?>" required>
                    </div>
                    <div class="input-group grid-line">
                        <input type="date" name="next_coupon" class="input-field excel-cell" 
                               value="<?php echo $_POST['next_coupon'] ?? '2026-01-17'; ?>" required>
                    </div>
                    <div class="input-group grid-line">
                        <input type="date" name="last_coupon" class="input-field excel-cell" 
                               value="<?php echo $_POST['last_coupon'] ?? '2025-07-17'; ?>" required>
                    </div>
                    <div class="input-group grid-line">
                        <input type="date" name="settlement_date" class="input-field excel-cell" 
                               value="<?php echo $_POST['settlement_date'] ?? '2025-07-17'; ?>" required>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell readonly" 
                               value="<?php echo $results ? number_format($results['buy']['clean_price'], 4) : '99.9709'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell date-field readonly" 
                               value="<?php echo $results ? '0-Jan-00' : '0-Jan-00'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell readonly" value="182.5" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell readonly" 
                               value="<?php echo $results ? number_format($results['buy']['accrued_interest'], 7) : '0.0000000'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell readonly important-value" 
                               value="<?php echo $results ? number_format($results['buy']['dirty_price'], 4) : '99.9709'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <span class="result-value"></span>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell readonly currency" 
                               value="<?php echo $results ? number_format($results['buy']['actual_cost'], 2) : '349,898,131.23'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" name="bond_number" class="input-field excel-cell" 
                               value="<?php echo $_POST['bond_number'] ?? '673'; ?>" required>
                    </div>
                    <div class="input-group grid-line last-grid-line">
                        <input type="text" class="input-field excel-cell readonly currency important-value" 
                               value="<?php echo $results ? number_format($results['buy']['actual_cost'], 2) : '349,898,150.00'; ?>" readonly>
                    </div>
                </div>
                
                <!-- Sell Column -->
                <div class="sell-column">
                    <div class="section-header" style="background: #f8d7da; color: #721c24;">SALE</div>
                    <div class="input-group grid-line">
                        <input type="text" name="sell_face_value" class="input-field excel-cell" 
                               value="<?php echo number_format($sell_face_value, 0); ?>" required>
                    </div>
                    <div class="input-group grid-line">
                        <input type="number" step="0.0001" name="sell_yield" class="input-field excel-cell percentage" 
                               value="<?php echo number_format($sell_yield * 100, 4); ?>" required>
                    </div>
                    <div class="input-group grid-line">
                        <input type="number" step="0.0001" name="sell_coupon" class="input-field excel-cell percentage" 
                               value="<?php echo number_format($sell_coupon * 100, 4); ?>" required>
                    </div>
                    <div class="input-group grid-line">
                        <input type="date" class="input-field excel-cell readonly" 
                               value="<?php echo $_POST['auction_date'] ?? '2025-07-16'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="date" class="input-field excel-cell readonly" 
                               value="<?php echo $_POST['maturity_date'] ?? '2030-07-17'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="date" class="input-field excel-cell readonly" 
                               value="<?php echo $_POST['next_coupon'] ?? '2026-01-17'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="date" class="input-field excel-cell readonly" 
                               value="<?php echo $_POST['last_coupon'] ?? '2025-07-17'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="date" class="input-field excel-cell readonly" 
                               value="<?php echo $_POST['settlement_date'] ?? '2025-07-17'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell readonly" 
                               value="<?php echo $results ? number_format($results['sell']['clean_price'], 4) : '99.9709'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell date-field readonly" 
                               value="<?php echo $results ? '0-Jan-00' : '0-Jan-00'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell readonly" value="182.5" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell readonly" 
                               value="<?php echo $results ? number_format($results['sell']['accrued_interest'], 7) : '0.0000000'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell readonly important-value" 
                               value="<?php echo $results ? number_format($results['sell']['dirty_price'], 4) : '99.9709'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <span class="result-value"></span>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell readonly currency" 
                               value="<?php echo $results ? number_format($results['sell']['actual_cost'], 2) : '319,906,862.84'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line">
                        <input type="text" class="input-field excel-cell readonly" 
                               value="<?php echo $_POST['bond_number'] ?? '673'; ?>" readonly>
                    </div>
                    <div class="input-group grid-line last-grid-line">
                        <input type="text" class="input-field excel-cell readonly currency important-value" 
                               value="<?php echo $results ? number_format($results['sell']['actual_cost'], 2) : '319,906,880.00'; ?>" readonly>
                    </div>
                </div>
            </div>
            
            <div class="actions">
                <button type="submit" class="calculate-btn">
                    <i class="bi bi-calculator"></i> Calculate Bond
                </button>
                <button type="button" class="reset-btn" onclick="resetForm()">
                    <i class="bi bi-arrow-clockwise"></i> Reset
                </button>
            </div>
        </form>
        
        <!-- Optional: Coupon Payout Section -->
        <div class="coupon-section" style="margin: 20px;">
            <div class="section-header">Coupon Payout Schedule</div>
            <table class="coupon-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount on <?php echo number_format($buy_face_value/1000000, 0); ?>Mil</th>
                        <th>Amount on <?php echo number_format($sell_face_value/1000000, 0); ?>Mil</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $dates = [
                        '2026-01-17', '2026-07-17', '2027-01-17', '2027-07-17',
                        '2028-01-17', '2028-07-17', '2029-01-17', '2029-07-17',
                        '2030-01-17', '2030-07-17'
                    ];
                    
                    foreach ($dates as $date) {
                        $buy_amount = $buy_face_value * $buy_coupon / 2;
                        $sell_amount = $sell_face_value * $sell_coupon / 2;
                        echo "<tr>
                            <td>" . date('d-M-y', strtotime($date)) . "</td>
                            <td class='currency'>" . number_format($buy_amount, 2) . "</td>
                            <td class='currency'>" . number_format($sell_amount, 2) . "</td>
                        </tr>";
                    }
                    ?>
                    <tr style="background: #2c3e50; color: white; font-weight: bold;">
                        <td>Total Coupon Payout</td>
                        <td class="currency"><?php echo number_format($buy_face_value * $buy_coupon / 2 * 10, 2); ?></td>
                        <td class="currency"><?php echo number_format($sell_face_value * $sell_coupon / 2 * 10, 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="footer">
            <p>Bond Calculator v1.0 | Excel-like interface | Bond No. <?php echo $_POST['bond_number'] ?? '673'; ?></p>
        </div>
    </div>
    
    <script>
        // Auto-format face values on blur
        document.querySelectorAll('input[name$="face_value"]').forEach(input => {
            input.addEventListener('blur', function() {
                let value = this.value.replace(/,/g, '');
                if (!isNaN(value) && value !== '') {
                    this.value = parseInt(value).toLocaleString('en-US');
                }
            });
        });
        
        // Auto-add percentage sign
        document.querySelectorAll('.percentage').forEach(input => {
            input.addEventListener('blur', function() {
                let value = parseFloat(this.value);
                if (!isNaN(value)) {
                    this.value = value.toFixed(4);
                }
            });
        });
        
        // Reset form function
        function resetForm() {
            document.querySelector('form').reset();
            location.reload();
        }
        
        // Set default dates if empty
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const sixMonthsLater = new Date();
            sixMonthsLater.setMonth(sixMonthsLater.getMonth() + 6);
            const sixMonthsLaterStr = sixMonthsLater.toISOString().split('T')[0];
            
            // Set dates if empty
            const dateInputs = document.querySelectorAll('input[type="date"]:not([readonly])');
            dateInputs.forEach((input, index) => {
                if (!input.value) {
                    switch(index) {
                        case 0: // Auction date
                            input.value = today;
                            break;
                        case 1: // Maturity date
                            input.value = sixMonthsLaterStr;
                            break;
                        case 2: // Next coupon
                            input.value = sixMonthsLaterStr;
                            break;
                        case 3: // Last coupon
                            const sixMonthsAgo = new Date();
                            sixMonthsAgo.setMonth(sixMonthsAgo.getMonth() - 6);
                            input.value = sixMonthsAgo.toISOString().split('T')[0];
                            break;
                        case 4: // Settlement date
                            input.value = today;
                            break;
                    }
                }
            });
        });
    </script>
</body>
</html>