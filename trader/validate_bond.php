<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/bond_validation.php';

require_trader();
require_mandate();

$response = ['valid' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ats_code'])) {
    $ats_code = sanitize_input($_POST['ats_code']);
    
    if (empty($ats_code)) {
        $response['message'] = 'ATS code is required.';
    } else {
        $db = getDBConnection();
        $validation = verify_bond_exists($ats_code, $db);
        
        if ($validation['exists']) {
            $response['valid'] = true;
            $response['message'] = 'Bond validated successfully.';
            $response['bond'] = $validation['bond'];
        } else {
            $response['message'] = $validation['error'];
        }
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>
