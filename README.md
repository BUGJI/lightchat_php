# LightChat PHP

轻量级 PHP 聊天系统，专为**虚拟主机（共享托管）环境**设计：零 MySQL、无守护进程、单文件双客户端，上传即用。

- **后端**：PHP 7.4+ 纯 PHP + JSON 文件存储（可选 MySQL / SQLite / PDO）
- **前端**：桌面版 SPA 与手表（穿戴）优化版，均为单 HTML 自包含
- **部署**：内置网页安装向导（`install.php`），FTP 上传 → 访问向导 → 完成

<img width="640" height="480" alt="QQ_1788421524824" src="https://github.com/user-attachments/assets/ac85f644-1feb-4b81-b057-bd5db8cf2100"/>

<img width="240" height="240" alt="image" src="https://github.com/user-attachments/assets/da5f14d1-2d7e-4456-b449-7ac6d5fcc5dc"/>

## 特性

| 类别 | 能力 |
|---|---|
| 存储 | 默认 **LocalDriver（JSON 文件）**，零数据库依赖；切换配置即可用 MySQL / SQLite / PDO 驱动 |
| 兼容 | PHP 7.4+；内置 mbstring polyfill，弱扩展环境也能跑；支持共享虚拟主机（无 cron、无常驻进程） |
| 频道 | 公开 / 私密频道、创建 / 解散 / 退出、**成员邀请链接**、owner / admin / member 三级角色（成员不可改频道名，权限按角色锁定） |
| 私聊 | 会话化私聊、**已读回执与未读红点**、**联系人管理**（备注名 / 免打扰 🔕 / 删除好友；删除后对方或己方再次发消息自动恢复会话） |
| 消息 | 文本 / 图片 / 文件上传、历史记录、实时轮询、删除、敏感词过滤 |
| 通知 | PushPlus / Webhook / Email 三通道（邮件默认关闭，需填真实 SMTP 后开启） |
| Bot | 管理员登录后创建 Bot 获得永久 `api_key`，随附 **Python SDK**（`sdk/`），可禁启 / 重生成 Key / 删除，全程审计 |
| 管理 | 审计日志、用户封禁、数据导出、服务器资源 / 流量用量状态（`api/server/status.php`）、健康检查（`api/health.php`） |
| 安全 | 全 API Token 鉴权 + 细粒度权限、防注入 / XSS 转义输出、登录与操作限流、IP 记录、聊天内容明文合规留存 |
| 客户端 | `public/index.html`（桌面/手机标准界面）、`public/wear_lightchat.html`（手表/小屏穿戴界面）、根目录 `index.html` 设备选择页 |

## 快速开始

1. 将整个项目上传到虚拟主机 Web 根目录（如 `public_html`），保持目录结构不变
2. 确保以下目录/文件**可写（755）**：`data/` `uploads/` `logs/` `config.local.php`（由向导生成）
3. 浏览器访问 `https://你的域名/install.php`，跟随向导：
   - 环境检查（目录可写、PHP 版本等）
   - 设置服务器名、文件配额、上传带宽等
   - **注册第一个管理员账号**（首个账号自动成为 admin）
   - 自动创建 `data/` 结构与 `config.local.php`，写入 `installed.lock` 完成锁定
4. 访问根目录选择界面，进入 **标准界面**（手机/电脑）或 **穿戴界面**（手表/小屏）

> 已有部署升级时**不要删除 `data/`**；新版会自动补齐缺失的数据表（LocalDriver 自动建表）。

## 配置

- 主配置 `config.php`：版本、默认角色（guest/member/vip/admin）、细粒度权限表、用户名规则、私密字段、**存储驱动**（`database.driver`：local / mysql / sqlite / pdo）、上传（local/oss/cos 驱动、大小/带宽/缩略图）、敏感词开关、配额、通知开关等
- 本机部署配置写入 `config.local.php`（向导生成），优先级高于 `config.php` 对应项
- 修改存储驱动时请先备份 `data/`，并按驱动要求准备数据库连接信息

### 通知

