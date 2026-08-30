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
                    'user.bot.register' => true,       // 自助注册 Bot
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
                    'admin.bot.create' => true,        // 创建 Bot
                    'admin.bot.manage' => true,        // 管理 Bot（启禁/删/重生成Key）
                    'admin.bot.delete' => true,        // 删除 Bot
                ],
                'rate_limit' => 120,
                'message_rate' => 60,
            ],
        ],
        
        // 资料卡配置
        'profile' => [
            'public_fields' => ['username', 'avatar', 'role', 'join_date', 'status'],  // 公开可见
            'private_fields' => ['email', 'contact', 'real_name', 'birthday'],           // 仅自己和admin可见
            'editable_fields' => ['avatar', 'bio', 'nickname', 'signature'],           // 用户可编辑
        ],
        
        // 用户名规则
        'username' => [
            'min_length' => 3,
            'max_length' => 20,
            'pattern' => '/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u',  // 字母数字下划线中文
            'reserved' => ['admin', 'system', 'robot', 'anonymous'],  // 保留用户名
        ],
        
        // ── Bot 配置 ──
        'bot' => [
            'allow_self_register' => true,        // 是否允许用户自助注册为 Bot（通过注册接口传 account_type=bot）
            'max_per_user' => 5,                  // 每人最多自助注册的 Bot 数量
            'key_prefix' => 'bot_',               // API Key 前缀
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
                'cache_enabled' => true,                  // 启用缓存减少文件读取（mtime 校验保证一致性）
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
        ],
    ],

    // ==================== 通知配置 ====================
    'notifications' => [
        // ===== 离线通知 =====
        // 注意：离线通知现在由接收消息事件触发，不再需要定时检查
        'offline_notify' => [
            'enabled' => true,                         // 全局开关
            'offline_threshold_minutes' => 10,         // 判定离线：距上次活跃时间 > 此值（分钟）
            // 'check_interval_minutes' => 5,          // 已废弃：不再使用定时检查
        ],

        // ===== 通知方式注册 =====
        // 每一种通知方式一个键，enabled 控制是否启用该方式
        'methods' => [
            'email' => [
                'enabled' => false,                  // 默认关闭：SMTP 为占位配置，启用前请填写真实 SMTP
                'label'   => '邮件通知',
                'smtp' => [
                    'host'     => 'smtp.example.com',
                    'port'     => 587,
                    'username' => '',
                    'password' => '',
                    'encryption' => 'tls',             // ssl / tls / null
                    'timeout' => 10,
                ],
                'from' => [
                    'email' => 'noreply@example.com',
                    'name'  => 'LightChat',
                ],
                'templates' => [
                    'subject' => '【LightChat】您有新的离线消息',
                    'body'    => "您好 {nickname}，\n\n"
                        . "您离线期间收到了 {unread_count} 条新消息。\n\n"
                        . "最近消息：\n{messages_preview}\n\n"
                        . "发送者：{sender_name}\n"
                        . "最后消息时间：{last_message_time}\n\n"
                        . "请登录查看完整内容。\n\n"
                        . "-- LightChat 通知系统",
                ],
            ],

            'pushplus' => [
                'enabled' => false,                  // 默认关闭：启用前请填写真实 PushPlus Token
                'label'   => 'PushPlus',
                'api_url' => 'https://www.pushplus.plus/send',
                'channel' => 'wechat',                 // wechat / sms / mail / webhook
                'template' => 'html',                   // html / txt / json / markdown
                'timeout' => 10,
                'templates' => [
                    'title'   => '【LightChat】离线消息提醒',
                    'content' => "<h3>您好 {nickname}</h3>"
                        . "<p>您离线期间收到了 <b>{unread_count}</b> 条新消息。</p>"
                        . "<p>发送者：{sender_name}<br>"
                        . "时间：{last_message_time}</p>"
                        . "<hr><p>{messages_preview}</p>"
                        . "<p><small>— LightChat 通知系统</small></p>",
                ],
            ],

            'webhook' => [
                'enabled' => false,                  // 默认关闭：启用前请配置用户级 Webhook URL
                'label'   => 'Webhook',
                'timeout' => 10,
                'templates' => [
                    'title'   => '【LightChat】离线消息提醒',
                    'content' => "您好 {nickname}\n\n"
                        . "您离线期间收到了 {unread_count} 条新消息。\n\n"
                        . "发送者：{sender_name}\n"
                        . "时间：{last_message_time}\n\n"
                        . "{messages_preview}\n\n"
                        . "-- LightChat 通知系统",
                ],
            ],
        ],

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
    ],

    // ==================== 安全配置 ====================
    'security' => [
        // 登录暴力破解防护（按 用户名+IP 统计失败次数，超限临时锁定）
        'login_protection' => [
            'enabled' => true,
            'max_failures' => 5,                    // 连续失败次数
            'lockout_minutes' => 15,                // 锁定分钟
            'fail_window' => 10,                    // 失败统计窗口（分钟）
        ],

        // 速率限制（基于IP）
        'ip_rate_limit' => [
            'enabled' => true,
            'requests_per_minute' => 120,               // 单IP每分钟最多请求
            'requests_per_hour' => 1000,                // 单IP每小时最多请求
            'ban_on_exceed' => false,                   // 是否自动封禁超限IP
            'ban_duration_minutes' => 60,               // 封禁时长
        ],
    ],
];