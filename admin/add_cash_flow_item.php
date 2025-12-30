<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDBConnection();
    
    $code = $_POST['code'];
    $description = $_POST['description'];
    $activity = $_POST['activity'];
    $format = $_POST['format'];
    $reverse = $_POST['reverse'];
    $hpal = $_POST['hpal'];
    $hbral = $_POST['hbral'];
    $priority = $_POST['priority'];
    
    // Check if code already exists
    $stmt = $db->prepare("SELECT id FROM cash_flow_formats WHERE code = ?");
    $stmt->execute([$code]);
    
    if ($stmt->fetch()) {
        $_SESSION['error_message'] = "Cash flow item with code '$code' already exists!";
    } else {
        $stmt = $db->prepare("INSERT INTO cash_flow_formats (code, description, activity, format, reverse, hpal, hbral, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$code, $description, $activity, $format, $reverse, $hpal, $hbral, $priority]);
        
        $_SESSION['success_message'] = "Cash flow item added successfully!";
    }
    
    header("Location: cash_flow_configuration.php");
    exit();
}
?>