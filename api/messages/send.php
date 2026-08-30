<?php
/**
 * 发送消息到频道
 *
 * POST /api/messages/send.php
 *
 * 请求体 JSON:
 *   channel_id      int     频道 ID
 *   content         string  消息内容
 *   type            string  消息类型: text / image / file / system（默认 text）
 *   parent_id       int     引用回复的消息 ID（可选）
 *   mentioned_users array   被 @ 的用户 ID 列表（可选）
 *   file_id         int     关联的上传文件 ID（可选）
 *
 * 成功响应 201:
 *   message_id  int
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
$input          = get_json_input();
$channelId      = isset($input['channel_id']) ? (int)$input['channel_id'] : 0;
$content        = trim($input['content'] ?? '');
$type           = trim($input['type'] ?? 'text');
$parentId       = isset($input['parent_id']) ? (int)$input['parent_id'] : 0;
$mentionedUsers = isset($input['mentioned_users']) ? $input['mentioned_users'] : null;
$fileId         = isset($input['file_id']) ? (int)$input['file_id'] : null;

// ── 校验 ──
if ($channelId <= 0) {
    json_response(400, ['error' => 'invalid_channel', 'message' => '频道 ID 无效']);
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

// ── 检查频道是否存在 ──
$channel = $db->get('channels', ['id' => $channelId]);
if (!$channel) {
    json_response(404, ['error' => 'not_found', 'message' => '频道不存在']);
}

// ── 检查是否已加入 ──
$membership = $db->get('channel_members', ['channel_id' => $channelId, 'user_id' => $user['id']]);
if (!$membership) {
    json_response(403, ['error' => 'not_member', 'message' => '请先加入频道']);
}

// ── 公告频道仅管理员可发言 ──
if ($channel['type'] === 'announcement' && !role_at_least($user['role'], 'admin')) {
    json_response(403, ['error' => 'forbidden', 'message' => '公告频道仅管理员可发言']);
}

// ── 消息冷却检查 ──
$cooldownSeconds = isset($config['message']['cooldown_seconds']) ? (int)$config['message']['cooldown_seconds'] : 2;
if ($cooldownSeconds > 0 && !role_at_least($user['role'], 'admin')) {
    $recentMessages = $db->select('messages', [
        'channel_id' => $channelId,
        'user_id'    => $user['id'],
    ], '*', 'id DESC', 1);   // id DESC 走 LocalDriver 快速路径，避免全量排序
    if (!empty($recentMessages)) {
        $lastTime = strtotime($recentMessages[0]['created_at']);
        if (time() - $lastTime < $cooldownSeconds) {
            json_response(429, ['error' => 'cooldown', 'message' => "发送过快，请 {$cooldownSeconds} 秒后再发"]);
        }
    }
}

// ── 敏感词过滤 ──
$content = filter_sensitive_words($content);

// ── 处理 @ 列表（格式化为字符串存储） ──
$mentionedStr = null;
if (is_array($mentionedUsers) && count($mentionedUsers) > 0) {
    $maxMentions = isset($config['message']['mention']['max_mentions']) ? (int)$config['message']['mention']['max_mentions'] : 10;
    $mentionedUsers = array_slice($mentionedUsers, 0, $maxMentions);
    $mentionedStr = implode(',', array_map('intval', $mentionedUsers));
}

// ── 存储消息 ──
try {
    $messageData = [
        'channel_id'      => $channelId,
        'user_id'         => $user['id'],
        'parent_id'       => $parentId,
        'type'            => $type,
        'content'         => $content,
        'mentioned_users' => $mentionedStr,
    ];

    if ($fileId !== null && $fileId > 0) {
        $upload = $db->get('uploads', ['id' => $fileId, 'user_id' => $user['id']]);
        if ($upload) {
            $messageData['file_url']  = $upload['file_path'] ?? '';
            $messageData['file_size'] = $upload['file_size'] ?? 0;
        }
    }

    $messageId = $db->insert('messages', $messageData);

    // 关联文件到消息（消息插入成功后再更新归属）
    if ($fileId !== null && $fileId > 0) {
        $db->update('uploads', ['message_id' => $messageId], ['id' => $fileId]);
    }

    // ── 触发离线通知（接收消息时检查） ──
    require_once __DIR__ . '/../../notifications/NotificationManager.php';
    $notifConfig = $config['notifications'] ?? [];
    $offlineCfg = $notifConfig['offline_notify'] ?? [];
    // 仅当至少有一种通知方式被显式启用时才走通知逻辑，避免空转
    $enabledMethods = [];
    foreach (($notifConfig['methods'] ?? []) as $methodName => $methodCfg) {
        if (!empty($methodCfg['enabled'])) {
            $enabledMethods[$methodName] = $methodCfg;
        }
    }
    if (($offlineCfg['enabled'] ?? true) && $enabledMethods) {
        // 获取频道内其他成员（批量查询一次，避免循环内逐个查库）
        $members = $db->select('channel_members', ['channel_id' => $channelId], 'user_id');
        $memberIds = array_column($members, 'user_id');
        $memberIds = array_filter($memberIds, function ($id) use ($user) {
            return (int)$id !== (int)$user['id'];
        });

        $notifMgr = null;
        if (!empty($memberIds)) {
            // 批量取成员用户信息
            $memberRows = $db->select('users', ['id' => $memberIds]);
            foreach ($memberRows as $member) {
                // 检查用户是否开启了群通知
                if (isset($member['notification_group_enabled']) && !(bool)$member['notification_group_enabled']) {
                    continue; // 用户关闭了群通知
                }

                $messageDataForNotif = [
                    'nickname'         => $user['username'] ?? $user['nickname'] ?? '未知用户',
                    'unread_count'     => 1,
                    'messages_preview' => mb_substr($content, 0, 100, 'UTF-8'),
                    'sender_name'      => $user['username'] ?? $user['nickname'] ?? '未知用户',
                    'last_message_time'=> date('Y-m-d H:i:s'),
                    'channel_name'     => $channel['name'] ?? '频道',
                ];
                if ($notifMgr === null) {
                    $notifMgr = new NotificationManager($enabledMethods);
                }
                $notifMgr->sendIfOffline($member, $messageDataForNotif, $offlineCfg['offline_threshold_minutes'] ?? 10);
            }
        }
    }
} catch (Exception $e) {
    json_response(500, ['error' => 'send_failed', 'message' => '消息发送失败']);
}

json_response(201, [
    'success'    => true,
    'message_id' => $messageId,
    'content'    => $content,
]);
