<?php
/**
 * API v1 - Tickets Endpoint
 * 
 * Usage:
 * GET  /api/v1/tickets.php - Get all tickets (with filters)
 * GET  /api/v1/tickets.php?id={id} - Get specific ticket
 * POST /api/v1/tickets.php - Create new ticket
 * PUT  /api/v1/tickets.php?id={id} - Update ticket status/details
 */

// Enable comprehensive error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile:$errline");
    return true; // Don't execute PHP internal error handler
});

set_exception_handler(function($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && $error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
        error_log("Fatal Error on shutdown: " . json_encode($error));
    }
});

require_once __DIR__ . '/../config.php';

// Debug: Log incoming request
error_log("Tickets API Request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI']);

try {
    // Get database connection
    $db = getDBConnection();

    if (!$db) {
        error_log("Database connection failed");
        sendError('Database connection failed', 500);
    }

    // Handle different HTTP methods
    $method = $_SERVER['REQUEST_METHOD'];
    
    error_log("Processing request method: $method");

    switch ($method) {
        case 'GET':
            handleGetRequest($db);
            break;
        case 'POST':
            handlePostRequest($db);
            break;
        case 'PUT':
            handlePutRequest($db);
            break;
        default:
            sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log("Top-level exception in tickets.php: " . $e->getMessage() . " | " . $e->getTraceAsString());
    sendError('Internal server error: ' . $e->getMessage(), 500);
}

/**
 * Handle GET requests - Retrieve tickets
 */
function handleGetRequest($db) {
    try {
        // Get specific ticket by ID
        if (isset($_GET['id'])) {
            $ticket_id = intval($_GET['id']);
            
            $stmt = $db->prepare("
                SELECT t.*,
                       u.full_name as assigned_to_name,
                       COUNT(tc.id) as comment_count
                FROM tickets t
                LEFT JOIN users u ON t.assigned_to_user_id = u.id
                LEFT JOIN ticket_comments tc ON t.id = tc.ticket_id
                WHERE t.id = ?
                GROUP BY t.id
            ");
            $stmt->execute([$ticket_id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ticket) {
                // Decode metadata JSON if present
                if ($ticket['metadata']) {
                    $ticket['metadata'] = json_decode($ticket['metadata'], true);
                }
                
                // Get comments for this ticket
                $stmt = $db->prepare("
                    SELECT * FROM ticket_comments 
                    WHERE ticket_id = ? 
                    ORDER BY created_at ASC
                ");
                $stmt->execute([$ticket_id]);
                $ticket['comments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                logAPIRequest("/api/v1/tickets/{$ticket_id}", 'GET', 200);
                sendResponse($ticket, 200, "Ticket retrieved successfully");
            } else {
                logAPIRequest("/api/v1/tickets/{$ticket_id}", 'GET', 404);
                sendError("Ticket not found", 404);
            }
        } 
        // Check for new tickets since last check (for polling)
        elseif (isset($_GET['since'])) {
            $since = sanitizeInput($_GET['since']);
            
            $stmt = $db->prepare("
                SELECT t.*,
                       u.full_name as assigned_to_name
                FROM tickets t
                LEFT JOIN users u ON t.assigned_to_user_id = u.id
                WHERE t.created_at > ?
                ORDER BY t.created_at DESC
            ");
            $stmt->execute([$since]);
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode metadata for each ticket
            foreach ($tickets as &$ticket) {
                if ($ticket['metadata']) {
                    $ticket['metadata'] = json_decode($ticket['metadata'], true);
                }
            }
            
            logAPIRequest("/api/v1/tickets?since={$since}", 'GET', 200);
            sendResponse([
                'tickets' => $tickets,
                'count' => count($tickets),
                'has_new' => count($tickets) > 0
            ], 200, "New tickets retrieved successfully");
        }
        // Get all tickets with filters
        else {
            $where_conditions = [];
            $params = [];
            
            // Filter by status
            if (isset($_GET['status'])) {
                $where_conditions[] = "t.status = ?";
                $params[] = sanitizeInput($_GET['status']);
            }
            
            // Filter by priority
            if (isset($_GET['priority'])) {
                $where_conditions[] = "t.priority = ?";
                $params[] = sanitizeInput($_GET['priority']);
            }
            
            // Filter by category
            if (isset($_GET['category'])) {
                $where_conditions[] = "t.category = ?";
                $params[] = sanitizeInput($_GET['category']);
            }
            
            // Filter by assigned user
            if (isset($_GET['assigned_to'])) {
                $where_conditions[] = "t.assigned_to_user_id = ?";
                $params[] = intval($_GET['assigned_to']);
            }
            
            // Search
            if (isset($_GET['search']) && !empty($_GET['search'])) {
                $search_term = '%' . sanitizeInput($_GET['search']) . '%';
                $where_conditions[] = "(t.title LIKE ? OR t.description LIKE ? OR t.ticket_number LIKE ?)";
                $params[] = $search_term;
                $params[] = $search_term;
                $params[] = $search_term;
            }
            
            $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
            
            // Pagination
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $limit = isset($_GET['limit']) ? min(intval($_GET['limit']), 100) : 50;
            $offset = ($page - 1) * $limit;
            
            // Get total count
            $count_stmt = $db->prepare("SELECT COUNT(*) as total FROM tickets t {$where_clause}");
            $count_stmt->execute($params);
            $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get tickets
            $stmt = $db->prepare("
                SELECT t.*,
                       u.full_name as assigned_to_name,
                       COUNT(tc.id) as comment_count
                FROM tickets t
                LEFT JOIN users u ON t.assigned_to_user_id = u.id
                LEFT JOIN ticket_comments tc ON t.id = tc.ticket_id
                {$where_clause}
                GROUP BY t.id
                ORDER BY 
                    CASE t.priority
                        WHEN 'critical' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'medium' THEN 3
                        WHEN 'low' THEN 4
                    END,
                    t.created_at DESC
                LIMIT {$limit} OFFSET {$offset}
            ");
            $stmt->execute($params);
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode metadata for each ticket
            foreach ($tickets as &$ticket) {
                if ($ticket['metadata']) {
                    $ticket['metadata'] = json_decode($ticket['metadata'], true);
                }
            }
            
            logAPIRequest("/api/v1/tickets", 'GET', 200);
            sendResponse([
                'tickets' => $tickets,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ], 200, "Tickets retrieved successfully");
        }
    } catch (Exception $e) {
        error_log("Exception in handleGetRequest: " . $e->getMessage() . " | " . $e->getTraceAsString());
        logAPIRequest("/api/v1/tickets", 'GET', 500);
        sendError('Internal server error: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle POST requests - Create new ticket
 */
function handlePostRequest($db) {
    try {
        // Get JSON input
        $raw_input = file_get_contents('php://input');
        error_log("POST data received, length: " . strlen($raw_input));
        
        $input = json_decode($raw_input, true);
        
        if (!$input) {
            error_log("JSON decode failed: " . json_last_error_msg());
            sendError('Invalid JSON input: ' . json_last_error_msg(), 400);
        }
        
        error_log("Decoded JSON input: " . json_encode($input));
        
        // Validate required fields
        if (empty($input['title']) || empty($input['description'])) {
            error_log("Missing required fields. Title: " . (isset($input['title']) ? 'present' : 'missing') . ", Description: " . (isset($input['description']) ? 'present' : 'missing'));
            sendError('Missing required fields: title, description', 400);
        }
        
        // Generate ticket number - get the highest number for this year
        $year = date('Y');
        $count_result = $db->query("SELECT MAX(CAST(SUBSTRING(ticket_number, -4) AS UNSIGNED)) as max_num FROM tickets WHERE ticket_number LIKE 'TKT-{$year}-%'");
        if (!$count_result) {
            error_log("Failed to query ticket count");
            sendError('Failed to generate ticket number', 500);
        }
        
        $max_num = $count_result->fetch(PDO::FETCH_ASSOC)['max_num'];
        $next_num = ($max_num !== null) ? $max_num + 1 : 1;
        $ticket_number = 'TKT-' . $year . '-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);
        
        error_log("Generated ticket number: $ticket_number");
        
        // Prepare data
        $title = sanitizeInput($input['title']);
        $description = sanitizeInput($input['description']);
        $priority = isset($input['priority']) ? sanitizeInput($input['priority']) : 'medium';
        $category = isset($input['category']) ? sanitizeInput($input['category']) : null;
        $assigned_to = isset($input['assigned_to_user_id']) ? intval($input['assigned_to_user_id']) : null;
        $created_by = isset($input['created_by']) ? sanitizeInput($input['created_by']) : 'AI Chatbot';
        $related_entity_type = isset($input['related_entity_type']) ? sanitizeInput($input['related_entity_type']) : null;
        $related_entity_id = isset($input['related_entity_id']) ? intval($input['related_entity_id']) : null;
        $metadata = isset($input['metadata']) ? json_encode($input['metadata']) : null;
        $status = isset($input['status']) ? sanitizeInput($input['status']) : 'new';
        
        error_log("Prepared ticket data - Title: $title, Priority: $priority, Status: $status, Category: $category, Created By: $created_by");
        
        // Validate priority
        $valid_priorities = ['low', 'medium', 'high', 'critical'];
        if (!in_array($priority, $valid_priorities)) {
            error_log("Invalid priority: $priority, setting to medium");
            $priority = 'medium';
        }
        
        // Validate status
        $valid_statuses = ['new', 'viewed', 'in_progress', 'resolved', 'closed'];
        if (!in_array($status, $valid_statuses)) {
            error_log("Invalid status: $status, setting to new");
            $status = 'new';
        }
        
        // Insert ticket
        $stmt = $db->prepare("
            INSERT INTO tickets (
                ticket_number, title, description, priority, status, category, 
                assigned_to_user_id, created_by, related_entity_type, 
                related_entity_id, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            error_log("Prepare statement failed: " . json_encode($db->errorInfo()));
            sendError('Database prepare failed', 500);
        }
        
        $result = $stmt->execute([
            $ticket_number, $title, $description, $priority, $status, $category,
            $assigned_to, $created_by, $related_entity_type,
            $related_entity_id, $metadata
        ]);
        
        if (!$result) {
            error_log("Execute failed: " . json_encode($stmt->errorInfo()));
            sendError('Failed to create ticket: ' . $stmt->errorInfo()[2], 500);
        }
        
        error_log("Ticket inserted successfully");
        
        $ticket_id = $db->lastInsertId();
        error_log("Last insert ID: $ticket_id");
        
        // Retrieve the created ticket
        $stmt = $db->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticket) {
            error_log("Failed to retrieve created ticket with ID: $ticket_id");
            sendError('Ticket created but could not be retrieved', 500);
        }
        
        if ($ticket['metadata']) {
            $ticket['metadata'] = json_decode($ticket['metadata'], true);
        }
        
        logAPIRequest("/api/v1/tickets", 'POST', 201);
        sendResponse($ticket, 201, "Ticket created successfully");
    } catch (Exception $e) {
        error_log("Exception in handlePostRequest: " . $e->getMessage() . " | " . $e->getTraceAsString());
        logAPIRequest("/api/v1/tickets", 'POST', 500);
        sendError('Internal server error: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle PUT requests - Update ticket
 */
function handlePutRequest($db) {
    try {
        if (!isset($_GET['id'])) {
            sendError('Missing ticket ID', 400);
        }
        
        $ticket_id = intval($_GET['id']);
        
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendError('Invalid JSON input', 400);
        }
        
        // Check if ticket exists
        $stmt = $db->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing) {
            sendError('Ticket not found', 404);
        }
        
        // Build update query dynamically
        $update_fields = [];
        $params = [];
        
        if (isset($input['status'])) {
            $update_fields[] = "status = ?";
            $params[] = sanitizeInput($input['status']);
            
            // Set viewed_at timestamp if status is changed from 'new'
            if ($existing['status'] === 'new' && $input['status'] !== 'new') {
                $update_fields[] = "viewed_at = NOW()";
            }
            
            // Set resolved_at timestamp if status is 'resolved' or 'closed'
            if (in_array($input['status'], ['resolved', 'closed'])) {
                $update_fields[] = "resolved_at = NOW()";
            }
        }
        
        if (isset($input['priority'])) {
            $update_fields[] = "priority = ?";
            $params[] = sanitizeInput($input['priority']);
        }
        
        if (isset($input['title'])) {
            $update_fields[] = "title = ?";
            $params[] = sanitizeInput($input['title']);
        }
        
        if (isset($input['description'])) {
            $update_fields[] = "description = ?";
            $params[] = sanitizeInput($input['description']);
        }
        
        if (isset($input['assigned_to_user_id'])) {
            $update_fields[] = "assigned_to_user_id = ?";
            $params[] = intval($input['assigned_to_user_id']);
        }
        
        if (isset($input['category'])) {
            $update_fields[] = "category = ?";
            $params[] = sanitizeInput($input['category']);
        }
        
        if (isset($input['metadata'])) {
            $update_fields[] = "metadata = ?";
            $params[] = json_encode($input['metadata']);
        }
        
        if (empty($update_fields)) {
            sendError('No fields to update', 400);
        }
        
        // Add ticket_id to params
        $params[] = $ticket_id;
        
        // Execute update
        $sql = "UPDATE tickets SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result) {
            // Add comment if provided
            if (isset($input['comment']) && !empty($input['comment'])) {
                $comment_stmt = $db->prepare("
                    INSERT INTO ticket_comments (ticket_id, comment, created_by)
                    VALUES (?, ?, ?)
                ");
                $comment_stmt->execute([
                    $ticket_id,
                    sanitizeInput($input['comment']),
                    isset($input['comment_by']) ? sanitizeInput($input['comment_by']) : 'System'
                ]);
            }
            
            // Retrieve updated ticket
            $stmt = $db->prepare("SELECT * FROM tickets WHERE id = ?");
            $stmt->execute([$ticket_id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ticket['metadata']) {
                $ticket['metadata'] = json_decode($ticket['metadata'], true);
            }
            
            logAPIRequest("/api/v1/tickets/{$ticket_id}", 'PUT', 200);
            sendResponse($ticket, 200, "Ticket updated successfully");
        } else {
            sendError('Failed to update ticket', 500);
        }
    } catch (Exception $e) {
        error_log("Exception in handlePutRequest: " . $e->getMessage() . " | " . $e->getTraceAsString());
        logAPIRequest("/api/v1/tickets", 'PUT', 500);
        sendError('Internal server error: ' . $e->getMessage(), 500);
    }
}
