<?php
/**
 * 本地文件存储驱动（优化版）
 * 适用于没有数据库的虚拟主机，将数据存储在 JSON 文件中
 * 
 * 优化特性：
 * - 文件锁机制防止并发写入冲突
 * - 原子写入（临时文件 + rename）防止数据损坏
 * - 智能缓存减少文件 I/O
 * - PHP 7.4+ 类型声明支持
 */
class LocalDriver implements DatabaseDriverInterface {
    private array $config = [];
    private string $dataPath = '';
    private array $tables = [];
    private array $cache = [];
    private array $cacheTime = [];
    private bool $cacheEnabled = true;
    private int $cacheTtl = 300;
    private bool $inTransaction = false;
    private array $transactionData = [];
    private $lastId = null;
    private array $fileLocks = [];
    private const LOCK_EXTENSION = '.lock';
    
    public function __construct(array $config) {
        $this->config = $config;
        $this->dataPath = rtrim($config['data_path'], '/') . '/';
        
        // 读取缓存配置
        $this->cacheEnabled = $config['cache_enabled'] ?? true;
        $this->cacheTtl = (int)($config['cache_ttl'] ?? 300);
        
        // 确保数据目录存在
        if (!is_dir($this->dataPath)) {
            mkdir($this->dataPath, 0755, true);
        }
        
        $this->loadAllTables();
    }
    
    public function connect(): bool {
        return true;
    }
    
    public function disconnect(): void {
        if (!$this->inTransaction) {
            $this->saveAllTables();
        }
        // 释放所有文件锁
        foreach ($this->fileLocks as $table => $fp) {
            $this->releaseLock($table);
        }
    }
    
    /**
     * 加载所有表数据
     */
    private function loadAllTables(): void {
        $tables = ['users', 'sessions', 'channels', 'channel_members', 'messages', 
                   'private_chats', 'private_messages', 'uploads', 'bans', 
                   'audit_logs', 'bot_keys', 'user_relations'];
        
        foreach ($tables as $table) {
            $this->loadTable($table);
        }
    }
    
    /**
     * 加载单个表（带文件锁保护）
     */
    private function loadTable(string $table): void {
        // 检查缓存是否有效
        if ($this->cacheEnabled && isset($this->cache[$table], $this->cacheTime[$table])) {
            if (time() - $this->cacheTime[$table] < $this->cacheTtl) {
                $this->tables[$table] = $this->cache[$table];
                return;
            }
        }
        
        $file = $this->dataPath . $table . '.json';
        
        // 使用共享锁读取文件
        $this->acquireLock($table, LOCK_SH);
        try {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                $data = json_decode($content, true);
                $this->tables[$table] = is_array($data) ? $data : ['data' => [], 'next_id' => 1];
            } else {
                $this->tables[$table] = ['data' => [], 'next_id' => 1];
            }
        } finally {
            $this->releaseLock($table);
        }
        
