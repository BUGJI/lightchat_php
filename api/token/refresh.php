<?php
/**
 * 令牌刷新
 *
 * POST /api/token/refresh.php
 *
 * 请求头:
 *   Authorization: Bearer {token}
 * 或请求体 JSON:
 *   token  string  当前令牌
 *
 * 成功响应 200:
 *   token      string  新令牌
 *   expires_at string  新过期时间
 */

require_once __DIR__ . '/../bootstrap.php';

// ── 仅允许 POST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

// ── 提取令牌（优先请求头，其次请求体） ──
$token = '';

// 从 Authorization 头提取
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        $token = trim($m[1]);
    }
}

// 从请求体提取（fallback）
if ($token === '') {
    $input = get_json_input();
    $token = trim($input['token'] ?? '');
}

if ($token === '') {
    json_response(400, ['error' => 'missing_token', 'message' => '缺少令牌']);
}

// ── 查询会话 ──
try {
    $session = $db->get('sessions', ['token' => $token]);
} catch (Exception $e) {
    json_response(500, ['error' => 'db_error', 'message' => '数据库查询失败']);
}

if (!$session) {
    json_response(401, ['error' => 'invalid_token', 'message' => '令牌无效']);
}

// ── 检查过期 ──
if (isset($session['expires_at']) && strtotime($session['expires_at']) < time()) {
    // 令牌已过期，清理
    try {
        $db->delete('sessions', ['token' => $token]);
    } catch (Exception $e) {
        // 忽略清理错误
    }
    json_response(401, ['error' => 'token_expired', 'message' => '令牌已过期，请重新登录']);
}

// ── 检查用户状态 ──
try {
    $user = $db->get('users', ['id' => $session['user_id']]);
} catch (Exception $e) {
    json_response(500, ['error' => 'db_error', 'message' => '数据库查询失败']);
}

if (!$user) {
    json_response(401, ['error' => 'user_not_found', 'message' => '用户不存在']);
}

if (isset($user['status']) && (int)$user['status'] !== 1) {
    // 用户被禁用，清理所有会话
    try {
        $db->delete('sessions', ['user_id' => $user['id']]);
    } catch (Exception $e) {
        // 忽略
    }
    json_response(403, ['error' => 'account_disabled', 'message' => '账号已被禁用']);
}

// ── 生成新令牌 ──
$newToken       = generate_token();
$sessionLifetime = isset($config['user']['session']['lifetime'])
    ? (int)$config['user']['session']['lifetime'] : 3600;
$expiresAt      = date('Y-m-d H:i:s', time() + $sessionLifetime);

// ── 事务：删除旧令牌，创建新令牌 ──
try {
    $db->beginTransaction();
    $db->delete('sessions', ['token' => $token]);
    $db->insert('sessions', [
        'user_id'    => $session['user_id'],
        'token'      => $newToken,
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'expires_at' => $expiresAt,
    ]);
    $db->commit();
} catch (Exception $e) {
    try {
        $db->rollback();
    } catch (Exception $rollbackEx) {
        // 忽略
    }
    json_response(500, ['error' => 'refresh_failed', 'message' => '令牌刷新失败']);
}

// ── 更新最后活跃时间 ──
try {
    $db->update('users', ['last_active_at' => date('Y-m-d H:i:s')], ['id' => $user['id']]);
} catch (Exception $e) {
    // 非关键操作
}

// ── 成功响应 ──
json_response(200, [
    'token'      => $newToken,
    'expires_at' => $expiresAt,
]);
