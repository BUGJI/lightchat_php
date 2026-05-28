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
        'svg'  => 'image/svg+xml',
        'mp3'  => 'audio/mpeg', 'wav'  => 'audio/wav',  'ogg'  => 'audio/ogg',
        'mp4'  => 'video/mp4',  'webm' => 'video/webm',
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
        'zip'  => 'application/zip',
    ];
    $mimeType = isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'application/octet-stream';
}

$extension   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$fileSizeKb  = round($file['size'] / 1024);

// ── 判断文件分类 ──
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

// ── 记录到数据库 ──
try {
    $fileId = $db->insert('uploads', [
        'user_id'   => $user['id'],
        'file_name' => $file['name'],
        'file_path' => $urlPrefix . $uniqueName,
        'file_size' => $file['size'],
        'file_type' => $category,
        'mime_type' => $mimeType,
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
