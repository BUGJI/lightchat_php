<?php
/**
 * 本地文件存储驱动
 * 适用于没有数据库的虚拟主机，将数据存储在JSON文件中
 */
class LocalDriver implements DatabaseDriverInterface {
    private $config = [];
    private $dataPath = '';
    private $tables = [];
    private $cache = [];
    private $cacheTime = [];
    private $cacheEnabled = true;
    private $cacheTtl = 300;
    private $inTransaction = false;
    private $transactionData = [];
    private $lastId = null;
    
    public function __construct($config) {
        $this->config = $config;
        $this->dataPath = rtrim($config['data_path'], '/') . '/';
        
        // 读取缓存配置
        if (isset($config['cache_enabled'])) {
            $this->cacheEnabled = (bool)$config['cache_enabled'];
        }
        if (isset($config['cache_ttl'])) {
            $this->cacheTtl = (int)$config['cache_ttl'];
        }
        
        // 确保数据目录存在
        if (!is_dir($this->dataPath)) {
            mkdir($this->dataPath, 0755, true);
        }
        
        $this->loadAllTables();
    }
    
    public function connect() {
        return true;
    }
    
    public function disconnect() {
        if (!$this->inTransaction) {
            $this->saveAllTables();
        }
    }
    
    /**
     * 加载所有表数据
     */
    private function loadAllTables() {
        $tables = ['users', 'sessions', 'channels', 'channel_members', 'messages', 'private_chats', 'private_messages', 'uploads', 'bans', 'audit_logs', 'bot_keys'];
        
        foreach ($tables as $table) {
            $this->loadTable($table);
        }
    }
    
    /**
     * 加载单个表
     */
    private function loadTable($table) {
        // 检查缓存
        if ($this->cacheEnabled && isset($this->cache[$table]) && isset($this->cacheTime[$table])) {
            if (time() - $this->cacheTime[$table] < $this->cacheTtl) {
                $this->tables[$table] = $this->cache[$table];
                return;
            }
        }
        
        $file = $this->dataPath . $table . '.json';
        
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            $this->tables[$table] = $data ?: ['data' => [], 'next_id' => 1];
        } else {
            $this->tables[$table] = ['data' => [], 'next_id' => 1];
        }
        
