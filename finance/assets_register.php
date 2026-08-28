<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_login();
if (!in_array($_SESSION['role'], ['system_admin', 'finance_officer', 'ceo'])) {
    show_alert('Access denied.', 'danger');
    redirect('auth/login.php');
}

$db = getDBConnection();
$message = '';
$message_type = '';

// ─── DEPRECIATION HELPER ───
function calculateDepreciation($cost, $residual, $useful_life, $life_unit, $start_date) {
    $depreciable = max(0, $cost - $residual);
    $total_months = ($life_unit === 'years') ? $useful_life * 12 : $useful_life;
    if ($total_months <= 0) return ['monthly' => 0, 'total_months' => 0, 'end_date' => $start_date, 'elapsed' => 0, 'accumulated' => 0, 'book_value' => $cost, 'rate' => 0, 'pct' => 0];
    $monthly = round($depreciable / $total_months, 2);
    $rate = round(($depreciable / $cost) * (12 / $total_months) * 100, 4);
    $start = new DateTime($start_date);
    $now = new DateTime();
    $end = clone $start;
    $end->modify("+" . $total_months . " months");
    $end_date = $end->format('Y-m-d');
    $elapsed = 0;
    if ($now >= $start) {
        $interval = $start->diff($now);
        $elapsed = ($interval->y * 12) + $interval->m;
    }
    $accumulated = min($depreciable, $monthly * $elapsed);
    $book_value = max($residual, $cost - $accumulated);
    $pct = ($depreciable > 0) ? round(($accumulated / $depreciable) * 100, 1) : 0;
    return compact('monthly', 'total_months', 'end_date', 'elapsed', 'accumulated', 'book_value', 'rate', 'pct', 'depreciable');
}

// ─── GENERATE ASSET CODE ───
function generateAssetCode($db) {
    $stmt = $db->query("SELECT asset_code FROM company_assets ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    if ($last && preg_match('/AST-(\d+)/', $last, $m)) {
        return 'AST-' . str_pad($m[1] + 1, 6, '0', STR_PAD_LEFT);
    }
    return 'AST-000001';
}

