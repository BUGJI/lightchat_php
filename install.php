<?php
/**
 * LightChat 安装向导
 *
 * 功能：
 *   1. 环境检查（PHP 版本、扩展、目录可写）
 *   2. 服务器设置（站点名称、时区、调试模式）
 *   3. 配额与限制设置（流量、磁盘、带宽、上传、消息、安全）
 *   4. 创建管理员账号（首个用户，role=admin）
 *   5. 初始化默认频道
 *   6. 生成 config.local.php（运行时配置，覆盖 config.php 默认值）并锁定安装
 *
 * 使用：
 *   浏览器访问 http://你的域名/install.php → 填写表单 → 安装完成
 *   安装完成后请删除本文件；再次安装需删除 data/installed.lock 与 config.local.php
 *
 * 兼容：PHP 7.4+ / 虚拟主机（LocalDriver）
 */

// ── 会话（防 CSRF 一次性 token） ──
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// ── mbstring 兼容（本文件独立运行，不依赖 bootstrap 的 polyfill） ──
if (!function_exists('mb_strlen')) {
    function mb_strlen($str, $encoding = null) { return strlen($str); }
    function mb_substr($str, $start, $length = null, $encoding = null) {
        return $length === null ? substr($str, $start) : substr($str, $start, $length);
    }
}

// ── 路径常量 ──
define('LC_ROOT', __DIR__);
define('LC_DATA', LC_ROOT . '/data');
define('LC_LOCK', LC_DATA . '/installed.lock');
define('LC_LOCAL_CFG', LC_ROOT . '/config.local.php');

// ── 默认配置（用于表单回填与最终合并） ──
$config = require LC_ROOT . '/config.php';

// ── 是否已安装 ──
function lc_installed() {
    return file_exists(LC_LOCK);
}

// ── 环境检查 ──
function lc_checks() {
    $checks = [];
    $checks[] = ['php', PHP_VERSION >= '7.4', 'PHP 版本 ' . PHP_VERSION . '（要求 >= 7.4）'];
    foreach (['json', 'pcre', 'ctype', 'fileinfo'] as $ext) {
        $checks[] = ['ext_' . $ext, extension_loaded($ext), '扩展 ' . $ext . (extension_loaded($ext) ? '' : ' 缺失')];
    }
    $checks[] = ['ext_mbstring', extension_loaded('mbstring'), '扩展 mbstring（缺失时使用内置 polyfill 降级）'];
    foreach (['data' => LC_DATA, 'uploads' => LC_ROOT . '/uploads', 'logs' => LC_ROOT . '/logs'] as $name => $dir) {
        $writable = is_dir($dir) ? is_writable($dir) : @mkdir($dir, 0755, true) && is_writable($dir);
        $checks[] = ['dir_' . $name, $writable, '目录 ' . $name . ($writable ? ' 可写' : ' 不可写（请检查权限）')];
    }
    $checks[] = ['local_cfg', !file_exists(LC_LOCAL_CFG), 'config.local.php 不存在（重复安装需先删除）'];
    $checks[] = ['lock', !lc_installed(), 'data/installed.lock 不存在（已安装则本向导不可用）'];
    return $checks;
}

// ── 帮助函数：写文件（原子写） ──
function lc_write_atomic($file, $content) {
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return @file_put_contents($file, $content, LOCK_EX) !== false;
    }
    return true;
}

// ── 生成 config.local.php ──
function lc_write_local_config($cfg) {
    $php = "<?php\n"
        . "/**\n"
        . " * LightChat 运行时配置（由 install.php 生成，请勿手工编辑）\n"
        . " * 生成时间：" . date('Y-m-d H:i:s') . "\n"
        . " * 删除本文件与 data/installed.lock 可重新执行安装向导\n"
        . " */\nreturn "
        . var_export($cfg, true)
        . ";\n";
    return lc_write_atomic(LC_LOCAL_CFG, $php);
}

