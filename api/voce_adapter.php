<?php
/**
 * VoceChat → Lightchat API Adapter
 *
 * 让 VoceChat 手表客户端 (vocechat_web_wear) 对接 Lightchat 后端。
 *
 * 使用方式：
 *   配置 VoceChat 客户端 baseURL 为 http://your-server
 *   客户端会自动拼接 /api/xxx → 最终请求 /api/token/login
 *   服务器 URL 重写规则（.htaccess 或 nginx）：
 *     RewriteRule ^api/(.*)$ /api/voce_adapter.php/$1 [QSA,L]
 *
 * VoceChat 端点 → Lightchat 后端映射：
 *   POST /token/login           → /api/token/login.php
 *   POST /token/renew           → /api/token/refresh.php
 *   POST /token/logout          → 删除 session
 *   GET  /user                  → /api/users/list.php
 *   GET  /user/profile          → 数据库直查
 *   GET  /user/profile?user_id  → 数据库直查
 *   GET  /user/search           → /api/users/list.php + 过滤
 *   POST /user/register         → /api/token/register.php
 *   GET  /user/contacts         → 从数据库构建
 *   POST /user/contacts/add/{uid}  → 创建私聊会话
 *   GET  /user/{uid}/history    → /api/private/history.php
 *   POST /user/{uid}/send       → /api/private/send.php
 *   GET  /group                 → /api/channels/list.php
 *   POST /group/create          → 数据库建频道
 *   GET  /group/{gid}/members   → 数据库查成员
 *   POST /group/{gid}/join      → 加入频道
 *   POST /group/{gid}/leave     → 退出频道
 *   GET  /group/{gid}/history   → /api/messages/history.php
 *   POST /group/{gid}/send      → /api/messages/send.php
 *   GET  /resource/avatar       → 404（客户端自动降级文字头像）
 *   GET  /resource/group_avatar → 404
 *   GET  /resource/file         → 直接提供 uploads 目录文件
 *   GET  /resource/localization → 空对象
 *   GET  /system/info           → 服务器信息
 *   GET  /user/events           → SSE 轮询长连接
 */

// ========== 路径解析 ==========

$rawPath = $_SERVER['PATH_INFO'] ?? '';
if ($rawPath === '' && isset($_SERVER['REQUEST_URI'])) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($scriptName && strpos($uriPath, $scriptName) === 0) {
        $rawPath = substr($uriPath, strlen($scriptName));
    } else {
        $rawPath = $uriPath;
    }
}
$path   = '/' . trim($rawPath, '/');
$method = $_SERVER['REQUEST_METHOD'];

// ========== API 基础 URL ==========
// 自动检测本机 HTTP 调用地址
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$port   = $_SERVER['SERVER_PORT'] ?? '';
if ($port && $port !== '80' && $port !== '443') {
    $host .= ':' . $port;
}
// 适配器本身的 URL 前缀（去掉文件名本身）
$adapterDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/api');
$apiBaseUrl = "$scheme://$host{$adapterDir}";

// ========== /api 前缀处理 ==========
// VoceChat 客户端自动在 baseURL 后拼接 /api/xxx
// 如果 PATH_INFO 中包含 /api 前缀则去掉，还原为纯路由
if (preg_match('#^/api/(.*)#', $path, $m)) {
    $path = '/' . $m[1];
} elseif ($path === '/api') {
    $path = '/';
}

// ========== 认证转换 ==========
// VoceChat 使用 X-API-Key 头，Lightchat 使用 Authorization: Bearer
$apiKey = '';
if (isset($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = trim($_SERVER['HTTP_X_API_KEY']);
}
if ($apiKey === '' && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $apiKey = trim($headers['X-API-Key'] ?? $headers['X-Api-Key'] ?? '');
}

if ($apiKey !== '') {
    $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $apiKey";
}

// ========== 早期函数声明（路由匹配前需要的） ==========

/**
 * 获取当前认证用户的 ID（从 Authorization 头）
 */
function voce_get_auth_user_id() {
    $token = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $token = trim($m[1]);
        }
    }
    if ($token === '') {
        $token = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
    }
    if ($token === '') return null;

    include_once __DIR__ . '/../core/DatabaseDriverInterface.php';
    include_once __DIR__ . '/../core/Database.php';

    try {
        $db = Database::getInstance();
        $session = $db->get('sessions', ['token' => $token]);
        if ($session && isset($session['expires_at']) && strtotime($session['expires_at']) > time()) {
            return (int)$session['user_id'];
        }
    } catch (Exception $e) { /* ignore */ }
    return null;
}

