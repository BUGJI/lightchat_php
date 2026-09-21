<?php
/**
 * API 引导文件
 * 加载配置、初始化数据库连接、提供公共辅助函数与认证中间件
 *
 * 适用环境：PHP 7.4，虚拟主机（LocalDriver）
 */

// ── 使用统计：开启输出缓冲（所有 API 均为 JSON 输出，用于统计响应体字节数） ──
ob_start();

// ── mbstring 兼容：未安装 mbstring 扩展时提供最小 polyfill（UTF-8 字节级降级） ──
if (!function_exists('mb_strlen')) {
    function mb_strlen($str, $encoding = null) { return strlen($str); }
    function mb_stripos($haystack, $needle, $offset = 0, $encoding = null) {
        return stripos($haystack, $needle, $offset);
    }
    function mb_strtolower($str, $encoding = null) { return strtolower($str); }
    function mb_substr($str, $start, $length = null, $encoding = null) {
        return $length === null ? substr($str, $start) : substr($str, $start, $length);
    }
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) {
        return strpos($haystack, $needle, $offset);
    }
}

// ── 错误处理 ──
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ── 统一异常处理：未捕获异常返回 JSON（避免向客户端吐堆栈）并记日志 ──
set_exception_handler(function ($e) {
    error_log('[lightchat] Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'data'    => null,
        'error'   => ['code' => 'internal_error', 'message' => '服务器内部错误'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// ── 统一错误处理：非致命错误记日志（返回 false 交回 PHP 默认流程，@ 抑制的错误跳过） ──
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    error_log("[lightchat] PHP error {$severity}: {$message} in {$file}:{$line}");
    return false;
});

// ── 加载配置 ──
$config = require __DIR__ . '/../config.php';

// 安装向导生成的运行时配置（config.local.php）优先覆盖默认值
$localConfigFile = __DIR__ . '/../config.local.php';
if (file_exists($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (is_array($localConfig)) {
        $config = array_replace_recursive($config, $localConfig);
    }
}

// ── 时区 ──
if (isset($config['app']['timezone'])) {
    date_default_timezone_set($config['app']['timezone']);
}

// ── 字符集 ──
header('Content-Type: application/json; charset=utf-8');

// ── CORS ──
// 通过 config.php / config.local.php 的 api.cors.allowed_origins 配置跨域来源：
//   ['*']                                  放开所有来源
//   ['https://a.com', 'https://b.com']     精确白名单
//   ['https://*.example.com']              通配子域
// 也兼容逗号/空格分隔的字符串写法（如 "https://a.com,https://*.b.com"）。
$corsCfg = isset($config['api']['cors']) ? $config['api']['cors'] : [];

$allowedMethods = isset($corsCfg['allowed_methods']) && is_array($corsCfg['allowed_methods'])
    ? implode(', ', $corsCfg['allowed_methods'])
    : 'GET, POST, OPTIONS';

$allowedOrigins = isset($corsCfg['allowed_origins']) ? $corsCfg['allowed_origins'] : ['*'];
if (is_string($allowedOrigins)) {
    $allowedOrigins = preg_split('/[\s,]+/', $allowedOrigins, -1, PREG_SPLIT_NO_EMPTY);
}
if (!is_array($allowedOrigins) || empty($allowedOrigins)) {
    $allowedOrigins = ['*'];
}

$allowAll      = in_array('*', $allowedOrigins, true);
$requestOrigin = isset($_SERVER['HTTP_ORIGIN']) ? trim($_SERVER['HTTP_ORIGIN']) : '';
$allowOrigin   = '';

if ($allowAll) {
    // 放开所有来源（使用通配符，不携带凭据；本系统 API 鉴权走 Header Token，不依赖 Cookie）
    $allowOrigin = '*';
} elseif ($requestOrigin !== '') {
    foreach ($allowedOrigins as $pattern) {
        if ($pattern === $requestOrigin) {
            $allowOrigin = $requestOrigin;
            break;
        }
        // 通配子域：https://*.example.com 匹配任意单层/多层子域
        if (is_string($pattern) && strpos($pattern, '*') !== false) {
            $regex = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $requestOrigin)) {
                $allowOrigin = $requestOrigin;
                break;
            }
        }
    }
}

if ($allowOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $allowOrigin);
    header('Vary: Origin');
    // 仅在使用具体来源（非 *）且配置允许时下发凭据头
    if ($allowOrigin !== '*' && !empty($corsCfg['allow_credentials'])) {
        header('Access-Control-Allow-Credentials: true');
    }
}

