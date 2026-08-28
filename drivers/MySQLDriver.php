<?php
/**
 * MySQL数据库驱动
 */
class MySQLDriver implements DatabaseDriverInterface {
    private $connection = null;
    private $config = [];
    private $lastInsertId = null;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    public function connect() {
        try {
            $dsn = "mysql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['name']};charset={$this->config['charset']}";
            $this->connection = new PDO($dsn, $this->config['username'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->config['charset']}"
            ]);
            return true;
        } catch (PDOException $e) {
            throw new Exception("MySQL connection failed: " . $e->getMessage());
        }
    }
    
    public function disconnect() {
        $this->connection = null;
    }
    
    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function execute($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        $this->lastInsertId = $this->connection->lastInsertId();
        return $stmt->rowCount();
    }
    
    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);
        $sql = "INSERT INTO {$table} (" . implode(', ', $fields) . ") VALUES ({$placeholders})";
        
        $stmt = $this->connection->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->execute();
        $this->lastInsertId = $this->connection->lastInsertId();
        return $this->lastInsertId;
    }
    
    public function update($table, $data, $where) {
        $set = [];
        foreach ($data as $key => $value) {
            $set[] = "{$key} = :{$key}";
        }
        $sql = "UPDATE {$table} SET " . implode(', ', $set);
        
        $whereClause = $this->buildWhereClause($where);
        $sql .= " WHERE " . $whereClause['sql'];
        
        $params = array_merge($data, $whereClause['params']);
        
        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }
    
    public function delete($table, $where) {
        $whereClause = $this->buildWhereClause($where);
        $sql = "DELETE FROM {$table} WHERE " . $whereClause['sql'];
        
        $stmt = $this->connection->prepare($sql);
        foreach ($whereClause['params'] as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }
    
    public function get($table, $where, $fields = '*') {
        $results = $this->select($table, $where, $fields, '', 1);
        return $results[0] ?? null;
    }
    
    public function select($table, $where = [], $fields = '*', $order = '', $limit = 0) {
        $sql = "SELECT {$fields} FROM {$table}";
        $params = [];
        
        if (!empty($where)) {
            $whereClause = $this->buildWhereClause($where);
            $sql .= " WHERE " . $whereClause['sql'];
            $params = $whereClause['params'];
        }
        
        if ($order) {
            $sql .= " ORDER BY {$order}";
        }
        
        if ($limit > 0) {
            $sql .= " LIMIT {$limit}";
        }
        
        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function count($table, $where = []) {
        $result = $this->get($table, $where, 'COUNT(*) as count');
        return (int)($result['count'] ?? 0);
    }
    
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollback() {
        return $this->connection->rollback();
    }
    
    public function lastInsertId() {
        return $this->lastInsertId;
    }
    
    private function buildWhereClause($where) {
        $sql = [];
        $params = [];
        $idx = 0;

        foreach ($where as $key => $value) {
            if (strpos($key, ' ') !== false) {
                // 复杂条件，如 "id > :id" → 提取字段名与操作符，生成唯一参数名
                if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*(>=|<=|!=|=|>|<)\s*(?::[a-zA-Z0-9_]+)?$/', trim($key), $m)) {
                    $field = $m[1];
                    $op = $m[2];
                    $paramName = 'w' . $idx . '_' . $field;
                    $sql[] = "{$field} {$op} :{$paramName}";
                    $params[$paramName] = $value;
                    $idx++;
                } else {
                    // 无法解析的条件，原样保留（调用方保证安全）
                    $sql[] = trim($key);
                }
            } else {
                $sql[] = "{$key} = :{$key}";
                $params[$key] = $value;
            }
        }

        return [
            'sql' => implode(' AND ', $sql),
            'params' => $params
        ];
    }
}