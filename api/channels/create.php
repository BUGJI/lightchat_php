<?php
/**
 * 创建频道
 *
 * POST /api/channels/create.php
 *
 * 请求体 JSON:
 *   name         string  频道名称（英文标识）
 *   display_name string  显示名称
 *   type         string  频道类型: public / private（默认 public）
 *   description  string  频道描述（可选）
 *
 * 成功响应:
 *   channel_id   int
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

// ── 认证 ──
$user = authenticate();

// ── 权限检查 ──
if (!has_permission($user, 'channel.create')) {
    json_response(403, ['error' => 'forbidden', 'message' => '您没有创建频道的权限']);
}

// ── 检查创建数量限制 ──
$maxChannels = isset($config['chat']['channel']['max_per_user']) ? (int)$config['chat']['channel']['max_per_user'] : 10;
$ownedCount = $db->count('channels', ['owner_id' => $user['id']]);
if ($ownedCount >= $maxChannels) {
    json_response(400, ['error' => 'limit_reached', 'message' => "您最多可创建 {$maxChannels} 个频道"]);
}

// ── 读取输入 ──
$input       = get_json_input();
$name        = trim($input['name'] ?? '');
$displayName = trim($input['display_name'] ?? '');
$type        = trim($input['type'] ?? 'public');
$description = trim($input['description'] ?? '');

// ── 参数校验 ──
if ($name === '' || $displayName === '') {
    json_response(400, ['error' => 'missing_fields', 'message' => '频道名称和显示名称不能为空']);
}

// 频道名格式：字母数字下划线
if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
    json_response(400, ['error' => 'invalid_name', 'message' => '频道名称只能包含字母、数字和下划线']);
}

$nameLen = strlen($name);
if ($nameLen < 2 || $nameLen > 50) {
    json_response(400, ['error' => 'invalid_name', 'message' => '频道名称长度应在 2-50 个字符']);
}

// 频道类型校验
$allowedTypes = ['public', 'private'];
if (!in_array($type, $allowedTypes)) {
    json_response(400, ['error' => 'invalid_type', 'message' => '频道类型无效，可选: public, private']);
}

// 检查重名
$existing = $db->get('channels', ['name' => $name]);
if ($existing) {
    json_response(409, ['error' => 'duplicate_name', 'message' => '频道名称已被使用']);
}

// ── 创建频道 ──
try {
    $channelData = [
        'name'         => $name,
        'display_name' => $displayName,
        'type'         => $type,
        'description'  => $description,
        'owner_id'     => $user['id'],
        'member_count' => 1,
    ];
    $channelId = $db->insert('channels', $channelData);

    // 创建者自动加入
    $db->insert('channel_members', [
        'channel_id' => $channelId,
        'user_id'    => $user['id'],
        'role'       => 'owner',
    ]);
} catch (Exception $e) {
    json_response(500, ['error' => 'create_failed', 'message' => '频道创建失败']);
}

json_response(201, [
    'success'    => true,
    'channel_id' => $channelId,
    'message'    => '频道创建成功',
]);
