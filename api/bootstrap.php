<?php
/**
 * API 引导文件
 * 加载配置、初始化数据库连接、提供公共辅助函数与认证中间件
 *
 * 适用环境：PHP 7.4，虚拟主机（LocalDriver）
 */

// ── 错误处理 ──
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ── 加载配置 ──
$config = require __DIR__ . '/../config.php';

// ── 时区 ──
if (isset($config['app']['timezone'])) {
    date_default_timezone_set($config['app']['timezone']);
}

// ── 字符集 ──
header('Content-Type: application/json; charset=utf-8');

// ── CORS ──
$corsCfg = isset($config['api']['cors']) ? $config['api']['cors'] : [];
$allowedMethods = isset($corsCfg['allowed_methods']) ? implode(', ', $corsCfg['allowed_methods']) : 'GET, POST, OPTIONS';
$allowedOrigins = isset($corsCfg['allowed_origins']) ? $corsCfg['allowed_origins'] : ['*'];

// 按配置限制来源：配置为 ['*'] 时放开；否则仅放行白名单内的 Origin
$allowOrigin = '*';
if (!in_array('*', $allowedOrigins, true)) {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim($_SERVER['HTTP_ORIGIN']) : '';
    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        $allowOrigin = $origin;
    } else {
        // 不在白名单的来源：不允许跨域（非浏览器请求没有 Origin，保持同源可用）
        $allowOrigin = '';
    }
}
if ($allowOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $allowOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: ' . $allowedMethods);
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Bot-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── 加载核心类 ──
require_once __DIR__ . '/../core/DatabaseDriverInterface.php';
require_once __DIR__ . '/../core/Database.php';

// ── 初始化数据库 ──
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'error'   => 'database_init_failed',
        'message' => isset($config['app']['debug']) && $config['app']['debug']
            ? $e->getMessage()
            : '服务暂不可用，请检查 data/ 目录是否可写',
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'error'   => 'fatal_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ════════════════════════════════════════════
//  公共辅助函数
// ════════════════════════════════════════════

/**
 * 输出 JSON 响应并终止
 */
function json_response($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 成功响应快捷方式
 */
function json_success($data = [], $message = 'ok') {
    $body = array_merge(['success' => true, 'message' => $message], $data);
    json_response(200, $body);
}

/**
 * 获取 POST JSON 请求体
 */
function get_json_input() {
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_response(400, ['error' => 'invalid_json', 'message' => '请求体不是有效的 JSON']);
    }
    return $data;
}

/**
 * 生成安全随机令牌
 */
function generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * 从请求头中提取 Bearer Token
 * @return string
 */
function get_bearer_token() {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            return trim($m[1]);
        }
    }
    // 兼容 Apache 等可能不传 Authorization 头的情况
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            if (preg_match('/^Bearer\s+(.+)$/i', $headers['Authorization'], $m)) {
                return trim($m[1]);
            }
        }
    }
    return '';
}

/**
 * 从 GET / POST / JSON 中安全获取参数
 */
function get_param($key, $default = null) {
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    return $default;
}

/**
 * XSS 过滤（HTML 转义）
 */
function xss_clean($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * 获取敏感词列表（静态缓存，同一进程内只读一次文件）
 * @return array
 */
function get_sensitive_words() {
    global $config;
    $wordsFile = isset($config['message']['sensitive_words_file'])
        ? $config['message']['sensitive_words_file']
        : __DIR__ . '/../sensitive_words.txt';

    static $cache = null;
    static $cacheFile = '';
    if ($cache === null || $cacheFile !== $wordsFile) {
        $cacheFile = $wordsFile;
        $cache = [];
        if (file_exists($wordsFile)) {
            $cache = file($wordsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }
    }
    return $cache;
}

/**
 * 敏感词过滤：将敏感词替换为 ***
 */
function filter_sensitive_words($content) {
    global $config;
    $enabled = isset($config['message']['sensitive_words_enabled']) && $config['message']['sensitive_words_enabled'];
    if (!$enabled) {
        return $content;
    }

    foreach (get_sensitive_words() as $word) {
        $word = trim($word);
        if ($word !== '') {
            $content = str_ireplace($word, '***', $content);
        }
    }
    return $content;
}

/**
 * 从请求头中提取 Bot API Key
 * @return string
 */
function get_bot_key() {
    if (isset($_SERVER['HTTP_X_BOT_KEY'])) {
        return trim($_SERVER['HTTP_X_BOT_KEY']);
    }
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['X-Bot-Key'])) {
            return trim($headers['X-Bot-Key']);
        }
    }
    return '';
}

// ════════════════════════════════════════════
//  认证中间件
// ════════════════════════════════════════════

/**
 * 认证用户或 Bot 并返回用户数组（不含密码字段）
 * 支持 Bearer Token 和 X-Bot-Key 两种方式
 *
 * @return array 用户数据
 */
