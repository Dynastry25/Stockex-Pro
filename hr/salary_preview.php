<?php
// salary_preview.php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';
require_once '../includes/payroll_helpers.php';

$current_user_role = $_SESSION['role'];
$preview_id = (int)($_GET['id'] ?? 0);

if (!$preview_id) {
    header('Location: salary_setup.php');
    exit();
}

$db = getDBConnection();

// Get preview data
$preview_stmt = $db->prepare("
    SELECT sp.*, creator.full_name as created_by_name
    FROM salary_previews sp
    LEFT JOIN users creator ON sp.created_by = creator.id
    WHERE sp.id = ?
");
$preview_stmt->execute([$preview_id]);
$preview = $preview_stmt->fetch();

if (!$preview) {
    header('Location: salary_setup.php');
    exit();
}

$preview_data = json_decode($preview['preview_data'], true);
$page_title = 'Salary Preview - ' . htmlspecialchars($preview['preview_name']);

ob_start();
include '../includes/header.php';
?>

<div class="container-fluid pt-4 px-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-file-earmark-spreadsheet me-2"></i>
            Salary Preview: <?php echo htmlspecialchars($preview['preview_name']); ?>
        </h1>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="bi bi-printer me-2"></i>Print
            </button>
            <a href="export_salary_preview.php?id=<?php echo $preview_id; ?>" class="btn btn-success">
                <i class="bi bi-file-earmark-excel me-2"></i>Export to Excel
            </a>
            <?php if ($current_user_role === 'hr_manager' && $preview['status'] === 'draft'): ?>
                <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#submitApprovalModal">
                    <i class="bi bi-send-check me-2"></i>Submit for CEO Approval
                </button>
            <?php endif; ?>
            <a href="salary_setup.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-2"></i>Back to Setup
            </a>
        </div>
    </div>

    <!-- Preview Info -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Preview Information</h6>
                    <p><strong>Month:</strong> <?php echo date('F Y', strtotime($preview['pay_period_month'] . '-01')); ?></p>
                    <p><strong>Status:</strong> 
                        <span class="badge bg-<?php 
                            echo $preview['status'] === 'approved' ? 'success' : 
                                 ($preview['status'] === 'rejected' ? 'danger' : 
                                 ($preview['status'] === 'pending_ceo' ? 'warning' : 'secondary')); 
                        ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $preview['status'])); ?>
                        </span>
                    </p>
                    <p><strong>Created:</strong> <?php echo date('d/m/Y H:i', strtotime($preview['created_at'])); ?></p>
                    <p><strong>By:</strong> <?php echo htmlspecialchars($preview['created_by_name']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Summary Statistics</h6>
                    <p><strong>Employees:</strong> <?php echo $preview_data['summary']['total_employees'] ?? 0; ?></p>
                    <p><strong>Total Gross Salary:</strong> <?php echo format_payroll_currency($preview_data['summary']['total_gross'] ?? 0); ?></p>
                    <p><strong>Total Net Salary:</strong> <?php echo format_payroll_currency($preview_data['summary']['total_net'] ?? 0); ?></p>
                    <p><strong>Total Employer Cost:</strong> <?php echo format_payroll_currency($preview_data['summary']['total_employer_cost'] ?? 0); ?></p>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Deductions Summary</h6>
                    <p><strong>Total Employee Deductions:</strong> <?php echo format_payroll_currency($preview_data['summary']['total_employee_deductions'] ?? 0); ?></p>
                    <p><strong>Total Employer Contributions:</strong> <?php echo format_payroll_currency($preview_data['summary']['total_employer_contributions'] ?? 0); ?></p>
                    <?php if (!empty($preview_data['summary']['deduction_breakdown'])): ?>
                        <?php foreach ($preview_data['summary']['deduction_breakdown'] as $ded_name => $amount): ?>
                            <p><small><?php echo htmlspecialchars($ded_name); ?>: <?php echo format_payroll_currency($amount); ?></small></p>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Salary Breakdown -->
    <div class="card shadow mb-4">
        <div class="card-header bg-primary py-3">
            <h6 class="m-0 font-weight-bold">
                <i class="bi bi-table me-2"></i>
                Detailed Salary Breakdown - <?php echo date('F Y', strtotime($preview['pay_period_month'] . '-01')); ?>
            </h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="salaryPreviewTable">
                    <thead class="table-light">
                        <tr>
                            <th rowspan="2">Employee</th>
                            <th colspan="5" class="text-center bg-success">EARNINGS</th>
                            <th colspan="<?php echo count($preview_data['summary']['deduction_breakdown'] ?? []) + 1; ?>" class="text-center bg-danger">DEDUCTIONS (EMPLOYEE)</th>
                            <th rowspan="2" class="bg-info">NET PAY</th>
                            <th colspan="4" class="text-center bg-secondary">EMPLOYER CONTRIBUTIONS</th>
                            <th rowspan="2" class="bg-warning">TOTAL COST</th>
                        </tr>
                        <tr>
                            <!-- Earnings headers -->
                            <th class="bg-success">Basic</th>
                            <th class="bg-success">Allowances</th>
                            <th class="bg-success">Overtime</th>
                            <th class="bg-success">Bonus</th>
                            <th class="bg-success">Gross</th>
                            
                            <!-- Deduction headers -->
                            <?php foreach ($preview_data['summary']['deduction_breakdown'] ?? [] as $ded_name => $amount): ?>
                                <th class="bg-danger"><?php echo htmlspecialchars($ded_name); ?></th>
                            <?php endforeach; ?>
                            <th class="bg-danger">Total Deductions</th>
                            
                            <!-- Employer contributions headers -->
                            <th class="bg-secondary">NSSF (Emp)</th>
                            <th class="bg-secondary">SDL</th>
                            <th class="bg-secondary">WCF</th>
                            <th class="bg-secondary">OSHA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview_data['employees'] ?? [] as $employee): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($employee['full_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($employee['job_title']); ?></small>
                                </td>
                                
                                <!-- Earnings -->
                                <td class="text-end"><?php echo format_payroll_currency($employee['basic_salary']); ?></td>
                                <td class="text-end"><?php echo format_payroll_currency($employee['allowances']); ?></td>
                                <td class="text-end"><?php echo format_payroll_currency($employee['overtime']); ?></td>
                                <td class="text-end"><?php echo format_payroll_currency($employee['bonus']); ?></td>
                                <td class="text-end"><strong><?php echo format_payroll_currency($employee['gross_salary']); ?></strong></td>
                                
                                <!-- Deductions -->
                                <?php foreach ($preview_data['summary']['deduction_breakdown'] ?? [] as $ded_name => $amount): ?>
                                    <td class="text-end">
                                        <?php echo format_payroll_currency($employee['deductions'][$ded_name] ?? 0); ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-end text-danger">
                                    <strong><?php echo format_payroll_currency($employee['total_deductions']); ?></strong>
                                </td>
                                
                                <!-- Net Pay -->
                                <td class="text-end bg-info">
                                    <strong><?php echo format_payroll_currency($employee['net_salary']); ?></strong>
                                </td>
                                
                                <!-- Employer Contributions -->
                                <td class="text-end"><?php echo format_payroll_currency($employee['employer_contributions']['nssf'] ?? 0); ?></td>
                                <td class="text-end"><?php echo format_payroll_currency($employee['employer_contributions']['sdl'] ?? 0); ?></td>
                                <td class="text-end"><?php echo format_payroll_currency($employee['employer_contributions']['wcf'] ?? 0); ?></td>
                                <td class="text-end"><?php echo format_payroll_currency($employee['employer_contributions']['osha'] ?? 0); ?></td>
                                
                                <!-- Total Cost -->
                                <td class="text-end bg-warning">
                                    <strong><?php echo format_payroll_currency($employee['total_cost_to_employer']); ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td><strong>TOTALS</strong></td>
                            <!-- Earnings totals -->
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_basic'] ?? 0); ?></td>
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_allowances'] ?? 0); ?></td>
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_overtime'] ?? 0); ?></td>
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_bonus'] ?? 0); ?></td>
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_gross'] ?? 0); ?></td>
                            
                            <!-- Deduction totals -->
                            <?php foreach ($preview_data['summary']['deduction_breakdown'] ?? [] as $ded_name => $amount): ?>
                                <td class="text-end"><?php echo format_payroll_currency($amount); ?></td>
                            <?php endforeach; ?>
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_employee_deductions'] ?? 0); ?></td>
                            
                            <!-- Net pay total -->
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_net'] ?? 0); ?></td>
                            
                            <!-- Employer contribution totals -->
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_employer_nssf'] ?? 0); ?></td>
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_employer_sdl'] ?? 0); ?></td>
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_employer_wcf'] ?? 0); ?></td>
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_employer_osha'] ?? 0); ?></td>
                            
                            <!-- Total cost -->
                            <td class="text-end"><?php echo format_payroll_currency($preview_data['summary']['total_employer_cost'] ?? 0); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Notes -->
    <?php if (!empty($preview['notes'])): ?>
    <div class="card shadow">
        <div class="card-header bg-info">
            <h6 class="m-0 font-weight-bold"><i class="bi bi-chat-text me-2"></i>Notes</h6>
        </div>
        <div class="card-body">
            <?php echo nl2br(htmlspecialchars($preview['notes'])); ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Submit for Approval Modal -->
<?php if ($current_user_role === 'hr_manager' && $preview['status'] === 'draft'): ?>
<div class="modal fade" id="submitApprovalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="salary_setup.php">
                <input type="hidden" name="preview_id" value="<?php echo $preview_id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-send-check me-2"></i>Submit for CEO Approval
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        This will create a payment request and send it to the CEO for approval.
                        After CEO approval, it will go to Finance for payment processing.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" 
                                  placeholder="Add any notes for the CEO..."></textarea>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> Once submitted, you cannot edit this salary preview. 
                        Please ensure all calculations are correct.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_for_approval" class="btn btn-primary">
                        <i class="bi bi-send me-2"></i>Submit for Approval
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add search functionality
    const searchInput = document.createElement('input');
    searchInput.className = 'form-control form-control-sm';
    searchInput.placeholder = 'Search employees...';
    searchInput.style.width = '200px';
    searchInput.id = 'previewSearch';
    
    const table = document.getElementById('salaryPreviewTable');
    if (table) {
        const header = table.querySelector('thead tr:first-child');
        const searchCell = document.createElement('th');
        searchCell.colSpan = 4;
        searchCell.appendChild(searchInput);
        header.appendChild(searchCell);
        
        searchInput.addEventListener('input', function() {
            const term = this.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(term) ? '' : 'none';
            });
        });
    }
    
    // Print styling
    const printBtn = document.querySelector('button[onclick="window.print()"]');
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            // Add print-specific styles
            const style = document.createElement('style');
            style.textContent = `
                @media print {
                    .d-sm-flex, .btn, .modal, .card-header button {
                        display: none !important;
                    }
                    .card {
                        border: none !important;
                    }
                    .card-header {
                        background-color: #fff !important;
                        color: #000 !important;
                        border-bottom: 2px solid #000 !important;
                    }
                    table {
                        font-size: 9pt !important;
                    }
                    th, td {
                        padding: 4px !important;
                    }
                }
            `;
            document.head.appendChild(style);
        });
    }
});
</script>

<?php
include '../includes/footer.php';
ob_end_flush();
?>