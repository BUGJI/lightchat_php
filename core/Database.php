<?php
/**
 * 数据库管理类
 * 统一数据库入口，自动选择驱动
 */
class Database {
    private static $instance = null;
    private $driver = null;
    private $config = [];
    
    private function __construct() {
        global $config;
        $this->config = $config['database']['default'];
        $this->initDriver();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 初始化数据库驱动
     */
    private function initDriver() {
        $type = $this->config['type'];
        
        require_once __DIR__ . '/DatabaseDriverInterface.php';
        
        switch ($type) {
            case 'mysql':
                require_once __DIR__ . '/../drivers/MySQLDriver.php';
                $this->driver = new MySQLDriver($this->config);
                break;
            case 'sqlite':
                require_once __DIR__ . '/../drivers/SQLiteDriver.php';
                $this->driver = new SQLiteDriver($this->config['sqlite']);
                break;
            case 'local':
                require_once __DIR__ . '/../drivers/LocalDriver.php';
                $this->driver = new LocalDriver($this->config['local']);
                break;
            default:
                throw new Exception("Unsupported database type: {$type}");
        }
        
        $this->driver->connect();
        $this->initTables();
    }
    
    /**
     * 初始化数据表（如果不存在）
     */
    private function initTables() {
        // 创建用户表
        $this->driver->execute("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                email VARCHAR(100),
                contact VARCHAR(100),
                reg_ip VARCHAR(45),
                avatar VARCHAR(255),
                account_type VARCHAR(20) DEFAULT 'user',
                role VARCHAR(20) DEFAULT 'member',
                status TINYINT DEFAULT 1,
                last_active_at TIMESTAMP,
notification_mode VARCHAR(20) DEFAULT 'none',
                notification_email VARCHAR(255) DEFAULT '',
                notification_pushplus_key VARCHAR(128) DEFAULT '',
                notification_template TEXT DEFAULT NULL,
                notification_webhook_url VARCHAR(500) DEFAULT '',
                notification_webhook_secret VARCHAR(128) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // 迁移已有用户，补齐通知字段
        $this->migrateNotificationFields();
        
        // 创建会话表
        $this->driver->execute("
            CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token VARCHAR(255) UNIQUE NOT NULL,
                ip VARCHAR(45),
                user_agent TEXT,
                expires_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // 创建频道表
        $this->driver->execute("
            CREATE TABLE IF NOT EXISTS channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(50) UNIQUE NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                type VARCHAR(20) DEFAULT 'public',
                description TEXT,
                announcement TEXT,
                owner_id INTEGER,
                member_count INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // 创建频道成员表
        $this->driver->execute("
            CREATE TABLE IF NOT EXISTS channel_members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role VARCHAR(20) DEFAULT 'member',
                last_read_message_id INTEGER DEFAULT 0,
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(channel_id, user_id)
            )
        ");
        
        // 创建消息表
        $this->driver->execute("
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                parent_id INTEGER DEFAULT 0,
                type VARCHAR(20) DEFAULT 'text',
                content TEXT,
                file_url VARCHAR(500),
                file_size INTEGER,
                mentioned_users TEXT,
                is_deleted TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_channel_time (channel_id, created_at)
            )
        ");
        
        // 创建私聊会话表
        $this->driver->execute("
            CREATE TABLE IF NOT EXISTS private_chats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user1_id INTEGER NOT NULL,
                user2_id INTEGER NOT NULL,
                last_message TEXT,
                last_message_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user1_id, user2_id)
            )
        ");
        
        // 创建私聊消息表
        $this->driver->execute("
            CREATE TABLE IF NOT EXISTS private_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                from_user_id INTEGER NOT NULL,
                to_user_id INTEGER NOT NULL,
                content TEXT,
                is_read TINYINT DEFAULT 0,
                is_deleted TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_chat_read (chat_id, is_read, created_at)
            )
        ");
        
        // 创建上传文件表
        $this->driver->execute("
            CREATE TABLE IF NOT EXISTS uploads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                file_name VARCHAR(255),
                file_path VARCHAR(500),
                file_size INTEGER,
                file_type VARCHAR(50),
                mime_type VARCHAR(100),
                file_hash VARCHAR(32),
                message_id INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // 创建封禁记录表
        $this->driver->execute("
            CREATE TABLE IF NOT EXISTS bans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                admin_id INTEGER,
                reason TEXT,
                expires_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // 创建审计日志表（合规留存）
        $this->driver->execute("
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                username VARCHAR(50),
                action VARCHAR(50) NOT NULL,
                target_type VARCHAR(20),
                target_id INTEGER,
                ip VARCHAR(45),
                user_agent TEXT,
                detail TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // 创建 Bot 密钥表
        $this->driver->execute("
            CREATE TABLE IF NOT EXISTS bot_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                api_key VARCHAR(64) UNIQUE NOT NULL,
                name VARCHAR(100),
                active TINYINT DEFAULT 1,
                last_used_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // 创建默认频道
        $defaultChannels = [
            ['name' => 'general', 'display_name' => '闲聊大厅', 'type' => 'public'],
            ['name' => 'announcements', 'display_name' => '系统公告', 'type' => 'announcement'],
            ['name' => 'help', 'display_name' => '帮助中心', 'type' => 'public'],
        ];
        
        foreach ($defaultChannels as $channel) {
            $exists = $this->driver->get('channels', ['name' => $channel['name']]);
            if (!$exists) {
                $this->driver->insert('channels', $channel);
            }
        }
    }
    
    /**
     * 迁移：为已有 users 补齐通知相关字段（仅运行一次）
     */
    private function migrateNotificationFields()
    {
        // 检查迁移标记，避免每次请求都执行
        $flagFile = $this->driver instanceof \LocalDriver
            ? ($this->config['local']['data_path'] ?? '') . '.migrated_notification_fields'
            : __DIR__ . '/../data/.migrated_notification_fields';
        if (file_exists($flagFile)) {
            return;
        }

$defaults = [
            'notification_mode'          => 'none',
            'notification_email'         => '',
            'notification_pushplus_key'  => '',
            'notification_template'      => null,
            'notification_webhook_url'   => '',
            'notification_webhook_secret' => '',
        ];

        try {
            $users = $this->driver->select('users');
            $batchUpdates = [];
            foreach ($users as $idx => $user) {
                $needUpdate = false;
                foreach ($defaults as $field => $default) {
                    if (!array_key_exists($field, $user)) {
                        $user[$field] = $default;
                        $needUpdate = true;
                    }
                }
                if ($needUpdate) {
                    $batchUpdates[] = ['id' => $user['id'], 'data' => $user];
                }
            }
            foreach ($batchUpdates as $update) {
                $this->driver->update('users', $update['data'], ['id' => $update['id']]);
            }
        } catch (Exception $e) {
            // 忽略迁移错误，不阻塞正常使用
        }

        // 写入标记文件
        @touch($flagFile);
    }

    /**
     * 魔术方法，调用驱动方法
     */
    public function __call($method, $args) {
        if (method_exists($this->driver, $method)) {
            return call_user_func_array([$this->driver, $method], $args);
        }
        throw new Exception("Method {$method} not found in driver");
    }
    
    /**
     * 获取驱动实例
     */
    public function getDriver() {
        return $this->driver;
    }
}