        // 写入缓存
        if ($this->cacheEnabled) {
            $this->cache[$table] = $this->tables[$table];
            $this->cacheTime[$table] = time();
        }
    }
    
    /**
     * 保存所有表数据
     */
    private function saveAllTables() {
        foreach ($this->tables as $table => $data) {
            $this->saveTable($table);
        }
    }
    
    /**
     * 保存单个表
     */
    private function saveTable($table) {
        if (!isset($this->tables[$table])) {
            return;
        }
        
        $file = $this->dataPath . $table . '.json';
        $content = json_encode($this->tables[$table], JSON_PRETTY_PRINT);
        file_put_contents($file, $content);
        
        // 更新缓存
        if ($this->cacheEnabled) {
            $this->cache[$table] = $this->tables[$table];
            $this->cacheTime[$table] = time();
        }
    }
    
    /**
     * 获取表数据引用
     */
    private function &getTable($table) {
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = ['data' => [], 'next_id' => 1];
        }
        return $this->tables[$table];
    }
    
    public function query($sql, $params = []) {
        // 简单解析SELECT语句
        if (preg_match('/SELECT \* FROM (\w+)(?: WHERE (.*))?/i', $sql, $matches)) {
            $table = $matches[1];
            $where = isset($matches[2]) ? $this->parseWhereClause($matches[2], $params) : [];
            return $this->select($table, $where);
        }
        throw new Exception("LocalDriver: Complex queries not supported, use select/get methods instead");
    }
    
    public function execute($sql, $params = []) {
        // 执行DDL语句（创建表）
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $sql, $matches)) {
            $table = $matches[1];
            if (!isset($this->tables[$table])) {
                $this->tables[$table] = ['data' => [], 'next_id' => 1];
                $this->saveTable($table);
            }
            return 0;
        }
        throw new Exception("LocalDriver: Execute only supports CREATE TABLE");
    }
    
    public function insert($table, $data) {
        $tableRef = &$this->getTable($table);
        
        $id = $tableRef['next_id']++;
        $data['id'] = $id;
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        
        $tableRef['data'][] = $data;
        
        if (!$this->inTransaction) {
            $this->saveTable($table);
        } else {
            $this->transactionData[$table] = true;
        }
        
        $this->lastId = $id;
        return $id;
    }
    
    public function update($table, $data, $where) {
        $tableRef = &$this->getTable($table);
        $updated = 0;
        
        foreach ($tableRef['data'] as &$row) {
            if ($this->matchesWhere($row, $where)) {
                foreach ($data as $key => $value) {
                    if ($key !== 'id') {
                        $row[$key] = $value;
                    }
                }
                $row['updated_at'] = date('Y-m-d H:i:s');
                $updated++;
            }
        }
        
        if (!$this->inTransaction && $updated > 0) {
            $this->saveTable($table);
        } elseif ($this->inTransaction) {
            $this->transactionData[$table] = true;
        }
        
        return $updated;
    }
    
    public function delete($table, $where) {
        $tableRef = &$this->getTable($table);
        $deleted = 0;
        $newData = [];
        
        foreach ($tableRef['data'] as $row) {
            if ($this->matchesWhere($row, $where)) {
                $deleted++;
            } else {
                $newData[] = $row;
            }
        }
        
        $tableRef['data'] = $newData;
        
        if (!$this->inTransaction && $deleted > 0) {
            $this->saveTable($table);
        } elseif ($this->inTransaction) {
            $this->transactionData[$table] = true;
        }
        
        return $deleted;
    }
    
    public function get($table, $where, $fields = '*') {
        $results = $this->select($table, $where, $fields, '', 1);
        return $results[0] ?? null;
    }
    
    public function select($table, $where = [], $fields = '*', $order = '', $limit = 0) {
        $tableRef = &$this->getTable($table);
        $results = [];
        
        foreach ($tableRef['data'] as $row) {
            if ($this->matchesWhere($row, $where)) {
                if ($fields !== '*') {
                    $selected = [];
                    $fieldList = explode(',', $fields);
                    foreach ($fieldList as $field) {
                        $field = trim($field);
                        $selected[$field] = $row[$field] ?? null;
                    }
                    $row = $selected;
                }
                $results[] = $row;
            }
        }
        
        // 排序
        if ($order) {
            $this->sortResults($results, $order);
        }
        
        // 限制数量
        if ($limit > 0) {
            $results = array_slice($results, 0, $limit);
        }
        
        return $results;
    }
    
    public function count($table, $where = []) {
        return count($this->select($table, $where));
    }
    
    public function beginTransaction() {
        $this->inTransaction = true;
        $this->transactionData = [];
        return true;
    }
    
    public function commit() {
        foreach ($this->transactionData as $table => $_) {
            $this->saveTable($table);
        }
        $this->inTransaction = false;
        $this->transactionData = [];
        return true;
    }
    
    public function rollback() {
        // 重新加载所有表，放弃更改
        $this->loadAllTables();
        $this->inTransaction = false;
        $this->transactionData = [];
        return true;
    }
    
    public function lastInsertId() {
        return $this->lastId;
    }
    
    /**
     * 检查行是否匹配条件
     */
    private function matchesWhere($row, $where) {
        if (empty($where)) {
            return true;
        }
        
        foreach ($where as $key => $value) {
            if (strpos($key, ' ') !== false) {
                // 解析复杂条件，如 "id > :id"
                if (preg_match('/(\w+)\s*(=|>|<|>=|<=|!=)\s*/', $key, $matches)) {
                    $field = $matches[1];
                    $operator = $matches[2];
                    $rowValue = $row[$field] ?? null;
                    
                    switch ($operator) {
                        case '=': if ($rowValue != $value) return false; break;
                        case '>': if ($rowValue <= $value) return false; break;
                        case '<': if ($rowValue >= $value) return false; break;
                        case '>=': if ($rowValue < $value) return false; break;
                        case '<=': if ($rowValue > $value) return false; break;
                        case '!=': if ($rowValue == $value) return false; break;
                    }
                }
            } else {
                if (($row[$key] ?? null) != $value) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * 解析WHERE子句
     */
    private function parseWhereClause($whereStr, $params) {
        $where = [];
        $conditions = explode(' AND ', $whereStr);
        
        foreach ($conditions as $condition) {
            if (preg_match('/(\w+)\s*=\s*:(\w+)/', $condition, $matches)) {
                $key = $matches[1];
                $paramKey = $matches[2];
                if (isset($params[':' . $paramKey])) {
                    $where[$key] = $params[':' . $paramKey];
                }
            }
        }
        
        return $where;
    }
    
    /**
     * 排序结果
     */
    private function sortResults(&$results, $order) {
        if (preg_match('/(\w+)\s+(ASC|DESC)/i', $order, $matches)) {
            $field = $matches[1];
            $direction = strtoupper($matches[2]) === 'ASC' ? SORT_ASC : SORT_DESC;
            
            usort($results, function($a, $b) use ($field, $direction) {
                $valA = $a[$field] ?? null;
                $valB = $b[$field] ?? null;
                
                if ($valA == $valB) return 0;
                
                $result = ($valA < $valB) ? -1 : 1;
                return $direction === SORT_ASC ? $result : -$result;
            });
        }
    }
}
