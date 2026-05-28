<?php
/**
 * 用户登录
 *
 * POST /api/token/login.php
 *
 * 请求体 JSON:
 *   username  string  用户名
 *   password  string  密码
 *
 * 成功响应 200:
 *   user_id    int     用户ID
 *   username   string  用户名
 *   role       string  角色
 *   token      string  访问令牌
 *   expires_at string  过期时间
 */

require_once __DIR__ . '/../bootstrap.php';

// ── 仅允许 POST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

// ── 读取输入 ──
$input    = get_json_input();
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if ($username === '' || $password === '') {
    json_response(400, ['error' => 'missing_fields', 'message' => '邮箱（或用户名）和密码不能为空']);
}

// ── 查找用户（支持邮箱或用户名登录） ──
try {
    // 包含 @ 则按邮箱查，否则按用户名
    if (strpos($username, '@') !== false) {
        $user = $db->get('users', ['email' => $username]);
    } else {
        $user = $db->get('users', ['username' => $username]);
    }
    // 回退：如果按邮箱没找到，尝试按用户名（用户可能用邮箱前缀当用户名）
    if (!$user && strpos($username, '@') !== false) {
        $user = $db->get('users', ['username' => $username]);
    }
} catch (Exception $e) {
    json_response(500, ['error' => 'db_error', 'message' => '数据库查询失败']);
}

if (!$user) {
    json_response(401, ['error' => 'invalid_credentials', 'message' => '账号或密码错误']);
}

// ── 检查用户状态 ──
if (isset($user['status']) && (int)$user['status'] !== 1) {
    json_response(403, ['error' => 'account_disabled', 'message' => '账号已被禁用']);
}

// ── 验证密码 ──
if (!password_verify($password, $user['password'])) {
    json_response(401, ['error' => 'invalid_credentials', 'message' => '用户名或密码错误']);
}

// ── 检查是否需要 rehash（PHP 算法升级时） ──
if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
    $db->update('users', ['password' => password_hash($password, PASSWORD_BCRYPT)], ['id' => $user['id']]);
}

// ── 生成令牌 ──
$token          = generate_token();
$sessionLifetime = isset($config['user']['session']['lifetime'])
    ? (int)$config['user']['session']['lifetime'] : 3600;
$expiresAt      = date('Y-m-d H:i:s', time() + $sessionLifetime);

try {
    $db->insert('sessions', [
        'user_id'    => $user['id'],
        'token'      => $token,
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'expires_at' => $expiresAt,
    ]);

    // 审计日志：登录
    $db->insert('audit_logs', [
        'user_id'    => $user['id'],
        'username'   => $user['username'],
        'action'     => 'login',
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);
} catch (Exception $e) {
    json_response(500, ['error' => 'session_failed', 'message' => '令牌创建失败']);
}

// ── 更新最后活跃时间 ──
try {
    $db->update('users', ['last_active_at' => date('Y-m-d H:i:s')], ['id' => $user['id']]);
} catch (Exception $e) {
    // 非关键操作，忽略
}

// ── 成功响应 ──
json_response(200, [
    'user_id'    => $user['id'],
    'username'   => $user['username'],
    'role'       => $user['role'] ?? 'member',
    'token'      => $token,
    'expires_at' => $expiresAt,
]);