- **PushPlus / Webhook**：默认启用，凭据为**用户级**（个人设置里填写 token / URL），无需全局配置
- **Email**：默认关闭——先在 `config.php` 的 `notifications.email` 填入真实 SMTP 服务器与账号，并将 `enabled` 改为 `true`
- 管理员可在用户管理 / API 中测试通知，接口带整窗限流

## Bot 接入

1. 管理员登录后调用 `POST /api/bot/create.php` 创建 Bot，响应返回永久 `api_key`（该操作写入审计日志）
2. Bot 请求时在 Header 加 `X-Bot-Key: bot_xxx...`
3. 认证通过后 Bot 与普通用户一样可调用频道 / 消息等全部业务 API（按角色权限）
4. 管理员可随时禁用 / 启用 / 重新生成 Key / 删除 Bot（`admin.bot.*` 权限）

Python 快速上手见 `sdk/example_bot.py`：

```bash
pip install requests
python3 sdk/example_bot.py --base http://your-host --key bot_xxx...
```

## 客户端 API 地址配置

两个客户端均支持三种方式指定 API 基础地址：

1. URL 参数：`?api=http://host/path`
2. 界面设置面板手动填写
3. 留空使用同源地址

## 数据与备份

- 默认 LocalDriver 下**全部数据都在 `data/`**（用户、频道、消息、私聊、联系人备注、用量统计等 JSON 表）——备份只需打包该目录
- `uploads/` 为上传文件；`logs/` 为运行日志
- `data/`、`*.TODO.md`、`config.local.php` 均在仓库 `.gitignore` 中，不会被提交

## 目录结构

```
├── install.php          # 网页安装向导
├── index.html           # 设备选择页
├── config.php           # 全局配置（权限、配额、通知、存储驱动等）
├── config.local.php     # 安装向导生成的本地配置（不入库，已 gitignore）
├── api/                 # REST API（见下文概览；详见 API_DOCUMENTATION.md）
├── core/                # Database 抽象层
├── drivers/             # 存储驱动：LocalDriver / MySQLDriver / SQLiteDriver / PdoDriver
├── notifications/       # 通知通道：PushPlus / Webhook / Email
├── public/              # 前端
│   ├── index.html            # 桌面 / 手机标准界面 SPA
│   ├── wear_lightchat.html   # 手表 / 小屏穿戴界面
│   └── terms/                # 隐私政策 / 服务条款
├── sdk/                 # Python Bot SDK（lightchat_bot.py + example_bot.py）
├── data/                # JSON 数据存储（自动创建，请定期备份！）
├── uploads/             # 上传文件
├── logs/                # 日志
└── sensitive_words.txt  # 敏感词库（每行一个词，可选）
```

## API 概览

完整请求 / 响应字段见 **[API_DOCUMENTATION.md](API_DOCUMENTATION.md)**。鉴权统一为 Header `Authorization: Bearer <token>`（Bot 用 `X-Bot-Key: bot_xxx`）。

| 模块 | 端点 |
|---|---|
| 安装 / 健康 | `install.php` · `api/health.php` |
| 认证 | `api/token/register.php` · `login.php` · `refresh.php` |
| 频道 | `api/channels/list.php` · `create.php` · `join.php` · `leave.php` · `update.php` · `delete.php` · `invite.php`（成员邀请）· `members.php`（成员/角色）· `read.php`（已读上报） |
| 消息 | `api/messages/send.php` · `history.php` · `poll.php` · `delete.php` |
| 私聊 | `api/private/list.php` · `create.php`（发起/恢复）· `send.php` · `history.php` · `contact.php`（备注/免打扰/删除） |
| 用户 | `api/users/profile.php` · `search.php` · `list.php` · `test_notification.php` |
| 文件 | `api/files/upload.php` |
| 服务器 | `api/server/status.php`（资源配额 + 流量用量） |
| 管理 | `api/admin/audit.php`（审计）· `ban.php`（封禁）· `export.php`（导出） |
| Bot | `api/bot/create.php` · `list.php` |

## 许可

MIT
