<?php
/**
 * Base Query Builder for API
 */

class QueryBuilder {
    private $conn;
    private $table;
    private $query;
    private $params = [];
    
    public function __construct($db, $tableName) {
        $this->conn = $db;
        $this->table = $tableName;
    }
    
    /**
     * Get all records with optional filters, search, pagination and sorting
     */
    public function getAll($queryParams) {
        try {
            // Get total count for pagination
            $countQuery = "SELECT COUNT(*) as total FROM `{$this->table}`";
            $whereClause = $this->buildWhereClause($queryParams);
            
            if ($whereClause) {
                $countQuery .= " WHERE " . $whereClause;
            }
            
            $stmt = $this->conn->prepare($countQuery);
            $this->bindParams($stmt);
            $stmt->execute();
            $totalRecords = $stmt->fetch()['total'];
            
            // Build main query
            $this->query = "SELECT * FROM `{$this->table}`";
            
            if ($whereClause) {
                $this->query .= " WHERE " . $whereClause;
            }
            
            // Add sorting
            $sortBy = $this->sanitizeColumn($queryParams['sort_by']);
            $sortOrder = $queryParams['sort_order'];
            $this->query .= " ORDER BY `{$sortBy}` {$sortOrder}";
            
            // Add pagination
            $this->query .= " LIMIT :limit OFFSET :offset";
            
            $stmt = $this->conn->prepare($this->query);
            $this->bindParams($stmt);
            $stmt->bindValue(':limit', (int)$queryParams['limit'], PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$queryParams['offset'], PDO::PARAM_INT);
            $stmt->execute();
            
            $records = $stmt->fetchAll();
            
            return [
                'records' => $records,
                'pagination' => [
                    'total_records' => (int)$totalRecords,
                    'current_page' => (int)$queryParams['page'],
                    'page_size' => (int)$queryParams['limit'],
                    'total_pages' => ceil($totalRecords / $queryParams['limit'])
                ]
            ];
            
        } catch (PDOException $e) {
            throw new Exception("Database error: " . $e->getMessage());
        }
    }
    
    /**
     * Get single record by ID
     */
    public function getById($id) {
        try {
            $this->query = "SELECT * FROM `{$this->table}` WHERE `id` = :id LIMIT 1";
            $stmt = $this->conn->prepare($this->query);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (PDOException $e) {
            throw new Exception("Database error: " . $e->getMessage());
        }
    }
    
    /**
     * Get table columns
     */
    public function getColumns() {
        try {
            $query = "SHOW COLUMNS FROM `{$this->table}`";
            $stmt = $this->conn->query($query);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            throw new Exception("Database error: " . $e->getMessage());
        }
    }
    
    /**
     * Build WHERE clause from filters and search
     */
    private function buildWhereClause($queryParams) {
        $conditions = [];
        $this->params = [];
        
        // Add search condition
        if (!empty($queryParams['search'])) {
            $searchConditions = [];
            $columns = $this->getColumns();
            
            foreach ($columns as $column) {
                $colName = $column['Field'];
                $searchConditions[] = "`{$colName}` LIKE :search";
            }
            
            if (!empty($searchConditions)) {
                $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
                $this->params['search'] = '%' . $queryParams['search'] . '%';
            }
        }
        
        // Add filter conditions
        if (!empty($queryParams['filters'])) {
            $columns = array_column($this->getColumns(), 'Field');
            
            foreach ($queryParams['filters'] as $key => $value) {
                if (in_array($key, $columns)) {
                    $conditions[] = "`{$key}` = :{$key}";
                    $this->params[$key] = $value;
                }
            }
        }
        
        return implode(' AND ', $conditions);
    }
    
    /**
     * Bind parameters to statement
     */
    private function bindParams($stmt) {
        foreach ($this->params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
    }
    
    /**
     * Sanitize column name
     */
    private function sanitizeColumn($column) {
        // Get valid columns for this table
        $columns = array_column($this->getColumns(), 'Field');
        
        // If column exists, return it, otherwise return 'id' as default
        return in_array($column, $columns) ? $column : 'id';
    }
}
?>