        // 更新缓存
        if ($this->cacheEnabled) {
            $this->cache[$table] = $this->tables[$table];
            $this->cacheTime[$table] = time();
        }
    }
    
    /**
     * 保存所有表数据
     */
    private function saveAllTables(): void {
        foreach ($this->tables as $table => $data) {
            $this->saveTable($table);
        }
    }
    
    /**
     * 保存单个表（原子写入：临时文件 + rename）
     */
    private function saveTable(string $table): void {
        if (!isset($this->tables[$table])) {
            return;
        }
        
        $file = $this->dataPath . $table . '.json';
        
        // 使用排他锁写入文件
        $this->acquireLock($table, LOCK_EX);
        try {
            $content = json_encode($this->tables[$table], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            
            // 原子写入：先写临时文件，再 rename
            $tmpFile = $file . '.tmp.' . getmypid();
            $result = file_put_contents($tmpFile, $content, LOCK_EX);
            
            if ($result === false) {
                throw new Exception("Failed to write temporary file for table: {$table}");
            }
            
            // 确保临时文件写入成功
            if (!rename($tmpFile, $file)) {
                @unlink($tmpFile);
                throw new Exception("Failed to rename temporary file for table: {$table}");
            }
            
            // 设置合适的文件权限
            @chmod($file, 0644);
            
        } finally {
            $this->releaseLock($table);
        }
        
        // 更新缓存
        if ($this->cacheEnabled) {
            $this->cache[$table] = $this->tables[$table];
            $this->cacheTime[$table] = time();
        }
    }
    
    /**
     * 获取表数据引用
     */
    private function &getTable(string $table): array {
        if (!isset($this->tables[$table])) {
            $this->loadTable($table);
        }
        return $this->tables[$table];
    }
    
    /**
     * 获取文件锁
     */
    private function acquireLock(string $table, int $operation): void {
        if (isset($this->fileLocks[$table])) {
            return; // 已经持有锁
        }
        
        $lockFile = $this->dataPath . $table . self::LOCK_EXTENSION;
        $fp = fopen($lockFile, 'c+');
        
        if ($fp === false) {
            throw new Exception("Cannot open lock file for table: {$table}");
        }
        
        // 获取锁（阻塞模式）
        flock($fp, $operation);
        $this->fileLocks[$table] = $fp;
    }
    
    /**
     * 释放文件锁
     */
    private function releaseLock(string $table): void {
        if (isset($this->fileLocks[$table])) {
            flock($this->fileLocks[$table], LOCK_UN);
            fclose($this->fileLocks[$table]);
            unset($this->fileLocks[$table]);
        }
    }
    
    public function query(string $sql, array $params = []): array {
        // 简单解析 SELECT 语句
        if (preg_match('/SELECT \* FROM (\w+)(?: WHERE (.*))?/i', $sql, $matches)) {
            $table = $matches[1];
            $where = isset($matches[2]) ? $this->parseWhereClause($matches[2], $params) : [];
            return $this->select($table, $where);
        }
        throw new Exception("LocalDriver: Complex queries not supported, use select/get methods instead");
    }
    
    public function execute(string $sql, array $params = []): int {
        // 执行 DDL 语句（创建表）
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
    
    public function insert(string $table, array $data) {
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
    
    public function update(string $table, array $data, array $where): int {
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
        unset($row); // 断开引用
        
        if (!$this->inTransaction && $updated > 0) {
            $this->saveTable($table);
        } elseif ($this->inTransaction) {
            $this->transactionData[$table] = true;
        }
        
        return $updated;
    }
    
    public function delete(string $table, array $where): int {
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
    
    public function get(string $table, array $where, string $fields = '*'): ?array {
        $results = $this->select($table, $where, $fields, '', 1);
        return $results[0] ?? null;
    }
    
    public function select(string $table, array $where = [], string $fields = '*', string $order = '', int $limit = 0): array {
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
    
    public function count(string $table, array $where = []): int {
        return count($this->select($table, $where));
    }
    
    public function beginTransaction(): bool {
        $this->inTransaction = true;
        $this->transactionData = [];
        return true;
    }
    
    public function commit(): bool {
        foreach ($this->transactionData as $table => $_) {
            $this->saveTable($table);
        }
        $this->inTransaction = false;
        $this->transactionData = [];
        return true;
    }
    
    public function rollback(): bool {
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
    private function matchesWhere(array $row, array $where): bool {
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
     * 解析 WHERE 子句
     */
    private function parseWhereClause(string $whereStr, array $params): array {
        $where = [];
        $conditions = preg_split('/\s+AND\s+/i', $whereStr);
        
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
    private function sortResults(array &$results, string $order): void {
        if (preg_match('/(\w+)\s+(ASC|DESC)/i', $order, $matches)) {
            $field = $matches[1];
            $direction = strtoupper($matches[2]) === 'ASC' ? SORT_ASC : SORT_DESC;
            
            usort($results, static function($a, $b) use ($field, $direction) {
                $valA = $a[$field] ?? null;
                $valB = $b[$field] ?? null;
                
                if ($valA == $valB) return 0;
                
                $result = ($valA < $valB) ? -1 : 1;
                return $direction === SORT_ASC ? $result : -$result;
            });
        }
    }
}
