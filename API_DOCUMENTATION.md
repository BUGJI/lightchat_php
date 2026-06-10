# LightChat API 文档

> 基于源码逆向生成 · 版本 1.0 · 2026-06-09

---

## 目录

- [1. 概述](#1-概述)
- [2. 通用规范](#2-通用规范)
- [3. 认证与令牌](#3-认证与令牌)
- [4. 系统与健康检查](#4-系统与健康检查)
- [5. 用户管理](#5-用户管理)
- [6. 频道管理](#6-频道管理)
- [7. 频道消息](#7-频道消息)
- [8. 私聊消息](#8-私聊消息)
- [9. 文件上传](#9-文件上传)
- [10. Bot 管理](#10-bot-管理)
- [11. 管理员接口](#11-管理员接口)
- [12. VoceChat 适配器](#12-vocechat-适配器)
- [附录A：通用错误码](#附录a通用错误码)

---

## 1. 概述

LightChat 是一个基于 PHP 7.4 的轻量级即时通讯后端，使用本地 JSON 文件存储 (LocalDriver)。

- **Base URL:** `http://<host>/api`
- **Content-Type:** `application/json; charset=utf-8`
- **CORS:** 支持跨域（`Access-Control-Allow-Origin: *`）
- **认证方式:** Bearer Token（用户会话） 或 `X-Bot-Key`（Bot API Key）

---

## 2. 通用规范

### 2.1 请求格式

| 方法 | Content-Type | 说明 |
|------|-------------|------|
| GET | — | 参数通过 Query String 传递 |
| POST | `application/json` | 参数通过 JSON Body 传递 |
| POST (文件) | `multipart/form-data` | 仅 `/api/files/upload.php` |

### 2.2 认证方式

**用户会话（Bearer Token）：**
```
Authorization: Bearer <token>
```

**Bot API Key：**
```
X-Bot-Key: bot_<random_hex>
```

两种方式在绝大多数需要认证的接口中均可使用（`authenticate()` 函数优先尝试 `X-Bot-Key`，其次尝试 `Bearer`）。

### 2.3 成功响应格式

所有成功响应（除少数特例外）遵循统一结构：
```json
{
  "success": true,
  "message": "ok",
  // ... 具体数据字段
}
```

### 2.4 错误响应格式

```json
{
  "error": "<error_code>",
  "message": "<人类可读信息>"
}
```

常见 HTTP 状态码：

| 状态码 | 含义 |
|--------|------|
| 200 | 成功 |
| 201 | 创建成功（注册、发消息等） |
| 400 | 请求参数错误 |
| 401 | 未认证 / 令牌无效 |
| 403 | 权限不足 |
| 404 | 资源不存在 |
| 405 | 方法不允许 |
| 409 | 冲突（重复创建等） |
| 429 | 频率限制 |
| 500 | 服务器内部错误 |
| 503 | 功能已关闭 |

### 2.5 字段类型说明

| 类型 | 说明 |
|------|------|
| `int` | 整数 |
| `string` | UTF-8 字符串 |
| `bool` | 布尔值 (`true`/`false`) |
| `array` | JSON 数组 |
| `object` | JSON 对象 |
| `string|null` | 字符串或 null |
| `int|null` | 整数或 null |

---

## 3. 认证与令牌

### 3.1 用户注册

**`POST /api/token/register.php`**

创建一个新用户账号并自动登录，返回会话令牌。

**认证：** 无需认证

**请求体：**

```json
{
  "username": "string (3-20位, 字母/数字/下划线/中文)",
  "password": "string (≥6位)",
  "email": "string (必填, 有效邮箱格式)",
  "contact": "string (可选, 联系方式, 最长100字)",
  "account_type": "string (可选, 'user' 或 'bot', 默认 'user')"
}
```

**成功响应 (201)：**

```json
{
  "user_id": 1,
  "username": "zhangsan",
  "token": "a1b2c3d4e5f6...",
  "expires_at": "2026-06-10 03:18:00"
}
```

**错误响应：**

| error | 说明 |
|-------|------|
| `missing_fields` | 用户名或密码为空 |
| `invalid_username` | 用户名长度/字符不符合规则 |
| `reserved_username` | 系统保留用户名 |
| `weak_password` | 密码少于6位 |
| `missing_email` | 邮箱为空 |
| `invalid_email` | 邮箱格式不正确 |
| `duplicate_email` | 邮箱已被注册 |
| `duplicate_username` | 用户名已被注册 |
| `sensitive_word` | 用户名包含敏感词 |
| `invalid_contact` | 联系方式过长 |
| `invalid_account_type` | account_type 无效 |
| `bot_register_disabled` | 自助注册 Bot 已关闭 |
| `db_error` | 数据库查询失败 |
| `insert_failed` | 用户创建失败 |

**curl 示例：**

```bash
curl -X POST http://localhost/api/token/register.php \
  -H "Content-Type: application/json" \
  -d '{
    "username": "zhangsan",
    "password": "mypassword123",
    "email": "zhangsan@example.com",
    "contact": "13800138000"
  }'
```

---

### 3.2 用户登录

**`POST /api/token/login.php`**

支持用户名或邮箱登录。

**认证：** 无需认证

**请求体：**

```json
{
  "username": "string (用户名或邮箱)",
  "password": "string"
}
```

**成功响应 (200)：**

```json
{
  "user_id": 1,
  "username": "zhangsan",
  "role": "member",
  "token": "a1b2c3d4e5f6...",
  "expires_at": "2026-06-10 03:18:00"
}
```

**错误响应：**

| error | 说明 |
|-------|------|
| `missing_fields` | 用户名或密码为空 |
| `invalid_credentials` | 账号或密码错误 |
| `account_disabled` | 账号已被禁用 |
| `db_error` | 数据库查询失败 |
| `session_failed` | 令牌创建失败 |

**curl 示例：**

```bash
# 用户名登录
curl -X POST http://localhost/api/token/login.php \
  -H "Content-Type: application/json" \
  -d '{"username": "zhangsan", "password": "mypassword123"}'

# 邮箱登录
curl -X POST http://localhost/api/token/login.php \
  -H "Content-Type: application/json" \
  -d '{"username": "zhangsan@example.com", "password": "mypassword123"}'
```

---

### 3.3 令牌刷新

**`POST /api/token/refresh.php`**

旧令牌失效，生成新令牌。支持通过 Header 或 Body 传递令牌。

**认证：** 需要令牌（Header 或 Body）

**请求方式一 — Authorization 头：**
```bash
curl -X POST http://localhost/api/token/refresh.php \
  -H "Authorization: Bearer a1b2c3d4e5f6..."
```

**请求方式二 — JSON Body：**
```json
{
  "token": "string (当前令牌)"
}
```

**成功响应 (200)：**

```json
{
  "token": "f7e8d9c0b1a2...",
  "expires_at": "2026-06-10 04:18:00"
}
```

**错误响应：**

| error | 说明 |
|-------|------|
| `missing_token` | 缺少令牌 |
| `invalid_token` | 令牌无效 |
| `token_expired` | 令牌已过期，请重新登录 |
| `user_not_found` | 用户不存在 |
| `account_disabled` | 账号已被禁用 |
| `db_error` | 数据库查询失败 |
| `refresh_failed` | 令牌刷新失败 |

**curl 示例：**

```bash
# Authorization 头方式
curl -X POST http://localhost/api/token/refresh.php \
  -H "Authorization: Bearer a1b2c3d4e5f6..."

# JSON Body 方式
curl -X POST http://localhost/api/token/refresh.php \
  -H "Content-Type: application/json" \
  -d '{"token": "a1b2c3d4e5f6..."}'
```

---

## 4. 系统与健康检查

### 4.1 环境诊断

**`GET /api/health.php`**

无需数据库即可运行，检查 PHP 环境、核心文件、目录权限等。

**认证：** 无需认证

**成功响应 (200)：**

```json
{
  "all_ok": true,
  "base_dir": ".../",
  "checks": {
    "php_version": {
      "ok": true,
      "value": "7.4.33",
      "msg": "OK"
    },
    "ext_json": { "ok": true, "msg": "OK" },
    "ext_mbstring": { "ok": true, "msg": "OK" },
    "ext_pcre": { "ok": true, "msg": "OK" },
    "ext_ctype": { "ok": true, "msg": "OK" },
    "ext_fileinfo": { "ok": true, "msg": "OK" },
    "file_config.php": {
      "ok": true,
      "path": ".../config.php",
      "msg": "OK"
    },
    "file_core/Database.php": { "ok": true, "path": ".../core/Database.php", "msg": "OK" },
    "file_core/DatabaseDriverInterface.php": { "ok": true, "path": ".../core/DatabaseDriverInterface.php", "msg": "OK" },
    "file_drivers/LocalDriver.php": { "ok": true, "path": ".../drivers/LocalDriver.php", "msg": "OK" },
    "file_api/bootstrap.php": { "ok": true, "path": ".../api/bootstrap.php", "msg": "OK" },
    "dir_data/": {
      "ok": true,
      "exists": true,
      "writable": true,
      "path": ".../data/",
      "owner": "www-data",
      "msg": "OK"
    },
    "dir_uploads/": { "ok": true, "exists": true, "writable": true, "path": ".../uploads/", "owner": "www-data", "msg": "OK" },
    "dir_logs/": { "ok": true, "exists": true, "writable": true, "path": ".../logs/", "owner": "www-data", "msg": "OK" },
    "config_load": { "ok": true, "msg": "OK" },
    "config_db_type": { "ok": true, "value": "local", "msg": "OK" }
  },
  "hint": "一切正常，如果仍报错请检查 PHP 错误日志"
}
```

**失败响应 (500)：**

```json
{
  "all_ok": false,
  "base_dir": ".../",
  "checks": { ... },
  "hint": "请修复上面标记为 ❌ 的项"
}
```

**curl 示例：**

```bash
curl http://localhost/api/health.php | python -m json.tool
```

---

### 4.2 服务器资源状态

**`GET /api/server/status.php`**

查看服务资源配额、磁盘用量、PHP 环境信息。认证可选，管理员可见更多信息。

**认证：** 可选（有 Token 则获取用户信息，无 Token 也可访问）

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "quota": {
    "monthly_network_flow_mb": 0,
    "disk_space_mb": 0,
    "max_connections": 0,
    "max_processes": 0,
    "max_upload_mbps": 0,
    "max_download_mbps": 0
  },
  "disk": {
    "app_used_mb": 12.45,
    "data_kb": 1024.0,
    "uploads_kb": 512.0,
    "free_mb": 50000.0,
    "total_mb": 100000.0,
    "used_pct": 50.0
  },
  "php": {
    "version": "7.4.33",
    "memory_limit": "128M",
    "post_max_size": "8M",
    "upload_max_filesize": "2M",
    "max_execution_time": 30
  }
}
```

**字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `quota.monthly_network_flow_mb` | int | 月流量配额(MB) |
| `quota.disk_space_mb` | int | 磁盘配额(MB) |
| `quota.max_connections` | int | 最大连接数 |
| `quota.max_processes` | int | 最大进程数 |
| `quota.max_upload_mbps` | int | 上传带宽上限(Mbps) |
| `quota.max_download_mbps` | int | 下载带宽上限(Mbps) |
| `disk.app_used_mb` | float | 应用已用磁盘(MB) |
| `disk.data_kb` | float | data 目录大小(KB) |
| `disk.uploads_kb` | float | uploads 目录大小(KB) |
| `disk.free_mb` | float | 服务器剩余磁盘(MB)，-1 表示无法获取 |
| `disk.total_mb` | float | 服务器总磁盘(MB)，-1 表示无法获取 |
| `disk.used_pct` | float | 磁盘使用百分比，-1 表示无法获取 |
| `php.version` | string | PHP 版本 |
| `php.memory_limit` | string | 内存限制 |
| `php.post_max_size` | string | POST 最大体积 |
| `php.upload_max_filesize` | string | 上传文件最大体积 |
| `php.max_execution_time` | int | 最大执行时间(秒) |

**curl 示例：**

```bash
# 匿名访问
curl http://localhost/api/server/status.php

# 带认证访问
curl http://localhost/api/server/status.php \
  -H "Authorization: Bearer <token>"
```

---

## 5. 用户管理

### 5.1 查看用户资料

**`GET /api/users/profile.php`**  — 查看自己的资料

**`GET /api/users/profile.php?user_id=<id>`** — 查看他人公开资料

**认证：** 需要认证（自己的需 Bearer Token，他人的可公开查看）

**查看自己 — 响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "user": {
    "id": 1,
    "username": "zhangsan",
    "email": "zhangsan@example.com",
    "avatar": "/uploads/avatar_1_1680000000.jpg",
    "nickname": "张三",
    "bio": "一个普通的用户",
    "signature": "Hello World",
    "role": "member",
    "status": 1,
    "created_at": "2026-05-01 12:00:00",
    "last_active_at": "2026-06-09 15:18:00"
  }
}
```

**查看他人 — 响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "user": {
    "id": 2,
    "username": "lisi",
    "avatar": null,
    "role": "member",
    "join_date": "2026-05-10 08:00:00",
    "status": 1
  }
}
```

他人资料仅返回 `config.user.profile.public_fields` 中配置的字段。

**错误响应：**

| error | 说明 |
|-------|------|
| `not_found` | 用户不存在 |

**curl 示例：**

```bash
# 查看自己
curl http://localhost/api/users/profile.php \
  -H "Authorization: Bearer <token>"

# 查看他人
curl "http://localhost/api/users/profile.php?user_id=2" \
  -H "Authorization: Bearer <token>"
```

---

### 5.2 更新用户资料

**`POST /api/users/profile.php`**

**认证：** 需要认证 + `user.profile.update` 权限

**请求体：**

```json
{
  "nickname": "string (可选, 最多20字)",
  "avatar": "string (可选, data:image/png;base64,...)",
  "bio": "string (可选, 最多200字)",
  "signature": "string (可选, 最多100字)",
  "email": "string (可选, 有效邮箱)",
  "username": "string (可选, 3-20位)",
  "new_password": "string (可选, 修改密码时提供, ≥6位)",
  "old_password": "string (修改密码时必须提供旧密码)"
}
```

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "资料已更新"
}
```

**错误响应：**

| error | 说明 |
|-------|------|
| `forbidden` | 没有修改资料的权限 |
| `invalid_nickname` | 昵称过长 |
| `invalid_bio` | 简介过长 |
| `invalid_signature` | 签名过长 |
| `invalid_email` | 邮箱格式不正确 |
| `duplicate_email` | 邮箱已被使用 |
| `invalid_username` | 用户名不符合规则 |
| `reserved_username` | 系统保留用户名 |
| `duplicate_username` | 用户名已被占用 |
| `wrong_password` | 旧密码不正确 |
| `password_too_short` | 新密码太短 |
| `invalid_avatar` | 头像数据格式/解码失败 |
| `avatar_too_large` | 头像超过1MB |
| `dir_not_writable` | 上传目录不可写 |
| `avatar_save_failed` | 头像保存失败 |
| `update_failed` | 资料更新失败 |

**curl 示例：**

```bash
# 修改昵称和简介
curl -X POST http://localhost/api/users/profile.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"nickname": "新昵称", "bio": "这是我的新简介"}'

# 修改密码
curl -X POST http://localhost/api/users/profile.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"old_password": "oldpass", "new_password": "newpass123"}'

# 上传头像（Base64）
curl -X POST http://localhost/api/users/profile.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d "{\"avatar\": \"data:image/png;base64,$(base64 -w0 avatar.png)\"}"
```

---

### 5.3 用户列表

**`GET /api/users/list.php`**

列出所有已激活用户的公开信息。

**认证：** 需要认证

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "users": [
    {
      "id": 1,
      "username": "zhangsan",
      "nickname": "张三",
      "avatar": "/uploads/avatar_1_1680000000.jpg",
      "role": "member",
      "status": 1,
      "created_at": "2026-05-01 12:00:00"
    },
    {
      "id": 2,
      "username": "lisi",
      "nickname": "李四",
      "avatar": null,
      "role": "vip",
      "status": 1,
      "created_at": "2026-05-10 08:00:00"
    }
  ]
}
```

**字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int | 用户 ID |
| `username` | string | 用户名 |
| `nickname` | string | 昵称（无昵称时显示用户名） |
| `avatar` | string\|null | 头像 URL |
| `role` | string | 角色: `guest`, `member`, `vip`, `admin` |
| `status` | int | 1=正常, 0=禁用 |
| `created_at` | string | 注册时间 |

**curl 示例：**

```bash
curl http://localhost/api/users/list.php \
  -H "Authorization: Bearer <token>"
```

---

### 5.4 搜索用户

**`GET /api/users/search.php?q=<keyword>&limit=<n>`**

按用户名或昵称模糊搜索用户。

**认证：** 需要认证

**查询参数：**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `q` | string | 是 | 搜索关键词（1-30字符） |
| `limit` | int | 否 | 返回数量（1-50，默认 20） |

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "users": [
    {
      "id": 1,
      "username": "zhangsan",
      "nickname": "张三",
      "avatar": "/uploads/avatar_1.jpg",
      "role": "member",
      "signature": "Hello"
    }
  ]
}
```

**错误响应：**

| error | 说明 |
|-------|------|
| `invalid_query` | 搜索关键词长度不在 1-30 范围内 |

**curl 示例：**

```bash
curl "http://localhost/api/users/search.php?q=张三&limit=10" \
  -H "Authorization: Bearer <token>"
```

---

## 6. 频道管理

### 6.1 创建频道

**`POST /api/channels/create.php`**

**认证：** 需要认证 + `channel.create` 权限

**请求体：**

```json
{
  "name": "string (必填, 英文标识, 2-50位, 仅字母/数字/下划线)",
  "display_name": "string (必填, 显示名称)",
  "type": "string (可选, 'public' 或 'private', 默认 'public')",
  "description": "string (可选, 频道描述)"
}
```

**成功响应 (201)：**

```json
{
  "success": true,
  "channel_id": 5,
  "message": "频道创建成功"
}
```

**错误响应：**

| error | 说明 |
|-------|------|
| `forbidden` | 没有创建频道的权限 |
| `limit_reached` | 已达到频道创建上限 |
| `missing_fields` | 频道名称或显示名称为空 |
| `invalid_name` | 频道名称格式/长度不符合 |
| `invalid_type` | type 不是 public 或 private |
| `duplicate_name` | 频道名称已被使用 |
| `create_failed` | 频道创建失败 |

**curl 示例：**

```bash
curl -X POST http://localhost/api/channels/create.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "tech_chat",
    "display_name": "技术交流",
    "type": "public",
    "description": "技术爱好者交流群"
  }'
```

---

### 6.2 频道列表

**`GET /api/channels/list.php`**

**认证：** 可选（游客只能看到 `public` 和 `announcement` 频道）

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "channels": [
    {
      "id": 1,
      "name": "general",
      "display_name": "综合频道",
      "type": "public",
      "description": "默认的综合聊天频道",
      "announcement": null,
      "owner_id": 0,
      "member_count": 15,
      "is_joined": true,
      "created_at": "2026-05-01 12:00:00"
    }
  ]
}
```

**字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int | 频道 ID |
| `name` | string | 频道英文标识 |
| `display_name` | string | 显示名称 |
| `type` | string | `public`, `private`, `announcement` |
| `description` | string | 频道描述 |
| `announcement` | string\|null | 频道公告内容 |
| `owner_id` | int | 创建者用户 ID（0=系统） |
| `member_count` | int | 成员数量 |
| `is_joined` | bool | 当前用户是否已加入（游客为 false） |
| `created_at` | string | 创建时间 |

**curl 示例：**

```bash
curl http://localhost/api/channels/list.php \
  -H "Authorization: Bearer <token>"
```

---

### 6.3 加入频道

**`POST /api/channels/join.php`**

**认证：** 需要认证

**请求体：**

```json
{
  "channel_id": 3
}
```

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "已加入频道"
}
```

**错误响应：**

| error | 说明 |
|-------|------|
| `invalid_channel` | 频道 ID 无效 |
| `not_found` | 频道不存在 |
| `already_joined` | 已经是频道成员 |
| `private_channel` | 私密频道需邀请才能加入 |
| `limit_reached` | 已达到加入上限 |
| `channel_full` | 频道已满 |
| `join_failed` | 加入失败 |

**curl 示例：**

```bash
curl -X POST http://localhost/api/channels/join.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"channel_id": 3}'
```

---

### 6.4 退出频道

**`POST /api/channels/leave.php`**

**认证：** 需要认证

**请求体：**

```json
{
  "channel_id": 3
}
```

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "已退出频道"
}
```

**限制与行为：**
- 系统默认频道（`general`, `announcements`, `help`）不能退出（管理员除外）
- 退出后如频道无成员且非系统频道，将自动删除

**错误响应：**

| error | 说明 |
|-------|------|
| `invalid_channel` | 频道 ID 无效 |
| `not_found` | 频道不存在 |
| `cannot_leave` | 不能退出系统默认频道 |
| `not_member` | 不是频道成员 |
| `leave_failed` | 退出失败 |

**curl 示例：**

```bash
curl -X POST http://localhost/api/channels/leave.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"channel_id": 3}'
```

---

## 7. 频道消息

### 7.1 发送频道消息

**`POST /api/messages/send.php`**

**认证：** 需要认证 + `user.message.send` 权限

**请求体：**

```json
{
  "channel_id": 1,
  "content": "string (消息内容, 文本类必填)",
  "type": "string (可选, 'text'/'image'/'file'/'system', 默认 'text')",
  "parent_id": 0,
  "mentioned_users": [1, 2, 3],
  "file_id": 5
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `channel_id` | int | 是 | 频道 ID |
| `content` | string | 条件必填 | 消息内容（image/file 类型可为空） |
| `type` | string | 否 | 消息类型，默认 `text` |
| `parent_id` | int | 否 | 引用回复的消息 ID（0 表示不引用） |
| `mentioned_users` | array[int] | 否 | @ 的用户 ID 列表（上限由配置决定） |
| `file_id` | int | 否 | 关联的上传文件 ID |

**限制：**
- 文本消息长度 ≤ `config.message.max_length`（默认 500 字符）
- 发送频率 ≥ `config.message.cooldown_seconds`（默认 2 秒，管理员除外）
- 公告频道仅管理员可发言
- 消息内容经过敏感词过滤

**成功响应 (201)：**

```json
{
  "success": true,
  "message_id": 100,
  "content": "Hello World"
}
```

**错误响应：**

| error | 说明 |
|-------|------|
| `forbidden` | 没有发送权限 |
| `invalid_channel` | 频道 ID 无效 |
| `empty_content` | 消息内容为空 |
| `content_too_long` | 消息内容超过长度限制 |
| `not_found` | 频道不存在 |
| `not_member` | 不是频道成员 |
| `cooldown` | 发送过快，需等待 |
| `send_failed` | 消息发送失败 |

**curl 示例：**

```bash
# 发送文本消息
curl -X POST http://localhost/api/messages/send.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"channel_id": 1, "content": "大家好！"}'

# 回复并 @ 他人
curl -X POST http://localhost/api/messages/send.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "channel_id": 1,
    "content": "同意你的观点",
    "parent_id": 99,
    "mentioned_users": [2]
  }'

# 发送文件消息（关联已上传的文件）
curl -X POST http://localhost/api/messages/send.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "channel_id": 1,
    "content": "看看这个文档",
    "type": "file",
    "file_id": 10
  }'
```

---

### 7.2 获取频道消息历史

**`GET /api/messages/history.php?channel_id=<id>&before=<msg_id>&after=<msg_id>&limit=<n>`**

**认证：** 需要认证（非成员只能查看公开频道）

**查询参数：**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `channel_id` | int | 是 | 频道 ID |
| `before` | int | 否 | 获取此 ID 之前的消息（暂未实现精确 before，代码中取最新一批） |
| `after` | int | 否 | 获取此 ID 之后的新消息 |
| `limit` | int | 否 | 返回数量（1-200，默认 50） |

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "messages": [
    {
      "id": 100,
      "channel_id": 1,
      "user_id": 1,
      "username": "zhangsan",
      "avatar": "/uploads/avatar_1.jpg",
      "role": "member",
      "parent_id": 0,
      "type": "text",
      "content": "Hello World",
      "file_url": null,
      "file_size": null,
      "mentioned_users": null,
      "is_deleted": 0,
      "created_at": "2026-06-09 15:10:00"
    }
  ],
  "has_more": true
}
```

**消息对象字段：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int | 消息 ID |
| `channel_id` | int | 频道 ID |
| `user_id` | int | 发送者 ID（0=系统） |
| `username` | string | 发送者用户名 |
| `avatar` | string\|null | 发送者头像 |
| `role` | string\|null | 发送者角色 |
| `parent_id` | int | 引用的消息 ID |
| `type` | string | 消息类型: `text`, `image`, `file`, `system` |
| `content` | string | 消息内容 |
| `file_url` | string\|null | 附件 URL |
| `file_size` | int\|null | 附件大小(字节) |
| `mentioned_users` | string\|null | @ 用户列表（逗号分隔的 ID 字符串） |
| `is_deleted` | int | 是否已软删除（1=已删除，非管理员不可见） |
| `created_at` | string | 发送时间 |
| `has_more` | bool | 是否还有更多消息 |

**curl 示例：**

```bash
# 获取最新 50 条
curl "http://localhost/api/messages/history.php?channel_id=1&limit=50" \
  -H "Authorization: Bearer <token>"

# 获取某条消息之后的新消息
curl "http://localhost/api/messages/history.php?channel_id=1&after=100&limit=20" \
  -H "Authorization: Bearer <token>"
```

---

### 7.3 消息轮询（长轮询）

**`GET /api/messages/poll.php?channels=<ids>&since_id=<id>&private_chat_id=<id>&timeout=<s>`**

用于客户端实时拉取新消息。支持长轮询：有新消息立即返回，无新消息等待直到超时。

**认证：** 需要认证

**查询参数：**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `channels` | string | 条件必填 | 频道 ID 列表（逗号分隔），如 `"1,2,3"` |
| `since_id` | int | 否 | 上次获取的最大消息 ID（不含此 ID 之后的才算新） |
| `private_chat_id` | int | 否 | 私聊会话 ID（与 channels 可同时使用） |
| `timeout` | int | 否 | 长轮询超时秒数（1-30，默认 25） |

> 注意：轮询结果不包含用户自己发送的消息，且不会重复获取 `since_id` 之前的消息。

**有新消息 — 立即返回 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "messages": [
    {
      "id": 105,
      "channel_id": 1,
      "user_id": 2,
      "username": "lisi",
      "avatar": null,
      "parent_id": 0,
      "type": "text",
      "content": "你好",
      "file_url": null,
      "mentioned_users": null,
      "created_at": "2026-06-09 15:18:05"
    },
    {
      "id": 50,
      "private_chat_id": 3,
      "from_user_id": 3,
      "username": "wangwu",
      "avatar": "/uploads/avatar_3.jpg",
      "type": "text",
      "content": "私聊消息",
      "file_url": null,
      "file_size": 0,
      "is_read": 0,
      "created_at": "2026-06-09 15:18:10"
    }
  ],
  "latest_id": 105
}
```

**私聊消息额外字段：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `private_chat_id` | int | 私聊会话 ID |
| `from_user_id` | int | 发送者 ID |
| `is_read` | int | 是否已读（poll 返回时自动标记为已读） |
| `file_size` | int | 附件大小(字节) |

**超时无新消息 — 返回空 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "messages": [],
  "latest_id": 104
}
```

**curl 示例：**

```bash
# 长轮询频道1,2的新消息
curl "http://localhost/api/messages/poll.php?channels=1,2&since_id=104&timeout=25" \
  -H "Authorization: Bearer <token>"

# 同时轮询频道和私聊
curl "http://localhost/api/messages/poll.php?channels=1,2&since_id=104&private_chat_id=3&timeout=25" \
  -H "Authorization: Bearer <token>"
```

---

### 7.4 删除消息

**`POST /api/messages/delete.php`**

软删除（标记 `is_deleted=1` 而非物理删除）。

**认证：** 需要认证

**权限：**
- 用户需要 `user.message.delete` 权限才能删除自己的消息
- 管理员需要 `admin.message.delete` 权限才能删除任何人的消息

**请求体：**

```json
{
  "message_id": 100
}
```

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "消息已删除"
}
```

**错误响应：**

| error | 说明 |
|-------|------|
| `invalid_message` | 消息 ID 无效 |
| `not_found` | 消息不存在 |
| `forbidden` | 没有删除权限 |
| `delete_failed` | 删除失败 |

**curl 示例：**

```bash
curl -X POST http://localhost/api/messages/delete.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"message_id": 100}'
```

---

## 8. 私聊消息

### 8.1 发送私聊消息

**`POST /api/private/send.php`**

首次发送会自动创建私聊会话（`private_chats` 表）。

**认证：** 需要认证 + `user.message.send` 权限

**请求体：**

```json
{
  "to_user_id": 2,
  "content": "string (消息内容)",
  "type": "string (可选, 'text'/'image'/'file', 默认 'text')",
  "file_id": 10
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `to_user_id` | int | 是 | 接收方用户 ID |
| `content` | string | 条件必填 | 消息内容（image/file 可为空） |
| `type` | string | 否 | 消息类型 |
| `file_id` | int | 否 | 关联的上传文件 ID |

**限制：**
- 不能给自己发私聊
- 消息长度 ≤ `config.message.max_length`

**成功响应 (201)：**

```json
{
  "success": true,
  "message_id": 50,
  "chat_id": 3,
  "content": "私聊内容"
}
```

**错误响应：**

| error | 说明 |
|-------|------|
| `forbidden` | 没有发送权限 |
| `invalid_user` | 接收方 ID 无效 |
| `self_message` | 不能给自己发私信 |
| `empty_content` | 消息内容为空 |
| `content_too_long` | 消息内容过长 |
| `not_found` | 接收方不存在 |
| `send_failed` | 发送失败 |

**curl 示例：**

```bash
curl -X POST http://localhost/api/private/send.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"to_user_id": 2, "content": "你好，李四！"}'
```

---

### 8.2 获取私聊消息历史

**`GET /api/private/history.php?chat_id=<id>&before=<msg_id>&limit=<n>`**

**认证：** 需要认证（必须是会话参与者）

**查询参数：**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `chat_id` | int | 是 | 私聊会话 ID |
| `before` | int | 否 | 获取此 ID 之前的消息（暂为取最新，未精确 before） |
| `limit` | int | 否 | 返回数量（1-200，默认 50） |

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "messages": [
    {
      "id": 48,
      "chat_id": 3,
      "from_user_id": 1,
      "to_user_id": 2,
      "username": "zhangsan",
      "avatar": "/uploads/avatar_1.jpg",
      "type": "text",
      "content": "你好",
      "file_url": null,
      "file_size": 0,
      "is_read": 1,
      "created_at": "2026-06-09 15:15:00"
    },
    {
      "id": 49,
      "chat_id": 3,
      "from_user_id": 2,
      "to_user_id": 1,
      "username": "lisi",
      "avatar": null,
      "type": "text",
      "content": "你好啊！",
      "file_url": null,
      "file_size": 0,
      "is_read": 1,
      "created_at": "2026-06-09 15:16:00"
    }
  ],
  "has_more": false
}
```

> 注意：查看历史时，对方发给当前用户的未读消息会自动标记为已读。

**错误响应：**

| error | 说明 |
|-------|------|
| `invalid_chat` | 私聊会话 ID 无效 |
| `not_found` | 私聊会话不存在 |
| `forbidden` | 不在该私聊中 |

**curl 示例：**

```bash
curl "http://localhost/api/private/history.php?chat_id=3&limit=50" \
  -H "Authorization: Bearer <token>"
```

---

### 8.3 私聊会话列表

**`GET /api/private/list.php`**

返回当前用户参与的所有私聊会话，按最后消息时间倒序。

**认证：** 需要认证

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "chats": [
    {
      "id": 3,
      "other_user_id": 2,
      "other_username": "lisi",
      "other_avatar": null,
      "other_role": "member",
      "last_message": "你好啊！",
      "last_message_at": "2026-06-09 15:16:00",
      "unread_count": 2,
      "created_at": "2026-06-09 15:15:00"
    }
  ]
}
```

**字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int | 私聊会话 ID |
| `other_user_id` | int | 对方用户 ID |
| `other_username` | string | 对方用户名 |
| `other_avatar` | string\|null | 对方头像 |
| `other_role` | string\|null | 对方角色 |
| `last_message` | string | 最后一条消息预览 |
| `last_message_at` | string | 最后消息时间 |
| `unread_count` | int | 未读消息数（对方发给你的） |
| `created_at` | string | 会话创建时间 |

**curl 示例：**

```bash
curl http://localhost/api/private/list.php \
  -H "Authorization: Bearer <token>"
```

---

## 9. 文件上传

### 9.1 上传文件

**`POST /api/files/upload.php`**

`multipart/form-data` 方式上传文件。支持内容去重（MD5 哈希）。

**认证：** 需要认证 + `user.file.upload` 权限

**表单字段：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `file` | File | 上传的文件 |

**限制：**
- 需要 `config.upload.enabled = true`
- 文件类型/大小限制由配置的 `file_types` 决定
- 支持的类型分类：`image`, `audio`, `video`, `file`（各有不同的扩展名及 MIME 白名单）

**成功响应 (201)：**

```json
{
  "success": true,
  "file_id": 10,
  "file_url": "/uploads/20260609_a1b2c3d4e5f6.jpg",
  "file_name": "photo.jpg",
  "file_size": 204800,
  "file_type": "image",
  "mime_type": "image/jpeg"
}
```

**去重成功响应 (200)：**

当上传文件与已有文件内容相同（MD5 一致）时返回：

```json
{
  "success": true,
  "file_id": 5,
  "file_url": "/uploads/20260608_existing.jpg",
  "file_name": "photo.jpg",
  "file_size": 204800,
  "file_type": "image",
  "mime_type": "image/jpeg",
  "duplicate": true
}
```

**错误响应：**

| error | 说明 |
|-------|------|
| `forbidden` | 没有上传权限 |
| `upload_disabled` | 上传功能已关闭 |
| `upload_failed` | 文件上传失败（文件过大/不完整/无文件等） |
| `unsupported_type` | 不支持的文件类型 |
| `file_too_large` | 文件超过大小限制 |
| `dir_failed` | 上传目录创建失败 |
| `dir_not_writable` | 上传目录不可写 |
| `save_failed` | 文件保存失败 |
| `record_failed` | 文件记录保存失败 |

**curl 示例：**

```bash
curl -X POST http://localhost/api/files/upload.php \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/to/photo.jpg"
```

---

## 10. Bot 管理

### 10.1 创建 Bot

**`POST /api/bot/create.php`**

**认证：** 需要认证 + `admin.bot.create` 权限（通常管理员）

Bot 用户名自动加 `bot_` 前缀以避免和普通用户冲突。

**请求体：**

```json
{
  "username": "string (必填, Bot 用户名, 自动加 bot_ 前缀)",
  "name": "string (可选, Bot 名称/描述, 默认等于 username)"
}
```

**成功响应 (201)：**

```json
{
  "success": true,
  "message": "Bot 创建成功",
  "user_id": 10,
  "username": "bot_mybot",
  "api_key": "bot_a1b2c3d4e5f6...",
  "hint": "请求时在 Header 中加入 X-Bot-Key: bot_a1b2c3d4e5f6..."
}
```

**使用方式：** Bot 通过 `X-Bot-Key` 请求头调用 API，与普通用户 Bearer Token 权限等价。

**错误响应：**

| error | 说明 |
|-------|------|
| `forbidden` | 没有创建 Bot 的权限 |
| `missing_username` | Bot 用户名为空 |
| `invalid_username` | Bot 用户名太短 |
| `duplicate` | Bot 用户名已存在 |

**curl 示例：**

```bash
curl -X POST http://localhost/api/bot/create.php \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"username": "weather", "name": "天气播报机器人"}'
```

---

### 10.2 Bot 列表

**`GET /api/bot/list.php`** — 列出所有 Bot

**`GET /api/bot/list.php?id=<bot_id>`** — 查看单个 Bot 详情

**认证：** 需要认证 + `admin.bot.manage` 权限

**列出所有 Bot — 响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "bots": [
    {
      "id": 10,
      "username": "bot_weather",
      "status": 1,
      "has_active_key": true,
      "last_used_at": "2026-06-09 15:00:00",
      "created_at": "2026-05-20 10:00:00"
    }
  ],
  "count": 1
}
```

**单个 Bot 详情 — 响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "bot": {
    "id": 10,
    "username": "bot_weather",
    "status": 1
  },
  "keys": [
    {
      "id": 5,
      "api_key": "bot_a1b2c3d4...",
      "name": "天气播报机器人",
      "active": 1,
      "last_used_at": "2026-06-09 15:00:00",
      "created_at": "2026-05-20 10:00:00"
    }
  ]
}
```

---

### 10.3 Bot 管理操作

**`POST /api/bot/list.php`** — 切换启用/禁用 或 重新生成 API Key

**认证：** 需要认证 + `admin.bot.manage` 权限

**操作1：切换启用/禁用**

```json
{
  "action": "toggle",
  "bot_id": 10
}
```

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "Bot 已禁用",
  "status": 0
}
```

> 禁用 Bot 时会同步禁用其所有 API Key。启用时会恢复最近一个 Key。

**操作2：重新生成 API Key**

```json
{
  "action": "regenerate",
  "bot_id": 10,
  "name": "再生_20260609"
}
```

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "API Key 已重新生成",
  "api_key": "bot_f7e8d9c0b1a2..."
}
```

> 旧 API Key 会被禁用，新 Key 立即生效。

**curl 示例：**

```bash
# 切换启用/禁用
curl -X POST http://localhost/api/bot/list.php \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"action": "toggle", "bot_id": 10}'

# 重新生成 Key
curl -X POST http://localhost/api/bot/list.php \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"action": "regenerate", "bot_id": 10, "name": "新Key"}'
```

---

## 11. 管理员接口

### 11.1 导出数据

**`GET /api/admin/export.php?type=<type>&format=<format>`**

导出系统数据用于合规留存，返回 JSON 下载文件。

**认证：** 需要 admin 角色

**查询参数：**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `type` | string | 否 | 导出类型: `messages`, `private_messages`, `users`, `sessions`, `all`（默认 `all`） |
| `format` | string | 否 | 输出格式（目前仅支持 `json`） |

**成功响应 (200)：** Content-Disposition 为附件下载

```json
{
  "export_type": "all",
  "exported_at": "2026-06-09 15:18:00",
  "data": {
    "channel_messages": [
      {
        "id": 1,
        "channel_id": 1,
        "sender_id": 1,
        "sender_name": "zhangsan",
        "type": "text",
        "content": "Hello",
        "file_url": null,
        "is_deleted": 0,
        "created_at": "2026-06-09 15:10:00"
      }
    ],
    "private_messages": [
      {
        "id": 1,
        "chat_id": 1,
        "from_user_id": 1,
        "from_username": "zhangsan",
        "to_user_id": 2,
        "to_username": "lisi",
        "content": "私聊",
        "is_deleted": 0,
        "created_at": "2026-06-09 15:12:00"
      }
    ],
    "users": [
      {
        "id": 1,
        "username": "zhangsan",
        "email": "zhangsan@example.com",
        "contact": "13800138000",
        "reg_ip": "192.168.1.1",
        "role": "member",
        "status": 1,
        "last_active": "2026-06-09 15:18:00",
        "created_at": "2026-05-01 12:00:00"
      }
    ],
    "sessions": [
      {
        "id": 1,
        "user_id": 1,
        "username": "zhangsan",
        "ip": "192.168.1.1",
        "user_agent": "Mozilla/5.0 ...",
        "expires_at": "2026-06-10 03:18:00",
        "created_at": "2026-06-09 15:18:00"
      }
    ]
  }
}
```

> ⚠️ 导出包含用户邮箱、联系方式、IP 等敏感信息，请妥善保管。

**curl 示例：**

```bash
# 导出全部数据
curl "http://localhost/api/admin/export.php?type=all&format=json" \
  -H "Authorization: Bearer <admin_token>" \
  -o export.json

# 仅导出频道消息
curl "http://localhost/api/admin/export.php?type=messages&format=json" \
  -H "Authorization: Bearer <admin_token>" \
  -o messages.json
```

---

### 11.2 审计日志

**`GET /api/admin/audit.php`**

**`GET /api/admin/audit.php?action=<action>&user_id=<id>&limit=<n>&export=1`**

查看和导出审计日志。

**认证：** 需要 admin 角色

**查询参数：**

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `action` | string | 否 | 按操作类型筛选: `login`, `register`, `logout` 等 |
| `user_id` | int | 否 | 按用户 ID 筛选 |
| `limit` | int | 否 | 返回数量（1-500，默认 100） |
| `export` | int | 否 | 设为 1 时以 JSON 文件下载 |

**成功响应 (200)：**

```json
{
  "success": true,
  "message": "ok",
  "stats": {
    "total_logs": 150,
    "action_counts": {
      "login": 80,
      "register": 10,
      "logout": 60
    }
  },
  "logs": [
    {
      "id": 150,
      "user_id": 1,
      "username": "zhangsan",
      "action": "login",
      "detail": null,
      "ip": "192.168.1.1",
      "user_agent": "Mozilla/5.0 ...",
      "created_at": "2026-06-09 15:18:00"
    }
  ],
  "count": 80,
  "params": {
    "action": "login",
    "user_id": "(all)",
    "limit": 100
  }
}
```

**字段说明：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `stats.total_logs` | int | 日志总数 |
| `stats.action_counts` | object | 各操作类型计数 |
| `logs[].id` | int | 日志 ID |
| `logs[].user_id` | int | 用户 ID |
| `logs[].username` | string | 用户名 |
| `logs[].action` | string | 操作类型 |
| `logs[].detail` | string\|null | 操作详情（JSON 字符串） |
| `logs[].ip` | string | IP 地址 |
| `logs[].user_agent` | string | 浏览器 UA |
| `logs[].created_at` | string | 时间 |
| `count` | int | 当前筛选结果数量 |

**curl 示例：**

```bash
# 查看所有日志
curl "http://localhost/api/admin/audit.php" \
  -H "Authorization: Bearer <admin_token>"

# 筛选登录日志
curl "http://localhost/api/admin/audit.php?action=login&limit=50" \
  -H "Authorization: Bearer <admin_token>"

# 筛选特定用户
curl "http://localhost/api/admin/audit.php?user_id=1&limit=50" \
  -H "Authorization: Bearer <admin_token>"

# 导出日志
curl "http://localhost/api/admin/audit.php?export=1" \
  -H "Authorization: Bearer <admin_token>" \
  -o audit.json
```

---

## 12. VoceChat 适配器

**入口文件：** `/api/voce_adapter.php`

让 VoceChat 手表客户端 (`vocechat_web_wear`) 对接 LightChat 后端。VoceChat 格式的请求会被转换为 LightChat API 调用。

**URL 重写规则：**

```apache
# Apache (.htaccess)
RewriteRule ^api/(.*)$ /api/voce_adapter.php/$1 [QSA,L]
```

```nginx
# Nginx
location /api/ {
    rewrite ^/api/(.*)$ /api/voce_adapter.php/$1 last;
}
```

### 12.1 端点映射

| VoceChat 端点 | 方法 | LightChat 后端 |
|---------------|------|----------------|
| `/token/login` | POST | `/api/token/login.php` |
| `/token/renew` | POST | `/api/token/refresh.php` |
| `/token/logout` | POST | 直接删除 session |
| `/user` | GET | `/api/users/list.php` |
| `/user/profile` | GET | 数据库直查 |
| `/user/profile?user_id=` | GET | 数据库直查 |
| `/user/search?q=` | GET | `/api/users/list.php` + 过滤 |
| `/user/register` | POST | `/api/token/register.php` |
| `/user/contacts` | GET | 从数据库构建 |
| `/user/contacts/add/{uid}` | POST | 创建私聊会话 |
| `/user/{uid}/history` | GET | `/api/private/history.php` |
| `/user/{uid}/send` | POST | `/api/private/send.php` |
| `/group` | GET | `/api/channels/list.php` |
| `/group/create` | POST | 数据库建频道 |
| `/group/{gid}/members` | GET | 数据库查成员 |
| `/group/{gid}/join` | POST | 加入频道 |
| `/group/{gid}/leave` | POST | 退出频道 |
| `/group/{gid}/history` | GET | `/api/messages/history.php` |
| `/group/{gid}/send` | POST | `/api/messages/send.php` |
| `/resource/avatar` | GET | 404（客户端降级文字头像） |
| `/resource/group_avatar` | GET | 404 |
| `/resource/file?file_path=` | GET | 提供 uploads 目录文件 |
| `/resource/localization` | GET | 空对象 `{}` |
| `/system/info` | GET | 服务器信息 |
| `/user/events` | GET | SSE 流长连接 |
| `/admin/system/initialized` | GET | `true` |

### 12.2 VoceChat 认证转换

VoceChat 客户端的 `X-API-Key` 请求头自动转换为 LightChat 的 `Authorization: Bearer`。

### 12.3 VoceChat 响应格式

适配器将 LightChat 响应转换为 VoceChat 客户端的期望格式：

**登录响应转换：**

```json
{
  "token": "a1b2c3...",
  "refresh_token": "a1b2c3...",
  "user": {
    "uid": 1,
    "name": "zhangsan",
    "email": ""
  }
}
```

**用户列表转换：**

```json
[
  {
    "uid": 1,
    "name": "张三",
    "email": "",
    "avatar": "",
    "status": "normal",
    "is_online": false,
    "is_contact": false
  }
]
```

**频道列表转换：**

```json
[
  {
    "gid": 1,
    "name": "技术交流",
    "description": "技术爱好者交流群",
    "owner": 1,
    "member_count": 15,
    "is_public": true,
    "is_joined": true
  }
]
```

**消息转换（VoceChat 格式）：**

```json
[
  {
    "mid": 100,
    "created_at": 1686300000000,
    "from_uid": 1,
    "from_name": "zhangsan",
    "detail": {
      "type": "normal",
      "content_type": "text/plain",
      "content": "Hello"
    },
    "properties": []
  }
]
```

### 12.4 SSE 事件流 (`/user/events`)

服务端推送事件流，VoceChat 手表客户端通过 `EventSource` 连接。

**连接响应：**

```
Content-Type: text/event-stream
Cache-Control: no-cache
Connection: keep-alive

event: connected
data: {}

data: {"mid":105,"created_at":1686301085000,"from_uid":2,"from_name":"lisi","chat_type":"group","to_gid":1,"detail":{"type":"normal","content_type":"text/plain","content":"你好"},"properties":[]}

event: heartbeat
data: {}
```

- 每 3 秒轮询一次数据库
- 有新消息立即推送 `data:` 行
- 55 秒超时后发送 heartbeat 并断开
- 客户端需重连

### 12.5 `/resource/file` 文件服务

提供 uploads 目录中的文件给 VoceChat 客户端下载。

```bash
curl "http://localhost/api/resource/file?file_path=/uploads/20260609_abc.jpg"
# 响应以 Content-Type: image/jpeg 返回文件内容
```

---

## 附录A：通用错误码

| error | HTTP | 说明 |
|-------|------|------|
| `unauthorized` | 401 | 未提供认证信息，请先登录 |
| `invalid_token` | 401 | 令牌无效，请重新登录 |
| `token_expired` | 401 | 令牌已过期，请重新登录 |
| `invalid_bot_key` | 401 | Bot Key 无效或已禁用 |
| `user_not_found` | 401 | 用户不存在 |
| `account_disabled` | 403 | 账号已被禁用 |
| `forbidden` | 403 | 权限不足 |
| `method_not_allowed` | 405 | 不支持的 HTTP 方法 |
| `missing_fields` | 400 | 缺少必填字段 |
| `invalid_json` | 400 | 请求体不是有效 JSON |
| `not_found` | 404 | 资源不存在 |
| `duplicate_*` | 409 | 资源重复（用户名/邮箱/频道名等） |
| `limit_reached` | 400 | 达到配额上限 |
| `db_error` | 500 | 数据库查询失败 |
| `*_failed` | 500 | 服务器操作失败（创建/更新/删除等） |

### 角色层次

| 角色 | 级别 | 继承 |
|------|------|------|
| `guest` | 0 | — |
| `member` | 1 | guest |
| `vip` | 2 | member |
| `admin` | 3 | vip |

### Token 自动续期

当令牌剩余有效期不足原始配置的 50% 时，任意需要认证的接口可能返回以下响应头：

```
X-Token-Refreshed: 1
X-Token-Expires: 2026-06-10 05:18:00
```

客户端应检测此头并更新本地存储的令牌。

---

> **文档版本：** 1.0
> **生成日期：** 2026-06-09
> **基于：** LightChat 源码（`/api/` 目录下所有 PHP 文件）
