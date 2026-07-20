<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

// Require login
require_login();

$db = getDBConnection();

// Handle export requests
if ($_POST['export_type'] ?? '') {
    $export_type = $_POST['export_type'];
    $report_data = $_POST['report_data'] ?? '';
    $report_name = $_POST['report_name'] ?? 'report';
    
    switch ($export_type) {
        case 'excel':
            exportToExcel($report_data, $report_name);
            break;
        case 'csv':
            exportToCSV($report_data, $report_name);
            break;
        case 'pdf':
            exportToPDF($report_data, $report_name);
            break;
    }
}

function exportToExcel($data, $filename) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo $data;
    exit;
}

function exportToCSV($data, $filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');
    
    // Convert HTML table to CSV
    $data = strip_tags($data);
    $data = preg_replace('/\s+/', ' ', $data);
    $data = str_replace(' ', ',', $data);
    
    echo $data;
    exit;
}

function exportToPDF($data, $filename) {
    // Simple PDF generation - in production, use a proper PDF library like TCPDF or DOMPDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    header('Cache-Control: max-age=0');
    
    // For now, just output HTML - implement proper PDF generation later
    echo $data;
    exit;
}
?>
