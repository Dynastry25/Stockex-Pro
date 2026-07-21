<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Stock Exchange System</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?php echo BASE_URL; ?>assets/css/style.css" rel="stylesheet">
</head>
<body class="professional-body">
    <?php if (is_logged_in()): ?>
        <?php 
        $current_user = get_logged_in_user(); 
        if (!$current_user || !is_array($current_user)) {
            header("Location: " . BASE_URL . "auth/login");
            exit();
        }
        ?>
        
        <!-- Added sidebar navigation system for better page control -->
        <!-- Sidebar Navigation -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-brand">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>StockEx Pro</span>
                </div>
                <button class="sidebar-close" id="sidebarClose">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            
            <div class="sidebar-content">
                <ul class="sidebar-nav">
                    <!-- Dashboard - FIXED FOR FINANCE OFFICER -->
                    <li class="nav-item">
                        <?php
                        // Determine dashboard path based on role
                        $dashboard_link = '';
                        switch($current_user['role']) {
                            case 'system_admin':
                                $dashboard_link = 'admin/dashboard';
                                break;
                            case 'trader':
                                $dashboard_link = 'trader/dashboard';
                                break;
                            case 'finance_officer':
                                $dashboard_link = 'finance/dashboard';
                                break;
                            case 'hr':
                            case 'human_resource':
                            case 'hr_manager':
                            case 'hr_officer':
                                $dashboard_link = 'hr/dashboard';
                                break;
                            case 'ceo':
                                $dashboard_link = 'ceo/dashboard'; // or create ceo/dashboard
                                break;
                            default:
                                $dashboard_link = $current_user['role'] . '/dashboard';
                        }
                        ?>
                        <a class="nav-link" href="<?php echo BASE_URL . $dashboard_link; ?>">
                            <i class="bi bi-speedometer2"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    
                    <!-- Leave Request - Available for all users -->
               
                    
                       <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>leave_request">
                            <i class="bi bi-calendar-check"></i>
                            <span>Request Leave</span>
                        </a>
                    </li>
                    
                    <!-- Your Performance Targets - Available for employees -->
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>my_targets">
                            <i class="bi bi-bullseye"></i>
                            <span>My Performance Targets</span>
                        </a>
                    </li>
                    
                   
                    <?php if ($current_user['role'] == 'system_admin' || $current_user['role'] == 'ceo'): ?>
                    <!-- Admin/CEO Features -->
                        <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>ceo/likizo">
                            <i class="bi bi-calendar-check"></i>
                            <span>Employees Leaves</span>
                        </a>
                    </li>
                           <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>ceo/pay_employees">
                            <i class="bi bi-calendar-check"></i>
                            <span>Pay employees</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>ceo/users">
                            <i class="bi bi-people"></i>
                            <span>User Management</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>ceo/client_management">
                            <i class="bi bi-person-lines-fill"></i>
                            <span>Client Management</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>ceo/master_data">
                            <i class="bi bi-database"></i>
                            <span>Master Data</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>ceo/fee_management">
                            <i class="bi bi-currency-dollar"></i>
                            <span>Fee Management</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($current_user['role'] == 'trader' || $current_user['role'] == 'system_admin'): ?>
                    <!-- Trading Features -->
                    <li class="nav-section">
                        <span class="nav-section-title">Trading</span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/trades">
                            <i class="bi bi-list-ul"></i>
                            <span>All Trades</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/dealing_sheet.php">
                            <i class="bi bi-journal-check"></i>
                            <span>Dealing Sheets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/order_sheet.php">
                            <i class="bi bi-journal-text"></i>
                            <span>Order Intake</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/upload_interface">
                            <i class="bi bi-upload"></i>
                            <span>Upload Trades</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/upload_history">
                            <i class="bi bi-receipt-cutoff"></i>
                            <span>Upload SOR Historical Transaction</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/ai_tickets">
                            <i class="bi bi-ticket-detailed"></i>
                            <span>AI Tickets</span>
                        </a>
                    </li>
                    
                        <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>ceo/client_management">
                            <i class="bi bi-person-lines-fill"></i>
                            <span>Client Management</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/send_contract_notes">
                            <i class="bi bi-graph-up"></i>
                            <span>Email Clients C notes</span>
                        </a>
                    </li>     
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/manage_lookups">
                            <i class="bi bi-graph-up"></i>
                            <span>Bond Settings</span>
                        </a>
                    </li>
                      <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/check_missing_csd">
                            <i class="bi bi-receipt-cutoff"></i>
                            <span>Missing trades in CSD</span>
                        </a>
                    </li>
                       <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/forms">
                            <i class="bi bi-list-ul"></i>
                            <span>Forms</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($current_user['role'] == 'finance_officer' || $current_user['role'] == 'system_admin'): ?>
                    <!-- Finance Features -->
                    <li class="nav-section">
                        <span class="nav-section-title">Finance</span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>finance/receipt">
                            <i class="bi bi-cash-stack"></i>
                            <span>Receipt</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>finance/payment">
                            <i class="bi bi-cash-stack"></i>
                            <span>Payment</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>finance/upload_mtp">
                            <i class="bi bi-cash-stack"></i>
                            <span>MTP Upload</span>
                        </a>
                    </li>
                  
                    
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>finance/chart_of_accounts">
                            <i class="bi bi-cash-stack"></i>
                            <span>Chart Of Accounts</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>finance/banks">
                            <i class="bi bi-cash-stack"></i>
                            <span>Bank Accounts</span>
                        </a>
                    </li>
                       <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>finance/debtors">
                            <i class="bi bi-cash-stack"></i>
                            <span>Inflow and Outflows</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>finance/manage_lookups">
                            <i class="bi bi-cash-stack"></i>
                            <span>Master Settings</span>
                        </a>
                    </li> 
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>finance/reports_dashboard">
                            <i class="bi bi-cash-stack"></i>
                            <span>Financial Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>finance/reconciliation">
                            <i class="bi bi-check2-square"></i>
                            <span>Reconciliation</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/settlement">
                            <i class="bi bi-currency-exchange"></i>
                            <span>Settle Trades</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/dealing_sheet">
                            <i class="bi bi-journal-check"></i>
                            <span>Dealing Sheet</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>trader/order_sheet">
                            <i class="bi bi-journal-text"></i>
                            <span>Order Intake</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($current_user['role'] == 'human_resource' || $current_user['role'] == 'hr' || $current_user['role'] == 'hr_manager' || $current_user['role'] == 'system_admin'): ?>
                    <!-- HR Department Features -->
                    <li class="nav-section">
                        <span class="nav-section-title">Human Resources</span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>hr/employees">
                            <i class="bi bi-people-fill"></i>
                            <span>Employee Management</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>hr/leave_management">
                            <i class="bi bi-calendar-check"></i>
                            <span>Leave Management</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>hr/payroll">
                            <i class="bi bi-cash-coin"></i>
                            <span>Payroll Setup</span>
                        </a>
                    </li>
                          <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>hr/pay_salary">
                            <i class="bi bi-folder-fill"></i>
                            <span>Pay Salaries</span>
                        </a>
                    </li>
                      <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>hr/hr_payment_request">
                            <i class="bi bi-folder-fill"></i>
                            <span>HR Other payments</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>hr/recruitment">
                            <i class="bi bi-person-badge"></i>
                            <span>Recruitment</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>hr/targets">
                            <i class="bi bi-bullseye"></i>
                            <span>Performance Targets</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>hr/reports">
                            <i class="bi bi-graph-up"></i>
                            <span>HR Reports</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($current_user['role'] == 'ceo' || $current_user['role'] == 'system_admin'): ?>
                    <!-- CEO Features -->
                    <li class="nav-section">
                        <span class="nav-section-title">CEO Approvals</span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>ceo/approvals_dashboard">
                            <i class="bi bi-check-circle"></i>
                            <span>Approval Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>finance/reports_dashboard">
                            <i class="bi bi-graph-up"></i>
                            <span>Finance Reports</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <!-- Document Repository (Available to all authenticated users) -->
                    <li class="nav-section">
                        <span class="nav-section-title">Document Center</span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>documents/department_docs">
                            <i class="bi bi-folder-fill"></i>
                            <span>Document Repository</span>
                        </a>
                    </li>
                    
                  
                    
                    <!-- Reports -->
                    <li class="nav-section">
                        <span class="nav-section-title">Reports</span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>reports/advanced_reports">
                            <i class="bi bi-file-earmark-bar-graph"></i>
                            <span>Advanced Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>reports/">
                            <i class="bi bi-house"></i>
                            <span>Reports Dashboard</span>
                        </a>
                    </li>

                </ul>
            </div>
        </nav>

        <!-- Updated top navigation to work with sidebar -->
        <!-- Top Navigation Bar -->
        <nav class="navbar navbar-expand-lg professional-navbar">
            <div class="container-fluid px-4">
                <button class="sidebar-toggle me-3" id="sidebarToggle">
                    <i class="bi bi-list"></i>
                </button>
                
                <a class="navbar-brand professional-brand" href="<?php echo BASE_URL; ?>">
                    <div class="brand-icon">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <div class="brand-text">
                        <span class="brand-name">StockEx</span>
                        <span class="brand-subtitle">Professional Trading</span>
                    </div>
                </a>
                
                <div class="ms-auto">
                    <!-- Enhanced user profile section -->
                    <ul class="navbar-nav professional-user-nav">
                        <li class="nav-item me-3">
                            <div class="user-info">
                                <span class="user-role-badge"><?php echo ucfirst(str_replace('_', ' ', $current_user['role'])); ?></span>
                            </div>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle professional-user-link" href="#" role="button" data-bs-toggle="dropdown">
                                <div class="user-avatar">
                                    <i class="bi bi-person-circle"></i>
                                </div>
                                <div class="user-details">
                                    <span class="user-name"><?php echo $current_user['full_name']; ?></span>
                                    <span class="user-status">Online</span>
                                </div>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end professional-dropdown">
                                <li class="dropdown-header">
                                    <div class="user-info-header">
                                        <strong><?php echo $current_user['full_name']; ?></strong>
                                        <small class="text-muted"><?php echo $current_user['email']; ?></small>
                                    </div>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>profile">
                                    <i class="bi bi-person"></i> My Profile
                                </a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>documents/department_docs">
                                    <i class="bi bi-folder"></i> Document Center
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>auth/logout">
                                    <i class="bi bi-box-arrow-right"></i> Sign Out
                                </a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
        
        <!-- Breadcrumb navigation - FIXED SYNTAX -->
        <?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
        <nav class="breadcrumb-nav">
            <div class="container-fluid px-4">
                <ol class="breadcrumb professional-breadcrumb">
                    <?php foreach ($breadcrumbs as $index => $crumb): ?>
                        <?php if ($index === count($breadcrumbs) - 1): ?>
                            <li class="breadcrumb-item active"><?php echo $crumb['title']; ?></li>
                        <?php else: ?>
                            <li class="breadcrumb-item">
                                <a href="<?php echo $crumb['url']; ?>"><?php echo $crumb['title']; ?></a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </div>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Updated main content area to work with sidebar -->
    <!-- Main content area with sidebar support -->
    <main class="main-content">
        <div class="container-fluid px-4">
            <?php display_alerts(); ?>
