<?php
/**
 * 列出所有用户（公开信息）
 *
 * GET /api/users/list.php
 *
 * 响应:
 *   users  array  用户列表（仅公开字段）
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

authenticate();

$allUsers = $db->select('users', [], '*', 'id ASC');

$users = [];
$publicFields = ['username', 'avatar', 'role', 'status'];

foreach ($allUsers as $u) {
    if (isset($u['status']) && (int)$u['status'] !== 1) continue;

    $users[] = [
        'id'        => (int)$u['id'],
        'username'  => $u['username'],
        'nickname'  => $u['nickname'] ?? $u['username'],
        'avatar'    => $u['avatar'] ?? null,
        'role'      => $u['role'] ?? 'member',
        'status'    => (int)($u['status'] ?? 1),
        'created_at'=> $u['created_at'] ?? '',
    ];
}

json_success(['users' => $users]);
