<?php
/**
 * Sheets API Endpoint
 * - GET action=trades: return matched trades for dealing sheet
 * - GET action=orders: return placeholder orders for order sheet
 * - POST action=mark_popup_shown: mark daily popup as shown
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/financial_helpers.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$db = getDBConnection();
$user = get_logged_in_user();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    $action = $body['action'] ?? $action;
}

try {
    switch ($action) {
        case 'trades':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed');
            }

            $date = $_GET['date'] ?? null;
            $trades = getDealingSheetTrades($db, $date);
            $summary = getTradesSummary($db, $date);

            echo json_encode([
                'success' => true,
                'data' => [
                    'trades' => $trades,
                    'summary' => $summary,
                    'date' => $date ?: date('Y-m-d')
                ]
            ]);
            break;

        case 'orders':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed');
            }

            $orders = getOrdersPlaceholder($db);
            echo json_encode([
                'success' => true,
                'data' => [
                    'orders' => $orders,
                    'note' => 'Placeholder data: API integration pending'
                ]
            ]);
            break;

        case 'mark_popup_shown':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }

            $ok = markFirstDailyPopupShown($db, $user['id']);
            if (isset($_SESSION['show_popup'])) {
                unset($_SESSION['show_popup']);
            }

            echo json_encode([
                'success' => $ok,
                'message' => $ok ? 'Popup state updated' : 'Failed to update popup state'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action. Supported actions: trades, orders, mark_popup_shown'
            ]);
            break;
    }
} catch (Exception $e) {
    if ($e->getMessage() === 'Method not allowed') {
        http_response_code(405);
    } else {
        http_response_code(500);
    }

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
