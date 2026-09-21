<?php
/**
 * 私聊会话列表
 *
 * GET /api/private/list.php
 *
 * 响应:
 *   chats  array  私聊会话列表（含对方用户信息、未读数、联系人元数据）
 *
 * 说明：查询逻辑见 core/ChatService::privateChatList()，与 /api/sync.php 复用同一实现。
 */

require_once __DIR__ . '/../bootstrap.php';

require_method('GET');

$user = authenticate();

json_success(['chats' => ChatService::privateChatList($user)]);
