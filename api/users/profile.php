<?php
/**
 * 用户资料
 *
 * GET  /api/users/profile.php              查看自己的资料
 * GET  /api/users/profile.php?user_id=1     查看他人公开资料
 * POST /api/users/profile.php               更新自己的资料
 *
 * POST 请求体 JSON（可部分更新）:
 *   nickname  string  昵称
 *   avatar    string  头像 URL / Base64 Data URI
 *   bio       string  个人简介
 *   signature string  签名
 *   email     string  邮箱
 *   
 *   notification_mode         string  不提醒/邮件/PushPlus (none / email / pushplus)
 *   notification_email         string  通知邮箱（选邮件时必填）
 *   notification_pushplus_key  string  PushPlus 密钥（选 PushPlus 时必填）
 *   notification_template      object  自定义通知模板（尖括号变量自动替换）
 *     {
 *       "email":    { "subject": "...", "body": "..." },
 *       "pushplus": { "title": "...", "content": "..." }
 *     }
 *   模板可用变量: {nickname} {unread_count} {messages_preview}
 *                {sender_name} {last_message_time}
 */

require_once __DIR__ . '/../bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

    if ($userId > 0) {
        // ── 查看他人公开资料 ──
        $target = $db->get('users', ['id' => $userId]);
        if (!$target) {
            json_response(404, ['error' => 'not_found', 'message' => '用户不存在']);
        }
        if (isset($target['status']) && (int)$target['status'] !== 1) {
            json_response(404, ['error' => 'not_found', 'message' => '用户不存在']);
        }

        // 公开字段
        $publicFields = isset($config['user']['profile']['public_fields'])
            ? $config['user']['profile']['public_fields']
            : ['username', 'avatar', 'role', 'join_date', 'status'];

        $profile = [];
        foreach ($publicFields as $f) {
            if ($f === 'join_date') {
                $profile['join_date'] = $target['created_at'] ?? '';
            } else {
                $profile[$f] = $target[$f] ?? null;
            }
        }
        $profile['id'] = (int)$target['id'];

        json_success(['user' => $profile]);
    } else {
        // ── 查看自己 ──
        $user = authenticate();

        $profile = [
            'id'         => (int)$user['id'],
            'username'   => $user['username'],
            'email'      => $user['email'] ?? null,
            'avatar'     => $user['avatar'] ?? null,
            'nickname'   => $user['nickname'] ?? $user['username'],
            'bio'        => $user['bio'] ?? '',
            'signature'  => $user['signature'] ?? '',
            'role'       => $user['role'] ?? 'member',
            'status'     => (int)($user['status'] ?? 1),
            'created_at' => $user['created_at'] ?? '',
            'last_active_at' => $user['last_active_at'] ?? '',
            // 通知设置
            'notification' => [
                'mode'          => $user['notification_mode'] ?? 'none',
                'email'         => $user['notification_email'] ?? '',
                'pushplus_key'  => $user['notification_pushplus_key'] ?? '',
                'webhook_url'   => $user['notification_webhook_url'] ?? '',
                'webhook_secret' => $user['notification_webhook_secret'] ?? '',
                'group_enabled'  => isset($user['notification_group_enabled']) ? (bool)$user['notification_group_enabled'] : true,
                'private_enabled' => isset($user['notification_private_enabled']) ? (bool)$user['notification_private_enabled'] : true,
                'template'      => $user['notification_template'] 
                    ? json_decode($user['notification_template'], true) 
                    : null,
            ],
        ];

        json_success(['user' => $profile]);
    }
}

