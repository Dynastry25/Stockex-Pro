<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_trader();
require_mandate();

$db = getDBConnection();
$success_message = '';
$error_message = '';

// --- PHP Code for Dynamic Data Fetching ---
// Fetch active issuers
$stmt_issuers = $db->query("SELECT id, description FROM bond_issuers WHERE status = 'active' ORDER BY priority ASC");
$active_issuers = $stmt_issuers->fetchAll(PDO::FETCH_ASSOC);

// Fetch active bond types
$stmt_types = $db->query("SELECT id, description FROM bond_types WHERE status = 'active' ORDER BY priority ASC");
$active_bond_types = $stmt_types->fetchAll(PDO::FETCH_ASSOC);

// Fetch active economic sectors
$stmt_sectors = $db->query("SELECT id, description FROM bonds_economic_sectors WHERE status = 'active' ORDER BY priority ASC");
$active_economic_sectors = $stmt_sectors->fetchAll(PDO::FETCH_ASSOC);

// Fetch active coupon determiners
$stmt_determiners = $db->query("SELECT id, description FROM coupon_determiners WHERE status = 'active' ORDER BY priority ASC");
$active_coupon_determiners = $stmt_determiners->fetchAll(PDO::FETCH_ASSOC);

// Fetch active payment frequencies
$stmt_frequencies = $db->query("SELECT id, description FROM payment_frequencies WHERE status = 'active' ORDER BY priority ASC");
$active_payment_frequencies = $stmt_frequencies->fetchAll(PDO::FETCH_ASSOC);
// --- End Dynamic Data Fetching ---

// Handle auction actions (activate, complete, cancel)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $auction_id = (int)$_GET['id'];
    
    switch ($action) {
        case 'activate':
            $stmt = $db->prepare("UPDATE bond_auctions SET status = 'active' WHERE id = ?");
            if ($stmt->execute([$auction_id])) {
                show_alert('Bond auction activated successfully.', 'success');
            } else {
                show_alert('Error activating auction.', 'danger');
            }
            break;
            
        case 'complete':
            $stmt = $db->prepare("UPDATE bond_auctions SET status = 'completed' WHERE id = ?");
            if ($stmt->execute([$auction_id])) {
                show_alert('Bond auction completed successfully.', 'info');
            } else {
                show_alert('Error completing auction.', 'danger');
            }
            break;
            
        case 'cancel':
            $stmt = $db->prepare("UPDATE bond_auctions SET status = 'cancelled' WHERE id = ?");
            if ($stmt->execute([$auction_id])) {
                show_alert('Bond auction cancelled successfully.', 'warning');
            } else {
                show_alert('Error cancelling auction.', 'danger');
            }
            break;
    }
    
    redirect('trader/bonds.php');
}

// Handle new auction creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_auction'])) {
    $auction_number = sanitize_input($_POST['auction_number']);
    $auction_date = sanitize_input($_POST['auction_date']);
    $maturity_date = sanitize_input($_POST['maturity_date']);
    $coupon_rate = (float)$_POST['coupon_rate'];
    $face_value = (float)$_POST['face_value'];
    $total_amount = (float)$_POST['total_amount'];
    
    if (empty($auction_number) || empty($auction_date) || empty($maturity_date) || 
        empty($coupon_rate) || empty($face_value) || empty($total_amount)) {
        $error_message = 'All fields are required.';
    } elseif ($coupon_rate <= 0 || $face_value <= 0 || $total_amount <= 0) {
        $error_message = 'Coupon rate, face value, and total amount must be greater than zero.';
    } elseif (strtotime($maturity_date) <= strtotime($auction_date)) {
        $error_message = 'Maturity date must be after auction date.';
    } else {
        // Check if auction number already exists
        $stmt = $db->prepare("SELECT id FROM bond_auctions WHERE auction_number = ?");
        $stmt->execute([$auction_number]);
        if ($stmt->fetch()) {
            $error_message = 'Auction number already exists.';
        } else {
            // Create auction
            $stmt = $db->prepare("
                INSERT INTO bond_auctions (auction_number, auction_date, maturity_date, coupon_rate, 
                                            face_value, total_amount, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$auction_number, $auction_date, $maturity_date, $coupon_rate, 
                               $face_value, $total_amount, $_SESSION['user_id']])) {
                show_alert('Bond auction created successfully.', 'success');
                redirect('trader/bonds.php');
            } else {
                $error_message = 'Error creating auction. Please try again.';
            }
        }
    }
}