// ─── HANDLE POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['create_asset'])) {
        $name = trim($_POST['asset_name'] ?? '');
        $purchase_date = $_POST['purchase_date'] ?? '';
        $purchase_cost = floatval($_POST['purchase_cost'] ?? 0);
        $residual = floatval($_POST['residual_value'] ?? 0);
        $useful_life = intval($_POST['useful_life'] ?? 0);
        $life_unit = $_POST['useful_life_unit'] ?? 'years';
        $dep_start = $_POST['depreciation_start_date'] ?? $purchase_date;

        if (empty($name)) { $message = 'Asset name is required.'; $message_type = 'danger'; }
        elseif (empty($purchase_date)) { $message = 'Purchase date is required.'; $message_type = 'danger'; }
        elseif ($purchase_cost < 0) { $message = 'Purchase cost cannot be negative.'; $message_type = 'danger'; }
        elseif ($residual > $purchase_cost && $purchase_cost > 0) { $message = 'Residual value cannot exceed purchase cost.'; $message_type = 'danger'; }
        elseif ($useful_life <= 0) { $message = 'Useful life must be greater than zero.'; $message_type = 'danger'; }
        elseif (empty($dep_start)) { $message = 'Depreciation start date is required.'; $message_type = 'danger'; }
        else {
            $dep = calculateDepreciation($purchase_cost, $residual, $useful_life, $life_unit, $dep_start);
            $code = generateAssetCode($db);
            $stmt = $db->prepare("
                INSERT INTO company_assets (
                    asset_code, asset_name, description, category, serial_number,
                    purchase_date, purchase_cost, currency, depreciation_method,
                    useful_life, useful_life_unit, residual_value,
                    depreciation_start_date, depreciation_end_date, depreciation_rate,
                    depreciation_per_period, accumulated_depreciation, current_book_value,
                    status, asset_condition, location, department_id, assigned_to,
                    supplier_id, invoice_number, warranty_expiry_date, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'straight_line', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ok = $stmt->execute([
                $code, $name, trim($_POST['description'] ?? ''), trim($_POST['category'] ?? ''),
                trim($_POST['serial_number'] ?? ''), $purchase_date, $purchase_cost,
                $_POST['currency'] ?? 'TZS', $useful_life, $life_unit, $residual,
                $dep_start, $dep['end_date'], $dep['rate'], $dep['monthly'],
                0, $dep['book_value'], $_POST['status'] ?? 'active',
                $_POST['asset_condition'] ?? 'new', trim($_POST['location'] ?? ''),
                $_POST['department_id'] ?: null, $_POST['assigned_to'] ?: null,
                $_POST['supplier_id'] ?: null, trim($_POST['invoice_number'] ?? ''),
                !empty($_POST['warranty_expiry_date']) ? $_POST['warranty_expiry_date'] : null,
                trim($_POST['notes'] ?? ''), $_SESSION['user_id']
            ]);
            $message = $ok ? "Asset $code created successfully." : 'Error creating asset.';
            $message_type = $ok ? 'success' : 'danger';
        }
    }

    if (isset($_POST['update_asset'])) {
        $id = intval($_POST['asset_id'] ?? 0);
        $name = trim($_POST['asset_name'] ?? '');
        $purchase_cost = floatval($_POST['purchase_cost'] ?? 0);
        $residual = floatval($_POST['residual_value'] ?? 0);
        $useful_life = intval($_POST['useful_life'] ?? 0);
        $life_unit = $_POST['useful_life_unit'] ?? 'years';
        $dep_start = $_POST['depreciation_start_date'] ?? '';

        if ($id <= 0) { $message = 'Invalid asset.'; $message_type = 'danger'; }
        elseif (empty($name)) { $message = 'Asset name is required.'; $message_type = 'danger'; }
        elseif ($useful_life <= 0) { $message = 'Useful life must be greater than zero.'; $message_type = 'danger'; }
        else {
            $dep = calculateDepreciation($purchase_cost, $residual, $useful_life, $life_unit, $dep_start);
            $stmt = $db->prepare("
                UPDATE company_assets SET
                    asset_name=?, description=?, category=?, serial_number=?,
                    purchase_date=?, purchase_cost=?, currency=?,
                    useful_life=?, useful_life_unit=?, residual_value=?,
                    depreciation_start_date=?, depreciation_end_date=?, depreciation_rate=?,
                    depreciation_per_period=?, current_book_value=?,
                    status=?, asset_condition=?, location=?, department_id=?,
                    assigned_to=?, supplier_id=?, invoice_number=?,
                    warranty_expiry_date=?, notes=?, updated_by=?
                WHERE id=?
            ");
            $ok = $stmt->execute([
                $name, trim($_POST['description'] ?? ''), trim($_POST['category'] ?? ''),
                trim($_POST['serial_number'] ?? ''), $_POST['purchase_date'] ?? '', $purchase_cost,
                $_POST['currency'] ?? 'TZS', $useful_life, $life_unit, $residual,
                $dep_start, $dep['end_date'], $dep['rate'], $dep['monthly'],
                $dep['book_value'], $_POST['status'] ?? 'active',
                $_POST['asset_condition'] ?? 'new', trim($_POST['location'] ?? ''),
                $_POST['department_id'] ?: null, $_POST['assigned_to'] ?: null,
                $_POST['supplier_id'] ?: null, trim($_POST['invoice_number'] ?? ''),
                !empty($_POST['warranty_expiry_date']) ? $_POST['warranty_expiry_date'] : null,
                trim($_POST['notes'] ?? ''), $_SESSION['user_id'], $id
            ]);
            $message = $ok ? 'Asset updated successfully.' : 'Error updating asset.';
            $message_type = $ok ? 'success' : 'danger';
        }
    }

    if (isset($_POST['archive_asset'])) {
        $id = intval($_POST['asset_id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE company_assets SET status='archived', archived_at=NOW(), updated_by=? WHERE id=?");
            $stmt->execute([$_SESSION['user_id'], $id]);
            $message = 'Asset archived.';
            $message_type = 'success';
        }
    }

    if (isset($_POST['restore_asset'])) {
        $id = intval($_POST['asset_id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE company_assets SET status='active', archived_at=NULL, updated_by=? WHERE id=?");
            $stmt->execute([$_SESSION['user_id'], $id]);
            $message = 'Asset restored.';
            $message_type = 'success';
        }
    }
}

// ─── RECALCULATE ALL DEPRECIATION ───
if (isset($_GET['action']) && $_GET['action'] === 'recalc_all') {
    $rows = $db->query("SELECT id, purchase_cost, residual_value, useful_life, useful_life_unit, depreciation_start_date FROM company_assets WHERE status NOT IN ('archived','disposed','sold','written_off')")->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0;
    foreach ($rows as $r) {
        $dep = calculateDepreciation($r['purchase_cost'], $r['residual_value'], $r['useful_life'], $r['useful_life_unit'], $r['depreciation_start_date']);
        $stmt = $db->prepare("UPDATE company_assets SET accumulated_depreciation=?, current_book_value=?, depreciation_per_period=?, depreciation_end_date=?, depreciation_rate=? WHERE id=?");
        $stmt->execute([$dep['accumulated'], $dep['book_value'], $dep['monthly'], $dep['end_date'], $dep['rate'], $r['id']]);
        $updated++;
    }
    $message = "Recalculated depreciation for $updated assets.";
    $message_type = 'success';
}

// ─── QUERIES ───
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['filter_status'] ?? '';
$filter_category = $_GET['filter_category'] ?? '';
$filter_condition = $_GET['filter_condition'] ?? '';
$filter_dept = $_GET['filter_department'] ?? '';
$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (a.asset_code LIKE ? OR a.asset_name LIKE ? OR a.serial_number LIKE ? OR a.category LIKE ? OR a.location LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s, $s, $s]);
}
if ($filter_status !== '') { $where .= " AND a.status = ?"; $params[] = $filter_status; }
if ($filter_category !== '') { $where .= " AND a.category = ?"; $params[] = $filter_category; }
if ($filter_condition !== '') { $where .= " AND a.asset_condition = ?"; $params[] = $filter_condition; }
if ($filter_dept !== '') { $where .= " AND a.department_id = ?"; $params[] = $filter_dept; }

$count_stmt = $db->prepare("SELECT COUNT(*) FROM company_assets a $where");
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_records / $limit));

