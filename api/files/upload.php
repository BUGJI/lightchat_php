<?php
/**
 * 文件上传
 *
 * POST /api/files/upload.php (multipart/form-data)
 *
 * 字段:
 *   file  File  上传的文件
 *
 * 成功响应 201:
 *   file_id   int
 *   file_url  string
 *   file_name string
 *   file_size int
 *   file_type string
 */

require_once __DIR__ . '/../bootstrap.php';

// 顶层异常保护：确保任何致命错误也返回 JSON
try {

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

$user = authenticate();

if (!has_permission($user, 'user.file.upload')) {
    json_response(403, ['error' => 'forbidden', 'message' => '您没有上传文件的权限']);
}

if (!isset($config['upload']['enabled']) || !$config['upload']['enabled']) {
    json_response(503, ['error' => 'upload_disabled', 'message' => '文件上传功能已关闭']);
}

// ── 检查是否有文件 ──
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = '文件上传失败';
    if (isset($_FILES['file'])) {
        switch ($_FILES['file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = '文件大小超过服务器限制';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMsg = '文件不完整';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMsg = '未选择文件';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $errorMsg = '服务器临时目录不可用';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $errorMsg = '服务器写入失败';
                break;
        }
    }
    json_response(400, ['error' => 'upload_failed', 'message' => $errorMsg]);
}

$file = $_FILES['file'];

// ── 检测 MIME 类型（多级回退） ──
$mimeType = null;
if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mimeType = @finfo_file($finfo, $file['tmp_name']);
        @finfo_close($finfo);
    }
}
if (empty($mimeType) && function_exists('mime_content_type')) {
    $mimeType = @mime_content_type($file['tmp_name']);
}
if (empty($mimeType)) {
    // 最后回退：通过扩展名推断
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mimeMap = [
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png'  => 'image/png',
        'gif'  => 'image/gif',  'webp' => 'image/webp', 'bmp'  => 'image/bmp',
        'mp3'  => 'audio/mpeg', 'wav'  => 'audio/wav',  'ogg'  => 'audio/ogg',
        'mp4'  => 'video/mp4',  'webm' => 'video/webm',
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
        'zip'  => 'application/zip',
    ];
    // SVG 不在白名单内（可内嵌脚本，虚拟主机静态服务场景有 XSS 风险），显式拒绝
    if (isset($mimeMap[$ext])) {
        $mimeType = $mimeMap[$ext];
    } else {
        $mimeType = 'application/octet-stream';
    }
}
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// ── 显式拒绝危险扩展名（SVG 可内嵌脚本） ──
if (in_array($extension, ['svg', 'svgz', 'html', 'htm', 'php', 'phtml', 'shtml', 'jsp', 'asp', 'aspx', 'cgi', 'pl'], true)) {
    json_response(400, ['error' => 'unsupported_type', 'message' => '不支持的文件类型（' . $extension . '）']);
}

$fileSizeKb  = round($file['size'] / 1024);
$category   = 'file';
$typeConfig = null;

foreach (['image', 'audio', 'video', 'file'] as $cat) {
    $cfg = isset($config['upload']['file_types'][$cat]) ? $config['upload']['file_types'][$cat] : [];
    if (empty($cfg['enabled'])) continue;

    $exts  = isset($cfg['extensions']) ? $cfg['extensions'] : [];
    $mimes = isset($cfg['mime_types']) ? $cfg['mime_types'] : [];

    if (in_array($extension, $exts) || in_array($mimeType, $mimes)) {
        $category   = $cat;
        $typeConfig = $cfg;
        break;
    }
}

if ($typeConfig === null) {
    json_response(400, [
        'error'   => 'unsupported_type',
        'message' => '不支持的文件类型（' . $extension . ', ' . $mimeType . '）',
    ]);
}

// ── 检查文件大小 ──
$maxSizeKb = isset($typeConfig['max_size_kb']) ? (int)$typeConfig['max_size_kb'] : 2048;
if ($fileSizeKb > $maxSizeKb) {
    json_response(400, ['error' => 'file_too_large', 'message' => "文件不能超过 {$maxSizeKb} KB"]);
}

