<?php
/**
 * Dealing Sheet helper functions.
 *
 * This layer turns the dealing sheet into a real workflow:
 * - capture an internal client order
 * - execute it into a trade record
 * - check and approve it
 * - track contract-note and settlement readiness
 * - preserve an internal audit trail
 */

if (!function_exists('dealingSheetEnsureSchema')) {
    function dealingSheetEnsureSchema($db)
    {
        static $initialized = false;

        if ($initialized) {
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS dealing_sheets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sheet_reference VARCHAR(50) NOT NULL UNIQUE,
                trade_id INT NULL,
                trade_reference VARCHAR(100) NULL,
                lifecycle_stage VARCHAR(30) NOT NULL DEFAULT 'draft',
                approval_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                order_type VARCHAR(10) NOT NULL DEFAULT 'buy',
                asset_class VARCHAR(50) NOT NULL DEFAULT 'equity',
                security_id VARCHAR(100) NOT NULL,
                security_name VARCHAR(255) NULL,
                client_name VARCHAR(255) NOT NULL,
                client_cds_account VARCHAR(50) NULL,
                broker_code VARCHAR(50) NULL,
                broker_name VARCHAR(255) NULL,
                quantity BIGINT NOT NULL DEFAULT 0,
                order_price DECIMAL(20,6) NULL,
                order_value DECIMAL(20,2) NULL,
                order_date DATE NULL,
                order_time TIME NULL,
                executed_quantity BIGINT NULL,
                executed_price DECIMAL(20,6) NULL,
                executed_value DECIMAL(20,2) NULL,
                trade_date DATE NULL,
                settlement_date DATE NULL,
                execution_time TIME NULL,
                brokerage_fee DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                cmsa_fee DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                dse_fee DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                cds_fee DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                vrf_fee DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                vat_fee DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                total_charges DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                payment_method VARCHAR(50) NULL,
                payment_reference VARCHAR(100) NULL,
                payment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                contract_note_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                execution_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                priority VARCHAR(30) NOT NULL DEFAULT 'normal',
                checked_by INT NULL,
                checked_at DATETIME NULL,
                approved_by INT NULL,
                approved_at DATETIME NULL,
                dealer_name VARCHAR(255) NULL,
                dealer_signature VARCHAR(255) NULL,
                execution_notes TEXT NULL,
                approval_notes TEXT NULL,
                remarks TEXT NULL,
                created_by INT NULL,
                updated_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_dealing_sheet_stage (lifecycle_stage),
                INDEX idx_dealing_sheet_trade (trade_id),
                INDEX idx_dealing_sheet_trade_date (trade_date),
                INDEX idx_dealing_sheet_client (client_cds_account),
                INDEX idx_execution_status (execution_status),
                INDEX idx_priority (priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS dealing_sheet_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                dealing_sheet_id INT NOT NULL,
                action_type VARCHAR(50) NOT NULL,
                from_stage VARCHAR(30) NULL,
                to_stage VARCHAR(30) NULL,
                action_notes TEXT NULL,
                performed_by INT NULL,
                performed_by_name VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_dealing_sheet_history_sheet (dealing_sheet_id),
                INDEX idx_dealing_sheet_history_action (action_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $sheetColumns = [
            'trade_id' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS trade_id INT NULL AFTER sheet_reference",
            'trade_reference' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS trade_reference VARCHAR(100) NULL AFTER trade_id",
            'lifecycle_stage' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS lifecycle_stage VARCHAR(30) NOT NULL DEFAULT 'draft' AFTER trade_reference",
            'approval_status' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS approval_status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER lifecycle_stage",
            'payment_status' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS payment_status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER payment_reference",
            'contract_note_status' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS contract_note_status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER payment_status",
            'execution_status' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS execution_status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER contract_note_status",
            'priority' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS priority VARCHAR(30) NOT NULL DEFAULT 'normal' AFTER execution_status",
            'checked_by' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS checked_by INT NULL AFTER priority",
            'checked_at' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS checked_at DATETIME NULL AFTER checked_by",
            'approved_by' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS approved_by INT NULL AFTER checked_at",
            'approved_at' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER approved_by",
            'dealer_name' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS dealer_name VARCHAR(255) NULL AFTER approved_at",
            'dealer_signature' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS dealer_signature VARCHAR(255) NULL AFTER dealer_name",
            'execution_notes' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS execution_notes TEXT NULL AFTER dealer_signature",
            'approval_notes' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS approval_notes TEXT NULL AFTER execution_notes",
            'remarks' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS remarks TEXT NULL AFTER approval_notes",
            'created_by' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER remarks",
            'updated_by' => "ALTER TABLE dealing_sheets ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by",
        ];

        $existingSheetColumns = dealingSheetGetTableColumns($db, 'dealing_sheets');
        foreach ($sheetColumns as $column => $sql) {
            if (!isset($existingSheetColumns[$column])) {
                try {
                    $db->exec($sql);
                } catch (PDOException $e) {
                    error_log("Error adding column $column: " . $e->getMessage());
                }
            }
        }

        $historyColumns = [
            'from_stage' => "ALTER TABLE dealing_sheet_history ADD COLUMN IF NOT EXISTS from_stage VARCHAR(30) NULL AFTER action_type",
            'to_stage' => "ALTER TABLE dealing_sheet_history ADD COLUMN IF NOT EXISTS to_stage VARCHAR(30) NULL AFTER from_stage",
            'action_notes' => "ALTER TABLE dealing_sheet_history ADD COLUMN IF NOT EXISTS action_notes TEXT NULL AFTER to_stage",
            'performed_by' => "ALTER TABLE dealing_sheet_history ADD COLUMN IF NOT EXISTS performed_by INT NULL AFTER action_notes",
            'performed_by_name' => "ALTER TABLE dealing_sheet_history ADD COLUMN IF NOT EXISTS performed_by_name VARCHAR(255) NULL AFTER performed_by",
        ];

        $existingHistoryColumns = dealingSheetGetTableColumns($db, 'dealing_sheet_history');
        foreach ($historyColumns as $column => $sql) {
            if (!isset($existingHistoryColumns[$column])) {
                try {
                    $db->exec($sql);
                } catch (PDOException $e) {
                    error_log("Error adding column $column: " . $e->getMessage());
                }
            }
        }

        $initialized = true;
    }

    function dealingSheetGetTableColumns($db, $table, $refresh = false)
    {
        static $cache = [];

        if (!$refresh && isset($cache[$table])) {
            return $cache[$table];
        }

        try {
            $stmt = $db->prepare("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $columns = [];
        }

        $cache[$table] = [];
        foreach ($columns as $column) {
            $cache[$table][$column] = true;
        }

        return $cache[$table];
    }

    function dealingSheetTableExists($db, $table)
    {
        $columns = dealingSheetGetTableColumns($db, $table);
        return !empty($columns);
    }

    function dealingSheetGetCurrentUserDisplayName($user)
    {
        if (!empty($user['full_name'])) {
            return $user['full_name'];
        }

        if (!empty($user['username'])) {
            return $user['username'];
        }

        return 'System User';
    }

    function dealingSheetGenerateReference($db)
    {
        dealingSheetEnsureSchema($db);

        $datePrefix = date('Ymd');
        $prefix = 'DS' . $datePrefix;

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM dealing_sheets
            WHERE sheet_reference LIKE ?
        ");
        $stmt->execute([$prefix . '%']);
        $count = (int) $stmt->fetchColumn() + 1;

        return $prefix . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    function dealingSheetGenerateTradeReference($db)
    {
        $basePrefix = 'TRD' . date('Ymd');
        $tradeColumns = dealingSheetGetTableColumns($db, 'trades');

        if (!isset($tradeColumns['trade_reference'])) {
            return $basePrefix . strtoupper(substr(md5(uniqid('', true)), 0, 4));
        }

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $basePrefix . strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 4));
            $stmt = $db->prepare("SELECT COUNT(*) FROM trades WHERE trade_reference = ?");
            $stmt->execute([$candidate]);

            if ((int) $stmt->fetchColumn() === 0) {
                return $candidate;
            }
        }

        return $basePrefix . strtoupper(substr(md5(uniqid('', true)), 0, 6));
    }

    function dealingSheetGetCompany($db)
    {
        try {
            $stmt = $db->query("
                SELECT company_name, company_code, phone, email, address
                FROM companies
                WHERE status = 'active' OR is_active = 1
                ORDER BY id ASC
                LIMIT 1
            ");
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $company = false;
        }

        if (!$company) {
            return [
                'company_name' => 'Victory Financial Services Ltd',
                'company_code' => 'VFS001',
                'phone' => '',
                'email' => '',
                'address' => ''
            ];
        }

        return $company;
    }

    function dealingSheetGetPaymentMethods($db)
    {
        try {
            $stmt = $db->query("
                SELECT code, description
                FROM payment_methods
                WHERE status = 'active' OR is_active = 1
                ORDER BY priority ASC, id ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $rows = [];
        }

        if (empty($rows)) {
            $rows = [
                ['code' => 'BANK', 'description' => 'BANK'],
                ['code' => 'CASH', 'description' => 'CASH'],
                ['code' => 'CHEQUE', 'description' => 'CHEQUE'],
                ['code' => 'MOBILE', 'description' => 'MOBILE MONEY'],
            ];
        }

        return $rows;
    }

    function dealingSheetGetClients($db)
    {
        try {
            $stmt = $db->query("
                SELECT id, client_name, cds_account
                FROM clients
                WHERE (status = 'active' OR status IS NULL)
                  AND (is_active = 1 OR is_active IS NULL)
                  AND (merged_into IS NULL OR merged_into = 0)
                ORDER BY client_name ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    function dealingSheetGetSecurities($db)
    {
        $securities = [];

        try {
            $stmt = $db->query("
                SELECT security_id, COALESCE(stock_name, company_name, security_id) AS security_name, 'equity' AS asset_class
                FROM equities
                WHERE status = 'active' OR is_active = 1
                ORDER BY security_id ASC
            ");
            $securities = array_merge($securities, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            // Ignore and continue.
        }

        try {
            $stmt = $db->query("
                SELECT security_id, COALESCE(bond_name, issuer, security_id) AS security_name, 'bond' AS asset_class
                FROM bonds
                WHERE status = 'active' OR is_active = 1
                ORDER BY security_id ASC
            ");
            $securities = array_merge($securities, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            // Ignore and continue.
        }

        return $securities;
    }

    function dealingSheetGetTradeById($db, $tradeId)
    {
        $stmt = $db->prepare("SELECT * FROM trades WHERE id = ?");
        $stmt->execute([(int) $tradeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function dealingSheetFindByTradeId($db, $tradeId)
    {
        dealingSheetEnsureSchema($db);

        $stmt = $db->prepare("SELECT * FROM dealing_sheets WHERE trade_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int) $tradeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function dealingSheetBuildFromTrade($db, $tradeId, $user = [])
    {
        $trade = dealingSheetGetTradeById($db, $tradeId);
        if (!$trade) {
            return null;
        }

        $existing = dealingSheetFindByTradeId($db, $tradeId);
        if ($existing) {
            return $existing;
        }

        $fees = dealingSheetCalculateFees(
            $db,
            $trade['asset_class'] ?? 'equity',
            (float) ($trade['consideration'] ?? 0),
            (float) ($trade['quantity'] ?? 0),
            (float) ($trade['price'] ?? 0)
        );

        $userName = dealingSheetGetCurrentUserDisplayName($user);

        return [
            'id' => null,
            'sheet_reference' => '',
            'trade_id' => (int) $trade['id'],
            'trade_reference' => $trade['trade_reference'] ?? '',
            'lifecycle_stage' => dealingSheetResolveLifecycleStage([
                'trade_id' => $trade['id'],
                'contract_note_status' => 'pending',
                'payment_status' => (($trade['settlement_status'] ?? '') === 'paid' || ($trade['status'] ?? '') === 'settled') ? 'paid' : 'pending',
                'checked_at' => null,
                'approved_at' => null,
                'approval_status' => 'pending',
            ], $trade),
            'approval_status' => 'pending',
            'order_type' => $trade['trade_side'] ?? 'buy',
            'asset_class' => $trade['asset_class'] ?? 'equity',
            'security_id' => $trade['security_id'] ?? '',
            'security_name' => $trade['security_name'] ?? ($trade['security_id'] ?? ''),
            'client_name' => $trade['client_name'] ?? '',
            'client_cds_account' => $trade['client_cds_account'] ?? '',
            'broker_code' => $trade['sca_code'] ?? '',
            'broker_name' => $trade['broker_name'] ?? '',
            'quantity' => $trade['quantity'] ?? 0,
            'order_price' => $trade['price'] ?? 0,
            'order_value' => $trade['consideration'] ?? 0,
            'order_date' => $trade['trade_date'] ?? date('Y-m-d'),
            'order_time' => $trade['time_executed'] ?? '',
            'executed_quantity' => $trade['quantity'] ?? 0,
            'executed_price' => $trade['price'] ?? 0,
            'executed_value' => $trade['consideration'] ?? 0,
            'trade_date' => $trade['trade_date'] ?? date('Y-m-d'),
            'settlement_date' => $trade['settlement_date'] ?? date('Y-m-d', strtotime('+2 days')),
            'execution_time' => $trade['time_executed'] ?? '',
            'brokerage_fee' => $fees['brokerage'] ?? 0,
            'cmsa_fee' => $fees['cmsa'] ?? 0,
            'dse_fee' => $fees['dse'] ?? 0,
            'cds_fee' => $fees['csd'] ?? 0,
            'vrf_fee' => $fees['vrf'] ?? 0,
            'vat_fee' => $fees['vat'] ?? 0,
            'total_charges' => $fees['total'] ?? 0,
            'payment_method' => '',
            'payment_reference' => '',
            'payment_status' => (($trade['settlement_status'] ?? '') === 'paid' || ($trade['status'] ?? '') === 'settled') ? 'paid' : 'pending',
            'contract_note_status' => 'pending',
            'execution_status' => 'executed',
            'priority' => 'normal',
            'checked_by' => null,
            'checked_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'dealer_name' => $trade['broker_name'] ?? $userName,
            'dealer_signature' => '',
            'execution_notes' => '',
            'approval_notes' => '',
            'remarks' => 'Created from executed trade record.',
        ];
    }

    function dealingSheetCalculateFees($db, $assetClass, $consideration, $quantity, $price)
    {
        $assetClass = (string) $assetClass;
        $consideration = (float) $consideration;
        $quantity = (float) $quantity;
        $price = (float) $price;

        $fees = [
            'brokerage' => 0.00,
            'vat' => 0.00,
            'cmsa' => 0.00,
            'dse' => 0.00,
            'csd' => 0.00,
            'vrf' => 0.00,
            'total' => 0.00,
            'tier_details' => []
        ];

        $isBond = in_array($assetClass, ['bond', 'treasury_bond', 'corporate_bond'], true);

        if ($isBond) {
            $faceValue = $quantity;
            $fees['brokerage'] = min($faceValue, 100000000) * (0.063132 / 100);
            if ($faceValue > 100000000) {
                $fees['brokerage'] += ($faceValue - 100000000) * (0.035 / 100);
            }
            $fees['vat'] = $fees['brokerage'] * 0.18;
            $fees['cmsa'] = $consideration * (0.01 / 100);
            $fees['csd'] = $faceValue * (0.0118 / 100);
            $fees['dse'] = $faceValue * (0.02006 / 100);
        } else {
            $remaining = $consideration;
            $tier1 = min($remaining, 10000000);
            if ($tier1 > 0) {
                $fees['brokerage'] += $tier1 * (1.7 / 100);
                $remaining -= $tier1;
            }

            $tier2 = min(max($remaining, 0), 40000000);
            if ($tier2 > 0) {
                $fees['brokerage'] += $tier2 * (1.5 / 100);
                $remaining -= $tier2;
            }

            if ($remaining > 0) {
                $fees['brokerage'] += $remaining * (0.8 / 100);
            }

            $fees['vat'] = $fees['brokerage'] * 0.18;
            $fees['cmsa'] = $consideration * (0.1400 / 100);
            $fees['dse'] = $consideration * (0.1652 / 100);
            $fees['vrf'] = $consideration * (0.0200 / 100);
            $fees['csd'] = $consideration * (0.0708 / 100);
        }

        $fees['total'] = $fees['brokerage'] + $fees['vat'] + $fees['cmsa'] + $fees['dse'] + $fees['csd'] + $fees['vrf'];

        foreach ($fees as $key => $value) {
            if ($key !== 'tier_details') {
                $fees[$key] = round((float) $value, 2);
            }
        }

        return $fees;
    }

    function dealingSheetSanitizePayload($payload)
    {
        $clean = [];

        $stringFields = [
            'sheet_reference', 'trade_reference', 'lifecycle_stage', 'approval_status', 'order_type',
            'asset_class', 'security_id', 'security_name', 'client_name', 'client_cds_account',
            'broker_code', 'broker_name', 'payment_method', 'payment_reference', 'payment_status',
            'contract_note_status', 'dealer_name', 'dealer_signature', 'execution_status', 'priority'
        ];

        foreach ($stringFields as $field) {
            $clean[$field] = isset($payload[$field]) ? trim((string) $payload[$field]) : '';
        }

        $numericFields = [
            'id', 'trade_id', 'quantity', 'order_price', 'order_value', 'executed_quantity', 'executed_price',
            'executed_value', 'brokerage_fee', 'cmsa_fee', 'dse_fee', 'cds_fee', 'vrf_fee', 'vat_fee',
            'total_charges', 'checked_by', 'approved_by'
        ];

        foreach ($numericFields as $field) {
            $clean[$field] = isset($payload[$field]) && $payload[$field] !== '' ? (float) $payload[$field] : null;
        }

        if ($clean['id'] !== null) {
            $clean['id'] = (int) $clean['id'];
        }

        if ($clean['trade_id'] !== null) {
            $clean['trade_id'] = (int) $clean['trade_id'];
        }

        $dateFields = ['order_date', 'trade_date', 'settlement_date'];
        foreach ($dateFields as $field) {
            $value = isset($payload[$field]) ? trim((string) $payload[$field]) : '';
            $clean[$field] = dealingSheetValidateDate($value) ? $value : null;
        }

        $timeFields = ['order_time', 'execution_time'];
        foreach ($timeFields as $field) {
            $value = isset($payload[$field]) ? trim((string) $payload[$field]) : '';
            $clean[$field] = dealingSheetValidateTime($value) ? $value : null;
        }

        $textFields = ['execution_notes', 'approval_notes', 'remarks'];
        foreach ($textFields as $field) {
            $clean[$field] = isset($payload[$field]) ? trim((string) $payload[$field]) : '';
        }

        // Set default execution_status if not provided
        if (empty($clean['execution_status'])) {
            $clean['execution_status'] = 'pending';
        }

        // Set default priority if not provided
        if (empty($clean['priority'])) {
            $clean['priority'] = 'normal';
        }

        return $clean;
    }

    function dealingSheetValidateDate($value)
    {
        if ($value === '') {
            return false;
        }

        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    function dealingSheetValidateTime($value)
    {
        if ($value === '') {
            return false;
        }

        return (bool) preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value);
    }

    function dealingSheetPersist($db, $payload, $user, $forcedStage = null)
    {
        dealingSheetEnsureSchema($db);

        $clean = dealingSheetSanitizePayload($payload);
        $company = dealingSheetGetCompany($db);
        $existing = null;

        if (!empty($clean['id'])) {
            $existing = dealingSheetGetById($db, $clean['id']);
        }

        $nowStage = $forcedStage ?: ($existing['lifecycle_stage'] ?? $clean['lifecycle_stage'] ?: 'draft');
        $orderQuantity = (float) ($clean['quantity'] ?? 0);
        $orderPrice = (float) ($clean['order_price'] ?? 0);
        $executedQuantity = $clean['executed_quantity'] !== null ? (float) $clean['executed_quantity'] : 0;
        $executedPrice = $clean['executed_price'] !== null ? (float) $clean['executed_price'] : 0;

        $baseQuantity = $executedQuantity > 0 ? $executedQuantity : $orderQuantity;
        $basePrice = $executedPrice > 0 ? $executedPrice : $orderPrice;
        $baseConsideration = round($baseQuantity * $basePrice, 2);

        $fees = dealingSheetCalculateFees(
            $db,
            $clean['asset_class'] ?: ($existing['asset_class'] ?? 'equity'),
            $baseConsideration,
            $baseQuantity,
            $basePrice
        );

        $data = [
            'sheet_reference' => $existing['sheet_reference'] ?? ($clean['sheet_reference'] ?: dealingSheetGenerateReference($db)),
            'trade_id' => $clean['trade_id'] ?: ($existing['trade_id'] ?? null),
            'trade_reference' => $clean['trade_reference'] ?: ($existing['trade_reference'] ?? null),
            'lifecycle_stage' => $nowStage,
            'approval_status' => $clean['approval_status'] ?: ($existing['approval_status'] ?? 'pending'),
            'order_type' => strtolower($clean['order_type'] ?: ($existing['order_type'] ?? 'buy')),
            'asset_class' => $clean['asset_class'] ?: ($existing['asset_class'] ?? 'equity'),
            'security_id' => $clean['security_id'] ?: ($existing['security_id'] ?? ''),
            'security_name' => $clean['security_name'] ?: ($existing['security_name'] ?? $clean['security_id']),
            'client_name' => $clean['client_name'] ?: ($existing['client_name'] ?? ''),
            'client_cds_account' => $clean['client_cds_account'] ?: ($existing['client_cds_account'] ?? ''),
            'broker_code' => $clean['broker_code'] ?: ($existing['broker_code'] ?? ($company['company_code'] ?? '')),
            'broker_name' => $clean['broker_name'] ?: ($existing['broker_name'] ?? ($company['company_name'] ?? '')),
            'quantity' => (int) round($orderQuantity > 0 ? $orderQuantity : (float) ($existing['quantity'] ?? 0)),
            'order_price' => $orderPrice > 0 ? $orderPrice : ($existing['order_price'] ?? null),
            'order_value' => round(($orderQuantity > 0 ? $orderQuantity : (float) ($existing['quantity'] ?? 0)) * (float) ($orderPrice > 0 ? $orderPrice : ($existing['order_price'] ?? 0)), 2),
            'order_date' => $clean['order_date'] ?: ($existing['order_date'] ?? date('Y-m-d')),
            'order_time' => $clean['order_time'] ?: ($existing['order_time'] ?? date('H:i:s')),
            'executed_quantity' => $executedQuantity > 0 ? (int) round($executedQuantity) : ($existing['executed_quantity'] ?? null),
            'executed_price' => $executedPrice > 0 ? $executedPrice : ($existing['executed_price'] ?? null),
            'executed_value' => $executedQuantity > 0 && $executedPrice > 0
                ? round($executedQuantity * $executedPrice, 2)
                : ($existing['executed_value'] ?? null),
            'trade_date' => $clean['trade_date'] ?: ($existing['trade_date'] ?? null),
            'settlement_date' => $clean['settlement_date'] ?: ($existing['settlement_date'] ?? date('Y-m-d', strtotime('+2 days'))),
            'execution_time' => $clean['execution_time'] ?: ($existing['execution_time'] ?? null),
            'brokerage_fee' => $fees['brokerage'],
            'cmsa_fee' => $fees['cmsa'],
            'dse_fee' => $fees['dse'],
            'cds_fee' => $fees['csd'],
            'vrf_fee' => $fees['vrf'],
            'vat_fee' => $fees['vat'],
            'total_charges' => $fees['total'],
            'payment_method' => $clean['payment_method'] ?: ($existing['payment_method'] ?? ''),
            'payment_reference' => $clean['payment_reference'] ?: ($existing['payment_reference'] ?? ''),
            'payment_status' => $clean['payment_status'] ?: ($existing['payment_status'] ?? 'pending'),
            'contract_note_status' => $clean['contract_note_status'] ?: ($existing['contract_note_status'] ?? 'pending'),
            'execution_status' => $clean['execution_status'] ?: ($existing['execution_status'] ?? 'pending'),
            'priority' => $clean['priority'] ?: ($existing['priority'] ?? 'normal'),
            'checked_by' => $existing['checked_by'] ?? null,
            'checked_at' => $existing['checked_at'] ?? null,
            'approved_by' => $existing['approved_by'] ?? null,
            'approved_at' => $existing['approved_at'] ?? null,
            'dealer_name' => $clean['dealer_name'] ?: ($existing['dealer_name'] ?? dealingSheetGetCurrentUserDisplayName($user)),
            'dealer_signature' => $clean['dealer_signature'] ?: ($existing['dealer_signature'] ?? ''),
            'execution_notes' => $clean['execution_notes'] !== '' ? $clean['execution_notes'] : ($existing['execution_notes'] ?? ''),
            'approval_notes' => $clean['approval_notes'] !== '' ? $clean['approval_notes'] : ($existing['approval_notes'] ?? ''),
            'remarks' => $clean['remarks'] !== '' ? $clean['remarks'] : ($existing['remarks'] ?? ''),
            'created_by' => $existing['created_by'] ?? ($user['id'] ?? null),
            'updated_by' => $user['id'] ?? null,
        ];

        if ($existing) {
            $assignments = [];
            $values = [];
            foreach ($data as $column => $value) {
                $assignments[] = $column . ' = ?';
                $values[] = $value;
            }
            $values[] = $existing['id'];

            $stmt = $db->prepare("UPDATE dealing_sheets SET " . implode(', ', $assignments) . " WHERE id = ?");
            $stmt->execute($values);
            $sheetId = (int) $existing['id'];
        } else {
            $columns = array_keys($data);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $db->prepare("
                INSERT INTO dealing_sheets (" . implode(', ', $columns) . ")
                VALUES (" . $placeholders . ")
            ");
            $stmt->execute(array_values($data));
            $sheetId = (int) $db->lastInsertId();

            dealingSheetRecordHistory(
                $db,
                $sheetId,
                'created',
                null,
                $data['lifecycle_stage'],
                'Dealing sheet created.',
                $user
            );
        }

        return dealingSheetGetById($db, $sheetId);
    }

    function dealingSheetResolveLifecycleStage($sheet, $trade = null)
    {
        $stage = $sheet['lifecycle_stage'] ?? 'draft';
        $approvalStatus = $sheet['approval_status'] ?? 'pending';
        $paymentStatus = $sheet['payment_status'] ?? 'pending';
        $contractStatus = $sheet['contract_note_status'] ?? 'pending';

        if ($stage === 'cancelled') {
            return 'cancelled';
        }

        if ($approvalStatus === 'rejected') {
            return 'rejected';
        }

        if ($trade) {
            $tradeStatus = $trade['status'] ?? '';
            $tradeSettlementStatus = $trade['settlement_status'] ?? '';

            if ($tradeStatus === 'cancelled') {
                return 'cancelled';
            }

            if (in_array($tradeSettlementStatus, ['paid', 'linked'], true) || $tradeStatus === 'settled') {
                return 'settled';
            }
        }

        if (in_array($paymentStatus, ['paid', 'linked'], true)) {
            return 'settled';
        }

        if (in_array($contractStatus, ['generated', 'sent'], true)) {
            return 'contracted';
        }

        if ($approvalStatus === 'approved' || !empty($sheet['approved_at'])) {
            return 'approved';
        }

        if (!empty($sheet['checked_at'])) {
            return 'checked';
        }

        if (!empty($sheet['trade_id']) || !empty($sheet['executed_quantity']) || !empty($sheet['executed_price'])) {
            return 'executed';
        }

        if (!empty($sheet['client_name']) && !empty($sheet['security_id']) && !empty($sheet['quantity'])) {
            return 'order_recorded';
        }

        return 'draft';
    }

    function dealingSheetRecordHistory($db, $sheetId, $actionType, $fromStage, $toStage, $notes, $user)
    {
        dealingSheetEnsureSchema($db);

        $stmt = $db->prepare("
            INSERT INTO dealing_sheet_history (
                dealing_sheet_id,
                action_type,
                from_stage,
                to_stage,
                action_notes,
                performed_by,
                performed_by_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            (int) $sheetId,
            $actionType,
            $fromStage,
            $toStage,
            $notes,
            $user['id'] ?? null,
            dealingSheetGetCurrentUserDisplayName($user)
        ]);
    }

    function dealingSheetCreateOrUpdateTrade($db, $sheet, $user)
    {
        $tradeColumns = dealingSheetGetTableColumns($db, 'trades');
        if (empty($tradeColumns)) {
            throw new RuntimeException('Trades table is not available.');
        }

        $tradeReference = $sheet['trade_reference'];
        if ($tradeReference === null || $tradeReference === '') {
            $tradeReference = dealingSheetGenerateTradeReference($db);
        }

        $executedQuantity = (float) ($sheet['executed_quantity'] ?? 0);
        $executedPrice = (float) ($sheet['executed_price'] ?? 0);
        if ($executedQuantity <= 0 || $executedPrice <= 0) {
            throw new InvalidArgumentException('Execution details are required before syncing to trades.');
        }

        $company = dealingSheetGetCompany($db);
        $tradeData = [
            'trade_reference' => $tradeReference,
            'asset_class' => $sheet['asset_class'] ?? 'equity',
            'security_id' => $sheet['security_id'] ?? '',
            'security_name' => $sheet['security_name'] ?: ($sheet['security_id'] ?? ''),
            'client_cds_account' => $sheet['client_cds_account'] ?? '',
            'client_name' => $sheet['client_name'] ?? '',
            'capacity' => 'agency',
            'broker_name' => $sheet['broker_name'] ?: ($company['company_name'] ?? ''),
            'counterparty_broker' => $sheet['broker_code'] ?: ($company['company_code'] ?? ''),
            'counterparty_name' => $sheet['broker_name'] ?: 'Market Counterparty',
            'counterparty_cds_account' => '',
            'trade_side' => strtolower($sheet['order_type'] ?? 'buy'),
            'quantity' => (int) round($executedQuantity),
            'price' => $executedPrice,
            'rate' => $executedPrice,
            'consideration' => round($executedQuantity * $executedPrice, 2),
            'trade_date' => $sheet['trade_date'] ?: date('Y-m-d'),
            'settlement_date' => $sheet['settlement_date'] ?: date('Y-m-d', strtotime('+2 days')),
            'exchange_reference' => $tradeReference,
            'origin' => 'dealing_sheet',
            'time_executed' => $sheet['execution_time'] ?: date('H:i:s'),
            'currency' => 'TZS',
            'status' => 'active',
            'uploaded_by' => $user['id'] ?? null,
            'total_value' => round($executedQuantity * $executedPrice, 2),
            'custom_brokerage_fee' => null,
            'final_brokerage_fee' => $sheet['brokerage_fee'] ?? 0,
            'brokerage_fee_type' => 'normal',
            'sca_code' => $sheet['broker_code'] ?: ($company['company_code'] ?? ''),
            'settlement_status' => in_array(($sheet['payment_status'] ?? ''), ['paid', 'linked'], true) ? $sheet['payment_status'] : 'unpaid',
            'settled_by' => null,
            'settlement_notes' => 'Created from dealing sheet ' . ($sheet['sheet_reference'] ?? ''),
            'settled_at' => null,
            'failure_reason' => '',
            'action_needed' => '',
        ];

        $filteredData = [];
        foreach ($tradeData as $column => $value) {
            if (isset($tradeColumns[$column])) {
                $filteredData[$column] = $value;
            }
        }

        if (!isset($filteredData['trade_reference'])) {
            throw new RuntimeException('Trades table does not have a trade_reference column.');
        }

        $tradeId = !empty($sheet['trade_id']) ? (int) $sheet['trade_id'] : 0;
        if ($tradeId > 0) {
            $assignments = [];
            $values = [];
            foreach ($filteredData as $column => $value) {
                $assignments[] = $column . ' = ?';
                $values[] = $value;
            }
            $values[] = $tradeId;

            $stmt = $db->prepare("UPDATE trades SET " . implode(', ', $assignments) . " WHERE id = ?");
            $stmt->execute($values);
        } else {
            $columns = array_keys($filteredData);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $db->prepare("
                INSERT INTO trades (" . implode(', ', $columns) . ")
                VALUES (" . $placeholders . ")
            ");
            $stmt->execute(array_values($filteredData));
            $tradeId = (int) $db->lastInsertId();
        }

        return [
            'trade_id' => $tradeId,
            'trade_reference' => $tradeReference
        ];
    }

    function dealingSheetTransition($db, $sheetId, $action, $payload, $user)
    {
        dealingSheetEnsureSchema($db);

        $sheet = dealingSheetGetById($db, $sheetId);
        if (!$sheet) {
            throw new RuntimeException('Dealing sheet not found.');
        }

        $fromStage = $sheet['lifecycle_stage'];
        $updates = [];
        $notes = '';

        switch ($action) {
            case 'save_draft':
                $updates['lifecycle_stage'] = 'draft';
                $updates['execution_status'] = 'pending';
                $notes = 'Draft saved.';
                break;

            case 'record_order':
                if (empty($sheet['client_name']) || empty($sheet['security_id']) || empty($sheet['quantity'])) {
                    throw new InvalidArgumentException('Client, security, and quantity are required to record an order.');
                }
                $updates['lifecycle_stage'] = 'order_recorded';
                $notes = 'Client order recorded on dealing sheet.';
                break;

            case 'execute':
                if (empty($sheet['executed_quantity']) || empty($sheet['executed_price'])) {
                    throw new InvalidArgumentException('Executed quantity and executed price are required before execution.');
                }
                $tradeSync = dealingSheetCreateOrUpdateTrade($db, $sheet, $user);
                $updates['trade_id'] = $tradeSync['trade_id'];
                $updates['trade_reference'] = $tradeSync['trade_reference'];
                $updates['trade_date'] = $sheet['trade_date'] ?: date('Y-m-d');
                $updates['execution_time'] = $sheet['execution_time'] ?: date('H:i:s');
                $updates['lifecycle_stage'] = 'executed';
                $updates['execution_status'] = 'executed';
                $notes = 'Execution captured and synchronized to trades.';
                break;

            case 'mark_checked':
                if (empty($sheet['trade_id']) && (empty($sheet['executed_quantity']) || empty($sheet['executed_price']))) {
                    throw new InvalidArgumentException('The sheet must be executed before it can be checked.');
                }
                $updates['checked_by'] = $user['id'] ?? null;
                $updates['checked_at'] = date('Y-m-d H:i:s');
                $updates['lifecycle_stage'] = 'checked';
                $notes = 'Operations check completed.';
                break;

            case 'approve':
                if (empty($sheet['trade_id'])) {
                    throw new InvalidArgumentException('Execute the dealing sheet first so it links to a trade before approval.');
                }
                $updates['approved_by'] = $user['id'] ?? null;
                $updates['approved_at'] = date('Y-m-d H:i:s');
                $updates['approval_status'] = 'approved';
                $updates['lifecycle_stage'] = 'approved';
                $notes = 'Dealing sheet approved.';
                break;

            case 'reject':
                $updates['approval_status'] = 'rejected';
                $updates['approved_by'] = $user['id'] ?? null;
                $updates['approved_at'] = date('Y-m-d H:i:s');
                $updates['lifecycle_stage'] = 'rejected';
                $notes = 'Dealing sheet rejected.';
                break;

            case 'mark_contract_generated':
                if (empty($sheet['trade_id'])) {
                    throw new InvalidArgumentException('A linked trade is required before generating a contract note.');
                }
                $updates['contract_note_status'] = 'generated';
                $updates['lifecycle_stage'] = 'contracted';
                $notes = 'Contract note generated.';
                break;

            case 'mark_contract_sent':
                if (empty($sheet['trade_id'])) {
                    throw new InvalidArgumentException('A linked trade is required before sending a contract note.');
                }
                $updates['contract_note_status'] = 'sent';
                $updates['lifecycle_stage'] = in_array(($sheet['payment_status'] ?? ''), ['paid', 'linked'], true) ? 'settled' : 'contracted';
                $notes = 'Contract note marked as sent.';
                break;

            case 'mark_paid':
                $updates['payment_status'] = 'paid';
                $updates['lifecycle_stage'] = 'settled';
                if (!empty($sheet['trade_id'])) {
                    $tradeColumns = dealingSheetGetTableColumns($db, 'trades');
                    $tradeUpdates = [];

                    if (isset($tradeColumns['settlement_status'])) {
                        $tradeUpdates['settlement_status'] = 'paid';
                    }
                    if (isset($tradeColumns['settled_by'])) {
                        $tradeUpdates['settled_by'] = $user['id'] ?? null;
                    }
                    if (isset($tradeColumns['settled_at'])) {
                        $tradeUpdates['settled_at'] = date('Y-m-d H:i:s');
                    }

                    if (!empty($tradeUpdates)) {
                        $assignments = [];
                        $values = [];
                        foreach ($tradeUpdates as $column => $value) {
                            $assignments[] = $column . ' = ?';
                            $values[] = $value;
                        }
                        $values[] = (int) $sheet['trade_id'];

                        $stmt = $db->prepare("UPDATE trades SET " . implode(', ', $assignments) . " WHERE id = ?");
                        $stmt->execute($values);
                    }
                }
                $notes = 'Payment marked as received/processed.';
                break;

            case 'cancel':
                $updates['lifecycle_stage'] = 'cancelled';
                $updates['execution_status'] = 'cancelled';
                $notes = 'Dealing sheet cancelled.';
                break;

            case 'sync_trade':
                $trade = !empty($sheet['trade_id']) ? dealingSheetGetTradeById($db, $sheet['trade_id']) : null;
                if (!$trade) {
                    throw new InvalidArgumentException('There is no linked trade to synchronize.');
                }

                $updates['trade_reference'] = $trade['trade_reference'] ?? $sheet['trade_reference'];
                $updates['executed_quantity'] = $trade['quantity'] ?? $sheet['executed_quantity'];
                $updates['executed_price'] = $trade['price'] ?? $sheet['executed_price'];
                $updates['executed_value'] = $trade['consideration'] ?? $sheet['executed_value'];
                $updates['trade_date'] = $trade['trade_date'] ?? $sheet['trade_date'];
                $updates['settlement_date'] = $trade['settlement_date'] ?? $sheet['settlement_date'];
                $updates['execution_time'] = $trade['time_executed'] ?? $sheet['execution_time'];
                $updates['execution_status'] = 'executed';

                if (($trade['status'] ?? '') === 'cancelled') {
                    $updates['lifecycle_stage'] = 'cancelled';
                    $updates['execution_status'] = 'cancelled';
                } else {
                    $updates['lifecycle_stage'] = dealingSheetResolveLifecycleStage($sheet, $trade);
                }

                if (in_array(($trade['settlement_status'] ?? ''), ['paid', 'linked'], true) || ($trade['status'] ?? '') === 'settled') {
                    $updates['payment_status'] = ($trade['settlement_status'] ?? '') === 'linked' ? 'linked' : 'paid';
                } elseif (($trade['settlement_status'] ?? '') === 'failed') {
                    $updates['payment_status'] = 'failed';
                }

                $notes = 'Dealing sheet synchronized from linked trade.';
                break;

            default:
                return $sheet;
        }

        if (!empty($updates)) {
            $updates['updated_by'] = $user['id'] ?? null;
            $assignments = [];
            $values = [];
            foreach ($updates as $column => $value) {
                $assignments[] = $column . ' = ?';
                $values[] = $value;
            }
            $values[] = (int) $sheetId;

            $stmt = $db->prepare("UPDATE dealing_sheets SET " . implode(', ', $assignments) . " WHERE id = ?");
            $stmt->execute($values);
        }

        $updated = dealingSheetGetById($db, $sheetId);
        $resolvedStage = dealingSheetResolveLifecycleStage($updated, !empty($updated['trade_id']) ? dealingSheetGetTradeById($db, $updated['trade_id']) : null);

        if ($resolvedStage !== $updated['lifecycle_stage']) {
            $stmt = $db->prepare("UPDATE dealing_sheets SET lifecycle_stage = ? WHERE id = ?");
            $stmt->execute([$resolvedStage, $sheetId]);
            $updated = dealingSheetGetById($db, $sheetId);
        }

        dealingSheetRecordHistory(
            $db,
            $sheetId,
            $action,
            $fromStage,
            $updated['lifecycle_stage'],
            $notes,
            $user
        );

        return $updated;
    }

    function dealingSheetGetById($db, $sheetId)
    {
        dealingSheetEnsureSchema($db);

        $stmt = $db->prepare("
            SELECT ds.*,
                   t.status AS trade_status,
                   t.settlement_status AS trade_settlement_status,
                   t.consideration AS trade_consideration,
                   cu.full_name AS created_by_name,
                   uu.full_name AS updated_by_name,
                   chk.full_name AS checked_by_name,
                   ap.full_name AS approved_by_name
            FROM dealing_sheets ds
            LEFT JOIN trades t ON ds.trade_id = t.id
            LEFT JOIN users cu ON ds.created_by = cu.id
            LEFT JOIN users uu ON ds.updated_by = uu.id
            LEFT JOIN users chk ON ds.checked_by = chk.id
            LEFT JOIN users ap ON ds.approved_by = ap.id
            WHERE ds.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int) $sheetId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Ensure execution_status has a default value
        if ($result && !isset($result['execution_status'])) {
            $result['execution_status'] = 'pending';
        }
        
        return $result ?: null;
    }

    function dealingSheetGetHistory($db, $sheetId)
    {
        dealingSheetEnsureSchema($db);

        $stmt = $db->prepare("
            SELECT *
            FROM dealing_sheet_history
            WHERE dealing_sheet_id = ?
            ORDER BY id DESC
        ");
        $stmt->execute([(int) $sheetId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    function dealingSheetSyncTradeLifecycle($db, $tradeId, $user = [], $createIfMissing = false)
    {
        dealingSheetEnsureSchema($db);

        $tradeId = (int) $tradeId;
        if ($tradeId <= 0) {
            return null;
        }

        $sheet = dealingSheetFindByTradeId($db, $tradeId);
        if (!$sheet && $createIfMissing) {
            $seed = dealingSheetBuildFromTrade($db, $tradeId, $user);
            if ($seed) {
                $forcedStage = $seed['lifecycle_stage'] ?? null;
                $sheet = dealingSheetPersist($db, $seed, $user, $forcedStage);
                dealingSheetRecordHistory(
                    $db,
                    $sheet['id'],
                    'import_trade',
                    null,
                    $sheet['lifecycle_stage'],
                    'Dealing sheet created from existing trade.',
                    $user
                );
            }
        }

        if (!$sheet) {
            return null;
        }

        return dealingSheetTransition($db, $sheet['id'], 'sync_trade', [], $user);
    }

    function dealingSheetMarkContractGeneratedForTrade($db, $tradeId, $user = [], $createIfMissing = false)
    {
        dealingSheetEnsureSchema($db);

        $tradeId = (int) $tradeId;
        if ($tradeId <= 0) {
            return null;
        }

        $sheet = dealingSheetFindByTradeId($db, $tradeId);
        if (!$sheet && $createIfMissing) {
            $sheet = dealingSheetSyncTradeLifecycle($db, $tradeId, $user, true);
        }

        if (!$sheet) {
            return null;
        }

        if (in_array(($sheet['contract_note_status'] ?? ''), ['generated', 'sent'], true)) {
            return dealingSheetGetById($db, $sheet['id']) ?: $sheet;
        }

        return dealingSheetTransition($db, $sheet['id'], 'mark_contract_generated', [], $user);
    }

    function dealingSheetBuildListQuery($filters = [])
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $where[] = "(ds.sheet_reference LIKE ? OR ds.trade_reference LIKE ? OR ds.client_name LIKE ? OR ds.client_cds_account LIKE ? OR ds.security_id LIKE ? OR ds.security_name LIKE ?)";
            array_push($params, $search, $search, $search, $search, $search, $search);
        }

        if (!empty($filters['asset_class']) && $filters['asset_class'] !== 'all') {
            $where[] = "ds.asset_class = ?";
            $params[] = $filters['asset_class'];
        }

        if (!empty($filters['stage']) && $filters['stage'] !== 'all') {
            $where[] = "ds.lifecycle_stage = ?";
            $params[] = $filters['stage'];
        }

        if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
            $where[] = "ds.payment_status = ?";
            $params[] = $filters['payment_status'];
        }

        if (!empty($filters['date_from']) && dealingSheetValidateDate($filters['date_from'])) {
            $where[] = "COALESCE(ds.trade_date, ds.order_date, DATE(ds.created_at)) >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to']) && dealingSheetValidateDate($filters['date_to'])) {
            $where[] = "COALESCE(ds.trade_date, ds.order_date, DATE(ds.created_at)) <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['view']) && $filters['view'] !== 'all') {
            switch ($filters['view']) {
                case 'orders':
                    $where[] = "ds.lifecycle_stage IN ('draft', 'order_recorded')";
                    break;
                case 'execution':
                    $where[] = "ds.lifecycle_stage IN ('executed', 'checked')";
                    break;
                case 'approved':
                    $where[] = "ds.lifecycle_stage IN ('approved', 'contracted')";
                    break;
                case 'settled':
                    $where[] = "ds.lifecycle_stage = 'settled'";
                    break;
            }
        }

        $sql = "
            SELECT ds.*,
                   t.status AS trade_status,
                   t.settlement_status AS trade_settlement_status,
                   t.consideration AS trade_consideration,
                   chk.full_name AS checked_by_name,
                   ap.full_name AS approved_by_name
            FROM dealing_sheets ds
            LEFT JOIN trades t ON ds.trade_id = t.id
            LEFT JOIN users chk ON ds.checked_by = chk.id
            LEFT JOIN users ap ON ds.approved_by = ap.id
        ";

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY COALESCE(ds.trade_date, ds.order_date, DATE(ds.created_at)) DESC, ds.id DESC";

        return [$sql, $params];
    }

    function dealingSheetList($db, $filters = [])
    {
        dealingSheetEnsureSchema($db);

        list($sql, $params) = dealingSheetBuildListQuery($filters);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    function dealingSheetOverview($db)
    {
        dealingSheetEnsureSchema($db);

        $rows = dealingSheetList($db, []);
        $overview = [
            'total' => 0,
            'orders' => 0,
            'execution' => 0,
            'approved' => 0,
            'settled' => 0,
            'total_executed_value' => 0.00,
        ];

        foreach ($rows as $row) {
            $overview['total']++;
            $overview['total_executed_value'] += (float) ($row['executed_value'] ?: $row['order_value'] ?: 0);

            if (in_array($row['lifecycle_stage'], ['draft', 'order_recorded'], true)) {
                $overview['orders']++;
            } elseif (in_array($row['lifecycle_stage'], ['executed', 'checked'], true)) {
                $overview['execution']++;
            } elseif (in_array($row['lifecycle_stage'], ['approved', 'contracted'], true)) {
                $overview['approved']++;
            } elseif ($row['lifecycle_stage'] === 'settled') {
                $overview['settled']++;
            }
        }

        $overview['total_executed_value'] = round($overview['total_executed_value'], 2);

        return $overview;
    }

    function dealingSheetPrintableRecord($db, $sheetId)
    {
        $sheet = dealingSheetGetById($db, $sheetId);
        if (!$sheet) {
            return null;
        }

        $company = dealingSheetGetCompany($db);
        $sheet['company_name'] = $company['company_name'];
        $sheet['company_code'] = $company['company_code'];
        return $sheet;
    }

    function dealingSheetStageBadgeClass($stage)
    {
        switch ($stage) {
            case 'draft':
                return 'secondary';
            case 'order_recorded':
                return 'primary';
            case 'executed':
                return 'info';
            case 'checked':
                return 'warning';
            case 'approved':
                return 'success';
            case 'contracted':
                return 'dark';
            case 'settled':
                return 'success';
            case 'rejected':
                return 'danger';
            case 'cancelled':
                return 'danger';
            default:
                return 'secondary';
        }
    }

    function getDealingSheetTrades($db, $date = null, $filters = [])
    {
        dealingSheetEnsureSchema($db);

        $sheetFilters = array_merge(['view' => 'all'], $filters);
        $rows = dealingSheetList($db, $sheetFilters);

        $trades = [];
        foreach ($rows as $row) {
            if (empty($row['trade_id']) && (empty($row['executed_quantity']) || empty($row['executed_price']))) {
                continue;
            }

            $tradeDate = $row['trade_date'] ?: $row['order_date'];
            if ($date && $tradeDate !== $date) {
                continue;
            }

            $quantity = (float) ($row['executed_quantity'] ?: $row['quantity'] ?: 0);
            $price = (float) ($row['executed_price'] ?: $row['order_price'] ?: 0);
            $value = (float) ($row['executed_value'] ?: $row['order_value'] ?: ($quantity * $price));

            $trades[] = [
                'id' => $row['id'],
                'trade_reference' => $row['trade_reference'] ?: $row['sheet_reference'],
                'trade_type' => $row['asset_class'],
                'security_id' => $row['security_id'],
                'security_name' => $row['security_name'] ?: $row['security_id'],
                'quantity' => $quantity,
                'price' => $price,
                'total_value' => $value,
                'trade_side' => strtolower($row['order_type']),
                'trade_date' => $tradeDate ?: date('Y-m-d'),
                'buyer_name' => strtolower($row['order_type']) === 'buy' ? $row['client_name'] : ($row['broker_name'] ?: 'Market'),
                'seller_name' => strtolower($row['order_type']) === 'sell' ? $row['client_name'] : ($row['broker_name'] ?: 'Market'),
                'status' => $row['lifecycle_stage'],
            ];
        }

        if (!empty($trades)) {
            return $trades;
        }

        $tradeColumns = dealingSheetGetTableColumns($db, 'trades');
        if (empty($tradeColumns)) {
            return [];
        }

        $where = [];
        $params = [];
        if ($date) {
            $where[] = "trade_date = ?";
            $params[] = $date;
        }
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $where[] = "asset_class = ?";
            $params[] = $filters['type'];
        }

        $sql = "
            SELECT id,
                   trade_reference,
                   asset_class AS trade_type,
                   security_id,
                   COALESCE(security_name, security_id) AS security_name,
                   quantity,
                   price,
                   COALESCE(total_value, consideration, quantity * price) AS total_value,
                   trade_side,
                   trade_date,
                   client_name AS buyer_name,
                   counterparty_name AS seller_name,
                   status
            FROM trades
        ";

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY trade_date DESC, id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    function getTradesSummary($db, $date = null)
    {
        $trades = getDealingSheetTrades($db, $date);
        $summary = [
            'count' => 0,
            'total_value' => 0.00,
            'total_quantity' => 0,
        ];

        foreach ($trades as $trade) {
            $summary['count']++;
            $summary['total_value'] += (float) ($trade['total_value'] ?? 0);
            $summary['total_quantity'] += (int) round((float) ($trade['quantity'] ?? 0));
        }

        $summary['total_value'] = round($summary['total_value'], 2);
        return $summary;
    }

    function getOrdersPlaceholder($db)
    {
        dealingSheetEnsureSchema($db);
        $rows = dealingSheetList($db, ['view' => 'orders']);

        $orders = [];
        foreach ($rows as $row) {
            $orders[] = [
                'order_id' => $row['sheet_reference'],
                'client_name' => $row['client_name'],
                'security' => $row['security_id'],
                'quantity' => (float) ($row['quantity'] ?? 0),
                'price' => (float) ($row['order_price'] ?? 0),
                'status' => !empty($row['trade_id']) ? 'matched' : 'unmatched',
                'order_date' => trim(($row['order_date'] ?: date('Y-m-d')) . ' ' . ($row['order_time'] ?: '')),
            ];
        }

        return $orders;
    }
}