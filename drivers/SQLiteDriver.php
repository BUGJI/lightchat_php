<?php
/**
 * SQLite数据库驱动（继承 PDO 基类）
 * 适用于轻量级部署
 */
class SQLiteDriver extends PdoDriver {
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
}
