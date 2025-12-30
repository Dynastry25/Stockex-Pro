<?php
/**
 * Bond Validation Functions
 * Ensures uploaded bonds match auction-created bonds
 */

function validate_ats_code($ats_code) {
    // ATS code format: bond_number-coupon_rate-Term-Auction_number
    // Example: 675-15-T16-A1
    $pattern = '/^(\d+)-(\d+(?:\.\d+)?)-T(\d+)-([A-Z0-9]+)$/';
    
    if (!preg_match($pattern, $ats_code, $matches)) {
        return [
            'valid' => false,
            'error' => 'Invalid ATS code format. Expected: BondNumber-CouponRate-TYears-AuctionNumber (e.g., 675-15-T16-A1)'
        ];
    }
    
    return [
        'valid' => true,
        'bond_number' => $matches[1],
        'coupon_rate' => (float)$matches[2],
        'term_years' => (int)$matches[3],
        'auction_number' => $matches[4]
    ];
}

function verify_bond_exists($ats_code, $db) {
    $stmt = $db->prepare("
        SELECT *
        FROM bonds
        WHERE security_id = ? AND status = 'active'
    ");
    $stmt->execute([$ats_code]);
    $bond = $stmt->fetch();
    
    if (!$bond) {
        return [
            'exists' => false,
            'error' => 'Bond with ATS code ' . $ats_code . ' not found or inactive. Please create the bond through auction first.'
        ];
    }
    
    // Validate ATS code components match database
    $validation = validate_ats_code($ats_code);
    if (!$validation['valid']) {
        return [
            'exists' => false,
            'error' => $validation['error']
        ];
    }
    
    // Check if components match (if available in database)
    $errors = [];
    
    if (isset($bond['bond_number']) && $bond['bond_number'] != $validation['bond_number']) {
        $errors[] = 'Bond number mismatch';
    }
    
    if ((float)$bond['coupon_rate'] != $validation['coupon_rate']) {
        $errors[] = 'Coupon rate mismatch';
    }
    
    if (isset($bond['term_years']) && $bond['term_years'] != $validation['term_years']) {
        $errors[] = 'Term years mismatch';
    }
    
    if (!empty($errors)) {
        return [
            'exists' => false,
            'error' => 'ATS code validation failed: ' . implode(', ', $errors)
        ];
    }
    
    return [
        'exists' => true,
        'bond' => $bond
    ];
}

function get_available_bonds_for_trading($db) {
    $stmt = $db->query("
        SELECT id, security_id, bond_name, coupon_rate, face_value, maturity_date
        FROM bonds
        WHERE status = 'active'
        ORDER BY security_id
    ");
    
    return $stmt->fetchAll();
}
?>
