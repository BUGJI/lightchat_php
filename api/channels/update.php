<?php
/**
 * 更新频道设置（仅频道创建者 owner）
 *
 * POST /api/channels/update.php
 *
 * 请求体 JSON:
 *   channel_id    int    频道 ID（必填）
 *   display_name  string 新名称（可选）
 *   type          string 新类型 public / private（可选）
 *   description   string 新描述（可选，传空字符串清空）
 *
 * 成功响应:
 *   success   bool
 *   channel   { id, name, display_name, type, description, owner_id, member_count }
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

$user = authenticate();

$input     = get_json_input();
$channelId = isset($input['channel_id']) ? (int)$input['channel_id'] : 0;

if ($channelId <= 0) {
    json_response(400, ['error' => 'invalid_channel', 'message' => '频道 ID 无效']);
}

$channel = $db->get('channels', ['id' => $channelId]);
if (!$channel) {
    json_response(404, ['error' => 'not_found', 'message' => '频道不存在']);
}

// 系统默认频道不允许改名/改类型
$systemChannelNames = ['general', 'announcements', 'help'];
if (in_array($channel['name'], $systemChannelNames)) {
    json_response(403, ['error' => 'system_channel', 'message' => '系统默认频道不允许修改']);
}

// 仅频道创建者可修改
$isOwner = (isset($channel['owner_id']) && (int)$channel['owner_id'] === (int)$user['id']);
if (!$isOwner) {
    $membership = $db->get('channel_members', ['channel_id' => $channelId, 'user_id' => $user['id']]);
    $isOwner = $membership && $membership['role'] === 'owner';
}
if (!$isOwner) {
    json_response(403, ['error' => 'not_owner', 'message' => '只有频道创建者可以修改频道设置']);
}

// ── 校验并收集待更新字段 ──
$updates = [];
$hasUpdate = false;

if (array_key_exists('display_name', $input)) {
    $displayName = trim($input['display_name']);
    if ($displayName === '') {
        json_response(400, ['error' => 'invalid_name', 'message' => '频道名称不能为空']);
    }
    if (mb_strlen($displayName, 'UTF-8') > 50) {
        json_response(400, ['error' => 'invalid_name', 'message' => '频道名称过长（最多 50 字）']);
    }
    $updates['display_name'] = $displayName;
    $hasUpdate = true;
}

if (array_key_exists('type', $input)) {
    $type = $input['type'];
    if (!in_array($type, ['public', 'private'], true)) {
        json_response(400, ['error' => 'invalid_type', 'message' => '频道类型无效，可选: public, private']);
    }
    $updates['type'] = $type;
    $hasUpdate = true;
}

if (array_key_exists('description', $input)) {
    $updates['description'] = mb_substr(trim($input['description']), 0, 200, 'UTF-8');
    $hasUpdate = true;
}

if (!$hasUpdate) {
    json_response(400, ['error' => 'nothing_to_update', 'message' => '没有需要更新的字段']);
}

try {
    $db->update('channels', $updates, ['id' => $channelId]);
} catch (Exception $e) {
    json_response(500, ['error' => 'update_failed', 'message' => '频道更新失败']);
}

$updated = $db->get('channels', ['id' => $channelId]);
json_success([
    'channel' => [
        'id'           => (int)$updated['id'],
        'name'         => $updated['name'],
        'display_name' => $updated['display_name'],
        'type'         => $updated['type'],
        'description'  => $updated['description'] ?? '',
        'owner_id'     => isset($updated['owner_id']) ? (int)$updated['owner_id'] : 0,
        'member_count' => (int)$updated['member_count'],
    ],
], '频道设置已更新');