/**
 * 组合认证请求头
 */
function voce_auth_headers() {
    $headers = ["Content-Type: application/json"];
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
        $headers[] = 'Authorization: Bearer ' . $_SERVER['HTTP_X_API_KEY'];
    }
    return $headers;
}

/**
 * HTTP 子请求（调用 Lightchat PHP 端点）
 */
function voce_http_call($relativeUrl, $method = 'GET', $body = null, $extraHeaders = []) {
    global $apiBaseUrl;

    $url = $apiBaseUrl . $relativeUrl;
    $headers = voce_auth_headers();
    foreach ($extraHeaders as $h) $headers[] = $h;

    $opts = [
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
            'timeout'       => 30,
        ],
    ];

    if ($body !== null && $method !== 'GET') {
        if (is_array($body)) {
            $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
        } else {
            $opts['http']['content'] = $body;
            $opts['http']['header'] = str_replace(
                'Content-Type: application/json',
                'Content-Type: text/plain',
                $opts['http']['header']
            );
        }
    }

    $context = stream_context_create($opts);
    $raw     = @file_get_contents($url, false, $context);

    $statusCode = 200;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $respHeader) {
            if (preg_match('#^HTTP/[\d.]+\s+(\d+)#', $respHeader, $m)) {
                $statusCode = (int)$m[1];
                break;
            }
        }
    }

    if ($raw === false) {
        return ['code' => 500, 'body' => json_encode(['error' => 'backend_unreachable', 'message' => 'Lightchat 后端不可达'])];
    }

    return ['code' => $statusCode, 'body' => $raw];
}

// ========== 路由匹配 ==========

$matched = false;

