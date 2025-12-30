<?php
// chatbot_engine.php
require_once '../config/config.php';

class FinancialChatbot {
    private $db;
    private $session_id;
    private $user_id;
    
    public function __construct($db, $user_id, $session_id = null) {
        $this->db = $db;
        $this->user_id = $user_id;
        $this->session_id = $session_id ?: $this->generateSessionId();
        $this->initializeSession();
    }
    
    private function generateSessionId() {
        return 'SESS_' . time() . '_' . bin2hex(random_bytes(8));
    }
    
    private function initializeSession() {
        $stmt = $this->db->prepare("
            INSERT INTO chatbot_sessions (session_id, user_id) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE last_activity = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$this->session_id, $this->user_id]);
    }
    
    public function processMessage($user_message) {
        // Save user message
        $this->saveMessage('user', $user_message);
        
        // Extract entities from message
        $entities = $this->extractEntities($user_message);
        
        // Match intent
        $intent = $this->matchIntent($user_message);
        
        // Get response
        $response = $this->generateResponse($intent, $entities);
        
        // Save bot response
        $this->saveMessage('bot', $response['text'], $intent['intent_key']);
        
        // Update session activity
        $this->updateSessionActivity();
        
        return $response;
    }
    
    private function extractEntities($message) {
        $entities = [];
        
        // Extract client names (from database)
        $stmt = $this->db->prepare("
            SELECT DISTINCT client_name FROM trades 
            WHERE client_name LIKE ? 
            UNION 
            SELECT DISTINCT name as client_name FROM clients 
            WHERE name LIKE ?
            LIMIT 5
        ");
        $stmt->execute(['%' . $message . '%', '%' . $message . '%']);
        $clients = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($clients as $client) {
            if (stripos($message, $client) !== false) {
                $entities['client'] = $client;
                break;
            }
        }
        
        // Extract dates
        $date_patterns = [
            'today' => date('Y-m-d'),
            'yesterday' => date('Y-m-d', strtotime('-1 day')),
            'tomorrow' => date('Y-m-d', strtotime('+1 day')),
            'this week' => date('Y-m-d', strtotime('monday this week')) . ' to ' . date('Y-m-d', strtotime('sunday this week')),
            'last week' => date('Y-m-d', strtotime('monday last week')) . ' to ' . date('Y-m-d', strtotime('sunday last week')),
            'this month' => date('Y-m-01') . ' to ' . date('Y-m-t'),
            'last month' => date('Y-m-01', strtotime('-1 month')) . ' to ' . date('Y-m-t', strtotime('-1 month'))
        ];
        
        foreach ($date_patterns as $pattern => $date_value) {
            if (stripos($message, $pattern) !== false) {
                $entities['date'] = $date_value;
                break;
            }
        }
        
        // Extract months
        $months = [
            'january', 'february', 'march', 'april', 'may', 'june',
            'july', 'august', 'september', 'october', 'november', 'december'
        ];
        
        foreach ($months as $month) {
            if (stripos($message, $month) !== false) {
                $entities['month'] = $month;
                $entities['year'] = date('Y'); // Default to current year
                
                // Try to extract year
                if (preg_match('/(20\d{2})/', $message, $matches)) {
                    $entities['year'] = $matches[1];
                }
                break;
            }
        }
        
        // Extract amounts
        if (preg_match('/TZS\s*([\d,\.]+)/i', $message, $matches)) {
            $entities['amount'] = (float) str_replace(',', '', $matches[1]);
        } elseif (preg_match('/USD\s*([\d,\.]+)/i', $message, $matches)) {
            $entities['amount'] = (float) str_replace(',', '', $matches[1]);
            $entities['currency'] = 'USD';
        } elseif (preg_match('/\$\s*([\d,\.]+)/', $message, $matches)) {
            $entities['amount'] = (float) str_replace(',', '', $matches[1]);
            $entities['currency'] = 'USD';
        }
        
        // Extract security symbols
        $stmt = $this->db->prepare("SELECT DISTINCT security_id FROM equities_settings");
        $stmt->execute();
        $securities = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($securities as $security) {
            if (stripos($message, $security) !== false) {
                $entities['security'] = $security;
                break;
            }
        }
        
        return $entities;
    }
    
    private function matchIntent($message) {
        $message_lower = strtolower(trim($message));
        
        // Get all patterns with their intents
        $stmt = $this->db->prepare("
            SELECT i.intent_key, i.intent_name, p.pattern, p.priority
            FROM chatbot_patterns p
            JOIN chatbot_intents i ON p.intent_id = i.id
            WHERE i.is_active = 1
            ORDER BY p.priority DESC
        ");
        $stmt->execute();
        $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $best_match = ['intent_key' => 'unknown', 'confidence' => 0];
        
        foreach ($patterns as $pattern) {
            $pattern_text = strtolower($pattern['pattern']);
            
            // Replace entity placeholders with regex
            $pattern_regex = str_replace(
                ['{client}', '{period}', '{amount}', '{security}', '{date}'],
                ['([a-z\s]+)', '([a-z\s\d]+)', '([\d,\.]+)', '([a-z0-9]+)', '([a-z\s\d\-]+)'],
                $pattern_text
            );
            
            // Convert to regex pattern
            $pattern_regex = '/^.*' . str_replace('.*', '.*', $pattern_regex) . '.*$/i';
            
            if (preg_match($pattern_regex, $message_lower)) {
                $confidence = $pattern['priority'] / 10.0;
                if ($confidence > $best_match['confidence']) {
                    $best_match = [
                        'intent_key' => $pattern['intent_key'],
                        'intent_name' => $pattern['intent_name'],
                        'confidence' => $confidence
                    ];
                }
            }
        }
        
        // If no pattern matches, check for keywords
        if ($best_match['intent_key'] === 'unknown') {
            $keywords = [
                'contract' => 'create_contract',
                'statement' => 'financial_statement',
                'client' => 'client_info',
                'trade' => 'trade_search',
                'portfolio' => 'portfolio_summary',
                'fee' => 'calculate_fees',
                'invoice' => 'create_invoice',
                'hello' => 'greeting',
                'hi' => 'greeting',
                'help' => 'help'
            ];
            
            foreach ($keywords as $keyword => $intent) {
                if (stripos($message_lower, $keyword) !== false) {
                    $best_match = [
                        'intent_key' => $intent,
                        'intent_name' => ucfirst(str_replace('_', ' ', $intent)),
                        'confidence' => 0.5
                    ];
                    break;
                }
            }
        }
        
        return $best_match;
    }
    
    private function generateResponse($intent, $entities) {
        // Get a random response for this intent
        $stmt = $this->db->prepare("
            SELECT response_template, response_type, action_endpoint 
            FROM chatbot_responses r
            JOIN chatbot_intents i ON r.intent_id = i.id
            WHERE i.intent_key = ?
            ORDER BY RAND()
            LIMIT 1
        ");
        $stmt->execute([$intent['intent_key']]);
        $response_template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$response_template) {
            return [
                'text' => "I understand you want to " . str_replace('_', ' ', $intent['intent_key']) . ". How can I help with that?",
                'type' => 'text',
                'data' => null
            ];
        }
        
        // Replace placeholders in template
        $response_text = $response_template['response_template'];
        foreach ($entities as $key => $value) {
            $response_text = str_replace('{' . $key . '}', $value, $response_text);
        }
        
        // Get actual data based on intent
        $data = $this->getIntentData($intent['intent_key'], $entities);
        
        return [
            'text' => $response_text,
            'type' => $response_template['response_type'],
            'data' => $data,
            'intent' => $intent,
            'entities' => $entities
        ];
    }
    
    private function getIntentData($intent_key, $entities) {
        switch ($intent_key) {
            case 'financial_statement':
                return $this->getFinancialStatementData($entities);
                
            case 'client_info':
                return $this->getClientInfoData($entities);
                
            case 'trade_search':
                return $this->getTradeData($entities);
                
            case 'portfolio_summary':
                return $this->getPortfolioData();
                
            case 'calculate_fees':
                return $this->calculateFeesData($entities);
                
            case 'create_contract':
                return $this->prepareContractData($entities);
                
            default:
                return null;
        }
    }
    
    private function getFinancialStatementData($entities) {
        $period = $entities['period'] ?? $entities['month'] ?? date('F Y');
        $year = $entities['year'] ?? date('Y');
        $month = $entities['month'] ?? date('F');
        
        // Get actual data from database
        $stmt = $this->db->prepare("
            SELECT 
                'Revenue' as category,
                COALESCE(SUM(
                    CASE WHEN gl.credit_amount > 0 AND coa.account_code LIKE '4%' 
                    THEN gl.credit_amount ELSE 0 END
                ), 0) as amount
            FROM general_ledger gl
            JOIN chart_of_accounts coa ON gl.account_id = coa.id
            WHERE DATE_FORMAT(gl.transaction_date, '%Y-%m') = ?
            
            UNION ALL
            
            SELECT 
                'Expenses' as category,
                COALESCE(SUM(
                    CASE WHEN gl.debit_amount > 0 AND coa.account_code LIKE '5%' 
                    THEN gl.debit_amount ELSE 0 END
                ), 0) as amount
            FROM general_ledger gl
            JOIN chart_of_accounts coa ON gl.account_id = coa.id
            WHERE DATE_FORMAT(gl.transaction_date, '%Y-%m') = ?
            
            UNION ALL
            
            SELECT 
                'Assets' as category,
                COALESCE(SUM(
                    CASE WHEN coa.account_code LIKE '1%' 
                    THEN (gl.debit_amount - gl.credit_amount) ELSE 0 END
                ), 0) as amount
            FROM general_ledger gl
            JOIN chart_of_accounts coa ON gl.account_id = coa.id
            WHERE DATE(gl.transaction_date) <= LAST_DAY(?)
            
            UNION ALL
            
            SELECT 
                'Liabilities' as category,
                COALESCE(SUM(
                    CASE WHEN coa.account_code LIKE '2%' 
                    THEN (gl.credit_amount - gl.debit_amount) ELSE 0 END
                ), 0) as amount
            FROM general_ledger gl
            JOIN chart_of_accounts coa ON gl.account_id = coa.id
            WHERE DATE(gl.transaction_date) <= LAST_DAY(?)
        ");
        
        $month_date = date('Y-m', strtotime($month . ' ' . $year));
        $stmt->execute([$month_date, $month_date, $month_date . '-01', $month_date . '-01']);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = ['period' => $period];
        foreach ($results as $row) {
            $data[strtolower($row['category'])] = (float)$row['amount'];
        }
        
        // Calculate net income
        $data['net_income'] = $data['revenue'] - $data['expenses'];
        $data['equity'] = $data['assets'] - $data['liabilities'];
        
        return $data;
    }
    
    private function getClientInfoData($entities) {
        $client_name = $entities['client'] ?? '';
        
        $stmt = $this->db->prepare("
            SELECT 
                c.name,
                c.email,
                c.phone,
                c.address,
                c.client_since,
                COUNT(DISTINCT t.id) as total_trades,
                COALESCE(SUM(t.consideration), 0) as total_volume,
                COUNT(DISTINCT con.id) as active_contracts
            FROM clients c
            LEFT JOIN trades t ON c.name = t.client_name AND t.status = 'active'
            LEFT JOIN contracts con ON c.id = con.client_id AND con.status = 'active'
            WHERE c.name LIKE ?
            GROUP BY c.id
            LIMIT 1
        ");
        
        $stmt->execute(['%' . $client_name . '%']);
        $client_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$client_data) {
            // Try from trades table
            $stmt = $this->db->prepare("
                SELECT 
                    client_name as name,
                    COUNT(*) as total_trades,
                    SUM(consideration) as total_volume,
                    MIN(trade_date) as first_trade_date
                FROM trades 
                WHERE client_name LIKE ?
                GROUP BY client_name
                LIMIT 1
            ");
            $stmt->execute(['%' . $client_name . '%']);
            $client_data = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        return $client_data ?: ['name' => $client_name, 'error' => 'Client not found'];
    }
    
    private function getTradeData($entities) {
        $client_name = $entities['client'] ?? '';
        $security = $entities['security'] ?? '';
        $date = $entities['date'] ?? date('Y-m-d');
        
        $query = "SELECT * FROM trades WHERE 1=1";
        $params = [];
        
        if ($client_name) {
            $query .= " AND client_name LIKE ?";
            $params[] = '%' . $client_name . '%';
        }
        
        if ($security) {
            $query .= " AND security_id = ?";
            $params[] = $security;
        }
        
        // Handle date ranges
        if (strpos($date, ' to ') !== false) {
            list($date_from, $date_to) = explode(' to ', $date);
            $query .= " AND trade_date BETWEEN ? AND ?";
            $params[] = $date_from;
            $params[] = $date_to;
        } else {
            $query .= " AND trade_date >= ?";
            $params[] = date('Y-m-d', strtotime($date . ' -30 days'));
        }
        
        $query .= " ORDER BY trade_date DESC LIMIT 10";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getPortfolioData() {
        // Get company portfolio summary
        $stmt = $this->db->prepare("
            SELECT 
                'Equity Investments' as category,
                COALESCE(SUM(
                    CASE WHEN coa.account_code = '1253' 
                    THEN (gl.debit_amount - gl.credit_amount) ELSE 0 END
                ), 0) as value
            FROM general_ledger gl
            JOIN chart_of_accounts coa ON gl.account_id = coa.id
            WHERE coa.account_code = '1253'
            
            UNION ALL
            
            SELECT 
                'Cash & Equivalents' as category,
                COALESCE(SUM(
                    CASE WHEN coa.account_code LIKE '111%' 
                    THEN (gl.debit_amount - gl.credit_amount) ELSE 0 END
                ), 0) as value
            FROM general_ledger gl
            JOIN chart_of_accounts coa ON gl.account_id = coa.id
            WHERE coa.account_code LIKE '111%'
            
            UNION ALL
            
            SELECT 
                'Receivables' as category,
                COALESCE(SUM(
                    CASE WHEN coa.account_code LIKE '12%' AND coa.account_code != '1253'
                    THEN (gl.debit_amount - gl.credit_amount) ELSE 0 END
                ), 0) as value
            FROM general_ledger gl
            JOIN chart_of_accounts coa ON gl.account_id = coa.id
            WHERE coa.account_code LIKE '12%' AND coa.account_code != '1253'
        ");
        
        $stmt->execute();
        $portfolio = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total = 0;
        foreach ($portfolio as &$item) {
            $item['value'] = (float)$item['value'];
            $total += $item['value'];
        }
        
        return [
            'items' => $portfolio,
            'total' => $total,
            'as_of' => date('Y-m-d')
        ];
    }
    
    private function calculateFeesData($entities) {
        $amount = $entities['amount'] ?? 1000000;
        $currency = $entities['currency'] ?? 'TZS';
        
        // Use the same fee calculation logic from your upload script
        $tier1_rate = 1.7;
        $tier2_rate = 1.5;
        $tier3_rate = 0.8;
        
        if ($amount <= 10000000) {
            $brokerage = $amount * ($tier1_rate / 100);
        } elseif ($amount <= 40000000) {
            $brokerage = $amount * ($tier2_rate / 100);
        } else {
            $brokerage = $amount * ($tier3_rate / 100);
        }
        
        $vat = $brokerage * 0.18;
        $cmsa = $amount * (0.01 / 100);
        $csd = $amount * (0.0118 / 100);
        $dse = $amount * (0.02006 / 100);
        $vrf = $amount * (0.0025 / 100);
        
        return [
            'amount' => $amount,
            'currency' => $currency,
            'brokerage' => $brokerage,
            'vat' => $vat,
            'cmsa' => $cmsa,
            'csd' => $csd,
            'dse' => $dse,
            'vrf' => $vrf,
            'total_fees' => $brokerage + $vat + $cmsa + $csd + $dse + $vrf
        ];
    }
    
    private function prepareContractData($entities) {
        $client_name = $entities['client'] ?? 'New Client';
        
        return [
            'client_name' => $client_name,
            'contract_type' => 'Investment Advisory',
            'amount' => $entities['amount'] ?? 0,
            'duration' => '12 months',
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+1 year')),
            'status' => 'draft'
        ];
    }
    
    private function saveMessage($type, $content, $intent = null) {
        $stmt = $this->db->prepare("
            INSERT INTO chatbot_messages (session_id, message_type, content, intent_matched)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->session_id, $type, $content, $intent]);
    }
    
    private function updateSessionActivity() {
        $stmt = $this->db->prepare("
            UPDATE chatbot_sessions 
            SET last_activity = CURRENT_TIMESTAMP 
            WHERE session_id = ?
        ");
        $stmt->execute([$this->session_id]);
    }
    
    public function getConversationHistory($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT message_type, content, intent_matched, created_at
            FROM chatbot_messages
            WHERE session_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$this->session_id, $limit]);
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}