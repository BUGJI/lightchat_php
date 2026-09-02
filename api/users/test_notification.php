<?php
/**
 * 发送测试通知（验证通知链路是否可用）
 *
 * POST /api/users/test_notification.php
 *
 * 无请求体。使用当前用户已保存的通知设置发送一条测试消息：
 *   - notification_mode = pushplus → 推送到 PushPlus（需已填 token）
 *   - notification_mode = email    → 发送邮件（需服务器 SMTP 已配置 + 有通知邮箱）
 *   - notification_mode = webhook  → POST 到用户 Webhook URL
 *
 * 限流：每用户每 60 秒最多 3 次（429 + retry_after）
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 POST']);
}

$user = authenticate();
if (!has_permission($user, 'user.profile.update')) {
    json_response(403, ['error' => 'forbidden', 'message' => '您没有修改资料的权限']);
}

// ── 限流：每用户每 60s 最多 3 次 ──
apply_user_rate_limit('test_notif:' . (int)$user['id'], 3, 60);

$mode = trim($user['notification_mode'] ?? 'none');
if ($mode === 'none' || $mode === '') {
    json_response(400, ['error' => 'no_notification_mode', 'message' => '请先在通知设置中选择通知方式']);
}

// ── 服务器是否启用该方式 ──
$available = notification_available_methods($config);
if (!in_array($mode, $available, true)) {
    $tips = [
        'email'    => '邮件通知需要管理员在 config.php 中配置真实 SMTP 并启用',
        'pushplus' => '服务器未启用 PushPlus 通知',
        'webhook'  => '服务器未启用 Webhook 通知',
    ];
    json_response(400, [
        'error'   => 'notification_method_disabled',
        'message' => isset($tips[$mode]) ? $tips[$mode] : '服务器未启用该通知方式',
    ]);
}

// ── 构造测试数据（收件人 = 当前用户自己） ──
$notifEmail = trim($user['notification_email'] ?? '');
if ($mode === 'email' && $notifEmail === '') {
    $notifEmail = trim($user['email'] ?? '');
}
if ($mode === 'email' && $notifEmail === '') {
    json_response(400, ['error' => 'missing_email', 'message' => '未设置通知邮箱，请在通知设置中填写']);
}
if ($mode === 'pushplus' && trim($user['notification_pushplus_key'] ?? '') === '') {
    json_response(400, ['error' => 'missing_pushplus_key', 'message' => '未设置 PushPlus Token，请在通知设置中填写']);
}
if ($mode === 'webhook' && trim($user['notification_webhook_url'] ?? '') === '') {
    json_response(400, ['error' => 'missing_webhook_url', 'message' => '未设置 Webhook URL，请在通知设置中填写']);
}

$nowStr = date('Y-m-d H:i:s');
$userData = [
    'nickname'                   => $user['nickname'] ?? $user['username'] ?? '用户',
    'notification_email'         => $notifEmail,
    'notification_pushplus_key'  => trim($user['notification_pushplus_key'] ?? ''),
    'notification_webhook_url'   => trim($user['notification_webhook_url'] ?? ''),
    'notification_webhook_secret'=> trim($user['notification_webhook_secret'] ?? ''),
    'unread_count'               => 0,
    'messages_preview'           => '这是一条测试消息',
    'sender_name'                => 'LightChat 通知测试',
    'last_message_time'          => $nowStr,
    // 明确标注为测试，避免与真实离线通知混淆
    'template_subject'   => '【LightChat】通知测试 ' . $nowStr,
    'template_body'      => "您好 {nickname}，\n\n"
        . "这是一条【测试通知】。如果您收到本邮件，说明邮件通知链路正常。\n\n"
        . "发送时间：{$nowStr}\n\n-- LightChat 通知系统",
    'template_title'     => '【LightChat】通知测试',
    'template_content'   => "<h3>您好 {nickname}</h3>"
        . "<p>这是一条<b>测试通知</b>，收到即代表 PushPlus 通知链路正常。</p>"
        . "<p>发送时间：{$nowStr}</p>"
        . "<p><small>— LightChat 通知系统</small></p>",
];

require_once __DIR__ . '/../../notifications/NotificationManager.php';
$notifMgr = new NotificationManager(isset($config['notifications']['methods']) ? $config['notifications']['methods'] : []);
$result = $notifMgr->send($mode, $userData);

if (!empty($result['success'])) {
    json_success([
        'method'  => $mode,
        'message' => '测试通知已发送，请检查是否收到',
    ]);
}

json_response(502, [
    'error'   => 'send_failed',
    'message' => '测试通知发送失败：' . ($result['message'] ?? '未知错误'),
    'detail'  => $result,
]);
