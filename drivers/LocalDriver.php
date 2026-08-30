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
    private $cacheMtime = [];   // 每个表对应文件的 mtime，用于检测外部修改
    private $requestId = '';    // 当前请求标识（用于请求级缓存隔离）
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

        // 表数据懒加载：首次访问时按需读取（见 getTable/loadTable）
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
     * 加载单个表
     *
     * 缓存命中条件：文件 mtime 未变化。仅靠 TTL 会在 php-fpm 多 worker 下读到
     * 其他进程写入前的旧快照（静态单例跨请求保留），导致数据不一致；
     * 用文件 mtime 校验后，外部写入会立即被感知。
     * 每次访问都检查 mtime（filemtime 走 stat 缓存，开销很小），懒加载只读用到的表。
     */
    private function loadTable($table) {
        // 请求级缓存隔离：常驻进程（php-fpm/mod_php/PHP-S）下静态单例跨请求保留，
        // 但数组内容在请求间可能被部分回收（mtime 保留、内容丢失），导致"幽灵缓存"命中空表。
        // 每次请求首次访问时强制从磁盘重新加载，保证数据正确性。
        $currentRequestId = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (string)$_SERVER['REQUEST_TIME_FLOAT']
            : uniqid('', true);
        if ($this->requestId !== $currentRequestId) {
            $this->requestId = $currentRequestId;
            $this->tables = [];
            $this->cache = [];
            $this->cacheTime = [];
            $this->cacheMtime = [];
        }

        $file = $this->dataPath . $table . '.json';
        $exists = file_exists($file);
        $mtime = $exists ? (int)@filemtime($file) : 0;

        // 缓存命中且文件未变化
        if ($this->cacheEnabled && isset($this->cache[$table]) && isset($this->cacheMtime[$table])
            && $this->cacheMtime[$table] === $mtime) {
            $this->tables[$table] = $this->cache[$table];
            $this->cacheTime[$table] = time();
            return;
        }

        if ($exists) {
            $content = @file_get_contents($file);
            $data = json_decode($content, true);
            $this->tables[$table] = $data ?: ['data' => [], 'next_id' => 1];
        } else {
            $this->tables[$table] = ['data' => [], 'next_id' => 1];
        }

        // 写入缓存
        if ($this->cacheEnabled) {
            $this->cache[$table] = $this->tables[$table];
            $this->cacheTime[$table] = time();
            $this->cacheMtime[$table] = $mtime;
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

        // 原子写：先写临时文件再 rename，避免写一半崩溃导致数据损坏
        $tmp = $file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            // 临时文件写入失败（只读/权限问题）时回退直接写
            @file_put_contents($file, $content, LOCK_EX);
        } else {
            @rename($tmp, $file);
        }
        
        // 更新缓存（同步记录最新 mtime）
        if ($this->cacheEnabled) {
            $this->cache[$table] = $this->tables[$table];
            $this->cacheTime[$table] = time();
            $this->cacheMtime[$table] = file_exists($file) ? (int)@filemtime($file) : 0;
        }
    }
    
    /**
     * 获取表数据引用（懒加载 + 每次 mtime 校验）
     */
    private function &getTable($table) {
        $this->loadTable($table);
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
            $file = $this->dataPath . $table . '.json';
            // 只有文件不存在时才真正创建（空表），
            // 绝不能覆盖已有数据文件（懒加载下 tables 仅存于当前请求，isset 判断每次都会成立）
            if (!file_exists($file)) {
                $this->loadTable($table);
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

        // 快速路径：id DESC/ASC + limit 时，data 数组按插入顺序即 id 递增，
        // 直接从尾部/头部倒序遍历，提前终止，避免全量扫描 + usort
        if ($limit > 0 && preg_match('/^id\s+(DESC|ASC)$/i', $order, $om)) {
            $desc = strtoupper($om[1]) === 'DESC';
            $count = count($tableRef['data']);
            for ($i = 0; $i < $count && count($results) < $limit; $i++) {
                $row = $tableRef['data'][$desc ? $count - 1 - $i : $i];
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
            return $results;
        }

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
        // 丢弃内存中所有更改，强制下次访问时从磁盘重新加载
        $this->tables = [];
        $this->cache = [];
        $this->cacheTime = [];
        $this->cacheMtime = [];
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
                    
                    // IN 支持：值为数组时判断包含
                    if ($operator === '=' && is_array($value)) {
                        if (!in_array($rowValue, $value)) return false;
                        continue;
                    }
                    
                    switch ($operator) {
                        case '=': if ($rowValue != $value) return false; break;
                        case '>': if ($rowValue <= $value) return false; break;
                        case '<': if ($rowValue >= $value) return false; break;
                        case '>=': if ($rowValue < $value) return false; break;
                        case '<=': if ($rowValue > $value) return false; break;
                        case '!=':
                            // 字段缺失（null）视为"不等于任何非 null 值"：
                            // 避免宽松比较下 null==0 被误判为相等而排除
                            if ($rowValue === null && $value !== null) {
                                break; // 保留
                            }
                            if ($rowValue == $value) return false;
                            break;
                    }
                }
            } else {
                // IN 支持：值为数组时判断包含
                if (is_array($value)) {
                    if (!in_array($row[$key] ?? null, $value)) {
                        return false;
                    }
                } elseif (($row[$key] ?? null) != $value) {
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
