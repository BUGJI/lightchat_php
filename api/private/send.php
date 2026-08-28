<?php
/**
 * 发送私聊消息
 *
 * POST /api/private/send.php
 *
 * 请求体 JSON:
 *   to_user_id  int     接收方用户 ID
 *   content     string  消息内容
 *   type        string  消息类型: text / image / file（默认 text）
 *   file_id     int     关联的上传文件 ID（可选）
 *
 * 成功响应 201:
 *   message_id  int
 *   chat_id     int
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST 请求']);
}

$user = authenticate();

// ── 权限 ──
if (!has_permission($user, 'user.message.send')) {
    json_response(403, ['error' => 'forbidden', 'message' => '您没有发送消息的权限']);
}

// ── 读取输入 ──
$input    = get_json_input();
$toUserId = isset($input['to_user_id']) ? (int)$input['to_user_id'] : 0;
$content  = trim($input['content'] ?? '');
$type     = trim($input['type'] ?? 'text');
$fileId   = isset($input['file_id']) ? (int)$input['file_id'] : null;

if ($toUserId <= 0) {
    json_response(400, ['error' => 'invalid_user', 'message' => '接收方用户 ID 无效']);
}
if ($toUserId === $user['id']) {
    json_response(400, ['error' => 'self_message', 'message' => '不能给自己发私信']);
}
// 消息内容不得为空（除非是文件类消息）
if ($content === '' && !in_array($type, ['image', 'file'])) {
    json_response(400, ['error' => 'empty_content', 'message' => '消息内容不能为空']);
}

// 消息长度限制
$maxLen = isset($config['message']['max_length']) ? (int)$config['message']['max_length'] : 500;
if (mb_strlen($content, 'UTF-8') > $maxLen) {
    json_response(400, ['error' => 'content_too_long', 'message' => "消息不能超过 {$maxLen} 个字符"]);
}

// ── 查找接收方 ──
$toUser = $db->get('users', ['id' => $toUserId]);
if (!$toUser) {
    json_response(404, ['error' => 'not_found', 'message' => '接收方用户不存在']);
}

// ── 敏感词过滤 ──
$content = filter_sensitive_words($content);

// ── 查找或创建私聊会话 ──
$u1 = min($user['id'], $toUserId);
$u2 = max($user['id'], $toUserId);
$chat = $db->get('private_chats', ['user1_id' => $u1, 'user2_id' => $u2]);

$chatId = null;
try {
    // 生成 last_message 预览文本
    $lastMsgPreview = $content;
    if ($type === 'image') {
        $lastMsgPreview = $content ?: '[图片]';
    } elseif ($type === 'file') {
        $lastMsgPreview = $content ?: '[文件]';
    }

    if (!$chat) {
        $chatId = $db->insert('private_chats', [
            'user1_id'        => $u1,
            'user2_id'        => $u2,
            'last_message'    => mb_substr($lastMsgPreview, 0, 100, 'UTF-8'),
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);
    } else {
        $chatId = (int)$chat['id'];
        $db->update('private_chats', [
            'last_message'    => mb_substr($lastMsgPreview, 0, 100, 'UTF-8'),
            'last_message_at' => date('Y-m-d H:i:s'),
        ], ['id' => $chatId]);
    }

    // ── 构建私聊消息数据 ──
    $messageData = [
        'chat_id'      => $chatId,
        'from_user_id' => $user['id'],
        'to_user_id'   => $toUserId,
        'type'         => $type,
        'content'      => $content,
        'is_read'      => 0,
    ];

    // 关联文件（记录待关联的文件 ID，insert 消息后回填归属）
    $attachFileId = null;
    if ($fileId !== null && $fileId > 0) {
        $upload = $db->get('uploads', ['id' => $fileId, 'user_id' => $user['id']]);
        if ($upload) {
            $messageData['file_url']  = $upload['file_path'] ?? '';
            $messageData['file_size'] = $upload['file_size'] ?? 0;
            $attachFileId = (int)$fileId;
        }
    }

    // ── 存储私聊消息 ──
    $messageId = $db->insert('private_messages', $messageData);

    // 文件归属：把上传记录关联到刚创建的消息（原代码置 null，方向反了）
    if ($attachFileId !== null) {
        $db->update('uploads', ['message_id' => $messageId], ['id' => $attachFileId]);
    }

    // ── 触发离线通知（接收消息时检查） ──
    require_once __DIR__ . '/../../notifications/NotificationManager.php';
    $notifConfig = $config['notifications'] ?? [];
    $offlineCfg = $notifConfig['offline_notify'] ?? [];
    if (($offlineCfg['enabled'] ?? true) && ($config['notifications']['methods'] ?? [])) {
        // 检查接收方是否开启了私信通知
        $toUserFull = $db->get('users', ['id' => $toUserId]);
        if ($toUserFull) {
            // 检查用户是否开启了私信通知
            if (!isset($toUserFull['notification_private_enabled']) || (bool)$toUserFull['notification_private_enabled']) {
                $notifMgr = new NotificationManager($notifConfig['methods'] ?? []);
                $messageDataForNotif = [
                    'nickname'         => $user['username'] ?? $user['nickname'] ?? '未知用户',
                    'unread_count'     => 1,
                    'messages_preview' => mb_substr($content, 0, 100, 'UTF-8'),
                    'sender_name'      => $user['username'] ?? $user['nickname'] ?? '未知用户',
                    'last_message_time'=> date('Y-m-d H:i:s'),
                ];
                $notifMgr->sendIfOffline($toUserFull, $messageDataForNotif, $offlineCfg['offline_threshold_minutes'] ?? 10);
            }
        }
    }
} catch (Exception $e) {
    json_response(500, ['error' => 'send_failed', 'message' => '私聊消息发送失败']);
}

json_response(201, [
    'success'    => true,
    'message_id' => $messageId,
    'chat_id'    => $chatId,
    'content'    => $content,
]);
