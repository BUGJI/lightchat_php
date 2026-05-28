<?php
/**
 * 删除消息
 *
 * POST /api/messages/delete.php
 *
 * 请求体 JSON:
 *   message_id  int  要删除的消息 ID
 *
 * 说明:
 *   普通用户只能删除自己的消息（软删除）
 *   管理员可以删除任何人的消息
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

$user = authenticate();

$input     = get_json_input();
$messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;

if ($messageId <= 0) {
    json_response(400, ['error' => 'invalid_message', 'message' => '消息 ID 无效']);
}

// ── 查找消息 ──
$message = $db->get('messages', ['id' => $messageId]);
if (!$message) {
    json_response(404, ['error' => 'not_found', 'message' => '消息不存在']);
}

// ── 权限检查 ──
$isOwner  = (int)$message['user_id'] === $user['id'];
$isAdmin  = role_at_least($user['role'], 'admin');
$canDeleteOwn = has_permission($user, 'user.message.delete');
$canDeleteAny = has_permission($user, 'admin.message.delete');

if ($isOwner && !$canDeleteOwn) {
    json_response(403, ['error' => 'forbidden', 'message' => '你没有删除消息的权限']);
}
if (!$isOwner && !$canDeleteAny) {
    json_response(403, ['error' => 'forbidden', 'message' => '你没有权限删除他人的消息']);
}

// ── 软删除（标记 is_deleted = 1） ──
try {
    $db->update('messages', ['is_deleted' => 1], ['id' => $messageId]);
} catch (Exception $e) {
    json_response(500, ['error' => 'delete_failed', 'message' => '消息删除失败']);
}

json_success([], '消息已删除');