// ──── POST /token/login ────
if (preg_match('#^/token/login$#', $path) && $method === 'POST') {
    $matched = true;
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $email    = $data['credential']['email']    ?? '';
    $password = $data['credential']['password'] ?? '';

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'missing_fields', 'message' => '邮箱和密码不能为空'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $res = voce_http_call('/token/login.php', 'POST', [
        'username' => $email,
        'password' => $password,
    ]);

    $lcData = json_decode($res['body'], true);
    if (!$lcData || $res['code'] >= 400) {
        http_response_code($res['code']);
        echo $res['body'];
        exit;
    }

    $token = $lcData['token'] ?? '';
    http_response_code(200);
    echo json_encode([
        'token'         => $token,
        'refresh_token' => $token,
        'user'          => [
            'uid'   => $lcData['user_id'] ?? 0,
            'name'  => $lcData['username'] ?? '',
            'email' => '',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── POST /token/renew ────
if (preg_match('#^/token/renew$#', $path) && $method === 'POST') {
    $matched = true;
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $refreshToken = $data['refresh_token'] ?? '';

    $extraHeaders = [];
    if ($refreshToken !== '') {
        $extraHeaders[] = "Authorization: Bearer $refreshToken";
    }

    $res = voce_http_call('/token/refresh.php', 'POST', [], $extraHeaders);
    $lcData = json_decode($res['body'], true);
    if (!$lcData || $res['code'] >= 400) {
        http_response_code($res['code']);
        echo $res['body'];
        exit;
    }

    $token = $lcData['token'] ?? '';
    http_response_code(200);
    echo json_encode([
        'token'         => $token,
        'refresh_token' => $token,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── POST /token/logout ────
if (preg_match('#^/token/logout$#', $path) && $method === 'POST') {
    $matched = true;
    include_once __DIR__ . '/../core/DatabaseDriverInterface.php';
    include_once __DIR__ . '/../core/Database.php';
    try {
        $token = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                $token = trim($m[1]);
            }
        }
        if ($token === '') {
            $token = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
        }
        if ($token !== '') {
            $db = Database::getInstance();
            $db->delete('sessions', ['token' => $token]);
        }
    } catch (Exception $e) { /* ignore */ }
    http_response_code(200);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── GET /user ────
if (preg_match('#^/user$#', $path) && $method === 'GET') {
    $matched = true;

    $res = voce_http_call('/users/list.php', 'GET');
    $lcData = json_decode($res['body'], true);
    if (!$lcData || $res['code'] >= 400) {
        http_response_code($res['code']);
        echo $res['body'];
        exit;
    }

    $users = $lcData['users'] ?? [];
    $result = [];
    foreach ($users as $u) {
        $result[] = [
            'uid'        => $u['id'] ?? 0,
            'name'       => $u['nickname'] ?? $u['username'] ?? '',
            'email'      => '',
            'avatar'     => '',
            'status'     => ($u['status'] ?? 1) == 1 ? 'normal' : 'disabled',
            'is_online'  => false,
            'is_contact' => false,
        ];
    }
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── GET /user/profile (自己的资料) ────
if (preg_match('#^/user/profile$#', $path) && $method === 'GET' && !isset($_GET['user_id'])) {
    $matched = true;
    $userId = voce_get_auth_user_id();
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    $profile = voce_get_user_by_id($userId);
    http_response_code(200);
    echo json_encode($profile, JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── GET /user/profile?user_id=xxx (他人资料卡) ────
if (preg_match('#^/user/profile$#', $path) && $method === 'GET' && isset($_GET['user_id'])) {
    $matched = true;
    $uid = (int)$_GET['user_id'];
    $profile = voce_get_user_by_id($uid);
    if (!$profile) {
        http_response_code(404);
        echo json_encode(['error' => 'user_not_found']);
        exit;
    }
    http_response_code(200);
    echo json_encode($profile, JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── GET /user/search?q=xxx ────
if (preg_match('#^/user/search$#', $path) && $method === 'GET') {
    $matched = true;
    $q = $_GET['q'] ?? '';
    if ($q === '') {
        http_response_code(200);
        echo '[]';
        exit;
    }
    $res = voce_http_call('/users/list.php', 'GET');
    $lcData = json_decode($res['body'], true);
    $users = $lcData['users'] ?? [];
    $result = [];
    foreach ($users as $u) {
        $name = $u['nickname'] ?? $u['username'] ?? '';
        if (mb_stripos($name, $q) === false && mb_stripos($u['username'] ?? '', $q) === false) {
            continue;
        }
        $result[] = [
            'uid'    => $u['id'] ?? 0,
            'name'   => $name,
            'avatar' => $u['avatar'] ?? '',
        ];
    }
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── POST /user/register ────
if (preg_match('#^/user/register$#', $path) && $method === 'POST') {
    $matched = true;
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $email    = $data['email']    ?? '';
    $password = $data['password'] ?? '';
    $name     = $data['name']     ?? explode('@', $email)[0];
    $gender   = $data['gender']   ?? 0;

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'missing_fields', 'message' => '邮箱和密码不能为空']);
        exit;
    }

    $res = voce_http_call('/token/register.php', 'POST', [
        'username' => $email,
        'password' => $password,
        'nickname' => $name,
        'gender'   => $gender,
    ]);

    $lcData = json_decode($res['body'], true);
    if (!$lcData || $res['code'] >= 400) {
        http_response_code($res['code']);
        echo $res['body'];
        exit;
    }

    http_response_code(200);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── GET /user/contacts ────
if (preg_match('#^/user/contacts$#', $path) && $method === 'GET') {
    $matched = true;
    echo voce_contacts_handler();
    exit;
}

// ──── POST /user/contacts/add/{uid} ────
if (preg_match('#^/user/contacts/add/(\d+)$#', $path, $m) && $method === 'POST') {
    $matched = true;
    $targetUid = (int)$m[1];
    $userId = voce_get_auth_user_id();
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    voce_ensure_private_chat($userId, $targetUid);
    http_response_code(200);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── GET /user/{uid}/history ────
if (preg_match('#^/user/(\d+)/history$#', $path, $m) && $method === 'GET') {
    $matched = true;
    $uid   = (int)$m[1];
    $chatId = voce_find_private_chat($uid);
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

    if ($chatId <= 0) {
        http_response_code(200);
        echo '[]';
        exit;
    }

    $res = voce_http_call("/private/history.php?chat_id={$chatId}&limit={$limit}", 'GET');
    $lcData = json_decode($res['body'], true);
    if (!$lcData || $res['code'] >= 400) {
        http_response_code($res['code']);
        echo $res['body'];
        exit;
    }

    http_response_code(200);
    echo voce_transform_history($lcData, 'user');
    exit;
}

// ──── POST /user/{uid}/send ────
if (preg_match('#^/user/(\d+)/send$#', $path, $m) && $method === 'POST') {
    $matched = true;
    $uid     = (int)$m[1];
    $rawBody = file_get_contents('php://input');

    $res = voce_http_call('/private/send.php', 'POST', [
        'to_user_id' => $uid,
        'content'    => $rawBody,
    ]);

    $lcData = json_decode($res['body'], true);
    if (!$lcData || $res['code'] >= 400) {
        http_response_code($res['code']);
        echo $res['body'];
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'mid'        => $lcData['message_id'] ?? 0,
        'created_at' => time() * 1000,
        'detail'     => [
            'type'         => 'normal',
            'content_type' => 'text/plain',
            'content'      => $lcData['content'] ?? '',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── GET /group ────
if (preg_match('#^/group$#', $path) && $method === 'GET') {
    $matched = true;

    $res = voce_http_call('/channels/list.php', 'GET');
    $lcData = json_decode($res['body'], true);
    if (!$lcData || $res['code'] >= 400) {
        http_response_code($res['code']);
        echo $res['body'];
        exit;
    }

    $channels = $lcData['channels'] ?? [];
    $result = [];
    foreach ($channels as $ch) {
        $result[] = [
            'gid'          => $ch['id'] ?? 0,
            'name'         => $ch['display_name'] ?? $ch['name'] ?? '',
            'description'  => $ch['description'] ?? '',
            'owner'        => $ch['owner_id'] ?? 0,
            'member_count' => $ch['member_count'] ?? 0,
            'is_public'    => ($ch['type'] ?? '') === 'public',
            'is_joined'    => $ch['is_joined'] ?? false,
        ];
    }
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── POST /group/create ────
if (preg_match('#^/group/create$#', $path) && $method === 'POST') {
    $matched = true;
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $name = $data['name'] ?? '';
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'missing_name']);
        exit;
    }
    // 先尝试 LightChat 后端接口
    $res = voce_http_call('/channels/create.php', 'POST', [
        'name'        => $name,
        'description' => $data['description'] ?? '',
        'type'        => 'public',
    ]);
    $lcData = json_decode($res['body'], true);
    if ($lcData && $res['code'] < 400 && (isset($lcData['channel_id']) || isset($lcData['id']))) {
        http_response_code(200);
        echo json_encode([
            'gid'  => $lcData['channel_id'] ?? $lcData['id'] ?? 0,
            'name' => $name,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // 后端无此接口，数据库直接创建
        include_once __DIR__ . '/../core/DatabaseDriverInterface.php';
        include_once __DIR__ . '/../core/Database.php';
        try {
            $db = Database::getInstance();
            $ownerId = voce_get_auth_user_id() ?? 0;
            $chId = $db->insert('channels', [
                'name'        => $name,
                'description' => $data['description'] ?? '',
                'type'        => 'public',
                'owner_id'    => $ownerId,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            if ($ownerId > 0) {
                $db->insert('channel_members', [
                    'channel_id' => $chId,
                    'user_id'    => $ownerId,
                    'joined_at'  => date('Y-m-d H:i:s'),
                ]);
            }
            http_response_code(200);
            echo json_encode(['gid' => $chId, 'name' => $name], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'db_error']);
        }
    }
    exit;
}

// ──── GET /group/{gid}/members ────
if (preg_match('#^/group/(\d+)/members$#', $path, $m) && $method === 'GET') {
    $matched = true;
    $gid = (int)$m[1];
    include_once __DIR__ . '/../core/DatabaseDriverInterface.php';
    include_once __DIR__ . '/../core/Database.php';
    try {
        $db = Database::getInstance();
        $memberships = $db->select('channel_members', ['channel_id' => $gid]);
        $result = [];
        foreach ($memberships as $m) {
            $u = $db->get('users', ['id' => $m['user_id']]);
            if ($u && ((int)($u['status'] ?? 1)) === 1) {
                $result[] = [
                    'uid'    => (int)$u['id'],
                    'name'   => $u['nickname'] ?? $u['username'] ?? '',
                    'avatar' => $u['avatar'] ?? '',
                    'role'   => $u['role'] ?? 'member',
                ];
            }
        }
        http_response_code(200);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'db_error']);
    }
    exit;
}

// ──── POST /group/{gid}/join ────
if (preg_match('#^/group/(\d+)/join$#', $path, $m) && $method === 'POST') {
    $matched = true;
    $gid = (int)$m[1];
    $userId = voce_get_auth_user_id();
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    include_once __DIR__ . '/../core/DatabaseDriverInterface.php';
    include_once __DIR__ . '/../core/Database.php';
    try {
        $db = Database::getInstance();
        $ch = $db->get('channels', ['id' => $gid]);
        if (!$ch) {
            http_response_code(404);
            echo json_encode(['error' => 'group_not_found']);
            exit;
        }
        $existing = $db->get('channel_members', ['channel_id' => $gid, 'user_id' => $userId]);
        if ($existing) {
            http_response_code(200);
            echo json_encode(['success' => true]);
            exit;
        }
        $db->insert('channel_members', [
            'channel_id' => $gid,
            'user_id'    => $userId,
            'joined_at'  => date('Y-m-d H:i:s'),
        ]);
        http_response_code(200);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'db_error']);
    }
    exit;
}

// ──── POST /group/{gid}/leave ────
if (preg_match('#^/group/(\d+)/leave$#', $path, $m) && $method === 'POST') {
    $matched = true;
    $gid = (int)$m[1];
    $userId = voce_get_auth_user_id();
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }
    include_once __DIR__ . '/../core/DatabaseDriverInterface.php';
    include_once __DIR__ . '/../core/Database.php';
    try {
        $db = Database::getInstance();
        $db->delete('channel_members', ['channel_id' => $gid, 'user_id' => $userId]);
        http_response_code(200);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'db_error']);
    }
    exit;
}

// ──── GET /group/{gid}/history ────
if (preg_match('#^/group/(\d+)/history$#', $path, $m) && $method === 'GET') {
    $matched = true;
    $gid   = (int)$m[1];
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

    $res = voce_http_call("/messages/history.php?channel_id={$gid}&limit={$limit}", 'GET');
    $lcData = json_decode($res['body'], true);
    if (!$lcData || $res['code'] >= 400) {
        http_response_code($res['code']);
        echo $res['body'];
        exit;
    }

    http_response_code(200);
    echo voce_transform_history($lcData, 'group');
    exit;
}

// ──── POST /group/{gid}/send ────
if (preg_match('#^/group/(\d+)/send$#', $path, $m) && $method === 'POST') {
    $matched = true;
    $gid     = (int)$m[1];
    $rawBody = file_get_contents('php://input');

    $res = voce_http_call('/messages/send.php', 'POST', [
        'channel_id' => $gid,
        'content'    => $rawBody,
        'type'       => 'text',
    ]);

    $lcData = json_decode($res['body'], true);
    if (!$lcData || $res['code'] >= 400) {
        http_response_code($res['code']);
        echo $res['body'];
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'mid'        => $lcData['message_id'] ?? 0,
        'created_at' => time() * 1000,
        'detail'     => [
            'type'         => 'normal',
            'content_type' => 'text/plain',
            'content'      => $lcData['content'] ?? '',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── GET /resource/avatar ────
// ──── GET /resource/group_avatar ────
if (preg_match('#^/resource/(avatar|group_avatar)$#', $path) && $method === 'GET') {
    $matched = true;
    http_response_code(404);
    echo json_encode(['error' => 'not_found', 'message' => '头像功能暂未实现'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── GET /resource/file ────
if (preg_match('#^/resource/file$#', $path) && $method === 'GET') {
    $matched = true;
    $filePath = $_GET['file_path'] ?? '';
    if ($filePath === '') {
        http_response_code(400);
        echo json_encode(['error' => 'missing_path']);
        exit;
    }

    $uploadsDir = realpath(__DIR__ . '/../uploads');
    $cleanPath = ltrim($filePath, '/');
    if (strpos($cleanPath, 'uploads/') === 0) {
        $cleanPath = substr($cleanPath, 8);
    }
    $fullPath = realpath($uploadsDir . '/' . $cleanPath);

    if ($fullPath && $uploadsDir && strpos($fullPath, $uploadsDir) === 0 && is_file($fullPath)) {
        $mime = mime_content_type($fullPath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: public, max-age=3600');
        readfile($fullPath);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'not_found', 'message' => '文件不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── GET /resource/localization?locale=xxx ────
if (preg_match('#^/resource/localization$#', $path) && $method === 'GET') {
    $matched = true;
    // 返回空对象让客户端用默认文案
    http_response_code(200);
    echo '{}';
    exit;
}

// ──── GET /admin/system/initialized ────
if (preg_match('#^/admin/system/initialized$#', $path) && $method === 'GET') {
    $matched = true;
    header('Content-Type: application/json; charset=utf-8');
    echo 'true';
    exit;
}

// ──── GET /system/info ────
if (preg_match('#^/system/info$#', $path) && $method === 'GET') {
    $matched = true;
    http_response_code(200);
    echo json_encode([
        'version'     => '1.0.0',
        'server_name' => 'LightChat (VoceChat compatible)',
        'max_upload'  => 5242880,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──── GET /user/events (SSE 长轮询) ────
if (preg_match('#^/user/events$#', $path) && $method === 'GET') {
    $matched = true;
    voce_events_handler();
    exit;
}

// ========== 404 ==========

if (!$matched) {
    http_response_code(404);
    echo json_encode([
        'error'   => 'not_found',
        'message' => '未知端点: ' . $path,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
//  辅助函数
// ====================================================================

/**
 * 根据用户 ID 获取资料（VoceChat 格式）
 */
function voce_get_user_by_id($uid) {
    include_once __DIR__ . '/../core/DatabaseDriverInterface.php';
    include_once __DIR__ . '/../core/Database.php';
    try {
        $db = Database::getInstance();
        $u = $db->get('users', ['id' => (int)$uid]);
        if (!$u || ((int)($u['status'] ?? 1)) !== 1) return null;
        return [
            'uid'       => (int)$u['id'],
            'name'      => $u['nickname'] ?? $u['username'] ?? '',
            'email'     => $u['email'] ?? '',
            'avatar'    => $u['avatar'] ?? '',
            'bio'       => $u['bio'] ?? '',
            'signature' => $u['signature'] ?? '',
            'gender'    => (int)($u['gender'] ?? 0),
            'role'      => $u['role'] ?? 'member',
            'status'    => 'normal',
        ];
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 确保当前用户与目标用户之间存在私聊会话
 */
function voce_ensure_private_chat($currentUid, $targetUid) {
    include_once __DIR__ . '/../core/DatabaseDriverInterface.php';
    include_once __DIR__ . '/../core/Database.php';
    try {
        $db = Database::getInstance();
        $u1 = min((int)$currentUid, (int)$targetUid);
        $u2 = max((int)$currentUid, (int)$targetUid);
        $existing = $db->get('private_chats', ['user1_id' => $u1, 'user2_id' => $u2]);
        if ($existing) return (int)$existing['id'];

        return (int)$db->insert('private_chats', [
            'user1_id'       => $u1,
            'user2_id'       => $u2,
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 查找当前用户与目标用户的私聊会话 ID
 */
function voce_find_private_chat($targetUid) {
    $currentUid = voce_get_auth_user_id();
    if (!$currentUid) return 0;

    include_once __DIR__ . '/../core/DatabaseDriverInterface.php';
    include_once __DIR__ . '/../core/Database.php';

    try {
        $db = Database::getInstance();
        $u1 = min($currentUid, (int)$targetUid);
        $u2 = max($currentUid, (int)$targetUid);
        $chat = $db->get('private_chats', ['user1_id' => $u1, 'user2_id' => $u2]);
        if ($chat) return (int)$chat['id'];
        return 0;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 转换消息历史 → VoceChat 格式
 * Lightchat: {success, messages: [{id, user_id, from_user_id, username, content, type, ...}]}
 * VoceChat: [{mid, created_at, from_uid, from_name, detail:{type, content_type, content}, properties}]
 */
function voce_transform_history($lcData, $chatType) {
    $messages = $lcData['messages'] ?? [];
    $result = [];

    foreach ($messages as $msg) {
        $fromUid = ($chatType === 'user')
            ? ($msg['from_user_id'] ?? 0)
            : ($msg['user_id'] ?? 0);

        $contentType = 'text/plain';
        $properties  = [];

        $msgType = $msg['type'] ?? 'text';
        if ($msgType === 'image' || $msgType === 'file') {
            $contentType = 'vocechat/file';
            $properties  = [
                'content_type' => $msgType === 'image' ? 'image/jpeg' : 'application/octet-stream',
                'name'         => basename($msg['file_url'] ?? ''),
                'size'         => $msg['file_size'] ?? 0,
            ];
        }

        $entry = [
            'mid'        => $msg['id'] ?? 0,
            'created_at' => strtotime($msg['created_at'] ?? 'now') * 1000,
            'from_uid'   => $fromUid,
            'from_name'  => $msg['username'] ?? '未知用户',
            'detail'     => [
                'type'         => 'normal',
                'content_type' => $contentType,
                'content'      => $msg['content'] ?? '',
            ],
            'properties' => $properties,
        ];

        if ($chatType === 'group') {
            $entry['to_gid'] = $msg['channel_id'] ?? 0;
        }

        $result[] = $entry;
    }

    return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * 构建联系人列表（私聊伙伴 + 已加入频道）
 */
function voce_contacts_handler() {
    $userId = voce_get_auth_user_id();
    if (!$userId) {
        http_response_code(401);
        return json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    }

    include_once __DIR__ . '/../core/DatabaseDriverInterface.php';
    include_once __DIR__ . '/../core/Database.php';

    try {
        $db = Database::getInstance();
        $contacts = [];
        $seenUid = [];

        // 私聊联系人
        $allChats = $db->select('private_chats', [], '*', 'last_message_at DESC');
        foreach ($allChats as $chat) {
            $uid1 = (int)$chat['user1_id'];
            $uid2 = (int)$chat['user2_id'];
            if ($uid1 !== $userId && $uid2 !== $userId) continue;

            $otherUid = ($uid1 === $userId) ? $uid2 : $uid1;
            if (isset($seenUid[$otherUid])) continue;
            $seenUid[$otherUid] = true;

            $otherUser = $db->get('users', ['id' => $otherUid]);
            if (!$otherUser || ((int)($otherUser['status'] ?? 1)) !== 1) continue;

            $unread = $db->count('private_messages', [
                'chat_id'    => $chat['id'],
                'to_user_id' => $userId,
                'is_read'    => 0,
            ]);

            $contacts[] = [
                'uid'          => $otherUid,
                'name'         => $otherUser['nickname'] ?? $otherUser['username'] ?? '',
                'avatar'       => '',
                'status'       => 'added',
                'is_online'    => false,
                'last_message' => $chat['last_message'] ?? '',
                'unread'       => $unread,
                'chat_type'    => 'user',
            ];
        }

        // 已加入的频道
        $memberships = $db->select('channel_members', ['user_id' => $userId]);
        foreach ($memberships as $m) {
            $channel = $db->get('channels', ['id' => $m['channel_id']]);
            if (!$channel) continue;

            $contacts[] = [
                'gid'          => (int)$channel['id'],
                'name'         => $channel['display_name'] ?? $channel['name'] ?? '',
                'description'  => $channel['description'] ?? '',
                'status'       => 'added',
                'chat_type'    => 'group',
                'last_message' => '',
                'unread'       => 0,
            ];
        }

        return json_encode($contacts, JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        http_response_code(500);
        return json_encode(['error' => 'db_error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

/**
 * SSE 事件流处理器
 * VoceChat watch client 需要 EventSource 实时推送
 */
function voce_events_handler() {
    $userId = voce_get_auth_user_id();
    if (!$userId) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        return;
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    while (ob_get_level()) ob_end_clean();

    set_time_limit(0);

    include_once __DIR__ . '/../core/DatabaseDriverInterface.php';
    include_once __DIR__ . '/../core/Database.php';

    $sinceId    = 0;
    $timeout    = 55;
    $startTime  = time();
    $pollSleep  = 3;

    echo "event: connected\ndata: {}\n\n";
    flush();

    while (true) {
        if (time() - $startTime > $timeout) {
            echo "event: heartbeat\ndata: {}\n\n";
            flush();
            break;
        }

        if (connection_aborted()) break;

        try {
            $db = Database::getInstance();
            $newMessages = [];

            // 私聊新消息
            $chats = $db->select('private_chats', [], '*', 'id ASC');
            foreach ($chats as $chat) {
                $uid1 = (int)$chat['user1_id'];
                $uid2 = (int)$chat['user2_id'];
                if ($uid1 !== $userId && $uid2 !== $userId) continue;

                $pms = $db->select('private_messages', ['chat_id' => $chat['id']], '*', 'id DESC', 20);
                foreach ($pms as $pm) {
                    if ((int)$pm['id'] <= $sinceId) continue;
                    if (!empty($pm['is_deleted']) && (int)$pm['is_deleted'] === 1) continue;

                    $sender = $db->get('users', ['id' => $pm['from_user_id']]);
                    $pmType = $pm['type'] ?? 'text';
                    $isFile = in_array($pmType, ['image', 'file']);
                    $newMessages[] = [
                        'mid'        => (int)$pm['id'],
                        'created_at' => strtotime($pm['created_at'] ?? 'now') * 1000,
                        'from_uid'   => (int)$pm['from_user_id'],
                        'from_name'  => $sender ? ($sender['nickname'] ?? $sender['username']) : '未知',
                        'to_uid'     => (int)$pm['to_user_id'],
                        'chat_type'  => 'user',
                        'detail'     => [
                            'type'         => 'normal',
                            'content_type' => $isFile ? 'vocechat/file' : 'text/plain',
                            'content'      => $pm['content'] ?? '',
                        ],
                        'properties' => $isFile ? [
                            'content_type' => $pmType === 'image' ? 'image/jpeg' : 'application/octet-stream',
                            'name'         => basename($pm['file_url'] ?? ''),
                            'size'         => $pm['file_size'] ?? 0,
                        ] : [],
                    ];
                    if ((int)$pm['id'] > $sinceId) $sinceId = (int)$pm['id'];
                }
            }

            // 频道新消息
            $memberships = $db->select('channel_members', ['user_id' => $userId]);
            foreach ($memberships as $m) {
                $msgs = $db->select('messages', ['channel_id' => $m['channel_id']], '*', 'id DESC', 20);
                foreach ($msgs as $msg) {
                    if ((int)$msg['id'] <= $sinceId) continue;
                    if (!empty($msg['is_deleted']) && (int)$msg['is_deleted'] === 1) continue;
                    if ((int)$msg['user_id'] === $userId) continue;

                    $sender = $db->get('users', ['id' => $msg['user_id']]);
                    $isImage = ($msg['type'] ?? 'text') === 'image';
                    $newMessages[] = [
                        'mid'        => (int)$msg['id'],
                        'created_at' => strtotime($msg['created_at'] ?? 'now') * 1000,
                        'from_uid'   => (int)$msg['user_id'],
                        'from_name'  => $sender ? ($sender['nickname'] ?? $sender['username']) : '系统',
                        'to_gid'     => (int)$msg['channel_id'],
                        'chat_type'  => 'group',
                        'detail'     => [
                            'type'         => 'normal',
                            'content_type' => $isImage ? 'vocechat/file' : 'text/plain',
                            'content'      => $msg['content'] ?? '',
                        ],
                        'properties' => $isImage ? [
                            'content_type' => 'image/jpeg',
                            'name'         => basename($msg['file_url'] ?? ''),
                            'size'         => $msg['file_size'] ?? 0,
                        ] : [],
                    ];
                    if ((int)$msg['id'] > $sinceId) $sinceId = (int)$msg['id'];
                }
            }

            if (count($newMessages) > 0) {
                foreach ($newMessages as $nm) {
                    echo "data: " . json_encode($nm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                    flush();
                }
                $startTime = time();
                continue;
            }

        } catch (Exception $e) {
            // 静默跳过
        }

        echo "event: heartbeat\ndata: {}\n\n";
        flush();

        sleep($pollSleep);
    }
}