$sql = "SELECT a.*, d.name AS dept_name, u.full_name AS assigned_name, s.name AS supplier_name
        FROM company_assets a
        LEFT JOIN departments d ON a.department_id = d.id
        LEFT JOIN users u ON a.assigned_to = u.id
        LEFT JOIN suppliers s ON a.supplier_id = s.id
        $where ORDER BY a.created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recalculate live depreciation for each asset
foreach ($assets as &$a) {
    $dep = calculateDepreciation($a['purchase_cost'], $a['residual_value'], $a['useful_life'], $a['useful_life_unit'], $a['depreciation_start_date']);
    $a['live_accumulated'] = $dep['accumulated'];
    $a['live_book_value'] = $dep['book_value'];
    $a['live_monthly'] = $dep['monthly'];
    $a['live_pct'] = $dep['pct'];
    $a['live_end_date'] = $dep['end_date'];
    $a['live_elapsed'] = $dep['elapsed'];
    $a['live_total_months'] = $dep['total_months'];
    $a['live_depreciable'] = $dep['depreciable'];
}

// Lookups
$departments = $db->query("SELECT id, name FROM departments WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers_list = $db->query("SELECT id, name FROM suppliers WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$employees = $db->query("SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $db->query("SELECT DISTINCT category FROM company_assets WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Assets Register';
include '../includes/header.php';
?>

<style>
.card { border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.card-header { background-color: #f8f9fa; border-bottom: 1px solid #e0e0e0; padding: 15px 20px; font-weight: 600; }
.card-body { padding: 20px; }
.table { font-size: 0.85rem; margin-bottom: 0; }
.table th { font-weight: 600; border-bottom: 2px solid #dee2e6; background-color: #f8f9fa; padding: 10px 12px; white-space: nowrap; }
.table td { padding: 10px 12px; border-top: 1px solid #f0f0f0; vertical-align: middle; }
.table-hover tbody tr:hover { background-color: #f8f9fa; }
.badge { padding: 4px 8px; font-size: 0.72rem; font-weight: 500; }
.btn { border-radius: 4px; padding: 6px 12px; font-size: 0.875rem; }
.btn-sm { padding: 4px 8px; font-size: 0.8rem; }
.form-control, .form-select { border: 1px solid #ced4da; border-radius: 4px; padding: 8px 12px; font-size: 0.9rem; }
.form-label { font-weight: 500; margin-bottom: 5px; font-size: 0.88rem; }
.required::after { content: ' *'; color: #dc3545; }
.form-section { background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #007bff; }
.form-section h6 { margin-bottom: 12px; color: #007bff; font-weight: 600; }
.optional-field { color: #6c757d; font-style: italic; }
.asset-code { font-family: monospace; background-color: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 0.85rem; }
.currency-val { font-family: monospace; }
.dep-progress { height: 6px; border-radius: 3px; background: #e9ecef; }
.dep-progress-bar { height: 100%; border-radius: 3px; background: #28a745; transition: width 0.3s; }
.detail-label { font-size: 0.8rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
.detail-value { font-size: 0.95rem; font-weight: 500; margin-bottom: 12px; }
.summary-card { text-align: center; padding: 20px; border-radius: 8px; }
.summary-card .summary-value { font-size: 1.5rem; font-weight: 700; }
.summary-card .summary-label { font-size: 0.8rem; opacity: 0.8; margin-top: 4px; }
.empty-state { text-align: center; padding: 40px 20px; color: #6c757d; }
.empty-state i { font-size: 3rem; margin-bottom: 15px; opacity: 0.3; }
.preview-box { background: #e7f3ff; border: 1px solid #b3d7ff; border-radius: 4px; padding: 12px 15px; margin-top: 10px; }
.preview-box .pv { margin-bottom: 4px; }
.preview-box .pv strong { display: inline-block; width: 180px; }
</style>

<div class="container-fluid py-3">
    <!-- Summary Cards -->
    <?php
    $summary_sql = "SELECT
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN status='active' THEN 1 ELSE 0 END), 0) as active_count,
        COALESCE(SUM(CASE WHEN status NOT IN ('archived','disposed','sold','written_off') THEN purchase_cost ELSE 0 END), 0) as total_cost,
        COALESCE(SUM(CASE WHEN status NOT IN ('archived','disposed','sold','written_off') THEN current_book_value ELSE 0 END), 0) as total_book
    FROM company_assets";
    $summary = $db->query($summary_sql)->fetch(PDO::FETCH_ASSOC);
    ?>
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card summary-card" style="background: linear-gradient(135deg, #007bff, #0056b3); color: #fff;">
                <div class="summary-value"><?php echo number_format($summary['total']); ?></div>
                <div class="summary-label">Total Assets</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card summary-card" style="background: linear-gradient(135deg, #28a745, #1e7e34); color: #fff;">
                <div class="summary-value"><?php echo number_format($summary['active_count']); ?></div>
                <div class="summary-label">Active Assets</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card summary-card" style="background: linear-gradient(135deg, #fd7e14, #e8590c); color: #fff;">
                <div class="summary-value currency-val"><?php echo number_format((float)($summary['total_cost'] ?? 0), 2); ?></div>
                <div class="summary-label">Total Cost</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card summary-card" style="background: linear-gradient(135deg, #6f42c1, #5a32a3); color: #fff;">
                <div class="summary-value currency-val"><?php echo number_format((float)($summary['total_book'] ?? 0), 2); ?></div>
                <div class="summary-label">Total Book Value</div>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1"><i class="bi bi-box-seam"></i> Assets Register</h2>
                    <p class="text-muted mb-0">Manage company equipment, depreciation and asset tracking</p>
                </div>
                <div>
                    <a href="?action=recalc_all" class="btn btn-outline-secondary btn-sm me-1" onclick="return confirm('Recalculate depreciation for all assets?')"><i class="bi bi-arrow-clockwise"></i> Recalc Depreciation</a>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assetModal" onclick="resetAssetForm()"><i class="bi bi-plus-circle"></i> Add Asset</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
        <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm" name="search" placeholder="Search code, name, serial..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" name="filter_status">
                        <option value="">All Status</option>
                        <?php foreach (['active','in_storage','under_maintenance','assigned','disposed','sold','written_off','archived'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $filter_status === $s ? 'selected' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $s)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" name="filter_category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filter_category === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" name="filter_condition">
                        <option value="">All Conditions</option>
                        <?php foreach (['new','good','fair','poor','damaged'] as $c): ?>
                            <option value="<?php echo $c; ?>" <?php echo $filter_condition === $c ? 'selected' : ''; ?>><?php echo ucfirst($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" name="filter_department">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo $filter_dept == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel"></i></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assets Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Assets <span class="badge bg-secondary ms-1"><?php echo number_format($total_records); ?></span></h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($assets)): ?>
                <div class="empty-state">
                    <i class="bi bi-box-seam"></i>
                    <h5>No Assets Found</h5>
                    <p>Click "Add Asset" to register your first asset.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Asset Name</th>
                            <th>Category</th>
                            <th>Purchase Cost</th>
                            <th>Monthly Dep.</th>
                            <th>Accum. Dep.</th>
                            <th>Book Value</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Dep. Progress</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assets as $a): ?>
                        <tr>
                            <td><span class="asset-code"><?php echo htmlspecialchars($a['asset_code']); ?></span></td>
                            <td>
                                <strong><?php echo htmlspecialchars($a['asset_name']); ?></strong>
                                <?php if ($a['serial_number']): ?><br><small class="text-muted">SN: <?php echo htmlspecialchars($a['serial_number']); ?></small><?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($a['category'] ?: '-'); ?></td>
                            <td class="currency-val"><?php echo htmlspecialchars($a['currency'] ?? 'TZS'); ?> <?php echo number_format($a['purchase_cost'], 2); ?></td>
                            <td class="currency-val"><?php echo number_format($a['live_monthly'], 2); ?></td>
                            <td class="currency-val"><?php echo number_format($a['live_accumulated'], 2); ?></td>
                            <td class="currency-val fw-bold"><?php echo number_format($a['live_book_value'], 2); ?></td>
                            <td>
                                <?php
                                $status_colors = ['active'=>'success','in_storage'=>'info','under_maintenance'=>'warning','assigned'=>'primary','disposed'=>'secondary','sold'=>'secondary','written_off'=>'danger','archived'=>'dark'];
                                $sc = $status_colors[$a['status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?php echo $sc; ?>"><?php echo ucwords(str_replace('_', ' ', $a['status'])); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($a['assigned_name'] ?? '-'); ?></td>
                            <td style="min-width:120px;">
                                <div class="d-flex justify-content-between mb-1"><small><?php echo $a['live_pct']; ?>%</small></div>
                                <div class="dep-progress"><div class="dep-progress-bar" style="width:<?php echo min(100, $a['live_pct']); ?>%"></div></div>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-info" onclick="viewAsset(<?php echo htmlspecialchars(json_encode($a)); ?>)" title="View"><i class="bi bi-eye"></i></button>
                                    <button class="btn btn-outline-primary" onclick="editAsset(<?php echo htmlspecialchars(json_encode($a)); ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                                    <?php if ($a['status'] !== 'archived'): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Archive this asset?')">
                                            <input type="hidden" name="asset_id" value="<?php echo $a['id']; ?>">
                                            <button type="submit" name="archive_asset" class="btn btn-outline-warning btn-sm" title="Archive"><i class="bi bi-archive"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Restore this asset?')">
                                            <input type="hidden" name="asset_id" value="<?php echo $a['id']; ?>">
                                            <button type="submit" name="restore_asset" class="btn btn-outline-success btn-sm" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav><ul class="pagination pagination-sm justify-content-center">
        <?php if ($page > 1): ?>
            <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">&laquo;</a></li>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 3); $i <= min($total_pages, $page + 3); $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a></li>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
            <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">&raquo;</a></li>
        <?php endif; ?>
    </ul></nav>
    <?php endif; ?>
</div>

<!-- ═══════════ CREATE/EDIT MODAL ═══════════ -->
<div class="modal fade" id="assetModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" id="assetForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="assetModalTitle">Add New Asset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="asset_id" id="asset_id">

                    <div class="form-section">
                        <h6><i class="bi bi-info-circle"></i> General Information</h6>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label required">Asset Name</label>
                                <input type="text" class="form-control" name="asset_name" id="asset_name" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Category</label>
                                <input type="text" class="form-control" name="category" id="category" list="categoryList" placeholder="e.g. Furniture, IT Equipment">
                                <datalist id="categoryList">
                                    <?php foreach ($categories as $cat): ?><option value="<?php echo htmlspecialchars($cat); ?>"><?php endforeach; ?>
                                    <option value="Furniture"><option value="IT Equipment"><option value="Vehicles"><option value="Machinery"><option value="Building"><option value="Land"><option value="Office Equipment"><option value="Electronics">
                                </datalist>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Serial Number</label>
                                <input type="text" class="form-control" name="serial_number" id="serial_number">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="description" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="bi bi-currency-dollar"></i> Purchase Information</h6>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label required">Purchase Date</label>
                                <input type="date" class="form-control" name="purchase_date" id="purchase_date" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label required">Purchase Cost</label>
                                <input type="number" class="form-control" name="purchase_cost" id="purchase_cost" step="0.01" min="0" required oninput="updatePreview()">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Currency</label>
                                <select class="form-select" name="currency" id="currency">
                                    <option value="TZS">TZS</option><option value="USD">USD</option><option value="EUR">EUR</option><option value="GBP">GBP</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Residual Value</label>
                                <input type="number" class="form-control" name="residual_value" id="residual_value" step="0.01" min="0" value="0" oninput="updatePreview()">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Invoice Number</label>
                                <input type="text" class="form-control" name="invoice_number" id="invoice_number">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Supplier</label>
                                <select class="form-select" name="supplier_id" id="supplier_id">
                                    <option value="">-- None --</option>
                                    <?php foreach ($suppliers_list as $s): ?>
                                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Warranty Expiry</label>
                                <input type="date" class="form-control" name="warranty_expiry_date" id="warranty_expiry_date">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="bi bi-graph-down"></i> Depreciation</h6>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Depreciation Method</label>
                                <select class="form-select" name="depreciation_method" id="depreciation_method" disabled>
                                    <option value="straight_line">Straight Line</option>
                                </select>
                                <small class="text-muted">More methods coming soon</small>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label required">Useful Life</label>
                                <input type="number" class="form-control" name="useful_life" id="useful_life" min="1" value="5" required oninput="updatePreview()">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Life Unit</label>
                                <select class="form-select" name="useful_life_unit" id="useful_life_unit" onchange="updatePreview()">
                                    <option value="years">Years</option>
                                    <option value="months">Months</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label required">Depreciation Start Date</label>
                                <input type="date" class="form-control" name="depreciation_start_date" id="depreciation_start_date" required oninput="updatePreview()">
                            </div>
                        </div>
                        <div class="preview-box" id="depPreview">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="pv"><strong>Depreciable Amount:</strong> <span id="pv_depreciable">0.00</span></div>
                                    <div class="pv"><strong>Useful Life (months):</strong> <span id="pv_months">0</span></div>
                                    <div class="pv"><strong>Monthly Depreciation:</strong> <span id="pv_monthly">0.00</span></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="pv"><strong>Depreciation End Date:</strong> <span id="pv_end_date">-</span></div>
                                    <div class="pv"><strong>Estimated Accum. Dep.:</strong> <span id="pv_accum">0.00</span></div>
                                    <div class="pv"><strong>Estimated Book Value:</strong> <span id="pv_book">0.00</span></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="pv"><strong>Depreciation Rate:</strong> <span id="pv_rate">0%</span></div>
                                    <div class="pv"><strong>% Depreciated:</strong> <span id="pv_pct">0%</span></div>
                                    <div class="pv"><strong>Remaining Period:</strong> <span id="pv_remaining">0 months</span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="bi bi-geo-alt"></i> Assignment & Location</h6>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="status">
                                    <?php foreach (['active','in_storage','under_maintenance','assigned','disposed','sold','written_off'] as $s): ?>
                                        <option value="<?php echo $s; ?>"><?php echo ucwords(str_replace('_', ' ', $s)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Condition</label>
                                <select class="form-select" name="asset_condition" id="asset_condition">
                                    <?php foreach (['new','good','fair','poor','damaged'] as $c): ?>
                                        <option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Location</label>
                                <input type="text" class="form-control" name="location" id="location" placeholder="e.g. Office Floor 2">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department_id" id="department_id">
                                    <option value="">-- None --</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assigned To</label>
                                <select class="form-select" name="assigned_to" id="assigned_to">
                                    <option value="">-- None --</option>
                                    <?php foreach ($employees as $e): ?>
                                        <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="assetSubmitBtn" name="create_asset">Create Asset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════ VIEW DETAILS MODAL ═══════════ -->
<div class="modal fade" id="viewAssetModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i>Asset Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewAssetBody"></div>
        </div>
    </div>
</div>

<script>
function updatePreview() {
    var cost = parseFloat(document.getElementById('purchase_cost').value) || 0;
    var residual = parseFloat(document.getElementById('residual_value').value) || 0;
    var life = parseInt(document.getElementById('useful_life').value) || 0;
    var unit = document.getElementById('useful_life_unit').value;
    var start = document.getElementById('depreciation_start_date').value;

    var depreciable = Math.max(0, cost - residual);
    var totalMonths = (unit === 'years') ? life * 12 : life;
    var monthly = totalMonths > 0 ? (depreciable / totalMonths) : 0;
    var rate = cost > 0 ? ((depreciable / cost) * (12 / totalMonths) * 100) : 0;

    var endDate = '-';
    var elapsed = 0;
    var accum = 0;
    var bookVal = cost;
    var pct = 0;

    if (start && totalMonths > 0) {
        var s = new Date(start);
        var now = new Date();
        var e = new Date(s);
        e.setMonth(e.getMonth() + totalMonths);
        endDate = e.toISOString().split('T')[0];
        if (now >= s) {
            var diffMs = now - s;
            elapsed = Math.floor(diffMs / (1000 * 60 * 60 * 24 * 30.44));
        }
        accum = Math.min(depreciable, monthly * elapsed);
        bookVal = Math.max(residual, cost - accum);
        pct = depreciable > 0 ? ((accum / depreciable) * 100) : 0;
    }

    document.getElementById('pv_depreciable').textContent = cost.toLocaleString(undefined, {minimumFractionDigits:2});
    document.getElementById('pv_months').textContent = totalMonths;
    document.getElementById('pv_monthly').textContent = monthly.toLocaleString(undefined, {minimumFractionDigits:2});
    document.getElementById('pv_end_date').textContent = endDate;
    document.getElementById('pv_accum').textContent = accum.toLocaleString(undefined, {minimumFractionDigits:2});
    document.getElementById('pv_book').textContent = bookVal.toLocaleString(undefined, {minimumFractionDigits:2});
    document.getElementById('pv_rate').textContent = rate.toFixed(2) + '%';
    document.getElementById('pv_pct').textContent = pct.toFixed(1) + '%';
    var remaining = Math.max(0, totalMonths - elapsed);
    document.getElementById('pv_remaining').textContent = remaining + ' months';
}

function editAsset(a) {
    document.getElementById('assetModalTitle').textContent = 'Edit Asset';
    document.getElementById('assetSubmitBtn').textContent = 'Update Asset';
    document.getElementById('assetSubmitBtn').name = 'update_asset';
    document.getElementById('asset_id').value = a.id;
    document.getElementById('asset_name').value = a.asset_name || '';
    document.getElementById('category').value = a.category || '';
    document.getElementById('serial_number').value = a.serial_number || '';
    document.getElementById('description').value = a.description || '';
    document.getElementById('purchase_date').value = a.purchase_date || '';
    document.getElementById('purchase_cost').value = a.purchase_cost || '';
    document.getElementById('currency').value = a.currency || 'TZS';
    document.getElementById('residual_value').value = a.residual_value || '0';
    document.getElementById('invoice_number').value = a.invoice_number || '';
    document.getElementById('supplier_id').value = a.supplier_id || '';
    document.getElementById('warranty_expiry_date').value = a.warranty_expiry_date || '';
    document.getElementById('useful_life').value = a.useful_life || '5';
    document.getElementById('useful_life_unit').value = a.useful_life_unit || 'years';
    document.getElementById('depreciation_start_date').value = a.depreciation_start_date || '';
    document.getElementById('status').value = a.status || 'active';
    document.getElementById('asset_condition').value = a.asset_condition || 'new';
    document.getElementById('location').value = a.location || '';
    document.getElementById('department_id').value = a.department_id || '';
    document.getElementById('assigned_to').value = a.assigned_to || '';
    document.getElementById('notes').value = a.notes || '';
    updatePreview();
    new bootstrap.Modal(document.getElementById('assetModal')).show();
}

function resetAssetForm() {
    document.getElementById('assetModalTitle').textContent = 'Add New Asset';
    document.getElementById('assetSubmitBtn').textContent = 'Create Asset';
    document.getElementById('assetSubmitBtn').name = 'create_asset';
    document.getElementById('assetForm').reset();
    document.getElementById('asset_id').value = '';
    document.getElementById('useful_life').value = '5';
    updatePreview();
}

function viewAsset(a) {
    var depreciable = (a.purchase_cost - a.residual_value).toFixed(2);
    var statusColors = {active:'success',in_storage:'info',under_maintenance:'warning',assigned:'primary',disposed:'secondary',sold:'secondary',written_off:'danger',archived:'dark'};
    var condColors = {new:'success',good:'info',fair:'warning',poor:'danger',damaged:'dark'};
    var sc = statusColors[a.status] || 'secondary';
    var cc = condColors[a.asset_condition] || 'secondary';
    var pct = a.live_pct || 0;
    var remaining = Math.max(0, (a.live_total_months || 0) - (a.live_elapsed || 0));

    var html = '<div class="row">';
    html += '<div class="col-md-6"><div class="detail-label">Asset Code</div><div class="detail-value"><span class="asset-code">' + (a.asset_code||'') + '</span></div></div>';
    html += '<div class="col-md-6"><div class="detail-label">Asset Name</div><div class="detail-value">' + (a.asset_name||'') + '</div></div>';
    html += '<div class="col-md-6"><div class="detail-label">Category</div><div class="detail-value">' + (a.category||'-') + '</div></div>';
    html += '<div class="col-md-6"><div class="detail-label">Serial Number</div><div class="detail-value">' + (a.serial_number||'-') + '</div></div>';
    html += '<div class="col-md-6"><div class="detail-label">Status</div><div class="detail-value"><span class="badge bg-'+sc+'">' + (a.status||'').replace(/_/g,' ').replace(/\b\w/g,l=>l.toUpperCase()) + '</span></div></div>';
    html += '<div class="col-md-6"><div class="detail-label">Condition</div><div class="detail-value"><span class="badge bg-'+cc+'">' + (a.asset_condition||'').replace(/\b\w/g,l=>l.toUpperCase()) + '</span></div></div>';
    html += '<div class="col-md-12"><div class="detail-label">Description</div><div class="detail-value">' + (a.description||'-') + '</div></div>';
    html += '</div><hr><h6 class="mb-3"><i class="bi bi-currency-dollar me-1"></i>Purchase & Depreciation</h6><div class="row">';
    html += '<div class="col-md-4"><div class="detail-label">Purchase Cost</div><div class="detail-value currency-val">' + (a.currency||'TZS') + ' ' + parseFloat(a.purchase_cost||0).toLocaleString(undefined,{minimumFractionDigits:2}) + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Residual Value</div><div class="detail-value currency-val">' + parseFloat(a.residual_value||0).toLocaleString(undefined,{minimumFractionDigits:2}) + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Depreciable Amount</div><div class="detail-value currency-val">' + parseFloat(depreciable).toLocaleString(undefined,{minimumFractionDigits:2}) + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Method</div><div class="detail-value">Straight Line</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Useful Life</div><div class="detail-value">' + (a.useful_life||0) + ' ' + (a.useful_life_unit||'years') + ' (' + (a.live_total_months||0) + ' months)</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Depreciation Rate</div><div class="detail-value">' + parseFloat(a.live_rate||a.depreciation_rate||0).toFixed(2) + '%</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Start Date</div><div class="detail-value">' + (a.depreciation_start_date||'-') + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">End Date</div><div class="detail-value">' + (a.live_end_date||a.depreciation_end_date||'-') + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Monthly Depreciation</div><div class="detail-value currency-val">' + parseFloat(a.live_monthly||a.depreciation_per_period||0).toLocaleString(undefined,{minimumFractionDigits:2}) + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Accumulated Depreciation</div><div class="detail-value currency-val">' + parseFloat(a.live_accumulated||a.accumulated_depreciation||0).toLocaleString(undefined,{minimumFractionDigits:2}) + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Current Book Value</div><div class="detail-value currency-val fw-bold">' + parseFloat(a.live_book_value||a.current_book_value||0).toLocaleString(undefined,{minimumFractionDigits:2}) + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">% Depreciated</div><div class="detail-value">' + pct + '%</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Remaining Period</div><div class="detail-value">' + remaining + ' months</div></div>';
    html += '</div>';
    html += '<div class="mt-2"><div class="dep-progress" style="height:8px;"><div class="dep-progress-bar" style="width:' + Math.min(100, pct) + '%"></div></div></div>';
    html += '<hr><h6 class="mb-3"><i class="bi bi-geo-alt me-1"></i>Assignment</h6><div class="row">';
    html += '<div class="col-md-4"><div class="detail-label">Location</div><div class="detail-value">' + (a.location||'-') + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Department</div><div class="detail-value">' + (a.dept_name||'-') + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Assigned To</div><div class="detail-value">' + (a.assigned_name||'-') + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Supplier</div><div class="detail-value">' + (a.supplier_name||'-') + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Invoice #</div><div class="detail-value">' + (a.invoice_number||'-') + '</div></div>';
    html += '<div class="col-md-4"><div class="detail-label">Warranty Expiry</div><div class="detail-value">' + (a.warranty_expiry_date||'-') + '</div></div>';
    html += '</div>';
    if (a.notes) html += '<hr><h6 class="mb-2"><i class="bi bi-journal-text me-1"></i>Notes</h6><p>' + (a.notes) + '</p>';
    html += '<hr><div class="row text-muted small"><div class="col-md-6">Created: ' + (a.created_at||'') + '</div><div class="col-md-6 text-end">Updated: ' + (a.updated_at||'') + '</div></div>';

    document.getElementById('viewAssetBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('viewAssetModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>
