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
 *   avatar    string  头像 URL
 *   bio       string  个人简介
 *   signature string  签名
 *   email     string  邮箱
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
