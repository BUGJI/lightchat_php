# LightChat PHP

轻量级 PHP 聊天系统，专为虚拟主机环境设计。

## 特性

- **零数据库依赖** — 默认使用 JSON 文件存储（LocalDriver），无需 MySQL
- **PHP 7.4+** — 兼容主流虚拟主机
- **RESTful API** — 完整的频道/私聊/文件上传/审计日志接口
- **双客户端** — 桌面版 SPA + 手表优化版，均单文件自包含
- **合规留存** — 审计日志、IP 记录、聊天内容明文存档

## 快速开始

1. 将整个目录上传到虚拟主机 web 根目录
2. 确保 `data/` `uploads/` `logs/` 目录可写（755）
3. 访问 `http://your-host/Lightchat/public/` 即可使用

## 目录结构

```
Lightchat/
├── api/               # REST API 端点
│   ├── token/         # 注册 / 登录 / Token 刷新
│   ├── channels/      # 频道 CRUD
│   ├── messages/      # 消息发送 / 历史 / 轮询 / 删除
│   ├── private/       # 私聊
│   ├── users/         # 用户资料 / 搜索 / 列表
│   ├── files/         # 文件上传
│   ├── server/        # 服务器资源配额
│   └── admin/         # 审计日志 / 数据导出
├── core/              # 数据库抽象层
├── drivers/           # 存储驱动 (Local / MySQL / SQLite)
├── public/            # 前端客户端
│   ├── index.html     # 桌面版 SPA
│   ├── wear_lightchat.html  # 手表优化版
│   └── terms/         # 隐私政策 / 服务条款
├── config.php         # 全局配置
└── data/              # JSON 数据存储（自动创建）
```

## API 概览

| 模块 | 端点 |
|---|---|
| 认证 | `POST /api/token/register.php` `login.php` `refresh.php` |
| 频道 | `GET/POST /api/channels/list.php` `create.php` `join.php` `leave.php` |
| 消息 | `GET/POST /api/messages/send.php` `history.php` `delete.php` `poll.php` |
| 私聊 | `GET/POST /api/private/list.php` `send.php` `history.php` |
| 用户 | `GET/POST /api/users/profile.php` `search.php` `list.php` |
| 文件 | `POST /api/files/upload.php` |
| 管理 | `GET/POST /api/admin/audit.php` `export.php` `/api/server/status.php` |
| Bot | `POST /api/bot/create.php` `GET/POST /api/bot/list.php` |

## Bot 接入

1. 管理员调用 `POST /api/bot/create.php` 创建 Bot，获得永久 `api_key`
2. Bot 请求时在 Header 加 `X-Bot-Key: bot_xxxx...`
3. 认证通过后 Bot 和普通用户一样调用消息、频道等全部 API
4. 管理员可随时禁用/启用/重新生成 Key

## 客户端 API 地址配置

两个客户端都支持三种方式指定 API 基础地址：

1. URL 参数 `?api=http://host/path`
2. 设置面板手动输入
3. 留空使用同源地址

## 许可

MIT
