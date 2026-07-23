<?php
/**
 * API v1 - User Context Endpoint
 * 
 * Returns complete user context including client details in one API call
 * This eliminates the need for multiple queries (users → clients → data)
 * 
 * Usage:
 * GET /api/v1/user_context.php?user={identifier}
 * 
 * Identifier can be:
 * - Username
 * - User ID
 * - CDS Account number
 * - Client name (partial match)
 */

require_once __DIR__ . '/../config.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed. Only GET requests are supported.', 405);
}

try {
    // Get database connection
    $db = getDBConnection();
    
    if (!$db) {
        sendError('Database connection failed', 500);
    }
    
    // Check if user parameter is provided
    if (!isset($_GET['user'])) {
        sendError('Missing required parameter: user', 400, [
            'examples' => [
                '/api/v1/user_context.php?user=696126',
                '/api/v1/user_context.php?user=MEHJABEEN',
                '/api/v1/user_context.php?user=123'
            ]
        ]);
    }
    
    $identifier = sanitizeInput($_GET['user']);
    $response = null;
    
    // Strategy 1: Look up in users table by username or ID
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :identifier OR id = :id LIMIT 1");
    $stmt->bindValue(':identifier', $identifier);
    $stmt->bindValue(':id', intval($identifier), PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch();
    
    if ($user) {
        // User found in users table
        $response = [
            'user_id' => (int)$user['id'],
            'username' => $user['username'],
            'email' => $user['email'] ?? null,
            'role' => $user['role'] ?? null,
            'client_id' => $user['client_id'] ?? null,
            'full_name' => $user['full_name'] ?? null
        ];
        
        // Get client details if client_id exists
        if ($user['client_id']) {
            $stmt = $db->prepare("SELECT * FROM clients WHERE id = :client_id LIMIT 1");
            $stmt->bindValue(':client_id', $user['client_id'], PDO::PARAM_INT);
            $stmt->execute();
            $client = $stmt->fetch();
            
            if ($client) {
                $response['client_name'] = $client['client_name'];
                $response['cds_account'] = $client['cds_account'];
                $response['client_type'] = $client['client_type'] ?? null;
                $response['account_status'] = $client['status'] ?? null;
                $response['client_data'] = $client;
            }
        }
    } else {
        // Strategy 2: Look up directly in clients table
        // Try CDS account, ID, or name match
        $stmt = $db->prepare("
            SELECT * FROM clients 
            WHERE cds_account = :identifier 
               OR id = :id 
               OR client_name LIKE :name_pattern 
            LIMIT 1
        ");
        $stmt->bindValue(':identifier', $identifier);
        $stmt->bindValue(':id', intval($identifier), PDO::PARAM_INT);
        $stmt->bindValue(':name_pattern', '%' . $identifier . '%');
        $stmt->execute();
        $client = $stmt->fetch();
        
        if ($client) {
            // Client found
            $response = [
                'user_id' => null,
                'username' => null,
                'client_id' => (int)$client['id'],
                'client_name' => $client['client_name'],
                'cds_account' => $client['cds_account'],
                'client_type' => $client['client_type'] ?? null,
                'account_status' => $client['status'] ?? null,
                'client_data' => $client
            ];
            
            // Try to find associated user account
            $stmt = $db->prepare("SELECT * FROM users WHERE client_id = :client_id LIMIT 1");
            $stmt->bindValue(':client_id', $client['id'], PDO::PARAM_INT);
            $stmt->execute();
            $linkedUser = $stmt->fetch();
            
            if ($linkedUser) {
                $response['user_id'] = (int)$linkedUser['id'];
                $response['username'] = $linkedUser['username'];
                $response['email'] = $linkedUser['email'] ?? null;
                $response['role'] = $linkedUser['role'] ?? null;
            }
        } else {
            // Not found in either table
            logAPIRequest('/api/v1/user_context.php', 'GET', 404);
            sendError('User or client not found', 404, [
                'searched_for' => $identifier,
                'hint' => 'Try using CDS account number, username, or client ID'
            ]);
        }
    }
    
    // Add quick stats if we have a CDS account
    if (isset($response['cds_account']) && $response['cds_account']) {
        $cdsAccount = $response['cds_account'];
        
        // Count trades
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM trades WHERE client_cds_account = :cds");
        $stmt->bindValue(':cds', $cdsAccount);
        $stmt->execute();
        $tradeCount = $stmt->fetch();
        
        $response['stats'] = [
            'total_trades' => (int)$tradeCount['count']
        ];
        
        // Get recent trade if exists
        $stmt = $db->prepare("
            SELECT trade_date, trade_side, company_symbol, quantity, consideration 
            FROM trades 
            WHERE client_cds_account = :cds 
            ORDER BY trade_date DESC 
            LIMIT 1
        ");
        $stmt->bindValue(':cds', $cdsAccount);
        $stmt->execute();
        $recentTrade = $stmt->fetch();
        
        if ($recentTrade) {
            $response['stats']['last_trade_date'] = $recentTrade['trade_date'];
            $response['stats']['last_trade'] = $recentTrade;
        }
    }
    
    logAPIRequest('/api/v1/user_context.php', 'GET', 200);
    sendResponse($response, 200, 'User context retrieved successfully');
    
} catch (Exception $e) {
    logAPIRequest('/api/v1/user_context.php', 'GET', 500);
    sendError('Internal server error', 500, ['error' => $e->getMessage()]);
}
?>
