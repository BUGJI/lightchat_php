<?php
/**
 * PDO 数据库驱动基类
 *
 * MySQL / SQLite 共用同一套查询实现，子类只需实现 connect() 差异。
 */
abstract class PdoDriver implements DatabaseDriverInterface {
    protected $connection = null;
    protected $config = [];
    protected $lastInsertId = null;

    public function __construct($config) {
        $this->config = $config;
    }

    abstract public function connect();

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

    /**
     * 校验 SQL 标识符（表名/列名），只允许字母数字下划线，且不以数字开头。
     * 表名与列名会直接拼接进 SQL，此校验可防止标识符注入。
     */
    protected function assertIdentifier($name, $context = 'identifier') {
        if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(
                "Invalid SQL {$context}: " . (is_string($name) ? $name : gettype($name))
            );
        }
        return $name;
    }

    public function insert($table, $data) {
        $this->assertIdentifier($table, 'table');
        foreach ($data as $field => $_) {
            $this->assertIdentifier($field, 'column');
        }
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
        $this->assertIdentifier($table, 'table');
        foreach ($data as $key => $value) {
            $this->assertIdentifier($key, 'column');
        }
        foreach ($where as $key => $value) {
            if (strpos($key, ' ') === false) {
                $this->assertIdentifier($key, 'column');
            }
        }
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
        $this->assertIdentifier($table, 'table');
        foreach ($where as $key => $value) {
            if (strpos($key, ' ') === false) {
                $this->assertIdentifier($key, 'column');
            }
        }
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
        $this->assertIdentifier($table, 'table');
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

    protected function buildWhereClause($where) {
        $sql = [];
        $params = [];
        $idx = 0;

        foreach ($where as $key => $value) {
            if (strpos($key, ' ') !== false) {
                // 复杂条件，如 "id > :id" → 提取字段名与操作符，生成唯一参数名
                if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*(>=|<=|!=|=|>|<)\s*(?::[a-zA-Z0-9_]+)?$/', trim($key), $m)) {
                    $field = $m[1];
                    $op = $m[2];

                    // 数组 + =/!= → IN / NOT IN（与 LocalDriver 行为一致）
                    if (is_array($value) && ($op === '=' || $op === '!=')) {
                        $placeholders = [];
                        foreach (array_values($value) as $i => $v) {
                            $paramName = 'w' . $idx . '_' . $field . '_' . $i;
                            $placeholders[] = ':' . $paramName;
                            $params[$paramName] = $v;
                        }
                        if (empty($placeholders)) {
                            $sql[] = ($op === '=') ? '0 = 1' : '1 = 1';
                        } else {
                            $keyword = ($op === '=') ? 'IN' : 'NOT IN';
                            $sql[] = "{$field} {$keyword} (" . implode(', ', $placeholders) . ")";
                        }
                        $idx++;
                        continue;
                    }

                    $paramName = 'w' . $idx . '_' . $field;
                    $sql[] = "{$field} {$op} :{$paramName}";
                    $params[$paramName] = $value;
                    $idx++;
                } else {
                    // 无法解析的条件，原样保留（调用方保证安全）
                    $sql[] = trim($key);
                }
            } else {
                // 数组值 → IN（空数组视为永假，避免生成非法 SQL）
                if (is_array($value)) {
                    if (empty($value)) {
                        $sql[] = '0 = 1';
                        continue;
                    }
                    $placeholders = [];
                    foreach (array_values($value) as $i => $v) {
                        $paramName = 'w' . $idx . '_' . $key . '_' . $i;
                        $placeholders[] = ':' . $paramName;
                        $params[$paramName] = $v;
                    }
                    $sql[] = "{$key} IN (" . implode(', ', $placeholders) . ")";
                    $idx++;
                    continue;
                }
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
