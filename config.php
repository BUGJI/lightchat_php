<?php
/**
 * 聊天系统配置文件
 * 适用于虚拟主机环境的聊天系统
 * 
 * @version 2.0
 * @last_modified 2026-01-18
 */

// ==================== 基础配置 ====================
return [
    // 应用基础信息
    'app' => [
        'name' => 'LightChat',                    // 应用名称
        'version' => '1.0.0',                     // 版本号
        'timezone' => 'Asia/Shanghai',            // 时区
        'charset' => 'UTF-8',                     // 字符集
        'debug' => false,                         // 调试模式（生产环境设为false）
    ],

    // ==================== 用户与权限配置 ====================
    'user' => [
        // 是否允许匿名访问
        'allow_anonymous' => false,
        
        // 是否需要验证邮箱
        'require_verification' => false,
        
        // 默认角色
        'default_role' => 'member',               // guest, member, vip, admin
        
        // 角色权限定义（支持继承）
        'roles' => [
            // 游客 - 只读权限
            'guest' => [
                'permissions' => [
                    'user.profile.get' => true,        // 查看自己的资料卡
                    'user.profile.public' => true,     // 查看他人公开资料卡
                    'user.message.get' => true,        // 接收消息
                    'channel.list.get' => true,        // 查看频道列表
                    'channel.join' => true,            // 加入公开频道
                ],
                'rate_limit' => 10,                    // 每分钟请求限制
                'message_rate' => 0,                   // 不能发消息
            ],
            
            // 普通成员
            'member' => [
                'extends' => 'guest',                  // 继承guest权限
                'permissions' => [
                    'user.account.delete' => true,     // 注销账号
                    'user.profile.update' => true,     // 修改个人资料
                    'user.message.send' => true,       // 发送消息
                    'user.message.delete' => true,     // 删除自己的消息
                    'channel.create' => true,          // 创建频道
                    'channel.manage' => true,          // 管理自己创建的频道
                ],
                'rate_limit' => 30,
                'message_rate' => 20,                  // 每分钟最多发送20条消息
            ],
            
            // VIP成员
            'vip' => [
                'extends' => 'member',
                'permissions' => [
                    'user.file.upload' => true,        // 上传文件
                    'user.file.delete' => true,        // 删除自己的文件
                    'user.voice.send' => true,         // 发送语音
                    'channel.create_limit' => 10,      // 最多创建10个频道
                ],
                'rate_limit' => 60,
                'message_rate' => 30,
            ],
            
            // 管理员
            'admin' => [
                'extends' => 'vip',
                'permissions' => [
                    'admin.user.ban' => true,          // 封禁用户
                    'admin.user.delete' => true,       // 删除用户
                    'admin.user.mute' => true,         // 禁言用户
                    'admin.message.delete' => true,    // 删除任何人的消息
                    'admin.channel.delete' => true,    // 删除任何频道
                    'admin.channel.set_announcement' => true,  // 设置频道公告
                    'admin.system.config' => true,     // 修改系统配置
                ],
                'rate_limit' => 120,
                'message_rate' => 60,
            ],
        ],
        
        // 资料卡配置
        'profile' => [
            'public_fields' => ['username', 'avatar', 'role', 'join_date', 'status'],  // 公开可见
            'private_fields' => ['email', 'phone', 'real_name', 'birthday'],           // 仅自己和admin可见
            'editable_fields' => ['avatar', 'bio', 'nickname', 'signature'],           // 用户可编辑
        ],
        
        // 用户名规则
        'username' => [
            'min_length' => 3,
            'max_length' => 20,
            'pattern' => '/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u',  // 字母数字下划线中文
            'reserved' => ['admin', 'system', 'robot', 'anonymous'],  // 保留用户名
        ],
        
        // 会话配置
        'session' => [
            'lifetime' => 3600,                    // 会话过期时间（秒）
            'cookie_name' => 'chat_session',
            'http_only' => true,
            'secure' => false,                     // 仅HTTPS下启用
            'same_site' => 'Lax',
        ],
    ],

    // ==================== 服务器资源监控与限制 ====================
    'server' => [
        // 资源配额（用于信息展示）
        'quota' => [
            'monthly_network_flow_mb' => 10240,     // 月流量限制 10GB（可用于计量）
            'disk_space_mb' => 512,                 // 磁盘空间限制 512MB（可用于计量）
            'max_connections' => 50,                // 最大并发连接数
            'max_processes' => 20,                  // 最大进程数
        ],
        
        // 带宽信息（用于信息展示）
        'bandwidth' => [
            'max_upload_mbps' => 4,                 // 最大上传带宽
            'max_download_mbps' => 4,               // 最大下载带宽
        ],
        
        // 当前使用统计（需要定期更新）
        'usage' => [
            'network_flow_mb' => 0,                 // 本月已使用流量
            'disk_used_mb' => 0,                    // 已使用磁盘空间
            'current_connections' => 0,             // 当前连接数
            'reset_day' => 1,                       // 每月几日重置流量统计
            'last_reset_date' => null,              // 上次重置日期
        ],
        
        // 性能配置
        'performance' => [
            'enable_gzip' => true,                  // 启用GZIP压缩
            'cache_ttl' => 300,                     // 缓存过期时间（秒）
            'db_persistent' => false,               // 数据库持久连接（虚拟主机慎用）
        ],
    ],

    // ==================== 消息配置 ====================
    'message' => [
        // 消息基本限制
        'max_length' => 500,                        // 单条消息最大长度（字符）
        'max_history_days' => 7,                    // 保留历史天数（超过后归档）
        'message_history_limit' => 1000,            // 单次拉取最大历史消息数
        
        // 敏感词过滤
        'sensitive_words_enabled' => true,
        'sensitive_words_file' => __DIR__ . '/sensitive_words.txt',  // 敏感词列表文件
        
        // 消息冷却
        'cooldown_seconds' => 2,                    // 用户发送消息最小间隔（秒）
        
        // 表情包支持
        'emoji_support' => true,
        'custom_emoji' => false,
        
        // 引用回复
        'quote_reply' => [
            'enabled' => true,
            'max_depth' => 1,                       // 最大引用嵌套深度
        ],
        
        // @提及
        'mention' => [
            'enabled' => true,
            'max_mentions' => 10,                   // 单条消息最多@人数
        ],
    ],

    // ==================== 频道与私聊配置 ====================
    'chat' => [
        // 私聊配置（固定两人）
        'private' => [
            'enabled' => true,
            'history_visible' => true,              // 是否可查看历史私聊记录
            'allow_block' => true,                  // 允许屏蔽对方
            'max_recent' => 50,                     // 最多保留最近50条私聊会话
        ],
        
        // 频道配置
        'channel' => [
            'enabled' => true,
            'max_per_user' => 10,                   // 每个用户最多创建/加入的频道数
            'max_members' => 500,                   // 每个频道最大成员数
            'allow_invite' => true,                 // 允许邀请成员
            'allow_leave' => true,                  // 允许主动退出
            
            // 频道类型
            'types' => [
                'public' => [                       // 公开频道
                    'visible_to_all' => true,       // 所有人可见
                    'join_approval' => false,       // 无需审批
                    'read_only_guest' => true,      // 游客可查看
                ],
                'private' => [                      // 私密频道
                    'visible_to_all' => false,      // 不可见
                    'join_approval' => true,        // 需要审批或邀请
                    'read_only_guest' => false,     // 游客不可见
                ],
                'announcement' => [                 // 公告频道（仅管理员可发言）
                    'visible_to_all' => true,
                    'join_approval' => false,
                    'read_only_guest' => true,
                    'post_permission' => 'admin',   // 仅管理员可发言
                ],
            ],
            
            // 默认频道（系统创建，不可删除）
            'default_channels' => [
                [
                    'name' => 'general',
                    'display_name' => '闲聊大厅',
                    'type' => 'public',
                    'description' => '欢迎闲聊，请遵守规则',
                ],
                [
                    'name' => 'announcements',
                    'display_name' => '系统公告',
                    'type' => 'announcement',
                    'description' => '重要公告发布区',
                ],
                [
                    'name' => 'help',
                    'display_name' => '帮助中心',
                    'type' => 'public',
                    'description' => '提问与解答',
                ],
            ],
        ],
    ],

    // ==================== 上传配置 ====================
    'upload' => [
        // 全局开关
        'enabled' => true,
        
        // 文件类型权限
        'file_types' => [
            'image' => [
                'enabled' => true,
                'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                'max_size_kb' => 2048,              // 2MB
                'max_width' => 1920,
                'max_height' => 1080,
            ],
            'audio' => [
                'enabled' => true,
                'extensions' => ['mp3', 'wav', 'ogg'],
                'mime_types' => ['audio/mpeg', 'audio/wav', 'audio/ogg'],
                'max_size_kb' => 5120,              // 5MB
                'max_duration_seconds' => 60,       // 最长录音60秒
            ],
            'video' => [
                'enabled' => false,                 // 视频默认关闭（消耗太大）
                'extensions' => ['mp4', 'webm'],
                'mime_types' => ['video/mp4', 'video/webm'],
                'max_size_kb' => 10240,             // 10MB
                'max_duration_seconds' => 30,
            ],
            'file' => [
                'enabled' => true,
                'extensions' => ['pdf', 'doc', 'docx', 'txt', 'zip'],
                'mime_types' => [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'text/plain',
                    'application/zip'
                ],
                'max_size_kb' => 5120,              // 5MB
            ],
        ],
        
        // 存储配置，聊天产生的资源
        'storage' => [
            'driver' => 'local',                    // local, oss, cos
            'local_path' => __DIR__ . '/uploads/',
            'url_prefix' => '/uploads/',
            'enable_compression' => true,           // 压缩图片
            'image_quality' => 80,                  // 图片压缩质量 1-100
            'thumbnail_size' => [200, 200],         // 缩略图尺寸
            'thumbnail_enabled' => true,
        ],
        
        // 上传频率限制
        'rate_limit' => [
            'per_minute' => 5,                      // 每分钟最多上传数
            'per_hour' => 20,                       // 每小时最多上传数
            'per_day' => 50,                        // 每天最多上传数
        ],
    ],

    // ==================== 轮询配置 ====================
    'polling' => [
        // 时间配置（毫秒）
        'min_interval_ms' => 1000,                  // 最小刷新间隔 1秒
        'max_interval_ms' => 30000,                 // 最大刷新间隔 30秒
        'default_interval_ms' => 3000,              // 默认刷新间隔 3秒
        
        // 长轮询配置
        'long_polling' => [
            'enabled' => true,                      // 启用长轮询
            'timeout_seconds' => 25,                // 长轮询超时时间（秒）
            'max_wait_cycles' => 5,                 // 最大等待检查次数
            'wait_interval_ms' => 2000,             // 每次检查间隔（毫秒）
        ],
        
        // 自适应优化
        'adaptive' => [
            'enabled' => true,
            'increase_factor' => 1.5,               // 无消息时增加间隔倍数
            'decrease_factor' => 0.5,               // 有消息时减少间隔倍数
            'idle_threshold_seconds' => 30,         // 闲置判断阈值（秒）
            'min_factor' => 0.5,
            'max_factor' => 3.0,
        ],
        
        // 智能回退（根据服务器负载）
        'fallback' => [
            'response_time_threshold_ms' => 500,    // 响应时间超过此值触发降级
            'error_threshold' => 3,                  // 连续错误次数触发降级
            'fallback_interval_ms' => 5000,         // 降级后的间隔（5秒）
            'recovery_attempts' => 5,                // 降级后尝试恢复的次数
        ],
    ],

    // ==================== 清理与维护配置 ====================
    'maintenance' => [
        // 自动清理策略
        'auto_cleanup' => [
            'enabled' => true,
            'trigger_mode' => 'request',            // cron, request
            'cron_expression' => '0 3 * * *',       // 每天凌晨3点执行（需外部定时触发）
            'request_probability' => 0.001,          // 请求时触发清理的概率（0.1%）
        ],
        
        // 消息清理
        'message_cleanup' => [
            'delete_after_days' => 30,               // 30天后删除旧消息
            'archive_old_messages' => false,         // 是否归档（而非删除）
            'archive_path' => __DIR__ . '/archives/',
            'batch_size' => 1000,                    // 每次清理批次大小
        ],
        
        // 会话清理
        'session_cleanup' => [
            'delete_expired_hours' => 24,            // 24小时后清理过期会话
            'cleanup_interval_hours' => 6,           // 清理间隔
        ],
        
        // 上传文件清理
        'upload_cleanup' => [
            'delete_orphaned_files' => true,         // 删除没有关联消息的文件
            'orphaned_check_days' => 7,              // 检查7天前的孤立文件
            'temp_cleanup_hours' => 24,              // 清理24小时前的临时文件
        ],
        
        // 日志清理
        'log_cleanup' => [
            'keep_days' => 7,                        // 保留7天日志
            'error_log_keep_days' => 30,             // 错误日志保留30天
            'access_log_keep_days' => 3,             // 访问日志保留3天
        ],
        
        // 存储空间管理
        'storage_management' => [
            'auto_delete_when_full' => false,        // 空间满时自动删除旧数据
            'warning_threshold_percent' => 85,       // 85%时发出警告
            'critical_threshold_percent' => 95,      // 95%时停止新上传
            'cleanup_target_percent' => 70,          // 自动清理到70%使用率
        ],
        
        // 备份配置
        'backup' => [
            'enabled' => true,
            'auto_backup' => false,                  // 自动备份（虚拟主机可能资源不足）
            'backup_interval_days' => 7,
            'keep_backup_count' => 4,
            'backup_path' => __DIR__ . '/backups/',
            'exclude_tables' => ['logs', 'sessions', 'cache'],
            'compress' => true,
        ],
    ],

    // ==================== API配置（前后端分离） ====================
    'api' => [
        // API版本
        'version' => 'v1',
        
        // CORS跨域配置
        'cors' => [
            'enabled' => true,
            'allowed_origins' => ['*'],              // 生产环境请限制具体域名
            'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'exposed_headers' => ['X-Total-Count', 'X-RateLimit-Remaining'],
            'allow_credentials' => true,
            'max_age' => 86400,                      // 预检请求缓存24小时
        ],
        
        // 速率限制（基于IP或用户）
        'rate_limit' => [
            'enabled' => true,
            'requests_per_minute' => 60,              // 每分钟最多请求数
            'requests_per_hour' => 1000,              // 每小时最多请求数
            'burst_multiplier' => 2,                  // 突发请求倍数
            'response_headers' => true,               // 返回剩余次数头部
        ],
        
        // 响应格式
        'response' => [
            'format' => 'json',                       // json, xml
            'pretty_print' => false,                  // 生产环境false
            'include_timestamp' => true,
            'include_request_id' => true,
            'include_execution_time' => false,        // 调试用
        ],
        
        // API响应码定义
        'response_codes' => [
            'success' => 200,
            'created' => 201,
            'accepted' => 202,
            'no_content' => 204,
            'bad_request' => 400,
            'unauthorized' => 401,
            'forbidden' => 403,
            'not_found' => 404,
            'method_not_allowed' => 405,
            'conflict' => 409,
            'too_many_requests' => 429,
            'server_error' => 500,
            'service_unavailable' => 503,
            
            'messages' => [
                200 => '操作成功',
                201 => '创建成功',
                204 => '操作成功',
                400 => '请求参数错误',
                401 => '未登录或登录已过期',
                403 => '权限不足',
                404 => '资源不存在',
                405 => '请求方法不支持',
                409 => '数据冲突',
                429 => '请求过于频繁，请稍后再试',
                500 => '服务器内部错误',
                503 => '服务暂不可用',
            ],
        ],
        
        // API密钥/令牌（用于第三方客户端）
        'api_keys' => [
            'enabled' => false,
            'keys' => [],                             // ['key1' => 'client_name']
            'header_name' => 'X-API-Key',
        ],
        
        // 支持的客户端类型
        'clients' => [
            'web' => ['version' => '1.0', 'min_version' => '1.0'],
            'mobile' => ['version' => '1.0', 'min_version' => '1.0'],
            'desktop' => ['version' => '0.9', 'min_version' => '0.9'],
            'api' => ['version' => '1.0', 'min_version' => '1.0'],
            'wechat' => ['version' => '1.0', 'min_version' => '1.0'],
        ],
    ],

    // ==================== 数据库配置 ====================
    'database' => [
        // 默认数据库连接
        'default' => [
            // 类型: mysql (MySQL数据库), sqlite (SQLite文件), local (本地文件存储，适合无数据库环境)
            'type' => 'local',
            
            // ===== SQLite 配置（type = 'sqlite' 时使用）=====
            'sqlite' => [
                'path' => __DIR__ . '/data/chat.db',      // 数据库文件路径
                'journal_mode' => 'WAL',                  // Write-Ahead Logging 提升并发性能
                'synchronous' => 'NORMAL',                // NORMAL / FULL / OFF
                'cache_size' => -2000,                    // 2MB缓存
            ],
            
            // ===== 本地文件存储配置（type = 'local' 时使用）=====
            // 说明：适用于不方便配置数据库的虚拟主机，将网站空间当作数据库使用
            // 优点：无需数据库，部署即用；缺点：不支持复杂查询，性能较低
            'local' => [
                'data_path' => __DIR__ . '/data/',        // 数据存储目录
                'format' => 'json',                       // 存储格式: json / serialize
                'auto_backup' => true,                    // 自动备份
                'backup_interval_hours' => 24,            // 备份间隔
                'cache_enabled' => true,                  // 启用缓存减少文件读取
                'cache_ttl' => 300,                       // 缓存有效期（秒）
            ],
            
            // ===== MySQL 配置（type = 'mysql' 时使用）=====
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'chat_db',
            'username' => 'chat_user',
            'password' => 'your_password_here',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => 'chat_',
            
            // 连接池（虚拟主机通常不支持，但预留配置）
            'pool' => [
                'max_connections' => 5,
                'timeout_seconds' => 3,
                'wait_timeout' => 30,
            ],
        ],
        
        // 读写分离配置（虚拟主机很少支持）
        'replication' => [
            'enabled' => false,
            'read_hosts' => [],
        ],
        
        // 查询优化
        'query' => [
            'slow_query_log' => true,
            'slow_query_threshold_ms' => 500,
            'enable_cache' => true,
            'cache_ttl' => 300,
        ],
    ],

    // ==================== 缓存配置 ====================
    'cache' => [
        'driver' => 'file',                           // file, redis, memcached
        'prefix' => 'chat_',
        'ttl' => 3600,                                // 默认过期时间
        
        'file' => [
            'path' => __DIR__ . '/cache/',
            'depth' => 2,                             // 目录深度
            'cleanup_probability' => 0.01,            // 清理过期缓存的概率
        ],
        
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => null,
            'database' => 0,
            'timeout' => 2.5,
        ],
    ],

    // ==================== 日志配置 ====================
    'logging' => [
        'enabled' => true,
        'level' => 'error',                           // debug, info, warning, error
        'driver' => 'file',                           // file, database
        
        'file' => [
            'path' => __DIR__ . '/logs/',
            'filename' => 'chat.log',
            'max_size_mb' => 100,
            'rotate' => true,
            'max_files' => 7,
            'date_format' => 'Y-m-d H:i:s',
        ],
        
        'database' => [
            'table' => 'logs',
            'async_insert' => true,                   // 异步写入（降低性能影响）
        ],
        
        // 不同级别的日志输出
        'channels' => [
            'error' => ['file', 'database'],
            'warning' => ['file'],
            'info' => ['file'],
            'debug' => [],                             // debug不记录，提升性能
        ],
    ],

    // ==================== 通知配置 ====================
    'notifications' => [
        // 系统消息模板（客户端渲染）
        'system_messages' => [
            'welcome' => '欢迎 {username} 加入 {channel}',
            'leave' => '{username} 离开了 {channel}',
            'kick' => '{username} 被移出频道',
            'mute' => '{username} 被禁言 {duration} 分钟',
            'unmute' => '{username} 已被解除禁言',
            'role_change' => '{username} 的角色已变更为 {role}',
            'channel_created' => '频道 {channel} 已创建',
            'channel_deleted' => '频道 {channel} 已被删除',
            'channel_announcement' => '【公告】{content}',
        ],
        
        // 邮件通知（用于重要提醒，如账号验证、密码重置）
        'email' => [
            'enabled' => false,
            'smtp' => [
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'username' => '',
                'password' => '',
                'encryption' => 'tls',                 // ssl, tls, null
            ],
            'from' => [
                'email' => 'noreply@example.com',
                'name' => 'Chat System',
            ],
            'templates' => [
                'verification' => 'email/verification',
                'password_reset' => 'email/password_reset',
                'account_banned' => 'email/account_banned',
            ],
        ],
        
        // Webhook通知（可对接其他系统）
        'webhook' => [
            'enabled' => false,
            'url' => null,
            'events' => ['user_register', 'user_ban', 'message_report'],  // 触发的事件
            'secret' => null,
        ],
    ],

    // ==================== 安全配置 ====================
    'security' => [
        // XSS防护
        'xss_protection' => [
            'enabled' => true,
            'filter_input' => true,                    // 过滤输入
            'escape_output' => true,                   // 转义输出
            'allowed_html_tags' => ['b', 'i', 'u', 'img', 'code'],  // 允许的HTML标签
        ],
        
        // CSRF防护
        'csrf_protection' => [
            'enabled' => true,
            'token_name' => 'csrf_token',
            'token_expiry' => 3600,
            'exclude_paths' => ['/api/poll'],          // 排除的路径（如轮询接口）
        ],
        
        // SQL注入防护
        'sql_injection_protection' => [
            'enabled' => true,
            'use_prepared_statements' => true,         // 使用预编译语句
            'filter_input' => true,                     // 过滤输入
        ],
        
        // 请求限制
        'request_limits' => [
            'max_post_size_kb' => 2048,                 // 最大POST数据大小 2MB
            'max_input_vars' => 1000,                   // 最大输入变量数
            'max_file_uploads' => 5,                    // 最大上传文件数
        ],
        
        // IP黑白名单
        'ip_filter' => [
            'blacklist' => [],                          // 封禁IP列表
            'whitelist' => [],                          // 白名单IP（空表示不限制）
            'blacklist_message' => '您的IP已被封禁',
        ],
        
        // 内容安全策略
        'csp_header' => "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.example.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:;",
        
        // 速率限制（基于IP）
        'ip_rate_limit' => [
            'enabled' => true,
            'requests_per_minute' => 120,               // 单IP每分钟最多请求
            'requests_per_hour' => 1000,                // 单IP每小时最多请求
            'ban_on_exceed' => false,                   // 是否自动封禁超限IP
            'ban_duration_minutes' => 60,               // 封禁时长
        ],
    ],

    // ==================== 开发与调试配置 ====================
    'debug_config' => [
        'enabled' => false,                             // 生产环境必须false
        'display_errors' => false,                      // 是否显示错误
        'error_reporting' => E_ALL & ~E_DEPRECATED & ~E_STRICT,
        'log_queries' => false,                         // 记录所有SQL查询
        'profile_execution_time' => false,              // 记录执行时间
        'profile_memory_usage' => false,                // 记录内存使用
        'allowed_ips' => ['127.0.0.1', '::1'],          // 允许调试的IP
        'log_file' => __DIR__ . '/logs/debug.log',
    ],
];