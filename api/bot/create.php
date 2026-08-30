<?php
/**
 * 创建 Bot 账号
 *
 * POST /api/bot/create.php
 *
 * 请求体 JSON（admin 权限）:
 *   username     string  Bot 用户名（展示用）
 *   name         string  Bot 名称/描述
 *
 * 成功响应 201:
 *   user_id      int     用户 ID
 *   api_key      string  永久 API Key（之后用 X-Bot-Key 头携带）
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

$user = authenticate();

if (!has_permission($user, 'admin.bot.create') && !role_at_least($user['role'], 'admin')) {
    json_response(403, ['error' => 'forbidden', 'message' => '您没有创建 Bot 的权限']);
}

$input    = get_json_input();
$username = trim($input['username'] ?? '');
$botName  = trim($input['name'] ?? $username);

if ($username === '') {
    json_response(400, ['error' => 'missing_username', 'message' => 'Bot 用户名不能为空']);
}

// 自动加 bot_ 前缀避免和普通用户冲突
$botUsername = 'bot_' . preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '', $username);
$uCfg = isset($config['user']['username']) ? $config['user']['username'] : [];
$uMin = isset($uCfg['min_length']) ? (int)$uCfg['min_length'] : 3;
$uMax = isset($uCfg['max_length']) ? (int)$uCfg['max_length'] : 20;
$uLen = mb_strlen($botUsername, 'UTF-8');
if ($uLen < $uMin || $uLen > $uMax) {
    json_response(400, ['error' => 'invalid_username', 'message' => "Bot 用户名长度应为 {$uMin}-{$uMax} 个字符"]);
}

// 检查是否已存在
$existing = $db->get('users', ['username' => $botUsername]);
if ($existing) {
    json_response(409, ['error' => 'duplicate', 'message' => '该 Bot 用户名已存在']);
}

// ── 创建用户（bot 类型，记录创建者） ──
$userId = $db->insert('users', [
    'username'     => $botUsername,
    'password'     => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
    'account_type' => 'bot',
    'role'         => 'member',
    'status'       => 1,
    'created_by'   => $user['id'],
]);

// ── 生成永久 API Key ──
$apiKey = 'bot_' . bin2hex(random_bytes(24)); // 48 位 hex + bot_ 前缀

$db->insert('bot_keys', [
    'user_id' => $userId,
    'api_key' => $apiKey,
    'name'    => $botName,
    'active'  => 1,
]);

// 审计日志
$db->insert('audit_logs', [
    'user_id'     => $user['id'],
    'username'    => $user['username'],
    'action'      => 'bot.create',
    'target_type' => 'bot',
    'target_id'   => $userId,
    'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'detail'      => json_encode(['bot_username' => $botUsername, 'creator_id' => $user['id']], JSON_UNESCAPED_UNICODE),
]);

json_response(201, [
    'success'    => true,
    'message'    => 'Bot 创建成功',
    'user_id'    => $userId,
    'username'   => $botUsername,
    'api_key'    => $apiKey,
    'creator_id' => (int)$user['id'],
    'hint'       => '请求时在 Header 中加入 X-Bot-Key: ' . $apiKey,
]);