if ($method === 'POST') {
    // ── 更新自己 ──
    $user = authenticate();

    if (!has_permission($user, 'user.profile.update')) {
        json_response(403, ['error' => 'forbidden', 'message' => '您没有修改资料的权限']);
    }

    $input   = get_json_input();
    $updates = [];

    // 可编辑字段
    $editableFields = isset($config['user']['profile']['editable_fields'])
        ? $config['user']['profile']['editable_fields']
        : ['avatar', 'bio', 'nickname', 'signature'];

    foreach ($editableFields as $field) {
        if (isset($input[$field])) {
            $val = trim($input[$field]);

            // 简单校验
            if ($field === 'nickname') {
                $len = mb_strlen($val, 'UTF-8');
                if ($len > 20) {
                    json_response(400, ['error' => 'invalid_nickname', 'message' => '昵称最多 20 个字符']);
                }
            }
            if ($field === 'bio' && mb_strlen($val, 'UTF-8') > 200) {
                json_response(400, ['error' => 'invalid_bio', 'message' => '个人简介最多 200 个字符']);
            }
            if ($field === 'signature' && mb_strlen($val, 'UTF-8') > 100) {
                json_response(400, ['error' => 'invalid_signature', 'message' => '签名最多 100 个字符']);
            }

            $updates[$field] = $val;
        }
    }

    // 邮箱单独处理（如果提供）
    if (isset($input['email'])) {
        $email = trim($input['email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(400, ['error' => 'invalid_email', 'message' => '邮箱格式不正确']);
        }
        // 检查邮箱唯一性
        if ($email !== '' && $email !== ($user['email'] ?? '')) {
            $existing = $db->get('users', ['email' => $email]);
            if ($existing) {
                json_response(409, ['error' => 'duplicate_email', 'message' => '邮箱已被使用']);
            }
        }
        $updates['email'] = $email;
    }

    // ── 用户名 ──
    if (isset($input['username']) && $input['username'] !== '') {
        $newUsername = trim($input['username']);
        $usernameCfg = isset($config['user']['username']) ? $config['user']['username'] : [];
        $minLen = isset($usernameCfg['min_length']) ? (int)$usernameCfg['min_length'] : 3;
        $maxLen = isset($usernameCfg['max_length']) ? (int)$usernameCfg['max_length'] : 20;
        $pattern = isset($usernameCfg['pattern']) ? $usernameCfg['pattern'] : '/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u';
        $reserved = isset($usernameCfg['reserved']) ? $usernameCfg['reserved'] : ['admin', 'system', 'robot', 'anonymous'];

        $len = mb_strlen($newUsername, 'UTF-8');
        if ($len < $minLen || $len > $maxLen) {
            json_response(400, ['error' => 'invalid_username', 'message' => "用户名需 {$minLen}-{$maxLen} 个字符"]);
        }
        if (!preg_match($pattern, $newUsername)) {
            json_response(400, ['error' => 'invalid_username', 'message' => '用户名包含非法字符']);
        }
        if (in_array(strtolower($newUsername), array_map('strtolower', $reserved))) {
            json_response(400, ['error' => 'reserved_username', 'message' => '该用户名为系统保留']);
        }
        if ($newUsername !== $user['username']) {
            $existing = $db->get('users', ['username' => $newUsername]);
            if ($existing) {
                json_response(409, ['error' => 'duplicate_username', 'message' => '用户名已被占用']);
            }
        }
        $updates['username'] = $newUsername;
    }

    // ── 密码 ──
    if (isset($input['new_password']) && $input['new_password'] !== '') {
        $oldPassword = isset($input['old_password']) ? $input['old_password'] : '';
        $newPassword = $input['new_password'];

        // 重新查询用户（authenticate 移除了 password 字段）
        $userWithPass = $db->get('users', ['id' => $user['id']]);
        if (!$userWithPass || !password_verify($oldPassword, $userWithPass['password'])) {
            json_response(400, ['error' => 'wrong_password', 'message' => '旧密码不正确']);
        }
        if (strlen($newPassword) < 6) {
            json_response(400, ['error' => 'password_too_short', 'message' => '新密码至少 6 个字符']);
        }
        $updates['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    // ── 头像（Base64 Data URI） ──
    if (isset($input['avatar']) && $input['avatar'] !== '') {
        $avatarData = $input['avatar'];
        if (preg_match('#^data:image/(\w+);base64,(.+)$#', $avatarData, $m)) {
            $ext = strtolower($m[1]);
            $ext = ($ext === 'jpeg') ? 'jpg' : $ext;
            $raw = base64_decode($m[2]);
            if ($raw === false) {
                json_response(400, ['error' => 'invalid_avatar', 'message' => '头像数据解码失败']);
            }
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                json_response(400, ['error' => 'invalid_avatar', 'message' => '不支持的图片格式，仅支持 JPG/PNG/GIF/WebP']);
            }
            // 限制大小 1MB
            if (strlen($raw) > 1024 * 1024) {
                json_response(400, ['error' => 'avatar_too_large', 'message' => '头像图片不能超过 1MB']);
            }

            $storageCfg = isset($config['upload']['storage']) ? $config['upload']['storage'] : [];
            $uploadDir  = isset($storageCfg['local_path']) ? rtrim($storageCfg['local_path'], '/') : (__DIR__ . '/../../uploads');
            $urlPrefix  = isset($storageCfg['url_prefix']) ? $storageCfg['url_prefix'] : '/uploads/';

            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            if (!is_writable($uploadDir)) {
                json_response(500, ['error' => 'dir_not_writable', 'message' => '上传目录不可写']);
            }

            $avatarName = 'avatar_' . $user['id'] . '_' . time() . '.' . $ext;
            $destPath = $uploadDir . '/' . $avatarName;
            if (@file_put_contents($destPath, $raw) === false) {
                json_response(500, ['error' => 'avatar_save_failed', 'message' => '头像保存失败']);
            }

            // 删除旧头像文件（可选）
            if (!empty($user['avatar'])) {
                $oldPath = $uploadDir . '/' . basename($user['avatar']);
                if (file_exists($oldPath) && strpos($user['avatar'], '/uploads/') !== false) {
                    @unlink($oldPath);
                }
            }

            $updates['avatar'] = $urlPrefix . $avatarName;
        } else {
            json_response(400, ['error' => 'invalid_avatar', 'message' => '头像数据格式不正确，需为 data:image/...;base64,...']);
        }
    }

    // ── 通知设置 ──
    $validModes = ['none', 'email', 'pushplus', 'webhook'];
    if (isset($input['notification_mode'])) {
        $mode = trim($input['notification_mode']);
        if (!in_array($mode, $validModes, true)) {
            json_response(400, ['error' => 'invalid_notification_mode', 'message' => '通知方式仅支持: none / email / pushplus / webhook']);
        }
        if ($mode === 'email' && empty($user['email']) && empty($input['notification_email'])) {
            json_response(400, ['error' => 'missing_email', 'message' => '选择邮件通知时需填写邮箱']);
        }
        if ($mode === 'pushplus' && empty($input['notification_pushplus_key']) && empty($user['notification_pushplus_key'])) {
            json_response(400, ['error' => 'missing_pushplus_key', 'message' => '选择 PushPlus 通知时需填写密钥']);
        }
        if ($mode === 'webhook' && empty($input['notification_webhook_url']) && empty($user['notification_webhook_url'])) {
            json_response(400, ['error' => 'missing_webhook_url', 'message' => '选择 Webhook 通知时需填写 URL']);
        }
        $updates['notification_mode'] = $mode;
    }

    // ── 通知邮箱 ──
    if (isset($input['notification_email'])) {
        $email = trim($input['notification_email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(400, ['error' => 'invalid_notification_email', 'message' => '通知邮箱格式不正确']);
        }
        $updates['notification_email'] = $email;
    }

    // ── PushPlus 密钥 ──
    if (isset($input['notification_pushplus_key'])) {
        $key = trim($input['notification_pushplus_key']);
        if ($key !== '' && strlen($key) > 128) {
            json_response(400, ['error' => 'pushplus_key_too_long', 'message' => 'PushPlus 密钥过长（最多128字符）']);
        }
        $updates['notification_pushplus_key'] = $key;
    }

    // ── Webhook URL ──
    if (isset($input['notification_webhook_url'])) {
        $url = trim($input['notification_webhook_url']);
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            json_response(400, ['error' => 'invalid_webhook_url', 'message' => 'Webhook URL 格式不正确']);
        }
        if ($url !== '' && strlen($url) > 500) {
            json_response(400, ['error' => 'webhook_url_too_long', 'message' => 'Webhook URL 过长（最多500字符）']);
        }
        $updates['notification_webhook_url'] = $url;
    }

    // ── Webhook 签名密钥 ──
    if (isset($input['notification_webhook_secret'])) {
        $secret = trim($input['notification_webhook_secret']);
        if ($secret !== '' && strlen($secret) > 128) {
            json_response(400, ['error' => 'webhook_secret_too_long', 'message' => 'Webhook 密钥过长（最多128字符）']);
        }
        $updates['notification_webhook_secret'] = $secret;
    }


    // ── 群通知开关 ──
    if (isset($input['notification_group_enabled'])) {
        $updates['notification_group_enabled'] = $input['notification_group_enabled'] ? 1 : 0;
    }

    // ── 私信通知开关 ──
    if (isset($input['notification_private_enabled'])) {
        $updates['notification_private_enabled'] = $input['notification_private_enabled'] ? 1 : 0;
    }
    // ── 通知模板 ──
    if (isset($input['notification_template'])) {
        $tpl = $input['notification_template'];
        if (!is_array($tpl)) {
            json_response(400, ['error' => 'invalid_template', 'message' => '通知模板应为 JSON 对象']);
        }
        // 验证键名
        foreach ($tpl as $method => $templates) {
            if (!in_array($method, ['email', 'pushplus', 'webhook'], true)) {
                json_response(400, ['error' => 'invalid_template_method', 'message' => "模板键仅支持 email / pushplus / webhook，收到: {$method}"]);
            }
            if (!is_array($templates)) {
                json_response(400, ['error' => 'invalid_template_format', 'message' => "{$method} 模板应为对象"]);
            }
        }
        $updates['notification_template'] = json_encode($tpl, JSON_UNESCAPED_UNICODE);
    }

    if (empty($updates)) {
        json_success([], '没有需要更新的字段');
    }

    try {
        $db->update('users', $updates, ['id' => $user['id']]);
    } catch (Exception $e) {
        json_response(500, ['error' => 'update_failed', 'message' => '资料更新失败']);
    }

    json_success([], '资料已更新');
}

json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET/POST 请求']);