// ── 上传频率限制（per_minute / per_hour / per_day） ──
$rlCfg = isset($config['upload']['rate_limit']) ? $config['upload']['rate_limit'] : [];
$perMinute = (int)($rlCfg['per_minute'] ?? 0);
$perHour   = (int)($rlCfg['per_hour'] ?? 0);
$perDay    = (int)($rlCfg['per_day'] ?? 0);
if ($perMinute > 0 || $perHour > 0 || $perDay > 0) {
    $rlDir = isset($config['database']['default']['local']['data_path'])
        ? rtrim($config['database']['default']['local']['data_path'], '/') . '/upload_limit'
        : __DIR__ . '/../data/upload_limit';
    if (!is_dir($rlDir)) {
        @mkdir($rlDir, 0755, true);
    }
    $rlFile = $rlDir . '/u' . $user['id'] . '.json';

    // 读-改-写放入 flock 临界区，保证并发安全
    $fp = @fopen($rlFile, 'c+');
    if ($fp) {
        @flock($fp, LOCK_EX);
        $now = time();
        $stats = [
            'minute_ts' => 0, 'minute_count' => 0,
            'hour_ts'   => 0, 'hour_count'   => 0,
            'day_ts'    => 0, 'day_count'    => 0,
        ];
        $content = stream_get_contents($fp);
        if ($content !== false && $content !== '') {
            $saved = @json_decode($content, true);
            if (is_array($saved)) {
                $stats = array_merge($stats, $saved);
            }
        }
        // 窗口过期重置
        if ($now - (int)$stats['minute_ts'] >= 60)  { $stats['minute_ts'] = $now; $stats['minute_count'] = 0; }
        if ($now - (int)$stats['hour_ts']   >= 3600) { $stats['hour_ts']   = $now; $stats['hour_count']   = 0; }
        if ($now - (int)$stats['day_ts']    >= 86400){ $stats['day_ts']    = $now; $stats['day_count']    = 0; }

        if ($perMinute > 0 && (int)$stats['minute_count'] >= $perMinute) {
            @flock($fp, LOCK_UN); @fclose($fp);
            json_response(429, ['error' => 'upload_rate_limited', 'message' => "上传太频繁，请稍后再试（每分钟最多 {$perMinute} 次）"]);
        }
        if ($perHour > 0 && (int)$stats['hour_count'] >= $perHour) {
            @flock($fp, LOCK_UN); @fclose($fp);
            json_response(429, ['error' => 'upload_rate_limited', 'message' => "上传太频繁，请稍后再试（每小时最多 {$perHour} 次）"]);
        }
        if ($perDay > 0 && (int)$stats['day_count'] >= $perDay) {
            @flock($fp, LOCK_UN); @fclose($fp);
            json_response(429, ['error' => 'upload_rate_limited', 'message' => "上传太频繁，请稍后再试（每天最多 {$perDay} 次）"]);
        }

        // 预扣计数
        $stats['minute_count']++;
        $stats['hour_count']++;
        $stats['day_count']++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($stats));
        fflush($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

// ── 存储文件 ──
$storageCfg = isset($config['upload']['storage']) ? $config['upload']['storage'] : [];
$uploadDir  = isset($storageCfg['local_path']) ? rtrim($storageCfg['local_path'], '/') : (__DIR__ . '/../uploads');
$urlPrefix  = isset($storageCfg['url_prefix']) ? $storageCfg['url_prefix'] : '/uploads/';

// 确保目录存在
if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0755, true)) {
        json_response(500, ['error' => 'dir_failed', 'message' => '上传目录创建失败']);
    }
}

// 验证目录可写
if (!is_writable($uploadDir)) {
    json_response(500, ['error' => 'dir_not_writable', 'message' => '上传目录不可写']);
}

// 生成唯一文件名
$uniqueName = date('Ymd') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 12) . '.' . $extension;
$destPath   = $uploadDir . '/' . $uniqueName;

if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
    json_response(500, ['error' => 'save_failed', 'message' => '文件保存失败，请检查目录权限']);
}

// ── 内容去重（方案2：MD5 哈希比对）──
$fileHash = md5_file($destPath);

// 查找是否已有相同内容的文件
$existing = null;
try {
    $existing = $db->get('uploads', ['file_hash' => $fileHash]);
} catch (Exception $e) {
    // 旧表可能没有 file_hash 列（LocalDriver 自动兼容），忽略异常
}

if ($existing && isset($existing['id'])) {
    // 内容重复 → 删掉刚上传的文件，复用已有记录
    @unlink($destPath);
    json_response(200, [
        'success'   => true,
        'file_id'   => (int)$existing['id'],
        'file_url'  => $existing['file_path'] ?? '',
        'file_name' => $file['name'],
        'file_size' => (int)($existing['file_size'] ?? $file['size']),
        'file_type' => $existing['file_type'] ?? $category,
        'mime_type' => $existing['mime_type'] ?? $mimeType,
        'duplicate' => true,
    ]);
}

// ── 记录到数据库 ──
try {
    $fileId = $db->insert('uploads', [
        'user_id'   => $user['id'],
        'file_name' => $file['name'],
        'file_path' => $urlPrefix . $uniqueName,
        'file_size' => $file['size'],
        'file_type' => $category,
        'mime_type' => $mimeType,
        'file_hash' => $fileHash,
    ]);
} catch (Exception $e) {
    @unlink($destPath);
    json_response(500, ['error' => 'record_failed', 'message' => '文件记录保存失败']);
}

json_response(201, [
    'success'   => true,
    'file_id'   => $fileId,
    'file_url'  => $urlPrefix . $uniqueName,
    'file_name' => $file['name'],
    'file_size' => $file['size'],
    'file_type' => $category,
    'mime_type' => $mimeType,
]);

} catch (Exception $e) {
    // 最后防线：任何未捕获异常都返回 JSON
    http_response_code(500);
    echo json_encode([
        'error'   => 'internal_error',
        'message' => '服务器内部错误: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    // PHP 7: 捕获 Error 类型
    http_response_code(500);
    echo json_encode([
        'error'   => 'fatal_error',
        'message' => '服务器致命错误: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
