<?php
/**
 * 环境诊断 — 无需数据库即可运行
 *
 * GET /api/health.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_error.log');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 捕获所有致命错误
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        http_response_code(500);
        echo json_encode([
            'all_ok' => false,
            'fatal_error' => $error,
            'hint' => '发生致命错误，请查看 logs/php_error.log 或下方 detailed_trace'
        ], JSON_PRETTY_PRINT);
        exit;
    }
});

$baseDir = realpath(__DIR__ . '/..');
$checks  = [];
$detailedTrace = [];

// ── PHP 版本 ──
$checks['php_version'] = [
    'ok'     => version_compare(PHP_VERSION, '7.4.0', '>='),
    'value'  => PHP_VERSION,
    'msg'    => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'OK' : '需要 PHP >= 7.4',
];

// ── 关键扩展 ──
foreach (['json', 'mbstring', 'pcre', 'ctype', 'fileinfo'] as $ext) {
    $loaded = extension_loaded($ext);
    $checks['ext_' . $ext] = [
        'ok'    => $loaded,
        'msg'   => $loaded ? 'OK' : '缺失（非关键，但部分功能受限）',
    ];
}

// ── 核心文件 ──
$files = [
    'config.php'          => $baseDir . '/config.php',
    'core/Database.php'   => $baseDir . '/core/Database.php',
    'core/DatabaseDriverInterface.php' => $baseDir . '/core/DatabaseDriverInterface.php',
    'drivers/LocalDriver.php' => $baseDir . '/drivers/LocalDriver.php',
    'api/bootstrap.php'   => $baseDir . '/api/bootstrap.php',
];
foreach ($files as $label => $path) {
    $exists = file_exists($path) && is_readable($path);
    $checks['file_' . $label] = [
        'ok'    => $exists,
        'path'  => str_replace($baseDir, '...', $path),
        'msg'   => $exists ? 'OK' : '文件不存在或不可读',
    ];
}

// ── 目录权限 ──
$dirs = [
    'data/'    => $baseDir . '/data',
    'uploads/' => $baseDir . '/uploads',
    'logs/'    => $baseDir . '/logs',
];
foreach ($dirs as $label => $path) {
    $exists = is_dir($path);
    $writable = $exists ? is_writable($path) : false;
    $creatable = !$exists;

    $checks['dir_' . $label] = [
        'ok'      => $exists && $writable,
        'exists'  => $exists,
        'writable'=> $writable,
        'path'    => str_replace($baseDir, '...', $path),
        'owner'   => $exists ? (function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($path))['name'] : '?') : '-',
        'msg'     => !$exists ? '目录不存在，需创建' : (!$writable ? '目录不可写！' : 'OK'),
    ];

    // 尝试创建
    if (!$exists) {
        $ok = @mkdir($path, 0755, true);
        $checks['dir_' . $label]['create_attempt'] = $ok;
        $checks['dir_' . $label]['msg'] = $ok ? '已自动创建' : '创建失败，请手动创建并 chmod 755';
        if ($ok) {
            $checks['dir_' . $label]['ok'] = true;
            $checks['dir_' . $label]['exists'] = true;
            $checks['dir_' . $label]['writable'] = true;
        }
    }
}

// ── 配置读取 ──
try {
    $config = @include $baseDir . '/config.php';
    $checks['config_load'] = [
        'ok'  => is_array($config),
        'msg' => is_array($config) ? 'OK' : 'config.php 返回异常',
    ];
    if (is_array($config)) {
        $dbType = isset($config['database']['default']['type']) ? $config['database']['default']['type'] : '?';
        $checks['config_db_type'] = [
            'ok'    => $dbType === 'local',
            'value' => $dbType,
            'msg'   => $dbType === 'local' ? 'OK' : '建议使用 local 驱动',
        ];
        
        // 测试 Database 类初始化
        try {
            // 显式引入依赖文件，防止自动加载失效
            require_once $baseDir . '/core/DatabaseDriverInterface.php';
            require_once $baseDir . '/drivers/LocalDriver.php';
            require_once $baseDir . '/core/Database.php';
            
            $dbStart = microtime(true);
            $db = \Core\Database::getInstance();
            $dbTime = round((microtime(true) - $dbStart) * 1000, 2);
            
            $checks['db_init'] = [
                'ok' => true,
                'time_ms' => $dbTime,
                'msg' => "Database 初始化成功 ({$dbTime}ms)"
            ];
            
            // 检查 users 表
            try {
                $usersCheckStart = microtime(true);
                $users = $db->select('users');
                $usersTime = round((microtime(true) - $usersCheckStart) * 1000, 2);
                $checks['users_table'] = [
                    'ok' => true,
                    'count' => count($users),
                    'time_ms' => $usersTime,
                    'msg' => "users 表检查成功 ({$usersTime}ms, " . count($users) . " 条记录)"
                ];
            } catch (Throwable $e) {
                $checks['users_table'] = [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                    'msg' => 'users 表检查失败'
                ];
                $detailedTrace[] = ['step' => 'users_table_check', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
            }
            
        } catch (Throwable $e) {
            $checks['db_init'] = [
                'ok' => false,
                'error' => $e->getMessage(),
                'trace' => explode("\n", $e->getTraceAsString()),
                'msg' => 'Database 初始化失败'
            ];
            $detailedTrace[] = ['step' => 'db_init', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
        }
    }
} catch (Throwable $e) {
    $checks['config_load'] = ['ok' => false, 'msg' => $e->getMessage()];
    $detailedTrace[] = ['step' => 'config_load', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
}

// ── 总体 ──
$allOk = true;
foreach ($checks as $c) {
    if (!$c['ok']) { $allOk = false; break; }
}

http_response_code($allOk ? 200 : 500);
echo json_encode([
    'all_ok'  => $allOk,
    'base_dir'=> str_replace($baseDir, '...', $baseDir) . '/',
    'checks'  => $checks,
    'detailed_trace' => $detailedTrace,
    'hint'    => $allOk ? '一切正常，如果仍报错请检查 PHP 错误日志'
                        : '请修复上面标记为 ❌ 的项，查看详细错误信息',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