// 允许的请求头：以配置为准，并始终放行鉴权相关头
$allowedHeaders = isset($corsCfg['allowed_headers']) && is_array($corsCfg['allowed_headers'])
    ? $corsCfg['allowed_headers']
    : ['Content-Type', 'Authorization', 'X-Requested-With'];
foreach (['Content-Type', 'Authorization', 'X-Requested-With', 'X-Bot-Key'] as $h) {
    if (!in_array($h, $allowedHeaders, true)) {
        $allowedHeaders[] = $h;
    }
}
header('Access-Control-Allow-Headers: ' . implode(', ', $allowedHeaders));

if (!empty($corsCfg['exposed_headers']) && is_array($corsCfg['exposed_headers'])) {
    header('Access-Control-Expose-Headers: ' . implode(', ', $corsCfg['exposed_headers']));
}
if (!empty($corsCfg['max_age'])) {
    header('Access-Control-Max-Age: ' . (int)$corsCfg['max_age']);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── 加载核心类 ──
require_once __DIR__ . '/../core/DatabaseDriverInterface.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/ChatService.php';

// ── 初始化数据库 ──
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    json_error(500, 'database_init_failed',
        isset($config['app']['debug']) && $config['app']['debug']
            ? $e->getMessage()
            : '服务暂不可用，请检查 data/ 目录是否可写');
} catch (Throwable $e) {
    json_error(500, 'fatal_error', $e->getMessage());
}

// ════════════════════════════════════════════
//  公共辅助函数
// ════════════════════════════════════════════

/**
 * 输出 JSON 响应并终止
 *
 * 全部 API 的唯一出口，负责把响应归一化为统一信封：
 *   { "success": bool, "data": object|array|null, "error": {code,message}|null }
 *
 * 以 HTTP 状态码为判定依据（避免业务字段名歧义）：
 *   - 4xx/5xx：归一为错误信封。`error` 为对象时原样保留；为字符串（旧式）时收拢为
 *     {code,message}，其余字段（如 retry_after）合并进 error；
 *   - 2xx：归一为成功信封。已是 {success,data[,error]} 标准信封则保留其 data；
 *     形如 ['data'=>...]（可含 message）的直接作为 data（避免 data.data）；
 *     其余（旧式成功负载）剥离遗留 success 后整体作为 data（含 message）。
 *
 * 注意：业务字段请勿与顶层 success/data/error 重名（2xx 下 error 仅作普通字段处理）。
 *
 * @param int   $code HTTP 状态码
 * @param mixed $data 响应负载
 */
