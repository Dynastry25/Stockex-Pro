<?php
/**
 * API v1 - Companies Endpoint
 * 
 * Usage:
 * GET  /api/v1/companies.php - Get all companies (with filters)
 * GET  /api/v1/companies.php?id={id} - Get specific company
 * GET  /api/v1/companies.php?search={term} - Search companies
 */

// Enable comprehensive error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile:$errline");
    return true;
});

set_exception_handler(function($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
});

require_once __DIR__ . '/../config.php';

// Debug: Log incoming request
error_log("Companies API Request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

try {
    // Only allow GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Method not allowed. Use GET.', 405);
    }
    
    // Get database connection
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        error_log("Database connection failed");
        sendError('Database connection failed', 500);
    }

    // DEFAULT: If no parameters provided, return company with id=1
    if (empty($_GET) || (!isset($_GET['id']) && !isset($_GET['search']) && !isset($_GET['status']) && !isset($_GET['business_type']) && !isset($_GET['country']))) {
        $_GET['id'] = 1;
        error_log("No parameters provided, defaulting to company ID: 1");
    }
    
    // Handle specific company by ID
    if (isset($_GET['id'])) {
        $company_id = intval($_GET['id']);
        error_log("Fetching company with ID: $company_id");
        
        $stmt = $db->prepare("
            SELECT * FROM companies 
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$company_id]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($company) {
            logAPIRequest("/api/v1/companies/{$company_id}", 'GET', 200);
            sendResponse($company, 200, "Company retrieved successfully");
        } else {
            logAPIRequest("/api/v1/companies/{$company_id}", 'GET', 404);
            sendError("Company not found", 404);
        }
    }
    
    // Handle search
    elseif (isset($_GET['search']) && !empty($_GET['search'])) {
        $search_term = '%' . sanitizeInput($_GET['search']) . '%';
        error_log("Searching companies with term: " . $_GET['search']);
        
        $stmt = $db->prepare("
            SELECT * FROM companies 
            WHERE company_name LIKE ? 
               OR registration_number LIKE ?
               OR email LIKE ?
            ORDER BY company_name ASC
            LIMIT 50
        ");
        $stmt->execute([$search_term, $search_term, $search_term]);
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        logAPIRequest("/api/v1/companies?search=" . $_GET['search'], 'GET', 200);
        sendResponse([
            'companies' => $companies,
            'count' => count($companies)
        ], 200, "Search results returned");
    }
    
    // Get all companies with filters and pagination
    else {
        error_log("Fetching all companies");
        
        $where_conditions = [];
        $params = [];
        
        // Filter by status
        if (isset($_GET['status'])) {
            $where_conditions[] = "status = ?";
            $params[] = sanitizeInput($_GET['status']);
        }
        
        // Filter by business_type (formerly called sector)
        if (isset($_GET['business_type'])) {
            $where_conditions[] = "business_type LIKE ?";
            $params[] = '%' . sanitizeInput($_GET['business_type']) . '%';
        }
        
        // Filter by country
        if (isset($_GET['country'])) {
            $where_conditions[] = "country = ?";
            $params[] = sanitizeInput($_GET['country']);
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        // Pagination
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? min(intval($_GET['limit']), 100) : 50;
        $offset = ($page - 1) * $limit;
        
        error_log("Page: $page, Limit: $limit, Offset: $offset");
        
        // Get total count
        $count_stmt = $db->prepare("SELECT COUNT(*) as total FROM companies {$where_clause}");
        $count_stmt->execute($params);
        $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get companies
        $stmt = $db->prepare("
            SELECT 
                id,
                company_name,
                name,
                registration_number,
                business_type,
                country,
                email,
                phone,
                mobile,
                status,
                is_active,
                created_at,
                updated_at
            FROM companies 
            {$where_clause}
            ORDER BY company_name ASC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Retrieved " . count($companies) . " companies");
        
        logAPIRequest("/api/v1/companies", 'GET', 200);
        sendResponse([
            'companies' => $companies,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ], 200, "Companies retrieved successfully");
    }
    
} catch (Exception $e) {
    error_log("Exception in companies.php: " . $e->getMessage() . " | " . $e->getTraceAsString());
    logAPIRequest("/api/v1/companies", 'GET', 500);
    sendError('Internal server error: ' . $e->getMessage(), 500);
}
?>