// Handle government bond creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_government_bond'])) {
    // Look up descriptions from submitted IDs
    $issuer_id = sanitize_input($_POST['issuer']);
    $bond_type_id = sanitize_input($_POST['type']);
    $economic_sector_id = sanitize_input($_POST['economic_sector']);
    $coupon_determiner_id = sanitize_input($_POST['coupon_determiner']);
    $payment_frequency_id = sanitize_input($_POST['payment_frequency']);

    $stmt_issuer = $db->prepare("SELECT description FROM bond_issuers WHERE id = ?");
    $stmt_issuer->execute([$issuer_id]);
    $issuer_name = $stmt_issuer->fetchColumn();

    $stmt_type = $db->prepare("SELECT description FROM bond_types WHERE id = ?");
    $stmt_type->execute([$bond_type_id]);
    $type_name = $stmt_type->fetchColumn();

    $stmt_sector = $db->prepare("SELECT description FROM bonds_economic_sectors WHERE id = ?");
    $stmt_sector->execute([$economic_sector_id]);
    $economic_sector = $stmt_sector->fetchColumn();

    $stmt_determiner = $db->prepare("SELECT description FROM coupon_determiners WHERE id = ?");
    $stmt_determiner->execute([$coupon_determiner_id]);
    $coupon_determiner = $stmt_determiner->fetchColumn();

    $stmt_frequency = $db->prepare("SELECT description FROM payment_frequencies WHERE id = ?");
    $stmt_frequency->execute([$payment_frequency_id]);
    $payment_frequency = $stmt_frequency->fetchColumn();
    
    // Sanitize other inputs - convert empty dates to NULL
    $issue_code = sanitize_input($_POST['issue_code']);
    $description = sanitize_input($_POST['description']);
    $effective_issue_date = !empty($_POST['effective_issue_date']) ? sanitize_input($_POST['effective_issue_date']) : NULL;
    $price = !empty($_POST['price']) ? (float)$_POST['price'] : 0;
    $ytm = !empty($_POST['ytm']) ? (float)$_POST['ytm'] : NULL;
    $day_count_convention = sanitize_input($_POST['day_count_convention']);
    $cash_flow_days = sanitize_input($_POST['cash_flow_days']);
    $issued_amount = !empty($_POST['issued_amount']) ? (float)$_POST['issued_amount'] : 0;
    $cds_security_code = sanitize_input($_POST['cds_security_code']);
    $ats_security_code = sanitize_input($_POST['ats_security_code']);
    $bond_no = sanitize_input($_POST['bond_no']);
    $issue_no = sanitize_input($_POST['issue_no']);
    $isin = sanitize_input($_POST['isin']);
    $maturity_date = !empty($_POST['maturity_date']) ? sanitize_input($_POST['maturity_date']) : NULL;
    $coupon = !empty($_POST['coupon']) ? (float)$_POST['coupon'] : 0;
    $amortization_method = sanitize_input($_POST['amortization_method']);
    $tenor = !empty($_POST['tenor']) ? (int)$_POST['tenor'] : 0;
    $no_of_cash_flows = !empty($_POST['no_of_cash_flows']) ? (int)$_POST['no_of_cash_flows'] : NULL;
    $withholding_tax = !empty($_POST['withholding_tax']) ? (float)$_POST['withholding_tax'] : 0;
    $statement_narrative = sanitize_input($_POST['statement_narrative']);

    if (empty($issue_code) || empty($issuer_name) || empty($coupon) || empty($maturity_date) || empty($ats_security_code)) {
        $error_message = 'Issue Code, ATS Security Code, Issuer, Coupon, and Maturity Date are required for government bond creation.';
    } elseif ($coupon <= 0) {
        $error_message = 'Coupon rate must be greater than zero.';
    } else {
        $security_id = $ats_security_code;

        $stmt = $db->prepare("SELECT id FROM bonds WHERE security_id = ?");
        $stmt->execute([$security_id]);
        if ($stmt->fetch()) {
            $error_message = 'Bond with this ATS code already exists: ' . $security_id;
        } else {
            $bond_name = "Government Bond " . $security_id;
            $security_type = "Government";

            $stmt = $db->prepare("
                INSERT INTO bonds (
                    security_id, bond_name, issuer, coupon_rate, price, ytm,
                    issue_date, maturity_date, term_years, security_type, description,
                    isin, economic_sector, coupon_determiner, day_count_convention,
                    cash_flow_days, issued_amount, cds_security_code, bond_no,
                    issue_no, amortization_method, payment_frequency, no_of_cash_flows,
                    withholding_tax, statement_narrative, type
                ) 
                VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?
                )
            ");
            
            if ($stmt->execute([
                $security_id, $bond_name, $issuer_name, $coupon, $price, $ytm,
                $effective_issue_date, $maturity_date, $tenor, $security_type, $description,
                $isin, $economic_sector, $coupon_determiner, $day_count_convention,
                $cash_flow_days, $issued_amount, $cds_security_code, $bond_no,
                $issue_no, $amortization_method, $payment_frequency, $no_of_cash_flows,
                $withholding_tax, $statement_narrative, $type_name
            ])) {
                show_alert('Government bond created successfully with ATS code: ' . $security_id, 'success');
                redirect('trader/bonds.php');
            } else {
                $error_message = 'Error creating government bond. Please try again.';
            }
        }
    }
}

// Handle corporate bond creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_corporate_bond'])) {
    // Look up descriptions from submitted IDs
    $issuer_id = sanitize_input($_POST['issuer']);
    $bond_type_id = sanitize_input($_POST['type']);
    $economic_sector_id = sanitize_input($_POST['economic_sector']);
    $coupon_determiner_id = sanitize_input($_POST['coupon_determiner']);
    $payment_frequency_id = sanitize_input($_POST['payment_frequency']);

    $stmt_issuer = $db->prepare("SELECT description FROM bond_issuers WHERE id = ?");
    $stmt_issuer->execute([$issuer_id]);
    $issuer_name = $stmt_issuer->fetchColumn();

    $stmt_type = $db->prepare("SELECT description FROM bond_types WHERE id = ?");
    $stmt_type->execute([$bond_type_id]);
    $type_name = $stmt_type->fetchColumn();

    $stmt_sector = $db->prepare("SELECT description FROM bonds_economic_sectors WHERE id = ?");
    $stmt_sector->execute([$economic_sector_id]);
    $economic_sector = $stmt_sector->fetchColumn();

    $stmt_determiner = $db->prepare("SELECT description FROM coupon_determiners WHERE id = ?");
    $stmt_determiner->execute([$coupon_determiner_id]);
    $coupon_determiner = $stmt_determiner->fetchColumn();

    $stmt_frequency = $db->prepare("SELECT description FROM payment_frequencies WHERE id = ?");
    $stmt_frequency->execute([$payment_frequency_id]);
    $payment_frequency = $stmt_frequency->fetchColumn();

    // Sanitize other inputs - convert empty dates to NULL
    $issue_code = sanitize_input($_POST['issue_code']);
    $description = sanitize_input($_POST['description']);
    $effective_issue_date = !empty($_POST['effective_issue_date']) ? sanitize_input($_POST['effective_issue_date']) : NULL;
    $price = !empty($_POST['price']) ? (float)$_POST['price'] : 0;
    $ytm = !empty($_POST['ytm']) ? (float)$_POST['ytm'] : NULL;
    $day_count_convention = sanitize_input($_POST['day_count_convention']);
    $cash_flow_days = sanitize_input($_POST['cash_flow_days']);
    $issued_amount = !empty($_POST['issued_amount']) ? (float)$_POST['issued_amount'] : 0;
    $cds_security_code = sanitize_input($_POST['cds_security_code']);
    $ats_security_code = sanitize_input($_POST['ats_security_code']);
    $bond_no = sanitize_input($_POST['bond_no']);
    $issue_no = sanitize_input($_POST['issue_no']);
    $isin = sanitize_input($_POST['isin']);
    $maturity_date = !empty($_POST['maturity_date']) ? sanitize_input($_POST['maturity_date']) : NULL;
    $coupon = !empty($_POST['coupon']) ? (float)$_POST['coupon'] : 0;
    $amortization_method = sanitize_input($_POST['amortization_method']);
    $tenor = !empty($_POST['tenor']) ? (int)$_POST['tenor'] : 0;
    $no_of_cash_flows = !empty($_POST['no_of_cash_flows']) ? (int)$_POST['no_of_cash_flows'] : NULL;
    $withholding_tax = !empty($_POST['withholding_tax']) ? (float)$_POST['withholding_tax'] : 0;
    $statement_narrative = sanitize_input($_POST['statement_narrative']);

    if (empty($issue_code) || empty($issuer_name) || empty($coupon) || empty($maturity_date) || empty($ats_security_code)) {
        $error_message = 'Issue Code, ATS Security Code, Issuer, Coupon, and Maturity Date are required for corporate bond creation.';
    } elseif ($coupon <= 0) {
        $error_message = 'Coupon rate must be greater than zero.';
    } else {
        $security_id = $ats_security_code;

        $stmt = $db->prepare("SELECT id FROM bonds WHERE security_id = ?");
        $stmt->execute([$security_id]);
        if ($stmt->fetch()) {
            $error_message = 'Bond with this ATS code already exists: ' . $security_id;
        } else {
            $bond_name = "Corporate Bond " . $security_id;
            $security_type = "Corporate";

            $stmt = $db->prepare("
                INSERT INTO bonds (
                    security_id, bond_name, issuer, coupon_rate, price, ytm,
                    issue_date, maturity_date, term_years, security_type, description,
                    isin, economic_sector, coupon_determiner, day_count_convention,
                    cash_flow_days, issued_amount, cds_security_code, bond_no,
                    issue_no, amortization_method, payment_frequency, no_of_cash_flows,
                    withholding_tax, statement_narrative, type
                ) 
                VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?
                )
            ");
            
            if ($stmt->execute([
                $security_id, $bond_name, $issuer_name, $coupon, $price, $ytm,
                $effective_issue_date, $maturity_date, $tenor, $security_type, $description,
                $isin, $economic_sector, $coupon_determiner, $day_count_convention,
                $cash_flow_days, $issued_amount, $cds_security_code, $bond_no,
                $issue_no, $amortization_method, $payment_frequency, $no_of_cash_flows,
                $withholding_tax, $statement_narrative, $type_name
            ])) {
                show_alert('Corporate bond created successfully with ATS code: ' . $security_id, 'success');
                redirect('trader/bonds.php');
            } else {
                $error_message = 'Error creating corporate bond. Please try again.';
            }
        }
    }
}

