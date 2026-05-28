<?php
/**
 * 用户注册
 *
 * POST /api/token/register.php
 *
 * 请求体 JSON:
 *   username  string  用户名（3-20位，字母/数字/下划线/中文）
 *   password  string  密码（6位以上）
 *   email     string  邮箱（可选）
 *
 * 成功响应 201:
 *   user_id   int     新用户ID
 *   username  string  用户名
 *   token     string  自动登录令牌
 *   expires_at string 令牌过期时间
 */

require_once __DIR__ . '/../bootstrap.php';

// ── 仅允许 POST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

// ── 读取输入 ──
$input = get_json_input();
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';
$email    = trim($input['email'] ?? '');

// ── 参数校验 ──
if ($username === '' || $password === '') {
    json_response(400, ['error' => 'missing_fields', 'message' => '用户名和密码不能为空']);
}

// 用户名规则（来自 config，不存在配置时使用默认值）
$userCfg = isset($config['user']) ? $config['user'] : [];
$usernameCfg = isset($userCfg['username']) ? $userCfg['username'] : [];
$minLen = isset($usernameCfg['min_length']) ? (int)$usernameCfg['min_length'] : 3;
$maxLen = isset($usernameCfg['max_length']) ? (int)$usernameCfg['max_length'] : 20;
$pattern = isset($usernameCfg['pattern']) ? $usernameCfg['pattern'] : '/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u';
$reserved = isset($usernameCfg['reserved']) ? $usernameCfg['reserved'] : [];

$len = mb_strlen($username, 'UTF-8');
if ($len < $minLen || $len > $maxLen) {
    json_response(400, [
        'error'   => 'invalid_username',
        'message' => "用户名长度应为 {$minLen}-{$maxLen} 个字符",
    ]);
}

if (!preg_match($pattern, $username)) {
    json_response(400, [
        'error'   => 'invalid_username',
        'message' => '用户名包含不允许的字符',
    ]);
}

if (in_array(strtolower($username), $reserved)) {
    json_response(400, [
        'error'   => 'reserved_username',
        'message' => '该用户名为系统保留，不可使用',
    ]);
}

if (mb_strlen($password, 'UTF-8') < 6) {
    json_response(400, ['error' => 'weak_password', 'message' => '密码长度不能少于 6 位']);
}

// 邮箱格式（如果提供）
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(400, ['error' => 'invalid_email', 'message' => '邮箱格式不正确']);
}

// ── 敏感词检查 ──
$sensitiveEnabled = isset($config['message']['sensitive_words_enabled'])
    ? $config['message']['sensitive_words_enabled'] : false;
if ($sensitiveEnabled) {
    $wordsFile = isset($config['message']['sensitive_words_file'])
        ? $config['message']['sensitive_words_file']
        : __DIR__ . '/../../sensitive_words.txt';

    if (file_exists($wordsFile)) {
        $words = file($wordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($words as $word) {
            $word = trim($word);
            if ($word !== '' && mb_stripos($username, $word) !== false) {
                json_response(400, [
                    'error'   => 'sensitive_word',
                    'message' => '用户名包含敏感词汇',
                ]);
            }
        }
    }
}

// ── 检查用户名是否已存在 ──
try {
    $existing = $db->get('users', ['username' => $username]);
    if ($existing) {
        json_response(409, ['error' => 'duplicate_username', 'message' => '用户名已被注册']);
    }

    // 检查邮箱（如果提供）
    if ($email !== '') {
        $existingEmail = $db->get('users', ['email' => $email]);
        if ($existingEmail) {
            json_response(409, ['error' => 'duplicate_email', 'message' => '邮箱已被注册']);
        }
    }
} catch (Exception $e) {
    json_response(500, ['error' => 'db_error', 'message' => '数据库查询失败']);
}

// ── 创建用户 ──
$passwordHash = password_hash($password, PASSWORD_BCRYPT);

$userData = [
    'username' => $username,
    'password' => $passwordHash,
    'email'    => $email,
    'role'     => isset($config['user']['default_role']) ? $config['user']['default_role'] : 'member',
    'status'   => 1,
];

try {
    $userId = $db->insert('users', $userData);
} catch (Exception $e) {
    json_response(500, ['error' => 'insert_failed', 'message' => '用户创建失败']);
}

// ── 自动加入默认频道 ──
try {
    $defaultChannels = isset($config['chat']['channel']['default_channels'])
        ? $config['chat']['channel']['default_channels'] : [];
    foreach ($defaultChannels as $dc) {
        $ch = $db->get('channels', ['name' => $dc['name']]);
        if ($ch) {
            $alreadyJoined = $db->get('channel_members', [
                'channel_id' => $ch['id'],
                'user_id'    => $userId,
            ]);
            if (!$alreadyJoined) {
                $db->insert('channel_members', [
                    'channel_id' => $ch['id'],
                    'user_id'    => $userId,
                    'role'       => 'member',
                ]);
                // 更新频道成员数
                $newCount = $db->count('channel_members', ['channel_id' => $ch['id']]);
                $db->update('channels', ['member_count' => $newCount], ['id' => $ch['id']]);
            }
        }
    }
} catch (Exception $e) {
    // 非关键操作，失败不影响注册
}

// ── 自动登录：生成令牌 ──
$token      = generate_token();
$sessionLifetime = isset($config['user']['session']['lifetime'])
    ? (int)$config['user']['session']['lifetime'] : 3600;
$expiresAt  = date('Y-m-d H:i:s', time() + $sessionLifetime);

try {
    $db->insert('sessions', [
        'user_id'    => $userId,
        'token'      => $token,
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'expires_at' => $expiresAt,
    ]);
} catch (Exception $e) {
    // 令牌创建失败不影响注册结果，回退为仅返回 userId
    json_response(201, [
        'user_id'  => $userId,
        'username' => $username,
        'message'  => '注册成功，但登录令牌创建失败，请手动登录',
    ]);
}

// ── 成功响应 ──
json_response(201, [
    'user_id'    => $userId,
    'username'   => $username,
    'token'      => $token,
    'expires_at' => $expiresAt,
]);
