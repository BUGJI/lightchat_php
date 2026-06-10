<?php
/**
 * 用户关系管理（好友/屏蔽/黑名单）
 *
 * GET  /api/users/relations.php?action=list&type=friend      获取好友列表
 * GET  /api/users/relations.php?action=list&type=blocked     获取屏蔽列表
 * GET  /api/users/relations.php?action=list&type=blacklist   获取黑名单列表
 * POST /api/users/relations.php?action=add                   添加好友
 * POST /api/users/relations.php?action=remove                移除好友
 * POST /api/users/relations.php?action=block                 屏蔽通知
 * POST /api/users/relations.php?action=unblock               取消屏蔽
 * POST /api/users/relations.php?action=blacklist             加入黑名单
 * POST /api/users/relations.php?action=unblacklist           移出黑名单
 * 
 * POST 请求体 JSON:
 *   target_user_id  int  目标用户 ID
 */

require_once __DIR__ . '/../bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = authenticate();
    
    $action = $_GET['action'] ?? '';
    $type = $_GET['type'] ?? 'friend';
    
    if ($action !== 'list') {
        json_response(400, ['error' => 'invalid_action', 'message' => '无效的操作']);
    }
    
    // 验证 type
    $validTypes = ['friend', 'blocked', 'blacklist'];
    if (!in_array($type, $validTypes)) {
        json_response(400, ['error' => 'invalid_type', 'message' => '无效的关系类型']);
    }
    
    // 映射 type 到 relation_type
    $relationMap = [
        'friend' => 'friend',
        'blocked' => 'blocked',
        'blacklist' => 'blacklist',
    ];
    
    $relType = $relationMap[$type];
    
    // 查询关系列表
    $relations = $db->select('user_relations', [
        'user_id' => $user['id'],
        'relation_type' => $relType,
    ], '*', 'created_at DESC');
    
    // 获取目标用户信息
    $result = [];
    foreach ($relations as $rel) {
        $targetUser = $db->get('users', ['id' => (int)$rel['target_user_id']]);
        if ($targetUser) {
            $result[] = [
                'id' => (int)$rel['id'],
                'target_user_id' => (int)$rel['target_user_id'],
                'target_username' => $targetUser['username'],
                'target_avatar' => $targetUser['avatar'] ?? null,
                'relation_type' => $rel['relation_type'],
                'mute_notifications' => (bool)$rel['mute_notifications'],
                'created_at' => $rel['created_at'],
            ];
        }
    }
    
    json_success(['relations' => $result]);
}