// Handle bond update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_bond'])) {
    $bond_id = (int)$_POST['bond_id'];
    
    // Look up descriptions from submitted IDs
    $issuer_id = sanitize_input($_POST['issuer']);
    $bond_type_id = sanitize_input($_POST['type']);
    $economic_sector_id = sanitize_input($_POST['economic_sector']);
    $coupon_determiner_id = sanitize_input($_POST['coupon_determiner']);
    $payment_frequency_id = sanitize_input($_POST['payment_frequency']);

    $stmt_issuer = $db->prepare("SELECT description FROM bond_issuers WHERE id = ?");
    $stmt_issuer->execute([$issuer_id]);
    $issuer_name = $stmt_issuer->fetchColumn();

    $stmt_type = $db->prepare("SELECT description FROM bond_types WHERE id = ?");
    $stmt_type->execute([$bond_type_id]);
    $type_name = $stmt_type->fetchColumn();

    $stmt_sector = $db->prepare("SELECT description FROM bonds_economic_sectors WHERE id = ?");
    $stmt_sector->execute([$economic_sector_id]);
    $economic_sector = $stmt_sector->fetchColumn();

    $stmt_determiner = $db->prepare("SELECT description FROM coupon_determiners WHERE id = ?");
    $stmt_determiner->execute([$coupon_determiner_id]);
    $coupon_determiner = $stmt_determiner->fetchColumn();

    $stmt_frequency = $db->prepare("SELECT description FROM payment_frequencies WHERE id = ?");
    $stmt_frequency->execute([$payment_frequency_id]);
    $payment_frequency = $stmt_frequency->fetchColumn();

    // Sanitize other inputs - convert empty dates to NULL
    $issue_code = sanitize_input($_POST['issue_code']);
    $description = sanitize_input($_POST['description']);
    $effective_issue_date = !empty($_POST['effective_issue_date']) ? sanitize_input($_POST['effective_issue_date']) : NULL;
    $price = !empty($_POST['price']) ? (float)$_POST['price'] : 0;
    $ytm = !empty($_POST['ytm']) ? (float)$_POST['ytm'] : NULL;
    $day_count_convention = sanitize_input($_POST['day_count_convention']);
    $cash_flow_days = sanitize_input($_POST['cash_flow_days']);
    $issued_amount = !empty($_POST['issued_amount']) ? (float)$_POST['issued_amount'] : 0;
    $cds_security_code = sanitize_input($_POST['cds_security_code']);
    $ats_security_code = sanitize_input($_POST['ats_security_code']);
    $bond_no = sanitize_input($_POST['bond_no']);
    $issue_no = sanitize_input($_POST['issue_no']);
    $isin = sanitize_input($_POST['isin']);
    $maturity_date = !empty($_POST['maturity_date']) ? sanitize_input($_POST['maturity_date']) : NULL;
    $coupon = !empty($_POST['coupon']) ? (float)$_POST['coupon'] : 0;
    $amortization_method = sanitize_input($_POST['amortization_method']);
    $tenor = !empty($_POST['tenor']) ? (int)$_POST['tenor'] : 0;
    $no_of_cash_flows = !empty($_POST['no_of_cash_flows']) ? (int)$_POST['no_of_cash_flows'] : NULL;
    $withholding_tax = !empty($_POST['withholding_tax']) ? (float)$_POST['withholding_tax'] : 0;
    $statement_narrative = sanitize_input($_POST['statement_narrative']);

    if (empty($issue_code) || empty($issuer_name) || empty($coupon) || empty($maturity_date) || empty($ats_security_code)) {
        $error_message = 'Issue Code, ATS Security Code, Issuer, Coupon, and Maturity Date are required.';
    } elseif ($coupon <= 0) {
        $error_message = 'Coupon rate must be greater than zero.';
    } else {
        $security_id = $ats_security_code;

        // Check if ATS code already exists for other bonds
        $stmt = $db->prepare("SELECT id FROM bonds WHERE security_id = ? AND id != ?");
        $stmt->execute([$security_id, $bond_id]);
        if ($stmt->fetch()) {
            $error_message = 'Bond with this ATS code already exists: ' . $security_id;
        } else {
            $bond_name = ($type_name == 'Government' ? "Government Bond " : "Corporate Bond ") . $security_id;

            $stmt = $db->prepare("
                UPDATE bonds SET
                    security_id = ?, bond_name = ?, issuer = ?, coupon_rate = ?, price = ?, ytm = ?,
                    issue_date = ?, maturity_date = ?, term_years = ?, description = ?,
                    isin = ?, economic_sector = ?, coupon_determiner = ?, day_count_convention = ?,
                    cash_flow_days = ?, issued_amount = ?, cds_security_code = ?, bond_no = ?,
                    issue_no = ?, amortization_method = ?, payment_frequency = ?, no_of_cash_flows = ?,
                    withholding_tax = ?, statement_narrative = ?, type = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            if ($stmt->execute([
                $security_id, $bond_name, $issuer_name, $coupon, $price, $ytm,
                $effective_issue_date, $maturity_date, $tenor, $description,
                $isin, $economic_sector, $coupon_determiner, $day_count_convention,
                $cash_flow_days, $issued_amount, $cds_security_code, $bond_no,
                $issue_no, $amortization_method, $payment_frequency, $no_of_cash_flows,
                $withholding_tax, $statement_narrative, $type_name, $bond_id
            ])) {
                show_alert('Bond updated successfully.', 'success');
                redirect('trader/bonds.php');
            } else {
                $error_message = 'Error updating bond. Please try again.';
            }
        }
    }
}

