<?php
/**
 * 服务器资源配额（公开）
 *
 * GET /api/server/status.php
 *
 * 所有用户可查看硬性配额和服务资源，不暴露用户数据
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

// 不强制认证，但如果有 token 则获取用户用于 maybe 管理员详情
$user = null;
$token = get_bearer_token();
if ($token !== '') {
    $session = $db->get('sessions', ['token' => $token]);
    if ($session && isset($session['expires_at']) && strtotime($session['expires_at']) < time()) {
        $db->delete('sessions', ['token' => $token]);
    } elseif ($session) {
        $user = $db->get('users', ['id' => $session['user_id']]);
        if ($user) unset($user['password']);
    }
}

// ═══════════════════════════════════════
//  配置配额
// ═══════════════════════════════════════

$serverCfg = isset($config['server']) ? $config['server'] : [];
$quotaCfg  = isset($serverCfg['quota']) ? $serverCfg['quota'] : [];
$bwCfg     = isset($serverCfg['bandwidth']) ? $serverCfg['bandwidth'] : [];

$quota = [
    'monthly_network_flow_mb' => isset($quotaCfg['monthly_network_flow_mb']) ? (int)$quotaCfg['monthly_network_flow_mb'] : 0,
    'disk_space_mb'           => isset($quotaCfg['disk_space_mb']) ? (int)$quotaCfg['disk_space_mb'] : 0,
    'max_connections'         => isset($quotaCfg['max_connections']) ? (int)$quotaCfg['max_connections'] : 0,
    'max_processes'           => isset($quotaCfg['max_processes']) ? (int)$quotaCfg['max_processes'] : 0,
    'max_upload_mbps'         => isset($bwCfg['max_upload_mbps']) ? (int)$bwCfg['max_upload_mbps'] : 0,
    'max_download_mbps'       => isset($bwCfg['max_download_mbps']) ? (int)$bwCfg['max_download_mbps'] : 0,
];

// ═══════════════════════════════════════
//  磁盘用量
// ═══════════════════════════════════════

$dataDir     = __DIR__ . '/../data';
$uploadsDir  = isset($config['upload']['storage']['local_path'])
    ? rtrim($config['upload']['storage']['local_path'], '/')
    : __DIR__ . '/../uploads';

$dataDirSize    = dir_size($dataDir);
$uploadsDirSize = dir_size($uploadsDir);
$diskUsedMB     = round(($dataDirSize + $uploadsDirSize) / 1048576, 2);

// 服务器磁盘
$diskFreeMB  = function_exists('disk_free_space') ? round(@disk_free_space(__DIR__) / 1048576, 2) : -1;
$diskTotalMB = function_exists('disk_total_space') ? round(@disk_total_space(__DIR__) / 1048576, 2) : -1;
$diskPct     = $diskTotalMB > 0 ? round(($diskTotalMB - $diskFreeMB) / $diskTotalMB * 100, 1) : -1;

// 数据目录文件大小明细
$dataFiles = [];
if (is_dir($dataDir)) {
    foreach (glob($dataDir . '/*.json') as $f) {
        $dataFiles[basename($f)] = round(filesize($f) / 1024, 2);
    }
}

// ═══════════════════════════════════════
//  PHP 环境
// ═══════════════════════════════════════

$phpInfo = [
    'version'            => PHP_VERSION,
    'memory_limit'       => ini_get('memory_limit'),
    'post_max_size'      => ini_get('post_max_size'),
    'upload_max_filesize'=> ini_get('upload_max_filesize'),
    'max_execution_time' => (int)ini_get('max_execution_time'),
];

// ═══════════════════════════════════════
//  响应
// ═══════════════════════════════════════

$result = [
    'quota' => $quota,

    'disk' => [
        'app_used_mb' => $diskUsedMB,
        'data_kb'     => round($dataDirSize / 1024, 2),
        'uploads_kb'  => round($uploadsDirSize / 1024, 2),
        'free_mb'     => $diskFreeMB,
        'total_mb'    => $diskTotalMB,
        'used_pct'    => $diskPct,
    ],

    'php' => $phpInfo,
];

json_success($result);

// ═══════════════════════════════════════
//  辅助
// ═══════════════════════════════════════

function dir_size($dir) {
    if (!is_dir($dir)) return 0;
    $size = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) $size += $f->getSize();
        }
    } catch (Exception $e) {
        foreach (glob(rtrim($dir, '/') . '/*') as $f) {
            if (is_file($f)) $size += filesize($f);
        }
    }
    return $size;
}
