<?php
/**
 * SQLite数据库驱动
 * 适用于轻量级部署
 */
class SQLiteDriver implements DatabaseDriverInterface {
    private $connection = null;
    private $config = [];
    private $lastInsertId = null;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    public function connect() {
        try {
            // 确保目录存在
            $dir = dirname($this->config['path']);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $this->connection = new PDO("sqlite:{$this->config['path']}");
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // 设置PRAGMA
            if (isset($this->config['journal_mode'])) {
                $this->connection->exec("PRAGMA journal_mode = {$this->config['journal_mode']}");
            }
            if (isset($this->config['synchronous'])) {
                $this->connection->exec("PRAGMA synchronous = {$this->config['synchronous']}");
            }
            if (isset($this->config['cache_size'])) {
                $this->connection->exec("PRAGMA cache_size = {$this->config['cache_size']}");
            }
            
            return true;
        } catch (PDOException $e) {
            throw new Exception("SQLite connection failed: " . $e->getMessage());
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
        
        foreach ($where as $key => $value) {
            if (strpos($key, ' ') !== false) {
                $sql[] = $key;
                $paramKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
                $params[$paramKey] = $value;
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