// Get auctions and bonds
$stmt = $db->query("
    SELECT ba.*, u.full_name as created_by_name
    FROM bond_auctions ba
    LEFT JOIN users u ON ba.created_by = u.id
    ORDER BY ba.created_at DESC
");
$auctions = $stmt->fetchAll();

$stmt = $db->query("
    SELECT b.*
    FROM bonds b
    ORDER BY b.created_at DESC
");
$bonds = $stmt->fetchAll();

$page_title = 'Bond Auction Management';
include '../includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Bond Management</h1>
        <div class="d-flex">
            <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#createAuctionModal">
                <i class="bi bi-plus-lg me-2"></i>Create New Auction
            </button>
            <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#createGovernmentBondModal">
                <i class="bi bi-plus-lg me-2"></i>Create Government Bond
            </button>
            <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#createCorporateBondModal">
                <i class="bi bi-plus-lg me-2"></i>Create Corporate Bond
            </button>
        </div>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Bond Auctions -->
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-calendar-event"></i> Bond Auctions</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Auction #</th>
                            <th>Date</th>
                            <th>Coupon Rate</th>
                            <th>Face Value</th>
                            <th>Total Amount</th>
                            <th>Maturity</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auctions as $auction): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($auction['auction_number']); ?></strong></td>
                                <td><?php echo format_date($auction['auction_date']); ?></td>
                                <td><?php echo number_format($auction['coupon_rate'], 2); ?>%</td>
                                <td>TZS <?php echo format_currency($auction['face_value']); ?></td>
                                <td>TZS <?php echo format_currency($auction['total_amount']); ?></td>
                                <td><?php echo format_date($auction['maturity_date']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $auction['status'] == 'active' ? 'success' : 
                                             ($auction['status'] == 'completed' ? 'primary' : 
                                             ($auction['status'] == 'cancelled' ? 'danger' : 'secondary')); 
                                    ?>">
                                        <?php echo ucfirst($auction['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($auction['created_by_name']); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($auction['status'] == 'planned'): ?>
                                            <a href="#" 
                                                class="btn btn-outline-success action-btn" 
                                                data-action="activate" 
                                                data-id="<?php echo $auction['id']; ?>"
                                                title="Activate">
                                                <i class="bi bi-play"></i>
                                            </a>
                                            <a href="#" 
                                                class="btn btn-outline-danger action-btn" 
                                                data-action="cancel" 
                                                data-id="<?php echo $auction['id']; ?>"
                                                title="Cancel">
                                                <i class="bi bi-x-lg"></i>
                                            </a>
                                        <?php elseif ($auction['status'] == 'active'): ?>
                                            <a href="#" 
                                                class="btn btn-outline-primary action-btn" 
                                                data-action="complete" 
                                                data-id="<?php echo $auction['id']; ?>"
                                                title="Complete">
                                                <i class="bi bi-check-lg"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Bonds -->
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-collection-fill"></i> Created Bonds</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ATS Code</th>
                            <th>Bond Name</th>
                            <th>Security Type</th>
                            <th>Issuer</th>
                            <th>Coupon Rate</th>
                            <th>Tenor</th>
                            <th>Issue Date</th>
                            <th>Maturity Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bonds as $bond): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($bond['security_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($bond['bond_name']); ?></td>
                                <td><?php echo htmlspecialchars($bond['security_type'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($bond['issuer']); ?></td>
                                <td><?php echo number_format($bond['coupon_rate'], 2); ?>%</td>
                                <td><?php echo ($bond['term_years'] ?? 'N/A'); ?> years</td>
                                <td><?php echo format_date($bond['issue_date']); ?></td>
                                <td><?php echo format_date($bond['maturity_date']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $bond['status'] == 'active' ? 'success' : 
                                             ($bond['status'] == 'matured' ? 'primary' : 'danger'); 
                                    ?>">
                                        <?php echo ucfirst($bond['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="#" class="btn btn-outline-info" title="View Details" data-bs-toggle="modal" data-bs-target="#viewBondModal_<?php echo $bond['id']; ?>">
                                            <i class="bi bi-info-circle"></i>
                                        </a>
                                        <a href="#" class="btn btn-outline-warning" title="Edit Bond" data-bs-toggle="modal" data-bs-target="#editBondModal_<?php echo $bond['id']; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create Auction Modal -->
    <div class="modal fade" id="createAuctionModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header">
                        <h5 class="modal-title">Create New Bond Auction</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="auction_number" class="form-label">Auction Number</label>
                                    <input type="text" class="form-control" id="auction_number" name="auction_number" 
                                            placeholder="e.g., A1, A2, etc." required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="auction_date" class="form-label">Auction Date</label>
                                    <input type="date" class="form-control" id="auction_date" name="auction_date" 
                                            value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="coupon_rate" class="form-label">Coupon Rate (%)</label>
                                    <input type="number" class="form-control" id="coupon_rate" name="coupon_rate" 
                                            step="0.01" min="0.01" placeholder="e.g., 15.00" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="face_value" class="form-label">Face Value (TZS)</label>
                                    <input type="number" class="form-control" id="face_value" name="face_value" 
                                            step="0.01" min="0.01" placeholder="e.g., 1,000.00" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="total_amount" class="form-label">Total Amount (TZS)</label>
                                    <input type="number" class="form-control" id="total_amount" name="total_amount" 
                                            step="0.01" min="0.01" placeholder="e.g., 1,000,000.00" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="maturity_date" class="form-label">Maturity Date</label>
                                    <input type="date" class="form-control" id="maturity_date" name="maturity_date" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_auction" class="btn btn-primary">Create Auction</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Government Bond Modal -->
    <div class="modal fade" id="createGovernmentBondModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header">
                        <h5 class="modal-title">Create Government Bond</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body modal-body-scroll">
                        <h6 class="text-primary mb-3">General Information</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="issue_code" class="form-label">Issue Code</label>
                                <input type="text" class="form-control" id="issue_code" name="issue_code" required>
                            </div>
                            <div class="col-md-6">
                                <label for="isin" class="form-label">ISIN</label>
                                <input type="text" class="form-control" id="isin" name="isin" required>
                            </div>
                            <div class="col-md-6">
                                <label for="issuer" class="form-label">Issuer</label>
                                <select class="form-select" id="issuer" name="issuer" required>
                                    <option value="">Select Issuer</option>
                                    <?php foreach ($active_issuers as $issuer): ?>
                                        <option value="<?php echo htmlspecialchars($issuer['id']); ?>">
                                            <?php echo htmlspecialchars($issuer['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="type" class="form-label">Bond Type</label>
                                <select class="form-select" id="type" name="type" required>
                                    <option value="">Select Type</option>
                                    <?php foreach ($active_bond_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type['id']); ?>">
                                            <?php echo htmlspecialchars($type['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="effective_issue_date" class="form-label">Effective Issue Date</label>
                                <input type="date" class="form-control" id="effective_issue_date" name="effective_issue_date" required>
                            </div>
                            <div class="col-md-6">
                                <label for="maturity_date" class="form-label">Maturity Date</label>
                                <input type="date" class="form-control" id="maturity_date" name="maturity_date" required>
                            </div>
                            <div class="col-md-6">
                                <label for="tenor" class="form-label">Tenor (Years)</label>
                                <input type="number" class="form-control" id="tenor" name="tenor" min="1" required>
                            </div>
                            <div class="col-md-6">
                                <label for="economic_sector" class="form-label">Economic Sector</label>
                                <select class="form-select" id="economic_sector" name="economic_sector">
                                    <option value="">Select Sector</option>
                                    <?php foreach ($active_economic_sectors as $sector): ?>
                                        <option value="<?php echo htmlspecialchars($sector['id']); ?>">
                                            <?php echo htmlspecialchars($sector['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                            </div>
                        </div>
                        
                        <h6 class="text-primary mt-4 mb-3">Pricing & Coupon</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="price" class="form-label">Price (TZS)</label>
                                <input type="number" class="form-control" id="price" name="price" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label for="coupon" class="form-label">Coupon Rate (%)</label>
                                <input type="number" class="form-control" id="coupon" name="coupon" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label for="ytm" class="form-label">Yield to Maturity (YTM) (%)</label>
                                <input type="number" class="form-control" id="ytm" name="ytm" step="0.01" min="0">
                            </div>
                            <div class="col-md-6">
                                <label for="coupon_determiner" class="form-label">Coupon Determiner</label>
                                <select class="form-select" id="coupon_determiner" name="coupon_determiner">
                                    <option value="">Select</option>
                                    <?php foreach ($active_coupon_determiners as $determiner): ?>
                                        <option value="<?php echo htmlspecialchars($determiner['id']); ?>">
                                            <?php echo htmlspecialchars($determiner['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="payment_frequency" class="form-label">Payment Frequency</label>
                                <select class="form-select" id="payment_frequency" name="payment_frequency">
                                    <option value="">Select</option>
                                    <?php foreach ($active_payment_frequencies as $frequency): ?>
                                        <option value="<?php echo htmlspecialchars($frequency['id']); ?>">
                                            <?php echo htmlspecialchars($frequency['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="withholding_tax" class="form-label">Withholding Tax (%)</label>
                                <input type="number" class="form-control" id="withholding_tax" name="withholding_tax" step="0.01" min="0">
                            </div>
                        </div>

                        <h6 class="text-primary mt-4 mb-3">Operational Details</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="issued_amount" class="form-label">Issued Amount (TZS)</label>
                                <input type="number" class="form-control" id="issued_amount" name="issued_amount" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label for="cds_security_code" class="form-label">CDS Security Code</label>
                                <input type="text" class="form-control" id="cds_security_code" name="cds_security_code">
                            </div>
                            <div class="col-md-6">
                                <label for="ats_security_code" class="form-label">ATS Security Code</label>
                                <input type="text" class="form-control" id="ats_security_code" name="ats_security_code">
                            </div>
                            <div class="col-md-6">
                                <label for="bond_no" class="form-label">Bond No.</label>
                                <input type="text" class="form-control" id="bond_no" name="bond_no">
                            </div>
                            <div class="col-md-6">
                                <label for="issue_no" class="form-label">Issue No.</label>
                                <input type="text" class="form-control" id="issue_no" name="issue_no">
                            </div>
                            <div class="col-md-6">
                                <label for="amortization_method" class="form-label">Amortization Method</label>
                                <select class="form-select" id="amortization_method" name="amortization_method">
                                    <option value="">Select</option>
                                    <option value="bullet">Bullet</option>
                                    <option value="sinking_fund">Sinking Fund</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="no_of_cash_flows" class="form-label">Number of Cash Flows</label>
                                <input type="number" class="form-control" id="no_of_cash_flows" name="no_of_cash_flows" min="0">
                            </div>
                            <div class="col-md-6">
                                <label for="day_count_convention" class="form-label">Day Count Convention</label>
                                <input type="text" class="form-control" id="day_count_convention" name="day_count_convention">
                            </div>
                            <div class="col-md-6">
                                <label for="cash_flow_days" class="form-label">Cash Flow Days</label>
                                <input type="text" class="form-control" id="cash_flow_days" name="cash_flow_days">
                            </div>
                            <div class="col-md-6">
                                <label for="statement_narrative" class="form-label">Statement Narrative</label>
                                <textarea class="form-control" id="statement_narrative" name="statement_narrative" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_government_bond" class="btn btn-success">Create Bond</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Corporate Bond Modal -->
    <div class="modal fade" id="createCorporateBondModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header">
                        <h5 class="modal-title">Create Corporate Bond</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body modal-body-scroll">
                        <h6 class="text-primary mb-3">General Information</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="issue_code" class="form-label">Issue Code</label>
                                <input type="text" class="form-control" id="issue_code" name="issue_code" required>
                            </div>
                            <div class="col-md-6">
                                <label for="isin" class="form-label">ISIN</label>
                                <input type="text" class="form-control" id="isin" name="isin" required>
                            </div>
                            <div class="col-md-6">
                                <label for="issuer" class="form-label">Issuer</label>
                                <select class="form-select" id="issuer" name="issuer" required>
                                    <option value="">Select Issuer</option>
                                    <?php foreach ($active_issuers as $issuer): ?>
                                        <option value="<?php echo htmlspecialchars($issuer['id']); ?>">
                                            <?php echo htmlspecialchars($issuer['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="type" class="form-label">Bond Type</label>
                                <select class="form-select" id="type" name="type" required>
                                    <option value="">Select Type</option>
                                    <?php foreach ($active_bond_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type['id']); ?>">
                                            <?php echo htmlspecialchars($type['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="effective_issue_date" class="form-label">Effective Issue Date</label>
                                <input type="date" class="form-control" id="effective_issue_date" name="effective_issue_date" required>
                            </div>
                            <div class="col-md-6">
                                <label for="maturity_date" class="form-label">Maturity Date</label>
                                <input type="date" class="form-control" id="maturity_date" name="maturity_date" required>
                            </div>
                            <div class="col-md-6">
                                <label for="tenor" class="form-label">Tenor (Years)</label>
                                <input type="number" class="form-control" id="tenor" name="tenor" min="1" required>
                            </div>
                            <div class="col-md-6">
                                <label for="economic_sector" class="form-label">Economic Sector</label>
                                <select class="form-select" id="economic_sector" name="economic_sector">
                                    <option value="">Select Sector</option>
                                    <?php foreach ($active_economic_sectors as $sector): ?>
                                        <option value="<?php echo htmlspecialchars($sector['id']); ?>">
                                            <?php echo htmlspecialchars($sector['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                            </div>
                        </div>
                        
                        <h6 class="text-primary mt-4 mb-3">Pricing & Coupon</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="price" class="form-label">Price (TZS)</label>
                                <input type="number" class="form-control" id="price" name="price" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label for="coupon" class="form-label">Coupon Rate (%)</label>
                                <input type="number" class="form-control" id="coupon" name="coupon" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label for="ytm" class="form-label">Yield to Maturity (YTM) (%)</label>
                                <input type="number" class="form-control" id="ytm" name="ytm" step="0.01" min="0">
                            </div>
                            <div class="col-md-6">
                                <label for="coupon_determiner" class="form-label">Coupon Determiner</label>
                                <select class="form-select" id="coupon_determiner" name="coupon_determiner">
                                    <option value="">Select</option>
                                    <?php foreach ($active_coupon_determiners as $determiner): ?>
                                        <option value="<?php echo htmlspecialchars($determiner['id']); ?>">
                                            <?php echo htmlspecialchars($determiner['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="payment_frequency" class="form-label">Payment Frequency</label>
                                <select class="form-select" id="payment_frequency" name="payment_frequency">
                                    <option value="">Select</option>
                                    <?php foreach ($active_payment_frequencies as $frequency): ?>
                                        <option value="<?php echo htmlspecialchars($frequency['id']); ?>">
                                            <?php echo htmlspecialchars($frequency['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="withholding_tax" class="form-label">Withholding Tax (%)</label>
                                <input type="number" class="form-control" id="withholding_tax" name="withholding_tax" step="0.01" min="0">
                            </div>
                        </div>

                        <h6 class="text-primary mt-4 mb-3">Operational Details</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="issued_amount" class="form-label">Issued Amount (TZS)</label>
                                <input type="number" class="form-control" id="issued_amount" name="issued_amount" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label for="cds_security_code" class="form-label">CDS Security Code</label>
                                <input type="text" class="form-control" id="cds_security_code" name="cds_security_code">
                            </div>
                            <div class="col-md-6">
                                <label for="ats_security_code" class="form-label">ATS Security Code</label>
                                <input type="text" class="form-control" id="ats_security_code" name="ats_security_code">
                            </div>
                            <div class="col-md-6">
                                <label for="bond_no" class="form-label">Bond No.</label>
                                <input type="text" class="form-control" id="bond_no" name="bond_no">
                            </div>
                            <div class="col-md-6">
                                <label for="issue_no" class="form-label">Issue No.</label>
                                <input type="text" class="form-control" id="issue_no" name="issue_no">
                            </div>
                            <div class="col-md-6">
                                <label for="amortization_method" class="form-label">Amortization Method</label>
                                <select class="form-select" id="amortization_method" name="amortization_method">
                                    <option value="">Select</option>
                                    <option value="bullet">Bullet</option>
                                    <option value="sinking_fund">Sinking Fund</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="no_of_cash_flows" class="form-label">Number of Cash Flows</label>
                                <input type="number" class="form-control" id="no_of_cash_flows" name="no_of_cash_flows" min="0">
                            </div>
                            <div class="col-md-6">
                                <label for="day_count_convention" class="form-label">Day Count Convention</label>
                                <input type="text" class="form-control" id="day_count_convention" name="day_count_convention">
                            </div>
                            <div class="col-md-6">
                                <label for="cash_flow_days" class="form-label">Cash Flow Days</label>
                                <input type="text" class="form-control" id="cash_flow_days" name="cash_flow_days">
                            </div>
                            <div class="col-md-6">
                                <label for="statement_narrative" class="form-label">Statement Narrative</label>
                                <textarea class="form-control" id="statement_narrative" name="statement_narrative" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_corporate_bond" class="btn btn-success">Create Bond</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Bond Details Modals -->
    <?php foreach ($bonds as $bond): ?>
        <div class="modal fade" id="viewBondModal_<?php echo $bond['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Bond Details - <?php echo htmlspecialchars($bond['security_id']); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <dl class="row">
                            <dt class="col-sm-4">Bond Name:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($bond['bond_name']); ?></dd>

                            <dt class="col-sm-4">Security Type:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($bond['security_type']); ?></dd>

                            <dt class="col-sm-4">Issuer:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($bond['issuer']); ?></dd>

                            <dt class="col-sm-4">Coupon Rate:</dt>
                            <dd class="col-sm-8"><?php echo number_format($bond['coupon_rate'], 2); ?>%</dd>

                            <dt class="col-sm-4">Tenor:</dt>
                            <dd class="col-sm-8"><?php echo ($bond['term_years'] ?? 'N/A'); ?> years</dd>

                            <dt class="col-sm-4">Effective Issue Date:</dt>
                            <dd class="col-sm-8"><?php echo format_date($bond['issue_date']); ?></dd>

                            <dt class="col-sm-4">Maturity Date:</dt>
                            <dd class="col-sm-8"><?php echo format_date($bond['maturity_date']); ?></dd>

                            <dt class="col-sm-4">Price:</dt>
                            <dd class="col-sm-8">TZS <?php echo format_currency($bond['price']); ?></dd>

                           <dt class="col-sm-4">YTM:</dt>
<dd class="col-sm-8"><?php echo $bond['ytm'] !== null ? number_format($bond['ytm'], 2) . '%' : 'N/A'; ?></dd>

                           <dt class="col-sm-4">Issued Amount:</dt>
<dd class="col-sm-8">TZS <?php echo $bond['issued_amount'] !== null ? format_currency($bond['issued_amount']) : '0.00'; ?></dd>

                           <dt class="col-sm-4">ISIN:</dt>
<dd class="col-sm-8"><?php echo $bond['isin'] !== null ? htmlspecialchars($bond['isin']) : 'N/A'; ?></dd>

                            <dt class="col-sm-4">Economic Sector:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($bond['economic_sector']); ?></dd>

                          <dt class="col-sm-4">CDS Security Code:</dt>
<dd class="col-sm-8"><?php echo $bond['cds_security_code'] !== null ? htmlspecialchars($bond['cds_security_code']) : 'N/A'; ?></dd>

                            <dt class="col-sm-4">ATS Security Code:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($bond['security_id']); ?></dd>

                     <dt class="col-sm-4">Description:</dt>
<dd class="col-sm-8"><?php echo $bond['description'] !== null ? nl2br(htmlspecialchars($bond['description'])) : 'N/A'; ?></dd>
                        </dl>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Edit Bond Modals -->
    <?php foreach ($bonds as $bond): ?>
        <div class="modal fade" id="editBondModal_<?php echo $bond['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <form method="POST" action="">
                        <input type="hidden" name="bond_id" value="<?php echo $bond['id']; ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Bond - <?php echo htmlspecialchars($bond['security_id']); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body modal-body-scroll">
                            <h6 class="text-primary mb-3">General Information</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="issue_code_<?php echo $bond['id']; ?>" class="form-label">Issue Code</label>
                                    <input type="text" class="form-control" id="issue_code_<?php echo $bond['id']; ?>" name="issue_code" value="<?php echo htmlspecialchars($bond['issue_code'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="isin_<?php echo $bond['id']; ?>" class="form-label">ISIN</label>
<input type="text" class="form-control" id="isin_<?php echo $bond['id']; ?>" name="isin" value="<?php echo $bond['isin'] !== null ? htmlspecialchars($bond['isin']) : ''; ?>" required>                                </div>
                                <div class="col-md-6">
                                    <label for="issuer_<?php echo $bond['id']; ?>" class="form-label">Issuer</label>
                                    <select class="form-select" id="issuer_<?php echo $bond['id']; ?>" name="issuer" required>
                                        <option value="">Select Issuer</option>
                                        <?php foreach ($active_issuers as $issuer): ?>
                                            <option value="<?php echo htmlspecialchars($issuer['id']); ?>" <?php echo ($issuer['description'] == $bond['issuer']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($issuer['description']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="type_<?php echo $bond['id']; ?>" class="form-label">Bond Type</label>
                                    <select class="form-select" id="type_<?php echo $bond['id']; ?>" name="type" required>
                                        <option value="">Select Type</option>
                                        <?php foreach ($active_bond_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type['id']); ?>" <?php echo ($type['description'] == $bond['type']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($type['description']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="effective_issue_date_<?php echo $bond['id']; ?>" class="form-label">Effective Issue Date</label>
                                    <input type="date" class="form-control" id="effective_issue_date_<?php echo $bond['id']; ?>" name="effective_issue_date" value="<?php echo htmlspecialchars($bond['issue_date']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="maturity_date_<?php echo $bond['id']; ?>" class="form-label">Maturity Date</label>
                                    <input type="date" class="form-control" id="maturity_date_<?php echo $bond['id']; ?>" name="maturity_date" value="<?php echo htmlspecialchars($bond['maturity_date']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="tenor_<?php echo $bond['id']; ?>" class="form-label">Tenor (Years)</label>
                                    <input type="number" class="form-control" id="tenor_<?php echo $bond['id']; ?>" name="tenor" min="1" value="<?php echo htmlspecialchars($bond['term_years']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="economic_sector_<?php echo $bond['id']; ?>" class="form-label">Economic Sector</label>
                                  <select class="form-select" id="economic_sector_<?php echo $bond['id']; ?>" name="economic_sector">
    <option value="">Select Sector</option>
    <?php foreach ($active_economic_sectors as $sector): ?>
        <option value="<?php echo htmlspecialchars($sector['id']); ?>" <?php echo ($bond['economic_sector'] !== null && $sector['description'] == $bond['economic_sector']) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($sector['description']); ?>
        </option>
    <?php endforeach; ?>
</select>

                                </div>
                                <div class="col-md-6">
                                    <label for="description_<?php echo $bond['id']; ?>" class="form-label">Description</label>
<textarea class="form-control" id="description_<?php echo $bond['id']; ?>" name="description" rows="3"><?php echo $bond['description'] !== null ? htmlspecialchars($bond['description']) : ''; ?></textarea>                                </div>
                            </div>
                            
                            <h6 class="text-primary mt-4 mb-3">Pricing & Coupon</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="price_<?php echo $bond['id']; ?>" class="form-label">Price (TZS)</label>
                                    <input type="number" class="form-control" id="price_<?php echo $bond['id']; ?>" name="price" step="0.01" min="0" value="<?php echo htmlspecialchars($bond['price']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="coupon_<?php echo $bond['id']; ?>" class="form-label">Coupon Rate (%)</label>
                                    <input type="number" class="form-control" id="coupon_<?php echo $bond['id']; ?>" name="coupon" step="0.01" min="0" value="<?php echo htmlspecialchars($bond['coupon_rate']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="ytm_<?php echo $bond['id']; ?>" class="form-label">Yield to Maturity (YTM) (%)</label>
<input type="number" class="form-control" id="ytm_<?php echo $bond['id']; ?>" name="ytm" step="0.01" min="0" value="<?php echo $bond['ytm'] !== null ? htmlspecialchars($bond['ytm']) : '0'; ?>">                                </div>
                                <div class="col-md-6">
                                    <label for="coupon_determiner_<?php echo $bond['id']; ?>" class="form-label">Coupon Determiner</label>
                                    <select class="form-select" id="coupon_determiner_<?php echo $bond['id']; ?>" name="coupon_determiner">
    <option value="">Select</option>
    <?php foreach ($active_coupon_determiners as $determiner): ?>
        <option value="<?php echo htmlspecialchars($determiner['id']); ?>" <?php echo ($bond['coupon_determiner'] !== null && $determiner['description'] == $bond['coupon_determiner']) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($determiner['description']); ?>
        </option>
    <?php endforeach; ?>
</select>
                                </div>
                                <div class="col-md-6">
                                    <label for="payment_frequency_<?php echo $bond['id']; ?>" class="form-label">Payment Frequency</label>
                                   <select class="form-select" id="payment_frequency_<?php echo $bond['id']; ?>" name="payment_frequency">
    <option value="">Select</option>
    <?php foreach ($active_payment_frequencies as $frequency): ?>
        <option value="<?php echo htmlspecialchars($frequency['id']); ?>" <?php echo ($bond['payment_frequency'] !== null && $frequency['description'] == $bond['payment_frequency']) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($frequency['description']); ?>
        </option>
    <?php endforeach; ?>
</select>
                                </div>
                                <div class="col-md-6">
                                    <label for="withholding_tax_<?php echo $bond['id']; ?>" class="form-label">Withholding Tax (%)</label>
                                    <input type="number" class="form-control" id="withholding_tax_<?php echo $bond['id']; ?>" name="withholding_tax" step="0.01" min="0" value="<?php echo htmlspecialchars($bond['withholding_tax']); ?>">
                                </div>
                            </div>

                            <h6 class="text-primary mt-4 mb-3">Operational Details</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="issued_amount_<?php echo $bond['id']; ?>" class="form-label">Issued Amount (TZS)</label>
<input type="number" class="form-control" id="issued_amount_<?php echo $bond['id']; ?>" name="issued_amount" step="0.01" min="0" value="<?php echo $bond['issued_amount'] !== null ? htmlspecialchars($bond['issued_amount']) : '0'; ?>" required>                                </div>
                                <div class="col-md-6">
                                    <label for="cds_security_code_<?php echo $bond['id']; ?>" class="form-label">CDS Security Code</label>
<input type="text" class="form-control" id="cds_security_code_<?php echo $bond['id']; ?>" name="cds_security_code" value="<?php echo $bond['cds_security_code'] !== null ? htmlspecialchars($bond['cds_security_code']) : ''; ?>">                                </div>
                                <div class="col-md-6">
                                    <label for="ats_security_code_<?php echo $bond['id']; ?>" class="form-label">ATS Security Code</label>
                                    <input type="text" class="form-control" id="ats_security_code_<?php echo $bond['id']; ?>" name="ats_security_code" value="<?php echo htmlspecialchars($bond['security_id']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="bond_no_<?php echo $bond['id']; ?>" class="form-label">Bond No.</label>
<input type="text" class="form-control" id="bond_no_<?php echo $bond['id']; ?>" name="bond_no" value="<?php echo $bond['bond_no'] !== null ? htmlspecialchars($bond['bond_no']) : ''; ?>">                                </div>
                                <div class="col-md-6">
                                    <label for="issue_no_<?php echo $bond['id']; ?>" class="form-label">Issue No.</label>
<input type="text" class="form-control" id="issue_no_<?php echo $bond['id']; ?>" name="issue_no" value="<?php echo $bond['issue_no'] !== null ? htmlspecialchars($bond['issue_no']) : ''; ?>">                                </div>
                                <div class="col-md-6">
                                    <label for="amortization_method_<?php echo $bond['id']; ?>" class="form-label">Amortization Method</label>
                                   <select class="form-select" id="amortization_method_<?php echo $bond['id']; ?>" name="amortization_method">
    <option value="">Select</option>
    <option value="bullet" <?php echo ($bond['amortization_method'] !== null && $bond['amortization_method'] == 'bullet') ? 'selected' : ''; ?>>Bullet</option>
    <option value="sinking_fund" <?php echo ($bond['amortization_method'] !== null && $bond['amortization_method'] == 'sinking_fund') ? 'selected' : ''; ?>>Sinking Fund</option>
</select>
                                </div>
                                <div class="col-md-6">
                                    <label for="no_of_cash_flows_<?php echo $bond['id']; ?>" class="form-label">Number of Cash Flows</label>
                                    <input type="number" class="form-control" id="no_of_cash_flows_<?php echo $bond['id']; ?>" name="no_of_cash_flows" min="0" value="<?php echo htmlspecialchars($bond['no_of_cash_flows']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="day_count_convention_<?php echo $bond['id']; ?>" class="form-label">Day Count Convention</label>
<input type="text" class="form-control" id="day_count_convention_<?php echo $bond['id']; ?>" name="day_count_convention" value="<?php echo $bond['day_count_convention'] !== null ? htmlspecialchars($bond['day_count_convention']) : ''; ?>">                                </div>
                                <div class="col-md-6">
                                    <label for="cash_flow_days_<?php echo $bond['id']; ?>" class="form-label">Cash Flow Days</label>
<input type="text" class="form-control" id="cash_flow_days_<?php echo $bond['id']; ?>" name="cash_flow_days" value="<?php echo $bond['cash_flow_days'] !== null ? htmlspecialchars($bond['cash_flow_days']) : ''; ?>">                                </div>
                                <div class="col-md-6">
                                    <label for="statement_narrative_<?php echo $bond['id']; ?>" class="form-label">Statement Narrative</label>
<textarea class="form-control" id="statement_narrative_<?php echo $bond['id']; ?>" name="statement_narrative" rows="3"><?php echo $bond['statement_narrative'] !== null ? htmlspecialchars($bond['statement_narrative']) : ''; ?></textarea>                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="update_bond" class="btn btn-warning">Update Bond</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

</div>

<?php include '../includes/footer.php'; ?>

<script>
    // Handles the activation, completion, and cancellation of auctions
    document.addEventListener('DOMContentLoaded', function() {
        const actionButtons = document.querySelectorAll('.action-btn');
        actionButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const action = this.getAttribute('data-action');
                const auctionId = this.getAttribute('data-id');
                let message = '';
                
                switch(action) {
                    case 'activate':
                        message = 'Are you sure you want to activate this auction? This will make it available for bidding.';
                        break;
                    case 'complete':
                        message = 'Are you sure you want to complete this auction? This action cannot be undone.';
                        break;
                    case 'cancel':
                        message = 'Are you sure you want to cancel this auction?';
                        break;
                }
                
                // Using a custom modal for confirmation
                const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
                const modalBody = document.getElementById('confirmationModalBody');
                const confirmButton = document.getElementById('confirmActionButton');

                modalBody.textContent = message;
                confirmButton.onclick = function() {
                    window.location.href = `bonds.php?action=${action}&id=${auctionId}`;
                };
                confirmationModal.show();
            });
        });
    });
</script>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirmationModalBody">
                <!-- Message will be inserted here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmActionButton">Confirm</button>
            </div>
        </div>
    </div>
</div>

<style>
    .modal-body-scroll {
        max-height: 70vh; /* Adjust this value as needed */
        overflow-y: auto;
    }
</style>

<?php include '../includes/footer.php'; ?>