// ── 校验 POST 输入，返回 [errors, values] ──
function lc_validate($in) {
    global $config;
    $errors = [];
    $v = [];

    // 站点名称
    $v['app_name'] = trim(isset($in['app_name']) ? $in['app_name'] : '');
    if ($v['app_name'] === '' || mb_strlen($v['app_name']) > 50) {
        $errors[] = '站点名称不能为空且不超过 50 字符';
    }

    // 时区白名单
    $timezones = [
        'Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Taipei', 'Asia/Tokyo',
        'Asia/Singapore', 'Asia/Seoul', 'Asia/Kolkata', 'Europe/London',
        'Europe/Berlin', 'Europe/Paris', 'America/New_York', 'America/Los_Angeles',
        'Australia/Sydney', 'UTC',
    ];
    $v['timezone'] = isset($in['timezone']) ? $in['timezone'] : 'Asia/Shanghai';
    if (!in_array($v['timezone'], $timezones, true)) {
        $errors[] = '时区不在允许列表';
    }

    // 调试模式
    $v['debug'] = isset($in['debug']) ? 1 : 0;

    // 数字类配置（正数）
    $numeric = [
        'quota_flow_mb'     => '月流量限制',
        'quota_disk_mb'     => '磁盘空间限制',
        'quota_conn'        => '最大并发连接数',
        'bandwidth_up'      => '上传带宽',
        'bandwidth_down'    => '下载带宽',
        'msg_max_length'    => '单条消息最大长度',
        'msg_cooldown'      => '消息冷却秒数',
        'channel_max_user'  => '每用户频道数上限',
        'channel_max_member'=> '单频道成员数上限',
        'login_max_fail'    => '登录失败锁定次数',
        'login_lock_min'    => '登录锁定分钟数',
        'ip_rpm'            => '单 IP 每分钟请求上限',
    ];
    foreach ($numeric as $key => $label) {
        $raw = isset($in[$key]) ? trim($in[$key]) : '';
        if (!preg_match('/^\d+$/', $raw) || (int)$raw <= 0) {
            $errors[] = $label . ' 必须为正整数';
            $v[$key] = 0;
        } else {
            $v[$key] = (int)$raw;
        }
    }

    // 开关类
    $v['upload_enabled'] = isset($in['upload_enabled']) ? 1 : 0;
    $v['sensitive_enabled'] = isset($in['sensitive_enabled']) ? 1 : 0;

    // 管理员账号
    $v['admin_username'] = trim(isset($in['admin_username']) ? $in['admin_username'] : '');
    $v['admin_email'] = trim(isset($in['admin_email']) ? $in['admin_email'] : '');
    $v['admin_password'] = isset($in['admin_password']) ? $in['admin_password'] : '';
    $v['admin_password2'] = isset($in['admin_password2']) ? $in['admin_password2'] : '';

    $uCfg = isset($config['user']['username']) ? $config['user']['username'] : [];
    $minLen = isset($uCfg['min_length']) ? (int)$uCfg['min_length'] : 3;
    $maxLen = isset($uCfg['max_length']) ? (int)$uCfg['max_length'] : 20;
    $pattern = isset($uCfg['pattern']) ? $uCfg['pattern'] : '/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u';

    $uLen = function_exists('mb_strlen') ? mb_strlen($v['admin_username'], 'UTF-8') : strlen($v['admin_username']);
    if ($v['admin_username'] === '' || $uLen < $minLen || $uLen > $maxLen) {
        $errors[] = "管理员用户名长度应为 {$minLen}-{$maxLen} 个字符";
    } elseif (!@preg_match($pattern, $v['admin_username'])) {
        $errors[] = '管理员用户名包含不允许的字符';
    }
    // 注意：管理员允许使用保留用户名（如 admin），保留名仅限制普通注册用户

    if (!filter_var($v['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = '管理员邮箱格式不正确';
    }
    if ($v['admin_password'] === '' || strlen($v['admin_password']) < 6) {
        $errors[] = '管理员密码长度不能少于 6 位';
    }
    if ($v['admin_password'] !== $v['admin_password2']) {
        $errors[] = '两次输入的密码不一致';
    }

    return [$errors, $v];
}

// ── 执行安装 ──
function lc_do_install($v) {
    global $config;

    // 组装运行时配置（只覆盖安装向导涉及到的键）
    $local = [
        'app' => [
            'name'      => $v['app_name'],
            'timezone'  => $v['timezone'],
            'debug'     => (bool)$v['debug'],
        ],
        'server' => [
            'quota' => [
                'monthly_network_flow_mb' => $v['quota_flow_mb'],
                'disk_space_mb'           => $v['quota_disk_mb'],
                'max_connections'         => $v['quota_conn'],
                'max_processes'           => max(1, (int)ceil($v['quota_conn'] / 2)),
            ],
            'bandwidth' => [
                'max_upload_mbps'   => $v['bandwidth_up'],
                'max_download_mbps' => $v['bandwidth_down'],
            ],
        ],
        'message' => [
            'max_length'             => $v['msg_max_length'],
            'cooldown_seconds'       => $v['msg_cooldown'],
            'sensitive_words_enabled'=> (bool)$v['sensitive_enabled'],
        ],
        'chat' => [
            'channel' => [
                'max_per_user' => $v['channel_max_user'],
                'max_members'  => $v['channel_max_member'],
            ],
        ],
        'upload' => [
            'enabled' => (bool)$v['upload_enabled'],
        ],
        'security' => [
            'login_protection' => [
                'enabled'        => true,
                'max_failures'   => $v['login_max_fail'],
                'lockout_minutes'=> $v['login_lock_min'],
                'fail_window'    => 10,
            ],
            'ip_rate_limit' => [
                'enabled'             => true,
                'requests_per_minute' => $v['ip_rpm'],
                'requests_per_hour'   => $v['ip_rpm'] * 10,
                'ban_on_exceed'       => false,
                'ban_duration_minutes'=> 60,
            ],
        ],
    ];

    // 1) 写入 config.local.php
    if (!lc_write_local_config($local)) {
        return ['ok' => false, 'message' => '写入 config.local.php 失败，请检查目录权限'];
    }

    // 2) 初始化数据库（建表）
    $config = array_replace_recursive($config, $local);
    require_once LC_ROOT . '/core/Database.php';
    try {
        $db = Database::getInstance();
    } catch (Exception $e) {
        @unlink(LC_LOCAL_CFG);
        return ['ok' => false, 'message' => '数据库初始化失败：' . $e->getMessage()];
    }

    // 3) 已有用户时拒绝（防止在已有数据上覆盖创建管理员）
    try {
        $userCount = $db->count('users', []);
    } catch (Exception $e) {
        $userCount = 0;
    }
    if ($userCount > 0) {
        @unlink(LC_LOCAL_CFG);
        return ['ok' => false, 'message' => '检测到 data/ 中已存在用户数据，为避免覆盖请先清空 data/ 或删除 config.local.php 后重试'];
    }

    // 4) 创建管理员
    try {
        $adminId = $db->insert('users', [
            'username'     => $v['admin_username'],
            'password'     => password_hash($v['admin_password'], PASSWORD_BCRYPT),
            'email'        => $v['admin_email'],
            'contact'      => '',
            'reg_ip'       => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            'account_type' => 'user',
            'role'         => 'admin',
            'status'       => 1,
        ]);
    } catch (Exception $e) {
        @unlink(LC_LOCAL_CFG);
        return ['ok' => false, 'message' => '创建管理员失败：' . $e->getMessage()];
    }

    // 5) 初始化默认频道（管理员为 owner 并加入）
    $channelCount = 0;
    $defaultChannels = isset($config['chat']['channel']['default_channels'])
        ? $config['chat']['channel']['default_channels'] : [];
    foreach ($defaultChannels as $dc) {
        try {
            $existing = $db->get('channels', ['name' => $dc['name']]);
            if ($existing) {
                continue;
            }
            $cid = $db->insert('channels', [
                'name'         => $dc['name'],
                'display_name' => $dc['display_name'],
                'type'         => $dc['type'],
                'description'  => isset($dc['description']) ? $dc['description'] : '',
                'owner_id'     => $adminId,
                'member_count' => 1,
            ]);
            $db->insert('channel_members', [
                'channel_id' => $cid,
                'user_id'    => $adminId,
                'role'       => 'owner',
            ]);
            $channelCount++;
        } catch (Exception $e) {
            // 单个频道失败不影响安装
        }
    }

    // 6) 写入安装锁
    $lockContent = "installed_at=" . date('Y-m-d H:i:s') . "\n"
        . "site_name={$v['app_name']}\n"
        . "admin={$v['admin_username']}\n"
        . "admin_id={$adminId}\n";
    if (!lc_write_atomic(LC_LOCK, $lockContent)) {
        @unlink(LC_LOCAL_CFG);
        return ['ok' => false, 'message' => '写入安装锁失败，请检查 data/ 目录权限'];
    }

    return [
        'ok' => true,
        'message' => '安装完成',
        'admin'   => $v['admin_username'],
        'channels'=> $channelCount,
    ];
}

// ═══════════════════════ 请求处理 ═══════════════════════

$installed = lc_installed();
$errors = [];
$success = null;
$formValues = [];
$tokenOk = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    $token = isset($_POST['install_token']) ? $_POST['install_token'] : '';
    if (isset($_SESSION['install_token']) && hash_equals($_SESSION['install_token'], $token)) {
        $tokenOk = true;
    }
    if (!$tokenOk) {
        $errors[] = '安全校验失败，请刷新页面重试';
    } else {
        list($errs, $vals) = lc_validate($_POST);
        $errors = $errs;
        $formValues = $vals;
        if (empty($errors)) {
            $success = lc_do_install($vals);
        }
    }
}

// 生成防 CSRF token（GET 或校验失败时刷新）
if (!isset($_SESSION['install_token'])) {
    $_SESSION['install_token'] = bin2hex(random_bytes(16));
}

// ── 表单回填默认值 ──
$form = [
    'app_name'      => isset($formValues['app_name']) ? $formValues['app_name'] : $config['app']['name'],
    'timezone'      => isset($formValues['timezone']) ? $formValues['timezone'] : $config['app']['timezone'],
    'debug'         => isset($formValues['debug']) ? $formValues['debug'] : (int)$config['app']['debug'],
    'quota_flow_mb' => isset($formValues['quota_flow_mb']) ? $formValues['quota_flow_mb'] : $config['server']['quota']['monthly_network_flow_mb'],
    'quota_disk_mb' => isset($formValues['quota_disk_mb']) ? $formValues['quota_disk_mb'] : $config['server']['quota']['disk_space_mb'],
    'quota_conn'    => isset($formValues['quota_conn']) ? $formValues['quota_conn'] : $config['server']['quota']['max_connections'],
    'bandwidth_up'  => isset($formValues['bandwidth_up']) ? $formValues['bandwidth_up'] : $config['server']['bandwidth']['max_upload_mbps'],
    'bandwidth_down'=> isset($formValues['bandwidth_down']) ? $formValues['bandwidth_down'] : $config['server']['bandwidth']['max_download_mbps'],
    'msg_max_length'=> isset($formValues['msg_max_length']) ? $formValues['msg_max_length'] : $config['message']['max_length'],
    'msg_cooldown'  => isset($formValues['msg_cooldown']) ? $formValues['msg_cooldown'] : $config['message']['cooldown_seconds'],
    'channel_max_user'  => isset($formValues['channel_max_user']) ? $formValues['channel_max_user'] : $config['chat']['channel']['max_per_user'],
    'channel_max_member'=> isset($formValues['channel_max_member']) ? $formValues['channel_max_member'] : $config['chat']['channel']['max_members'],
    'upload_enabled'=> isset($formValues['upload_enabled']) ? $formValues['upload_enabled'] : (int)$config['upload']['enabled'],
    'sensitive_enabled'=> isset($formValues['sensitive_enabled']) ? $formValues['sensitive_enabled'] : (int)$config['message']['sensitive_words_enabled'],
    'login_max_fail'=> isset($formValues['login_max_fail']) ? $formValues['login_max_fail'] : $config['security']['login_protection']['max_failures'],
    'login_lock_min'=> isset($formValues['login_lock_min']) ? $formValues['login_lock_min'] : $config['security']['login_protection']['lockout_minutes'],
    'ip_rpm'        => isset($formValues['ip_rpm']) ? $formValues['ip_rpm'] : $config['security']['ip_rate_limit']['requests_per_minute'],
];

$checks = lc_checks();
$allChecksOk = true;
foreach ($checks as $c) {
    if (!$c[1]) {
        $allChecksOk = false;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LightChat 安装向导</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
        background: #f0f4f8; color: #1f2937; line-height: 1.6;
        padding: 24px 12px;
    }
    .wrap { max-width: 720px; margin: 0 auto; }
    .card {
        background: #fff; border-radius: 12px; padding: 28px 32px;
        box-shadow: 0 2px 12px rgba(0,0,0,.08); margin-bottom: 20px;
    }
    h1 { font-size: 22px; margin-bottom: 4px; }
    .sub { color: #6b7280; font-size: 14px; margin-bottom: 20px; }
    h2 { font-size: 16px; margin: 24px 0 14px; padding-bottom: 8px; border-bottom: 1px solid #e5e7eb; }
    h2:first-child { margin-top: 0; }
    .field { margin-bottom: 14px; }
    label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 4px; }
    .hint { font-size: 12px; color: #9ca3af; font-weight: 400; margin-left: 6px; }
    input[type=text], input[type=password], input[type=email], select {
        width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px;
        font-size: 14px; background: #fff; color: #1f2937;
    }
    input:focus, select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
    .row { display: flex; gap: 14px; flex-wrap: wrap; }
    .row .field { flex: 1; min-width: 200px; }
    .check { display: flex; align-items: center; gap: 8px; }
    .check input { width: 16px; height: 16px; }
    .check label { margin: 0; font-weight: 500; }
    .checks { list-style: none; }
    .checks li { padding: 6px 0; font-size: 14px; display: flex; gap: 8px; align-items: center; }
    .ok { color: #059669; font-weight: 700; }
    .bad { color: #dc2626; font-weight: 700; }
    .warn { color: #d97706; }
    .btn {
        display: inline-block; background: #2563eb; color: #fff; border: none; cursor: pointer;
        padding: 12px 28px; border-radius: 8px; font-size: 15px; font-weight: 600;
    }
    .btn:hover { background: #1d4ed8; }
    .btn:disabled { background: #9ca3af; cursor: not-allowed; }
    .alert { padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
    .alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .alert-ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .banner { background: #fefce8; color: #a16207; border: 1px solid #fde68a; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; }
    .success-box { text-align: center; padding: 12px 0 4px; }
    .success-box .big { font-size: 44px; margin-bottom: 8px; }
    .code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-family: ui-monospace, monospace; font-size: 13px; }
    .small { font-size: 12px; color: #6b7280; }
</style>
</head>
<body>
<div class="wrap">

<?php if ($installed): ?>
    <div class="card">
        <h1>🚫 系统已安装</h1>
        <p class="sub">LightChat 已完成安装，安装向导已锁定。</p>
        <div class="alert alert-ok">检测到 <span class="code">data/installed.lock</span>。如需重新安装，请删除该文件与 <span class="code">config.local.php</span>，然后再次访问本页面。</div>
        <p><a href="index.html" style="color:#2563eb">← 返回首页</a></p>
    </div>

<?php elseif ($success && $success['ok']): ?>
    <div class="card">
        <div class="success-box">
            <div class="big">✅</div>
            <h1>安装完成！</h1>
            <p class="sub">管理员 <b><?php echo htmlspecialchars($success['admin']); ?></b> 已创建，默认频道初始化 <?php echo (int)$success['channels']; ?> 个。</p>
            <div class="alert alert-ok" style="text-align:left">
                <b>接下来的步骤：</b><br>
                1. 访问 <span class="code">/index.html</span> 登录（账号：<?php echo htmlspecialchars($success['admin']); ?>）<br>
                2. <b>立即删除</b> 服务器上的 <span class="code">install.php</span> 文件，防止被他人利用<br>
                3. 需要调整配置时直接编辑 <span class="code">config.php</span> 或 <span class="code">config.local.php</span>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="card">
        <h1>⚡ LightChat 安装向导</h1>
        <p class="sub">配置服务器与配额，并创建管理员账号。完成后请删除本文件。</p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <b>安装未完成：</b><br>
                <?php foreach ($errors as $e) { echo htmlspecialchars($e) . '<br>'; } ?>
            </div>
        <?php endif; ?>

        <h2>① 环境检查</h2>
        <ul class="checks">
            <?php foreach ($checks as $c): ?>
                <li>
                    <span class="<?php echo $c[1] ? 'ok' : 'bad'; ?>"><?php echo $c[1] ? '✓' : '✗'; ?></span>
                    <span><?php echo htmlspecialchars($c[2]); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if (!$allChecksOk): ?>
            <div class="banner">存在未通过的检查项，请先修复后刷新页面再继续。</div>
        <?php endif; ?>

        <form method="post" action="install.php" id="installForm" <?php echo $allChecksOk ? '' : 'data-disabled="1"'; ?>>
            <input type="hidden" name="install_token" value="<?php echo htmlspecialchars($_SESSION['install_token']); ?>">

            <h2>② 服务器设置</h2>
            <div class="field">
                <label>站点名称</label>
                <input type="text" name="app_name" value="<?php echo htmlspecialchars($form['app_name']); ?>" maxlength="50" required>
            </div>
            <div class="field">
                <label>站点时区</label>
                <select name="timezone">
                    <?php foreach (['Asia/Shanghai','Asia/Hong_Kong','Asia/Taipei','Asia/Tokyo','Asia/Singapore','Asia/Seoul','Asia/Kolkata','Europe/London','Europe/Berlin','Europe/Paris','America/New_York','America/Los_Angeles','Australia/Sydney','UTC'] as $tz): ?>
                        <option value="<?php echo $tz; ?>" <?php echo $form['timezone'] === $tz ? 'selected' : ''; ?>><?php echo $tz; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field check">
                <input type="checkbox" id="debug" name="debug" value="1" <?php echo $form['debug'] ? 'checked' : ''; ?>>
                <label for="debug">调试模式（生产环境请勿开启）</label>
            </div>

            <h2>③ 配额与限制</h2>
            <div class="row">
                <div class="field">
                    <label>月流量限制 <span class="hint">MB</span></label>
                    <input type="number" name="quota_flow_mb" min="1" value="<?php echo (int)$form['quota_flow_mb']; ?>" required>
                </div>
                <div class="field">
                    <label>磁盘空间限制 <span class="hint">MB</span></label>
                    <input type="number" name="quota_disk_mb" min="1" value="<?php echo (int)$form['quota_disk_mb']; ?>" required>
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label>最大并发连接数</label>
                    <input type="number" name="quota_conn" min="1" value="<?php echo (int)$form['quota_conn']; ?>" required>
                </div>
                <div class="field">
                    <label>上传带宽 <span class="hint">Mbps</span></label>
                    <input type="number" name="bandwidth_up" min="1" value="<?php echo (int)$form['bandwidth_up']; ?>" required>
                </div>
                <div class="field">
                    <label>下载带宽 <span class="hint">Mbps</span></label>
                    <input type="number" name="bandwidth_down" min="1" value="<?php echo (int)$form['bandwidth_down']; ?>" required>
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label>单条消息最大长度</label>
                    <input type="number" name="msg_max_length" min="1" value="<?php echo (int)$form['msg_max_length']; ?>" required>
                </div>
                <div class="field">
                    <label>消息冷却 <span class="hint">秒</span></label>
                    <input type="number" name="msg_cooldown" min="1" value="<?php echo (int)$form['msg_cooldown']; ?>" required>
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label>每用户频道数上限</label>
                    <input type="number" name="channel_max_user" min="1" value="<?php echo (int)$form['channel_max_user']; ?>" required>
                </div>
                <div class="field">
                    <label>单频道成员数上限</label>
                    <input type="number" name="channel_max_member" min="1" value="<?php echo (int)$form['channel_max_member']; ?>" required>
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label>登录失败锁定次数</label>
                    <input type="number" name="login_max_fail" min="1" value="<?php echo (int)$form['login_max_fail']; ?>" required>
                </div>
                <div class="field">
                    <label>锁定时长 <span class="hint">分钟</span></label>
                    <input type="number" name="login_lock_min" min="1" value="<?php echo (int)$form['login_lock_min']; ?>" required>
                </div>
                <div class="field">
                    <label>单 IP 每分钟请求上限</label>
                    <input type="number" name="ip_rpm" min="1" value="<?php echo (int)$form['ip_rpm']; ?>" required>
                </div>
            </div>
            <div class="field check">
                <input type="checkbox" id="upload_enabled" name="upload_enabled" value="1" <?php echo $form['upload_enabled'] ? 'checked' : ''; ?>>
                <label for="upload_enabled">启用文件上传</label>
            </div>
            <div class="field check">
                <input type="checkbox" id="sensitive_enabled" name="sensitive_enabled" value="1" <?php echo $form['sensitive_enabled'] ? 'checked' : ''; ?>>
                <label for="sensitive_enabled">启用敏感词过滤</label>
            </div>

            <h2>④ 管理员账号</h2>
            <div class="row">
                <div class="field">
                    <label>用户名</label>
                    <input type="text" name="admin_username" value="<?php echo htmlspecialchars(isset($formValues['admin_username']) ? $formValues['admin_username'] : ''); ?>" maxlength="20" required>
                </div>
                <div class="field">
                    <label>邮箱</label>
                    <input type="email" name="admin_email" value="<?php echo htmlspecialchars(isset($formValues['admin_email']) ? $formValues['admin_email'] : ''); ?>" required>
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label>密码 <span class="hint">至少 6 位</span></label>
                    <input type="password" name="admin_password" autocomplete="new-password" required>
                </div>
                <div class="field">
                    <label>确认密码</label>
                    <input type="password" name="admin_password2" autocomplete="new-password" required>
                </div>
            </div>

            <div style="margin-top:24px; text-align:center">
                <button type="submit" class="btn" id="submitBtn" <?php echo $allChecksOk ? '' : 'disabled'; ?>>开始安装</button>
            </div>
            <p class="small" style="text-align:center; margin-top:10px">
                安装将生成 <span class="code">config.local.php</span> 并锁定（data/installed.lock），不会修改 <span class="code">config.php</span> 默认模板。
            </p>
        </form>
    </div>

    <script>
        // 环境检查未通过时禁止提交
        var form = document.getElementById('installForm');
        if (form && form.getAttribute('data-disabled') === '1') {
            form.addEventListener('submit', function (e) { e.preventDefault(); });
        }
        // 提交按钮防重复
        var btn = document.getElementById('submitBtn');
        if (form) {
            form.addEventListener('submit', function () {
                if (btn) { btn.disabled = true; btn.textContent = '正在安装…'; }
            });
        }
    </script>
<?php endif; ?>

</div>
</body>
</html>