function authenticate() {
    global $db, $config;

    // ── 先尝试 Bot Key ──
    $botKey = get_bot_key();
    if ($botKey !== '') {
        $keyRow = $db->get('bot_keys', ['api_key' => $botKey]);
        if ($keyRow && isset($keyRow['active']) && (int)$keyRow['active'] === 1) {
            $user = $db->get('users', ['id' => $keyRow['user_id']]);
            if ($user && isset($user['status']) && (int)$user['status'] === 1) {
                // 更新最后使用时间
                $db->update('bot_keys', ['last_used_at' => date('Y-m-d H:i:s')], ['id' => $keyRow['id']]);
                unset($user['password']);
                return $user;
            }
        }
        json_response(401, ['error' => 'invalid_bot_key', 'message' => 'Bot Key 无效或已禁用']);
    }

    // ── 再尝试 Bearer Token ──
    $token = get_bearer_token();
    if ($token === '') {
        json_response(401, ['error' => 'unauthorized', 'message' => '请先登录']);
    }

    $session = $db->get('sessions', ['token' => $token]);
    if (!$session) {
        json_response(401, ['error' => 'invalid_token', 'message' => '令牌无效，请重新登录']);
    }

    // 检查过期
    if (isset($session['expires_at']) && strtotime($session['expires_at']) < time()) {
        $db->delete('sessions', ['token' => $token]);
        json_response(401, ['error' => 'token_expired', 'message' => '令牌已过期，请重新登录']);
    }

    $user = $db->get('users', ['id' => $session['user_id']]);
    if (!$user) {
        json_response(401, ['error' => 'user_not_found', 'message' => '用户不存在']);
    }

    if (isset($user['status']) && (int)$user['status'] !== 1) {
        json_response(403, ['error' => 'account_disabled', 'message' => '账号已被禁用']);
    }

    // 更新最后活跃时间
    $db->update('users', ['last_active_at' => date('Y-m-d H:i:s')], ['id' => $user['id']]);

    // 自动续期（所有走 authenticate 的接口统一生效）
    maybe_refresh_token($session, $user);

    // 不暴露密码哈希
    unset($user['password']);
    return $user;
}

/**
 * 角色层次比较
 * @param string $user_role 用户当前角色
 * @param string $required_role 需要的角色
 * @return bool
 */
function role_at_least($user_role, $required_role) {
    $hierarchy = ['guest' => 0, 'member' => 1, 'vip' => 2, 'admin' => 3];
    $userLevel = isset($hierarchy[$user_role]) ? $hierarchy[$user_role] : 0;
    $requiredLevel = isset($hierarchy[$required_role]) ? $hierarchy[$required_role] : 0;
    return $userLevel >= $requiredLevel;
}

/**
 * 检查用户是否有某权限（基于角色配置）
 */
function has_permission($user, $permission) {
    global $config;
    $roleName = isset($user['role']) ? $user['role'] : 'member';
    $rolesCfg = isset($config['user']['roles']) ? $config['user']['roles'] : [];

    // 收集该角色及其继承角色的所有权限
    $permissions = [];
    $role = $roleName;
    while (isset($rolesCfg[$role])) {
        $perms = isset($rolesCfg[$role]['permissions']) ? $rolesCfg[$role]['permissions'] : [];
        $permissions = array_merge($permissions, $perms);
        $role = isset($rolesCfg[$role]['extends']) ? $rolesCfg[$role]['extends'] : null;
        if ($role === null) break;
    }

    return isset($permissions[$permission]) && $permissions[$permission] === true;
}

/**
 * 刷新令牌临近过期时自动续期
 */
function maybe_refresh_token($session, $user) {
    global $db, $config;
    $sessionLifetime = isset($config['user']['session']['lifetime'])
        ? (int)$config['user']['session']['lifetime'] : 3600;

    if (!isset($session['expires_at'])) return;
    $remaining = strtotime($session['expires_at']) - time();

    // 剩余时间不足一半时自动续期
    if ($remaining < $sessionLifetime / 2) {
        $token = get_bearer_token();
        $newExpires = date('Y-m-d H:i:s', time() + $sessionLifetime);
        $db->update('sessions', ['expires_at' => $newExpires], ['token' => $token]);
        header('X-Token-Refreshed: 1');
        header('X-Token-Expires: ' . $newExpires);
    }
}

/**
 * IP 速率限制（基于文件计数窗口）
 * 使用 config['security']['ip_rate_limit'] 配置
 */
function apply_ip_rate_limit() {
    global $config;
    $cfg = isset($config['security']['ip_rate_limit']) ? $config['security']['ip_rate_limit'] : [];
    if (empty($cfg['enabled'])) {
        return;
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if ($ip === '') {
        return;
    }

    $limit = isset($cfg['requests_per_minute']) ? (int)$cfg['requests_per_minute'] : 120;
    if ($limit <= 0) {
        return;
    }

    // 本地文件存储（虚拟主机友好）
    $dir = isset($config['database']['default']['local']['data_path'])
        ? rtrim($config['database']['default']['local']['data_path'], '/') . '/rate_limit'
        : __DIR__ . '/../data/rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file = $dir . '/' . md5($ip) . '.json';
    $now = time();
    $windowStart = $now - 60;

    // 读-改-写放入 flock 临界区，保证并发安全
    $fp = @fopen($file, 'c+');
    if ($fp) {
        @flock($fp, LOCK_EX);

        $content = stream_get_contents($fp);
        $count = 0;
        if ($content !== false && $content !== '') {
            $saved = @json_decode($content, true);
            if (is_array($saved) && isset($saved['window']) && $saved['window'] === $windowStart) {
                $count = (int)$saved['count'];
            }
        }

        if ($count >= $limit) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
            // 超限：返回 429；可选封禁
            $banOnExceed = isset($cfg['ban_on_exceed']) && $cfg['ban_on_exceed'];
            if ($banOnExceed) {
                $banDuration = isset($cfg['ban_duration_minutes']) ? (int)$cfg['ban_duration_minutes'] : 60;
                $banFile = $dir . '/' . md5($ip) . '.ban';
                @file_put_contents($banFile, json_encode(['until' => $now + $banDuration * 60]));
            }
            json_response(429, ['error' => 'rate_limited', 'message' => '请求过于频繁，请稍后再试']);
        }

        // 写入窗口计数
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode(['window' => $windowStart, 'count' => $count + 1]));
        fflush($fp);

        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

// ── 执行 IP 速率限制（放在最后，依赖上面的辅助函数） ──
apply_ip_rate_limit();
