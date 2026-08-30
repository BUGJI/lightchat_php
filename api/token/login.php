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
 *
 * 内置暴力破解防护：按 用户名+IP 统计连续失败次数，超限返回 429 锁定。
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

// ── 暴力破解防护 ──
$lpCfg = isset($config['security']['login_protection']) ? $config['security']['login_protection'] : [];
$lpEnabled = !empty($lpCfg['enabled']);
$lpFailDir = rtrim(isset($config['data_path']) ? $config['data_path'] : dirname(__DIR__, 2) . '/data/', '/') . '/login_fail';

function lp_fail_file($dir, $username) {
    return $dir . '/' . md5(strtolower($username) . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')) . '.json';
}

function lp_read($file) {
    if (!file_exists($file)) {
        return ['failures' => 0, 'window_start' => 0, 'locked_until' => 0];
    }
    $fp = @fopen($file, 'r');
    if (!$fp) {
        return ['failures' => 0, 'window_start' => 0, 'locked_until' => 0];
    }
    @flock($fp, LOCK_SH);
    $content = @stream_get_contents($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
    $data = json_decode($content, true);
    return is_array($data) ? $data : ['failures' => 0, 'window_start' => 0, 'locked_until' => 0];
}

function lp_write($file, $data) {
    $fp = @fopen($file, 'c');
    if (!$fp) {
        return;
    }
    @flock($fp, LOCK_EX);
    @ftruncate($fp, 0);
    @fwrite($fp, json_encode($data));
    @fflush($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
}

function lp_record_failure($file, $cfg) {
    $now       = time();
    $windowSec = max(60, (int)($cfg['fail_window'] ?? 10) * 60);
    $maxFail   = max(1, (int)($cfg['max_failures'] ?? 5));
    $lockSec   = (int)($cfg['lockout_minutes'] ?? 15) * 60;

    $data = lp_read($file);
    if ($now - $data['window_start'] > $windowSec) {
        $data = ['failures' => 0, 'window_start' => $now, 'locked_until' => 0];
    }
    $data['failures']++;
    if ($data['failures'] >= $maxFail) {
        $data['locked_until'] = $now + $lockSec;
        $data['failures']     = 0;
    }
    lp_write($file, $data);
}

if ($lpEnabled) {
    if (!is_dir($lpFailDir)) {
        @mkdir($lpFailDir, 0755, true);
    }
    $failFile = lp_fail_file($lpFailDir, $username);
    $failData = lp_read($failFile);
    if ($failData['locked_until'] > time()) {
        $mins = (int)ceil(($failData['locked_until'] - time()) / 60);
        json_response(429, ['error' => 'account_locked', 'message' => "登录失败次数过多，请 {$mins} 分钟后再试"]);
    }
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
    if ($lpEnabled) {
        lp_record_failure($failFile, $lpCfg);
    }
    json_response(401, ['error' => 'invalid_credentials', 'message' => '账号或密码错误']);
}

// ── 检查用户状态 ──
if (isset($user['status']) && (int)$user['status'] !== 1) {
    json_response(403, ['error' => 'account_disabled', 'message' => '账号已被禁用']);
}

// ── 验证密码 ──
if (!password_verify($password, $user['password'])) {
    if ($lpEnabled) {
        lp_record_failure($failFile, $lpCfg);
    }
    json_response(401, ['error' => 'invalid_credentials', 'message' => '用户名或密码错误']);
}

// 登录成功：清除该 用户名+IP 的失败记录
if ($lpEnabled && file_exists($failFile)) {
    @unlink($failFile);
}

// ── 检查封禁（封禁用户即使密码正确也不放行） ──
check_user_banned($db, $user);

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
