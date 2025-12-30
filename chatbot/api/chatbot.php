<?php
// api/chatbot.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/chatbot_api_errors.log');

require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

session_start();
require_login();

header('Content-Type: application/json');

$current_user = get_logged_in_user();
$db = getDBConnection();

// Get or create session ID
$session_id = $_POST['session_id'] ?? $_COOKIE['chatbot_session'] ?? null;

$chatbot = new FinancialChatbot($db, $current_user['id'], $session_id);

// Set session cookie
if (!$session_id) {
    setcookie('chatbot_session', $chatbot->session_id, time() + (86400 * 30), "/");
}

$action = $_POST['action'] ?? 'message';

switch ($action) {
    case 'message':
        $message = trim($_POST['message'] ?? '');
        
        if (empty($message)) {
            echo json_encode(['error' => 'Message is required']);
            exit;
        }
        
        $response = $chatbot->processMessage($message);
        
        echo json_encode([
            'success' => true,
            'session_id' => $chatbot->session_id,
            'response' => $response['text'],
            'type' => $response['type'],
            'data' => $response['data'],
            'intent' => $response['intent'] ?? null,
            'entities' => $response['entities'] ?? []
        ]);
        break;
        
    case 'history':
        $history = $chatbot->getConversationHistory();
        echo json_encode(['success' => true, 'history' => $history]);
        break;
        
    case 'clear':
        // Clear conversation history
        $stmt = $db->prepare("DELETE FROM chatbot_messages WHERE session_id = ?");
        $stmt->execute([$chatbot->session_id]);
        
        echo json_encode(['success' => true, 'message' => 'Conversation cleared']);
        break;
        
    case 'suggestions':
        // Get suggested queries based on user role
        $suggestions = [
            "Create contract for John Mgini",
            "Get financial statement for December 2024",
            "Show client information for Sarah",
            "Search trades for NMB stock",
            "Calculate fees for 5,000,000 TZS",
            "Portfolio summary",
            "Create invoice for client",
            "Show recent trades"
        ];
        
        echo json_encode(['success' => true, 'suggestions' => $suggestions]);
        break;
        
    default:
        echo json_encode(['error' => 'Invalid action']);
}