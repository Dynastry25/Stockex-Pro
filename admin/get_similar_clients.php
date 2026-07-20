<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_admin();

$client_id = (int)($_GET['client_id'] ?? 0);
$db = getDBConnection();

if ($client_id) {
    $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND is_active = 1");
    $stmt->execute([$client_id]);
    $primary_client = $stmt->fetch();
    
    if ($primary_client) {
        $stmt = $db->prepare("
            SELECT c.*, 
                   (SELECT COUNT(*) FROM trades WHERE client_cds_account = c.cds_account) as trade_count,
                   LEVENSHTEIN(?, c.client_name) as name_distance,
                   SOUNDEX(?) = SOUNDEX(c.client_name) as soundex_match
            FROM clients c 
            WHERE c.id != ? 
            AND c.is_active = 1 
            AND (
                LEVENSHTEIN(?, c.client_name) <= 3 OR
                SOUNDEX(?) = SOUNDEX(c.client_name) OR
                c.phone = ? OR
                c.email = ?
            )
            ORDER BY soundex_match DESC, name_distance ASC
            LIMIT 10
        ");
        
        $stmt->execute([
            $primary_client['client_name'],
            $primary_client['client_name'],
            $client_id,
            $primary_client['client_name'],
            $primary_client['client_name'],
            $primary_client['phone'] ?? '',
            $primary_client['email'] ?? ''
        ]);
        
        $similar_clients = $stmt->fetchAll();
        
        header('Content-Type: application/json');
        echo json_encode($similar_clients);
    } else {
        header('Content-Type: application/json');
        echo json_encode([]);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode([]);
}
?>