function json_response($code, $data) {
    $code    = (int)$code;
    $isError = $code >= 400;

    if (!is_array($data)) {
        // 非数组：成功原样作为 data；错误包成 error
        $body = $isError
            ? ['success' => false, 'data' => null, 'error' => ['code' => 'error', 'message' => (string)$data]]
            : ['success' => true, 'data' => $data, 'error' => null];
    } elseif ($isError) {
        if (isset($data['error']) && is_array($data['error'])) {
            // 已是标准错误信封（由 json_error 构造）
            $err = $data['error'];
        } else {
            // 旧式错误负载：['error' => code, 'message' => msg, ...]
            $err = [
                'code'    => isset($data['error']) ? $data['error'] : 'error',
                'message' => isset($data['message']) ? $data['message'] : '',
            ];
            $extra = $data;
            unset($extra['error'], $extra['message'], $extra['success'], $extra['data']);
            if (!empty($extra)) {
                $err = array_merge($err, $extra);
            }
        }
        $body = ['success' => false, 'data' => null, 'error' => $err];
    } else {
        // 2xx 成功
        if (array_key_exists('success', $data) && array_key_exists('data', $data)) {
            // 已是标准成功信封
            $body = ['success' => true, 'data' => $data['data'], 'error' => null];
        } elseif (array_key_exists('data', $data)
            && count(array_diff(array_keys($data), ['data', 'message'])) === 0) {
            // 形如 ['data' => ...]（可含 message）→ 直接作为 data，避免 data.data
            $body = ['success' => true, 'data' => $data['data'], 'error' => null];
        } else {
            // 旧式成功负载：剥离遗留 success，其余（含 message）整体作为 data
            unset($data['success']);
            $body = ['success' => true, 'data' => $data, 'error' => null];
        }
    }

    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 成功响应快捷方式
 * 信封：{success:true, data:<payload>, error:null}
 *
 * $message 为人类可读提示，统一写入 data.message（信封本身不含顶层 message）；
 * 仅当 $data 为空数组或关联数组、且其内部尚无 message 时注入，避免破坏列表型载荷。
 *
 * @param mixed  $data    响应负载
 * @param string $message 人类可读提示
 * @param int    $status  HTTP 状态码（默认 200；创建类资源可传 201）
 */
function json_success($data = [], $message = 'ok', $status = 200) {
    if (is_array($data)
        && $message !== null && $message !== '' && $message !== 'ok'
        && !array_key_exists('message', $data)
        && ($data === [] || $data !== array_values($data))) {
        $data['message'] = $message;
    }
    json_response((int)$status, ['success' => true, 'data' => $data, 'error' => null]);
}

/**
 * 错误响应快捷方式
 * 信封：{success:false, data:null, error:{code,message}}
 *
 * @param int    $code    HTTP 状态码
 * @param string $error   机器可读错误码
 * @param string $message 人类可读信息
 * @param array  $extra   附加到 error 对象的字段（如 retry_after）
 */
function json_error($code, $error, $message, $extra = []) {
    $err = ['code' => $error, 'message' => $message];
    if (is_array($extra) && !empty($extra)) {
        $err = array_merge($err, $extra);
    }
    json_response($code, ['success' => false, 'data' => null, 'error' => $err]);
}

/**
 * 强制请求方法（不匹配则 405 并终止）
 * @param string $method 允许的方法，如 'POST'
 */
function require_method($method) {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        json_error(405, 'method_not_allowed', '仅支持 ' . strtoupper($method) . ' 请求');
    }
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
 * 计算会话令牌的存储哈希。
 * 数据库只保存哈希（sha256），不保存明文令牌；
 * 即使数据库/JSON 文件泄露，攻击者也无法直接复用会话。
 */
function hash_token($token) {
    return hash('sha256', (string)$token);
}

/**
 * 按令牌查找会话。
 * 优先按哈希查询；命中旧版明文记录时自动升级为哈希存储（向后兼容，无需强制重新登录）。
 *
 * @param string $token 明文令牌
 * @return array|null 会话记录（token 字段已归一化为哈希）
 */
function get_session_by_token($token) {
    global $db;
    if ($token === '') {
        return null;
    }

    $session = $db->get('sessions', ['token' => hash_token($token)]);
    if ($session) {
        return $session;
    }

    // 兼容旧数据：历史上 token 以明文入库，命中后升级
    $legacy = $db->get('sessions', ['token' => $token]);
    if ($legacy) {
        try {
            $db->update('sessions', ['token' => hash_token($token)], ['id' => $legacy['id']]);
        } catch (Exception $e) {
            // 升级失败不阻塞业务
        }
        $legacy['token'] = hash_token($token);
        return $legacy;
    }

    return null;
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
 * 构建敏感词匹配正则（同一进程内只编译一次）
 * 长词优先，避免短词先替换导致长词无法命中；使用 /iu 支持 Unicode 与大小写不敏感。
 * @return string|null 无敏感词时返回 null
 */
function get_sensitive_words_pattern() {
    static $pattern = null;
    static $built = false;
    if ($built) {
        return $pattern;
    }
    $built = true;

    $words = [];
    foreach (get_sensitive_words() as $word) {
        $word = trim($word);
        if ($word !== '') {
            $words[] = $word;
        }
    }
    if (empty($words)) {
        return $pattern = null;
    }

    usort($words, function ($a, $b) {
        return strlen($b) - strlen($a);
    });
    $alts = array_map(function ($w) {
        return preg_quote($w, '/');
    }, $words);
    $pattern = '/(' . implode('|', $alts) . ')/iu';
    return $pattern;
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

    $pattern = get_sensitive_words_pattern();
    if ($pattern === null) {
        return $content;
    }

    $result = preg_replace($pattern, '***', $content);
    return $result === null ? $content : $result;
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
/**
 * 检查用户封禁状态（未过期封禁 → 403；已过期 → 自动解除）
 * 供 authenticate / login 使用，覆盖 Bot Key 与 Token 两条认证路径。
 */
function check_user_banned($db, $user) {
    try {
        $ban = $db->get('bans', ['user_id' => $user['id']]);
        if ($ban) {
            $exp = isset($ban['expires_at']) ? $ban['expires_at'] : '';
            $expTs = ($exp !== '' && $exp !== null) ? strtotime($exp) : 0;
            if ($expTs === 0 || $expTs > time()) {
                $msg = '账号已被封禁';
                if ($expTs > 0) {
                    $msg .= '，至 ' . $exp;
                }
                if (!empty($ban['reason'])) {
                    $msg .= '（原因：' . $ban['reason'] . '）';
                }
                json_response(403, ['error' => 'account_banned', 'message' => $msg]);
            }
            // 已过期：自动解除
            $db->delete('bans', ['id' => $ban['id']]);
        }
    } catch (Exception $e) {
        // 忽略查询错误（旧数据结构）
    }
}

/**
 * 获取服务器当前可用的通知方式
 * pushplus / webhook 为用户级凭据（默认可用）；email 依赖 SMTP 配置
 * @return array<int, string> 可用方式 id 列表，如 ['pushplus','webhook']
 */
function notification_available_methods($config)
{
    $available = [];
    $methods = isset($config['notifications']['methods']) ? $config['notifications']['methods'] : [];

    foreach ($methods as $name => $cfg) {
        if (empty($cfg['enabled'])) {
            continue;
        }
        if ($name === 'email') {
            // 邮件需真实 SMTP（host + username + password），占位配置不算可用
            $smtp = isset($cfg['smtp']) ? $cfg['smtp'] : [];
            if (empty($smtp['host']) || empty($smtp['username']) || empty($smtp['password'])
                || strpos($smtp['host'], 'example.com') !== false) {
                continue;
            }
        }
        $available[] = $name;
    }
    return $available;
}

/**
 * 可选认证：有有效 Token 返回用户（不含密码），否则返回 null，不中断请求。
 * 用于游客可访问、登录后展示个性化内容的端点（频道列表 / 服务器状态等）。
 *
 * @return array|null
 */
function optional_authenticate() {
    global $db;

    $token = get_bearer_token();
    if ($token === '') {
        return null;
    }

    $session = get_session_by_token($token);
    if (!$session) {
        return null;
    }

    if (isset($session['expires_at']) && strtotime($session['expires_at']) < time()) {
        $db->delete('sessions', ['id' => $session['id']]);
        return null;
    }

    $user = $db->get('users', ['id' => $session['user_id']]);
    if (!$user || (isset($user['status']) && (int)$user['status'] !== 1)) {
        return null;
    }

    maybe_refresh_token($session, $user);
    unset($user['password']);
    return $user;
}

function authenticate() {
    global $db, $config;

    // ── 先尝试 Bot Key ──
    $botKey = get_bot_key();
    if ($botKey !== '') {
        $keyRow = $db->get('bot_keys', ['api_key' => $botKey]);
        if ($keyRow && isset($keyRow['active']) && (int)$keyRow['active'] === 1) {
            $user = $db->get('users', ['id' => $keyRow['user_id']]);
            if ($user && isset($user['status']) && (int)$user['status'] === 1) {
                check_user_banned($db, $user);
                // 更新最后使用时间（每分钟最多一次，避免高并发热点写）
                $lastUsedTs = isset($keyRow['last_used_at']) && $keyRow['last_used_at'] !== ''
                    ? strtotime($keyRow['last_used_at']) : 0;
                if ($lastUsedTs === false || time() - $lastUsedTs >= 60) {
                    $db->update('bot_keys', ['last_used_at' => date('Y-m-d H:i:s')], ['id' => $keyRow['id']]);
                }
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

    $session = get_session_by_token($token);
    if (!$session) {
        json_response(401, ['error' => 'invalid_token', 'message' => '令牌无效，请重新登录']);
    }

    // 检查过期
    if (isset($session['expires_at']) && strtotime($session['expires_at']) < time()) {
        $db->delete('sessions', ['id' => $session['id']]);
        json_response(401, ['error' => 'token_expired', 'message' => '令牌已过期，请重新登录']);
    }

    $user = $db->get('users', ['id' => $session['user_id']]);
    if (!$user) {
        json_response(401, ['error' => 'user_not_found', 'message' => '用户不存在']);
    }

    if (isset($user['status']) && (int)$user['status'] !== 1) {
        json_response(403, ['error' => 'account_disabled', 'message' => '账号已被禁用']);
    }

    check_user_banned($db, $user);

    // 更新最后活跃时间（每分钟最多一次，避免每次请求重写整个 users 表）
    $lastActiveTs = isset($user['last_active_at']) && $user['last_active_at'] !== ''
        ? strtotime($user['last_active_at']) : 0;
    if ($lastActiveTs === false || time() - $lastActiveTs >= 60) {
        $db->update('users', ['last_active_at' => date('Y-m-d H:i:s')], ['id' => $user['id']]);
    }

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
    global $config;

    if ($user_role === $required_role) {
        return true;
    }

    $rolesCfg = isset($config['user']['roles']) ? $config['user']['roles'] : [];

    // 用户角色已在配置中定义：沿 extends 链向上查找是否包含所需角色
    if (isset($rolesCfg[$user_role])) {
        $role = $user_role;
        $seen = [];
        while ($role !== null && isset($rolesCfg[$role]) && !isset($seen[$role])) {
            if ($role === $required_role) {
                return true;
            }
            $seen[$role] = true;
            $role = isset($rolesCfg[$role]['extends']) ? $rolesCfg[$role]['extends'] : null;
        }
        return false;
    }

    // 回退：内置层级（角色未在配置中定义时）
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

    // 剩余时间不足一半时自动续期（按会话 id 更新，避免依赖明文令牌）
    if ($remaining < $sessionLifetime / 2 && isset($session['id'])) {
        $newExpires = date('Y-m-d H:i:s', time() + $sessionLifetime);
        $db->update('sessions', ['expires_at' => $newExpires], ['id' => $session['id']]);
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

/**
 * 用户/动作级速率限制（基于文件计数窗口，flock 并发安全）
 * 与 apply_ip_rate_limit 不同：窗口按整分钟对齐（floor(now/window)*window），
 * 保证"每 N 秒最多 X 次"的真实语义（滑窗式会在每次请求时顺延起点而失效）。
 * @param string $bucket 限流桶标识（如 'test_notif:' . userId）
 * @param int    $limit  窗口内允许次数
 * @param int    $window 窗口秒数，默认 60
 * @return void 超限时直接 json_response(429) 并终止
 */
function apply_user_rate_limit($bucket, $limit, $window = 60)
{
    global $config;
    if ($limit <= 0) {
        return;
    }
    $dir = isset($config['database']['default']['local']['data_path'])
        ? rtrim($config['database']['default']['local']['data_path'], '/') . '/rate_limit'
        : __DIR__ . '/../data/rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file = $dir . '/user_' . md5($bucket) . '.json';
    $now = time();
    $windowStart = (int)floor($now / $window) * $window;
    $windowEnd = $windowStart + $window;

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return; // 限流文件不可写时放行，避免误伤
    }
    @flock($fp, LOCK_EX);

    $content = stream_get_contents($fp);
    $count = 0;
    if ($content !== false && $content !== '') {
        $saved = @json_decode($content, true);
        if (is_array($saved) && isset($saved['window']) && (int)$saved['window'] === $windowStart) {
            $count = (int)$saved['count'];
        }
    }

    if ($count >= $limit) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
        $retryAfter = max(1, $windowEnd - $now);
        header('X-RateLimit-Retry-After: ' . $retryAfter);
        json_response(429, [
            'error'      => 'rate_limited',
            'message'    => '操作过于频繁，每分钟最多 ' . $limit . ' 次，请 ' . $retryAfter . ' 秒后重试',
            'retry_after' => $retryAfter,
        ]);
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(['window' => $windowStart, 'count' => $count + 1]));
    fflush($fp);

    @flock($fp, LOCK_UN);
    @fclose($fp);
}

// ── 执行 IP 速率限制（放在最后，依赖上面的辅助函数） ──
apply_ip_rate_limit();

/**
 * 使用统计（近似流量 + 请求数，按自然月累计）
 *
 * 流量为近似值：请求体（CONTENT_LENGTH）+ 响应体（output buffer 字节数），
 * 仅统计 API 应用层流量，不含前端静态资源与真实 TCP 开销。
 * 统计写入 data/usage.json，每月 1 号自动重置（等价原配置 reset_day=1）。
 */
function track_usage() {
    global $config;

    // 近似流量 = 请求体 + 响应体
    $reqBytes = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    $respBytes = ob_get_length();
    if ($respBytes === false) {
        $respBytes = 0;
    }
    $totalBytes = $reqBytes + $respBytes;
    if ($totalBytes <= 0) {
        return;
    }

    $dir = isset($config['database']['default']['local']['data_path'])
        ? rtrim($config['database']['default']['local']['data_path'], '/')
        : __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/usage.json';

    $month = date('Y-m');

    // 读-改-写放入 flock 临界区，保证并发安全
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return;
    }
    @flock($fp, LOCK_EX);

    $stats = [
        'month'           => $month,
        'network_flow_mb' => 0,
        'total_requests'  => 0,
        'last_reset_date' => date('Y-m-d', strtotime($month . '-01')),
    ];
    $content = stream_get_contents($fp);
    if ($content !== false && $content !== '') {
        $saved = @json_decode($content, true);
        if (is_array($saved)) {
            $stats = array_merge($stats, $saved);
        }
    }

    // 跨月自动重置（每月 1 号开始新周期）
    if ($stats['month'] !== $month) {
        $stats['month']           = $month;
        $stats['network_flow_mb'] = 0;
        $stats['total_requests']  = 0;
        $stats['last_reset_date'] = date('Y-m-d', strtotime($month . '-01'));
    }

    $stats['network_flow_mb'] = round((float)$stats['network_flow_mb'] + $totalBytes / 1048576, 3);
    $stats['total_requests']  = (int)$stats['total_requests'] + 1;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($stats));
    fflush($fp);

    @flock($fp, LOCK_UN);
    @fclose($fp);
}

// ── 维护清理（概率触发，适配无 cron 的虚拟主机） ──
// 按 config.maintenance 配置执行：过期会话 / 超期消息 / 孤立上传文件。
// 每次请求按 request_probability 概率触发一次，清理失败不影响业务请求。
function maintenance_cleanup() {
    global $db, $config;
    try {
        $cfg = $config['maintenance'] ?? [];
        $auto = $cfg['auto_cleanup'] ?? [];
        if (empty($auto['enabled'])) {
            return;
        }
        $prob = (float)($auto['request_probability'] ?? 0.001);
        if ($prob <= 0 || mt_rand(1, 1000000) > (int)($prob * 1000000)) {
            return;
        }

        $now = time();

        // 批量删除包裹在一个事务里：LocalDriver 下把逐条写盘合并为每表一次落盘
        $db->beginTransaction();
        try {
            // 1. 会话清理：删除已过期超过 delete_expired_hours 的会话
            $scfg = $cfg['session_cleanup'] ?? [];
            $expiredHours = (int)($scfg['delete_expired_hours'] ?? 24);
            $cutoff = date('Y-m-d H:i:s', $now - $expiredHours * 3600);
            $expired = $db->select('sessions', ['expires_at <' => $cutoff], '*', 'id ASC', 500);
            foreach ($expired as $s) {
                $db->delete('sessions', ['id' => $s['id']]);
            }

            // 2. 消息清理：删除超过 delete_after_days 的旧消息
            $mcfg = $cfg['message_cleanup'] ?? [];
            $days = (int)($mcfg['delete_after_days'] ?? 0);
            if ($days > 0) {
                $msgCutoff = date('Y-m-d H:i:s', $now - $days * 86400);
                $batch = (int)($mcfg['batch_size'] ?? 1000);
                $old = $db->select('messages', ['created_at <' => $msgCutoff], '*', 'id ASC', $batch);
                foreach ($old as $m) {
                    $db->delete('messages', ['id' => $m['id']]);
                }
            }

            // 3. 上传文件清理：删除无关联消息且超过 orphaned_check_days 的孤立文件
            $ucfg = $cfg['upload_cleanup'] ?? [];
            if (!empty($ucfg['delete_orphaned_files'])) {
                $orphanDays = (int)($ucfg['orphaned_check_days'] ?? 7);
                $orphanCutoff = date('Y-m-d H:i:s', $now - $orphanDays * 86400);
                $uploadRoot = isset($config['upload']['local_path'])
                    ? rtrim($config['upload']['local_path'], '/') . '/'
                    : dirname(__DIR__) . '/uploads/';
                $orphans = $db->select('uploads', ['message_id' => [null, 0], 'created_at <' => $orphanCutoff], '*', 'id ASC', 200);
                foreach ($orphans as $u) {
                    $db->delete('uploads', ['id' => $u['id']]);
                    if (isset($u['file_path']) && strpos($u['file_path'], '/uploads/') !== false) {
                        @unlink($uploadRoot . basename($u['file_path']));
                    }
                }
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    } catch (Exception $e) {
        error_log('[maintenance_cleanup] ' . $e->getMessage());
    }
}

// ── 注册使用统计（shutdown 时执行，覆盖所有出口） ──
register_shutdown_function('track_usage');

// ── 概率触发维护清理 ──
maintenance_cleanup();
