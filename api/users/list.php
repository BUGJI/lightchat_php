<?php
/**
 * 列出所有用户（公开信息，游标分页）
 *
 * GET /api/users/list.php?limit=100&cursor=50
 *
 * 参数:
 *   limit   int  每页数量（默认 100，最大 500）
 *   cursor  int  上一页返回的 next_cursor（按 id 游标翻页），0 表示第一页
 *
 * 响应:
 *   users        array  用户列表（仅公开字段）
 *   count        int    本页数量
 *   next_cursor  int    下一页游标（null 表示没有更多）
 *   has_more     bool   是否还有下一页
 */

require_once __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET 请求']);
}

authenticate();

$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$cursor = isset($_GET['cursor']) ? (int)$_GET['cursor'] : 0;

if ($limit < 1) $limit = 100;
if ($limit > 500) $limit = 500;

// 条件下推：status=1 + id > cursor，多取一条用于判断 has_more
$where = ['status' => 1];
if ($cursor > 0) {
    $where['id >'] = $cursor;
}
$allUsers = $db->select('users', $where, '*', 'id ASC', $limit + 1);

$hasMore = count($allUsers) > $limit;
if ($hasMore) {
    array_pop($allUsers);
}

$users = [];
foreach ($allUsers as $u) {
    $users[] = [
        'id'         => (int)$u['id'],
        'username'   => $u['username'],
        'nickname'   => $u['nickname'] ?? $u['username'],
        'avatar'     => $u['avatar'] ?? null,
        'role'       => $u['role'] ?? 'member',
        'status'     => (int)($u['status'] ?? 1),
        'created_at' => $u['created_at'] ?? '',
    ];
}

$nextCursor = null;
if ($hasMore && !empty($allUsers)) {
    $nextCursor = (int)$allUsers[count($allUsers) - 1]['id'];
}

json_success([
    'users'       => $users,
    'count'       => count($users),
    'next_cursor' => $nextCursor,
    'has_more'    => $hasMore,
]);
