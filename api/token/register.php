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
$contact  = trim($input['contact'] ?? '');

// ── Bot 自助注册（必须登录，记录创建者便于溯源） ──
$accountType = trim($input['account_type'] ?? '');
if ($accountType === 'bot') {
    // 未登录直接 401（authenticate 内部处理）
    $creator = authenticate();

    $botCfg = isset($config['user']['bot']) ? $config['user']['bot'] : [];
    if (empty($botCfg['allow_self_register'])) {
        json_response(403, ['error' => 'bot_register_disabled', 'message' => '暂不支持自助注册 Bot']);
    }
    if (!has_permission($creator, 'user.bot.register') && !role_at_least($creator['role'], 'admin')) {
        json_response(403, ['error' => 'forbidden', 'message' => '您没有注册 Bot 的权限']);
    }

    $botUsernameRaw = trim($input['username'] ?? '');
    $botName        = trim($input['name'] ?? '');
    if ($botUsernameRaw === '') {
        json_response(400, ['error' => 'missing_username', 'message' => 'Bot 用户名不能为空']);
    }

    // 自动加 bot_ 前缀避免和普通用户冲突
    $botUsername = 'bot_' . preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '', $botUsernameRaw);

    // 长度校验（复用 config 用户名规则）
    $uCfg = isset($config['user']['username']) ? $config['user']['username'] : [];
    $uMin = isset($uCfg['min_length']) ? (int)$uCfg['min_length'] : 3;
    $uMax = isset($uCfg['max_length']) ? (int)$uCfg['max_length'] : 20;
    $uLen = mb_strlen($botUsername, 'UTF-8');
    if ($uLen < $uMin || $uLen > $uMax) {
        json_response(400, ['error' => 'invalid_username', 'message' => "Bot 用户名长度应为 {$uMin}-{$uMax} 个字符"]);
    }

    $existing = $db->get('users', ['username' => $botUsername]);
    if ($existing) {
        json_response(409, ['error' => 'duplicate_username', 'message' => '该 Bot 用户名已存在']);
    }

    // 每用户 Bot 数量上限（按创建者统计）
    $maxPerUser = isset($botCfg['max_per_user']) ? (int)$botCfg['max_per_user'] : 5;
    if ($maxPerUser > 0) {
        $creatorBotCount = $db->count('users', ['account_type' => 'bot', 'created_by' => $creator['id']]);
        if ($creatorBotCount >= $maxPerUser) {
            json_response(403, ['error' => 'bot_limit_reached', 'message' => "每个用户最多创建 {$maxPerUser} 个 Bot"]);
        }
    }

    // 创建 Bot 用户（密码随机，不可用密码登录）
    $userId = $db->insert('users', [
        'username'     => $botUsername,
        'password'     => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
        'email'        => '',
        'account_type' => 'bot',
        'role'         => 'member',
        'status'       => 1,
        'created_by'   => $creator['id'],
    ]);

    // 生成永久 API Key
    $apiKey = 'bot_' . bin2hex(random_bytes(24));
    $db->insert('bot_keys', [
        'user_id' => $userId,
        'api_key' => $apiKey,
        'name'    => $botName !== '' ? $botName : $botUsername,
        'active'  => 1,
    ]);

    // 审计日志（记录创建者）
    $db->insert('audit_logs', [
        'user_id'     => $creator['id'],
        'username'    => $creator['username'],
        'action'      => 'bot.create',
        'target_type' => 'bot',
        'target_id'   => $userId,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'detail'      => json_encode(['bot_username' => $botUsername, 'creator_id' => $creator['id']], JSON_UNESCAPED_UNICODE),
    ]);

    json_response(201, [
        'success'    => true,
        'message'    => 'Bot 注册成功',
        'user_id'    => $userId,
        'username'   => $botUsername,
        'api_key'    => $apiKey,
        'creator_id' => (int)$creator['id'],
        'hint'       => '请求时在 Header 中加入 X-Bot-Key: ' . $apiKey,
    ]);
}

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

// ── 邮箱格式（必填） ──
if ($email === '') {
    json_response(400, ['error' => 'missing_email', 'message' => '邮箱为必填项']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(400, ['error' => 'invalid_email', 'message' => '邮箱格式不正确']);
}

// 检查邮箱是否已注册
$existingEmail = $db->get('users', ['email' => $email]);
if ($existingEmail) {
    json_response(409, ['error' => 'duplicate_email', 'message' => '该邮箱已被注册']);
}

// 联系方式（可选，不限格式）
if ($contact !== '' && mb_strlen($contact, 'UTF-8') > 100) {
    json_response(400, ['error' => 'invalid_contact', 'message' => '联系方式过长']);
}

// ── 敏感词检查 ──
$sensitiveEnabled = isset($config['message']['sensitive_words_enabled'])
    ? $config['message']['sensitive_words_enabled'] : false;
if ($sensitiveEnabled) {
    foreach (get_sensitive_words() as $word) {
        $word = trim($word);
        if ($word !== '' && mb_stripos($username, $word) !== false) {
            json_response(400, [
                'error'   => 'sensitive_word',
                'message' => '用户名包含敏感词汇',
            ]);
        }
    }
}

// ── 检查用户名是否已存在 ──
try {
    $existing = $db->get('users', ['username' => $username]);
    if ($existing) {
        json_response(409, ['error' => 'duplicate_username', 'message' => '用户名已被注册']);
    }
} catch (Exception $e) {
    json_response(500, ['error' => 'db_error', 'message' => '数据库查询失败']);
}

// ── 创建用户 ──
$passwordHash = password_hash($password, PASSWORD_BCRYPT);

if ($accountType !== '' && $accountType !== 'user') {
    json_response(400, ['error' => 'invalid_account_type', 'message' => 'account_type 只能为 user 或 bot']);
}

$userData = [
    'username'     => $username,
    'password'     => $passwordHash,
    'email'        => $email,
    'contact'      => $contact,
    'reg_ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
    'account_type' => $accountType ?: 'user',
    // 角色按默认配置；管理员账号通过 install.php 安装向导创建
    'role'         => isset($config['user']['default_role']) ? $config['user']['default_role'] : 'member',
    'status'       => 1,
];

try {
    $userId = $db->insert('users', $userData);

    // 审计日志：注册
    $db->insert('audit_logs', [
        'user_id'    => $userId,
        'username'   => $username,
        'action'     => 'register',
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'detail'     => json_encode(['email' => $email, 'contact' => $contact], JSON_UNESCAPED_UNICODE),
    ]);
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