if ($method === 'POST') {
    $user = authenticate();
    
    $action = $_POST['action'] ?? '';
    $input = get_json_input();
    $targetUserId = isset($input['target_user_id']) ? (int)$input['target_user_id'] : 0;
    
    if ($targetUserId <= 0) {
        json_response(400, ['error' => 'invalid_user', 'message' => '目标用户 ID 无效']);
    }
    
    if ($targetUserId === $user['id']) {
        json_response(400, ['error' => 'self_operation', 'message' => '不能对自己进行操作']);
    }
    
    // 检查目标用户是否存在
    $targetUser = $db->get('users', ['id' => $targetUserId]);
    if (!$targetUser) {
        json_response(404, ['error' => 'not_found', 'message' => '目标用户不存在']);
    }
    
    switch ($action) {
        case 'add':
            // 添加好友：先检查是否在黑名单
            $existingBlacklist = $db->get('user_relations', [
                'user_id' => $user['id'],
                'target_user_id' => $targetUserId,
                'relation_type' => 'blacklist',
            ]);
            if ($existingBlacklist) {
                json_response(400, ['error' => 'in_blacklist', 'message' => '该用户在您的黑名单中，无法添加好友']);
            }
            
            // 检查是否已是好友
            $existingFriend = $db->get('user_relations', [
                'user_id' => $user['id'],
                'target_user_id' => $targetUserId,
                'relation_type' => 'friend',
            ]);
            if ($existingFriend) {
                json_response(400, ['error' => 'already_friend', 'message' => '已经是好友']);
            }
            
            // 添加好友关系
            try {
                $db->insert('user_relations', [
                    'user_id' => $user['id'],
                    'target_user_id' => $targetUserId,
                    'relation_type' => 'friend',
                    'mute_notifications' => 0,
                ]);
            } catch (Exception $e) {
                json_response(500, ['error' => 'add_failed', 'message' => '添加好友失败']);
            }
            json_success(['message' => '好友添加成功']);
            break;
            
        case 'remove':
            // 移除好友
            try {
                $db->delete('user_relations', [
                    'user_id' => $user['id'],
                    'target_user_id' => $targetUserId,
                    'relation_type' => 'friend',
                ]);
            } catch (Exception $e) {
                json_response(500, ['error' => 'remove_failed', 'message' => '移除好友失败']);
            }
            json_success(['message' => '好友已移除']);
            break;
            
        case 'block':
            // 屏蔽通知（仍接收消息但不通知）
            // 先检查是否是好友，不是则先添加为好友
            $existingFriend = $db->get('user_relations', [
                'user_id' => $user['id'],
                'target_user_id' => $targetUserId,
                'relation_type' => 'friend',
            ]);
            
            if (!$existingFriend) {
                // 添加为好友并设置屏蔽
                try {
                    $db->insert('user_relations', [
                        'user_id' => $user['id'],
                        'target_user_id' => $targetUserId,
                        'relation_type' => 'friend',
                        'mute_notifications' => 1,
                    ]);
                } catch (Exception $e) {
                    json_response(500, ['error' => 'block_failed', 'message' => '屏蔽失败']);
                }
            } else {
                // 更新现有关系为屏蔽状态
                try {
                    $db->update('user_relations', [
                        'mute_notifications' => 1,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], [
                        'user_id' => $user['id'],
                        'target_user_id' => $targetUserId,
                    ]);
                } catch (Exception $e) {
                    json_response(500, ['error' => 'block_failed', 'message' => '屏蔽失败']);
                }
            }
            json_success(['message' => '已屏蔽该用户的通知']);
            break;
            
        case 'unblock':
            // 取消屏蔽
            try {
                $db->update('user_relations', [
                    'mute_notifications' => 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], [
                    'user_id' => $user['id'],
                    'target_user_id' => $targetUserId,
                ]);
            } catch (Exception $e) {
                json_response(500, ['error' => 'unblock_failed', 'message' => '取消屏蔽失败']);
            }
            json_success(['message' => '已取消屏蔽']);
            break;
            
        case 'blacklist':
            // 加入黑名单（不允许对方添加好友，自动移除好友关系）
            // 先删除可能存在的好友关系
            $db->delete('user_relations', [
                'user_id' => $user['id'],
                'target_user_id' => $targetUserId,
                'relation_type' => 'friend',
            ]);
            
            try {
                $db->insert('user_relations', [
                    'user_id' => $user['id'],
                    'target_user_id' => $targetUserId,
                    'relation_type' => 'blacklist',
                    'mute_notifications' => 0,
                ]);
            } catch (Exception $e) {
                json_response(500, ['error' => 'blacklist_failed', 'message' => '加入黑名单失败']);
            }
            json_success(['message' => '已加入黑名单']);
            break;
            
        case 'unblacklist':
            // 移出黑名单
            try {
                $db->delete('user_relations', [
                    'user_id' => $user['id'],
                    'target_user_id' => $targetUserId,
                    'relation_type' => 'blacklist',
                ]);
            } catch (Exception $e) {
                json_response(500, ['error' => 'unblacklist_failed', 'message' => '移出黑名单失败']);
            }
            json_success(['message' => '已移出黑名单']);
            break;
            
        default:
            json_response(400, ['error' => 'invalid_action', 'message' => '无效的操作']);
    }
}

json_response(405, ['error' => 'method_not_allowed', 'message' => '仅支持 GET/POST 请求']);
