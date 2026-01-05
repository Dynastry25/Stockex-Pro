<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_trader();
require_mandate();

$page_title = 'Upload Securities';
include '../includes/header.php';
?>

<div class="page-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" 
                             style="width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);">
                            <i class="bi bi-cloud-upload" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="page-title mb-1">Upload Securities</h1>
                        <p class="page-subtitle">Choose the type of securities to upload</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="row g-4">
                <!-- Upload Bonds Card -->
                <div class="col-md-6">
                    <div class="card dashboard-card h-100 border-0 shadow-sm">
                        <div class="card-body text-center p-5">
                            <div class="mb-4">
                                <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" 
                                     style="width: 80px; height: 80px; background: linear-gradient(135deg, var(--warning-color) 0%, #f59e0b 100%);">
                                    <i class="bi bi-file-earmark-text" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                            <h4 class="fw-semibold mb-3">Upload Bonds</h4>
                            <p class="text-muted mb-4">Upload fixed income securities with automatic bond creation and validation</p>
                            <div class="mb-4">
                                <small class="text-muted">
                                    <i class="bi bi-check-circle text-success me-1"></i> Auto-creates bonds if not exist<br>
                                    <i class="bi bi-check-circle text-success me-1"></i> Validates ATS code format<br>
                                    <i class="bi bi-check-circle text-success me-1"></i> Supports CSV upload
                                </small>
                            </div>
                            <a href="upload_bonds.php" class="btn btn-warning btn-lg w-100">
                                <i class="bi bi-upload me-2"></i>
                                Upload Bonds
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Upload Shares Card -->
                <div class="col-md-6">
                    <div class="card dashboard-card h-100 border-0 shadow-sm">
                        <div class="card-body text-center p-5">
                            <div class="mb-4">
                                <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" 
                                     style="width: 80px; height: 80px; background: linear-gradient(135deg, var(--info-color) 0%, #0ea5e9 100%);">
                                    <i class="bi bi-graph-up" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                            <h4 class="fw-semibold mb-3">Upload Shares</h4>
                            <p class="text-muted mb-4">Upload equity securities with automatic share creation and validation</p>
                            <div class="mb-4">
                                <small class="text-muted">
                                    <i class="bi bi-check-circle text-success me-1"></i> Auto-creates equities if not exist<br>
                                    <i class="bi bi-check-circle text-success me-1"></i> Validates stock symbols<br>
                                    <i class="bi bi-check-circle text-success me-1"></i> Supports CSV upload
                                </small>
                            </div>
                            <a href="upload_shares.php" class="btn btn-info btn-lg w-100">
                                <i class="bi bi-upload me-2"></i>
                                Upload Shares
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Back to Dashboard -->
            <div class="text-center mt-4">
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
