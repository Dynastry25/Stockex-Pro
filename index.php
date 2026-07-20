<?php
require_once 'config/config.php';

// Redirect to login if not logged in
if (!is_logged_in()) {
    redirect('auth/login.php');
}

// Redirect to appropriate dashboard based on role
$user = get_logged_in_user();
if (!is_array($user) || !isset($user['role'])) {
    redirect('auth/login.php');
}

switch ($user['role']) {
    case 'system_admin':
        redirect('admin/dashboard.php');
        break;
    case 'trader':
        redirect('trader/dashboard.php');
        break;
    case 'ceo':
        redirect('ceo/dashboard.php');
        break;
    case 'finance_officer':
        redirect('finance/dashboard.php');
        break;
    default:
        redirect('auth/login.php');
}
?>
