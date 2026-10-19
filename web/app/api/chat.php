<?php
/*
 * Chat: direct messages between friends and group chats.
 * The browser polls chat/messages?after_id=... every few seconds.
 */

function chat_member_or_fail($conversationId, $userId)
{
    $row = q_one('SELECT c.*, cm.role, cm.last_read_id FROM conversations c
        JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = ?
        WHERE c.id = ?', [(int)$userId, (int)$conversationId]);
    if (!$row) {
        fail('Conversation not found.', 404);
    }
    return $row;
}

function chat_message_out(array $m)
{
    $deleted = !empty($m['deleted_at']);
    return [
        'id'         => (int)$m['id'],
        'user_id'    => $m['user_id'] !== null ? (int)$m['user_id'] : null,
        'kind'       => $m['kind'],
        'body'       => $deleted ? null : $m['body'],
        'attachment' => $deleted ? null : $m['attachment'],
        'deleted'    => $deleted,
        'created_at' => iso($m['created_at']),
    ];
}

function chat_system_message($conversationId, $text)
{
    $id = q_insert("INSERT INTO messages (conversation_id, user_id, kind, body, created_at) VALUES (?, NULL, 'system', ?, ?)",
        [(int)$conversationId, $text, now()]);
    q('UPDATE conversations SET last_message_id = ?, last_message_at = ? WHERE id = ?', [$id, now(), (int)$conversationId]);
    return $id;
}

function chat_conversation_out(array $c, $me)
{
    $memberIds = array_map('intval', q_col('SELECT user_id FROM conversation_members WHERE conversation_id = ? ORDER BY joined_at', [$c['id']]));
    $cards = hydrate_users($memberIds);
    $members = [];
    foreach ($memberIds as $mid) {
        if (isset($cards[$mid])) {
            $members[] = $cards[$mid];
        }
    }
    $other = null;
    if ($c['type'] === 'direct') {
        foreach ($members as $card) {
            if ($card['id'] !== (int)$me) {
                $other = $card;
            }
        }
        if (!$other) {
            $other = deleted_user_card();
        }
    }
    $last = $c['last_message_id'] ? q_one('SELECT * FROM messages WHERE id = ?', [$c['last_message_id']]) : null;
    $unread = (int)q_val("SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND id > ? AND deleted_at IS NULL
        AND kind <> 'system' AND (user_id IS NULL OR user_id <> ?)", [$c['id'], (int)$c['last_read_id'], (int)$me]);
    $roles = [];
    foreach (q_all('SELECT user_id, role FROM conversation_members WHERE conversation_id = ?', [$c['id']]) as $r) {
        $roles[(int)$r['user_id']] = $r['role'];
    }
    return [
        'id'              => (int)$c['id'],
        'type'            => $c['type'],
        'name'            => $c['type'] === 'direct' ? $other['name'] : $c['name'],
        'avatar'          => $c['type'] === 'direct' ? $other['avatar'] : $c['avatar'],
        'other'           => $other,
        'members'         => $members,
        'roles'           => $roles,
        'my_role'         => isset($c['role']) ? $c['role'] : null,
        'unread'          => $unread,
        'last_message'    => $last ? chat_message_out($last) : null,
        'last_message_at' => iso($c['last_message_at'] ?: $c['created_at']),
    ];
}

function api_chat_conversations()
{
    $me = uid();
    $rows = q_all('SELECT c.*, cm.role, cm.last_read_id FROM conversations c
        JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = ?
        ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT 200', [$me]);
    $out = [];
    foreach ($rows as $c) {
        // Hide empty direct chats the other person opened but never wrote in.
        if ($c['type'] === 'direct' && !$c['last_message_id'] && (int)$c['created_by'] !== $me) {
            continue;
        }
        $out[] = chat_conversation_out($c, $me);
    }
    return $out;
}

function api_chat_open()
{
    $me = current_user();
    $target = q_one('SELECT * FROM users WHERE id = ?', [require_id('user_id')]);
    if (!$target || (int)$target['id'] === (int)$me['id']) {
        fail('Player not found.', 404);
    }
    if (is_blocked_between($me['id'], $target['id'])) {
        fail('You cannot message this player.', 403);
    }
    if (!are_friends($me['id'], $target['id']) && $target['dm_privacy'] !== 'everyone') {
        fail($target['name'] . ' only accepts messages from friends. Send a friend request first.', 403, 'not_friends');
    }
    $key = pair_key($me['id'], $target['id']);
    $conv = q_one('SELECT * FROM conversations WHERE direct_key = ?', [$key]);
    if (!$conv) {
        try {
            $cid = q_insert("INSERT INTO conversations (type, direct_key, created_by, created_at) VALUES ('direct', ?, ?, ?)", [$key, $me['id'], now()]);
            q("INSERT INTO conversation_members (conversation_id, user_id, role, last_read_id, joined_at) VALUES (?, ?, 'member', 0, ?), (?, ?, 'member', 0, ?)",
                [$cid, $me['id'], now(), $cid, $target['id'], now()]);
        } catch (PDOException $e) {
            if (!is_duplicate_error($e)) {
                throw $e;
            }
            $cid = (int)q_val('SELECT id FROM conversations WHERE direct_key = ?', [$key]);
        }
    } else {
        $cid = (int)$conv['id'];
        // Re-add members if one of them had removed the chat earlier.
        foreach ([$me['id'], $target['id']] as $uidToAdd) {
            q("INSERT IGNORE INTO conversation_members (conversation_id, user_id, role, last_read_id, joined_at) VALUES (?, ?, 'member', ?, ?)",
                [$cid, $uidToAdd, (int)$conv['last_message_id'], now()]);
        }
    }
    return chat_conversation_out(chat_member_or_fail($cid, $me['id']), $me['id']);
}

function api_chat_group_create()
{
    $me = current_user();
    $name = in_str('name', 80, true, 'Group name');
    $memberIds = in_ids('member_ids');
    $friends = friend_ids($me['id']);
    $memberIds = array_values(array_intersect($memberIds, $friends));
    if (!$memberIds) {
        fail('Add at least one friend to the group.', 422);
    }
    if (count($memberIds) > 49) {
        fail('Groups can have up to 50 members.', 422);
    }
    rate_limit_or_fail('groupcreate:' . $me['id'], 10, 3600);
    $avatar = Upload::has('file') ? Upload::image('file', 'groups', ['square' => 400]) : null;
    $cid = q_insert("INSERT INTO conversations (type, name, avatar, created_by, created_at) VALUES ('group', ?, ?, ?, ?)", [$name, $avatar, $me['id'], now()]);
    q("INSERT INTO conversation_members (conversation_id, user_id, role, last_read_id, joined_at) VALUES (?, ?, 'owner', 0, ?)", [$cid, $me['id'], now()]);
    foreach ($memberIds as $mid) {
        q("INSERT INTO conversation_members (conversation_id, user_id, role, last_read_id, joined_at) VALUES (?, ?, 'member', 0, ?)", [$cid, $mid, now()]);
        notify($mid, 'group_added', $me['name'] . ' added you to "' . $name . '"', null, '#/friends?chat=' . $cid, $me['id']);
    }
    chat_system_message($cid, $me['name'] . ' created the group "' . $name . '"');
    return chat_conversation_out(chat_member_or_fail($cid, $me['id']), $me['id']);
}

function api_chat_group_update()
{
    $me = current_user();
    $conv = chat_member_or_fail(require_id('conversation_id'), $me['id']);
    if ($conv['type'] !== 'group') {
        fail('Only groups can be renamed.', 422);
    }
    if (has_input('name')) {
        $name = in_str('name', 80, true, 'Group name');
        if ($name !== $conv['name']) {
            q('UPDATE conversations SET name = ? WHERE id = ?', [$name, $conv['id']]);
            chat_system_message($conv['id'], $me['name'] . ' renamed the group to "' . $name . '"');
        }
    }
    if (Upload::has('file')) {
        $avatar = Upload::image('file', 'groups', ['square' => 400]);
        Upload::delete($conv['avatar']);
        q('UPDATE conversations SET avatar = ? WHERE id = ?', [$avatar, $conv['id']]);
        chat_system_message($conv['id'], $me['name'] . ' changed the group photo');
    }
    return chat_conversation_out(chat_member_or_fail($conv['id'], $me['id']), $me['id']);
}

function api_chat_group_add()
{
    $me = current_user();
    $conv = chat_member_or_fail(require_id('conversation_id'), $me['id']);
    if ($conv['type'] !== 'group') {
        fail('You can only add members to groups.', 422);
    }
    $ids = array_values(array_intersect(in_ids('user_ids'), friend_ids($me['id'])));
    if (!$ids) {
        fail('Choose at least one friend to add.', 422);
    }
    $count = (int)q_val('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?', [$conv['id']]);
    if ($count + count($ids) > 50) {
        fail('Groups can have up to 50 members.', 422);
    }
    $cards = hydrate_users($ids);
    foreach ($ids as $id) {
        $added = q_exec("INSERT IGNORE INTO conversation_members (conversation_id, user_id, role, last_read_id, joined_at) VALUES (?, ?, 'member', ?, ?)",
            [$conv['id'], $id, (int)$conv['last_message_id'], now()]);
        if ($added && isset($cards[$id])) {
            chat_system_message($conv['id'], $me['name'] . ' added ' . $cards[$id]['name']);
            notify($id, 'group_added', $me['name'] . ' added you to "' . $conv['name'] . '"', null, '#/friends?chat=' . $conv['id'], $me['id']);
        }
    }
    return chat_conversation_out(chat_member_or_fail($conv['id'], $me['id']), $me['id']);
}

function api_chat_group_remove()
{
    $me = current_user();
    $conv = chat_member_or_fail(require_id('conversation_id'), $me['id']);
    $target = require_id('user_id');
    if ($conv['type'] !== 'group' || $conv['role'] !== 'owner') {
        fail('Only the group owner can remove members.', 403);
    }
    if ($target === (int)$me['id']) {
        fail('Use "Leave group" to leave.', 422);
    }
    if (q_exec('DELETE FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$conv['id'], $target])) {
        $cards = hydrate_users([$target]);
        chat_system_message($conv['id'], $me['name'] . ' removed ' . (isset($cards[$target]) ? $cards[$target]['name'] : 'a member'));
    }
    return chat_conversation_out(chat_member_or_fail($conv['id'], $me['id']), $me['id']);
}

function api_chat_leave()
{
    $me = current_user();
    $conv = chat_member_or_fail(require_id('conversation_id'), $me['id']);
    q('DELETE FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$conv['id'], $me['id']]);
    if ($conv['type'] === 'group') {
        $remaining = (int)q_val('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?', [$conv['id']]);
        if ($remaining === 0) {
            foreach (q_col('SELECT attachment FROM messages WHERE conversation_id = ? AND attachment IS NOT NULL', [$conv['id']]) as $f) {
                Upload::delete($f);
            }
            Upload::delete($conv['avatar']);
            q('DELETE FROM conversations WHERE id = ?', [$conv['id']]);
        } else {
            chat_system_message($conv['id'], $me['name'] . ' left the group');
            if ($conv['role'] === 'owner') {
                $next = q_val('SELECT user_id FROM conversation_members WHERE conversation_id = ? ORDER BY joined_at ASC LIMIT 1', [$conv['id']]);
                q("UPDATE conversation_members SET role = 'owner' WHERE conversation_id = ? AND user_id = ?", [$conv['id'], $next]);
            }
        }
    }
    return ['left' => true];
}

function api_chat_messages()
{
    $me = uid();
    $conv = chat_member_or_fail(require_id('conversation_id'), $me);
    $after = in_int('after_id');
    $before = in_int('before_id');
    if ($after > 0) {
        $rows = q_all('SELECT * FROM messages WHERE conversation_id = ? AND id > ? ORDER BY id ASC LIMIT 200', [$conv['id'], $after]);
    } elseif ($before > 0) {
        $rows = array_reverse(q_all('SELECT * FROM messages WHERE conversation_id = ? AND id < ? ORDER BY id DESC LIMIT 40', [$conv['id'], $before]));
    } else {
        $rows = array_reverse(q_all('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 40', [$conv['id']]));
    }
    $maxId = $rows ? (int)end($rows)['id'] : 0;
    if ($maxId > (int)$conv['last_read_id']) {
        q('UPDATE conversation_members SET last_read_id = ? WHERE conversation_id = ? AND user_id = ? AND last_read_id < ?', [$maxId, $conv['id'], $me, $maxId]);
    }
    // Read receipts: the furthest message every other member has read.
    $othersRead = (int)q_val('SELECT MIN(last_read_id) FROM conversation_members WHERE conversation_id = ? AND user_id <> ?', [$conv['id'], $me]);
    $out = [
        'messages'    => array_map('chat_message_out', $rows),
        'others_read' => $othersRead,
        'has_more'    => $after === 0 && count($rows) === 40,
    ];
    if ($after === 0 && $before === 0) {
        $out['conversation'] = chat_conversation_out(chat_member_or_fail($conv['id'], $me), $me);
    }
    return $out;
}

function api_chat_send()
{
    $me = current_user();
    $conv = chat_member_or_fail(require_id('conversation_id'), $me['id']);
    $body = in_text('body', 2000, false, 'Message');
    $hasImage = Upload::has('file');
    if ($body === '' && !$hasImage) {
        fail('Type a message first.', 422);
    }
    rate_limit_or_fail('chat:' . $me['id'], 40, 60, 'You are sending messages too fast. Slow down a little.');
    if ($conv['type'] === 'direct') {
        $otherId = (int)q_val('SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id <> ? LIMIT 1', [$conv['id'], $me['id']]);
        if (!$otherId) {
            list($a, $b) = array_map('intval', explode(':', $conv['direct_key']));
            $otherId = $a === (int)$me['id'] ? $b : $a;
            if (q_val('SELECT 1 FROM users WHERE id = ?', [$otherId])) {
                q("INSERT IGNORE INTO conversation_members (conversation_id, user_id, role, last_read_id, joined_at) VALUES (?, ?, 'member', ?, ?)",
                    [$conv['id'], $otherId, (int)$conv['last_message_id'], now()]);
            } else {
                fail('This player no longer exists.', 410);
            }
        }
        if (is_blocked_between($me['id'], $otherId)) {
            fail('You cannot message this player.', 403);
        }
    }
    $attachment = $hasImage ? Upload::image('file', 'chat', ['max' => 1600]) : null;
    $id = q_insert('INSERT INTO messages (conversation_id, user_id, kind, body, attachment, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$conv['id'], $me['id'], $attachment ? 'image' : 'text', $body === '' ? null : $body, $attachment, now()]);
    q('UPDATE conversations SET last_message_id = ?, last_message_at = ? WHERE id = ?', [$id, now(), $conv['id']]);
    q('UPDATE conversation_members SET last_read_id = ? WHERE conversation_id = ? AND user_id = ?', [$id, $conv['id'], $me['id']]);
    return chat_message_out(q_one('SELECT * FROM messages WHERE id = ?', [$id]));
}

function api_chat_delete_message()
{
    $me = uid();
    $msg = q_one('SELECT * FROM messages WHERE id = ? AND user_id = ?', [require_id('message_id'), $me]);
    if (!$msg) {
        fail('Message not found.', 404);
    }
    Upload::delete($msg['attachment']);
    q('UPDATE messages SET body = NULL, attachment = NULL, deleted_at = ? WHERE id = ?', [now(), $msg['id']]);
    return chat_message_out(q_one('SELECT * FROM messages WHERE id = ?', [$msg['id']]));
}

function api_chat_read()
{
    $me = uid();
    $conv = chat_member_or_fail(require_id('conversation_id'), $me);
    $maxId = (int)q_val('SELECT MAX(id) FROM messages WHERE conversation_id = ?', [$conv['id']]);
    q('UPDATE conversation_members SET last_read_id = ? WHERE conversation_id = ? AND user_id = ?', [$maxId, $conv['id'], $me]);
    return ['read' => $maxId];
}

return [
    'conversations'  => ['GET', 'user', 'api_chat_conversations'],
    'open'           => ['POST', 'user', 'api_chat_open'],
    'group-create'   => ['POST', 'user', 'api_chat_group_create'],
    'group-update'   => ['POST', 'user', 'api_chat_group_update'],
    'group-add'      => ['POST', 'user', 'api_chat_group_add'],
    'group-remove'   => ['POST', 'user', 'api_chat_group_remove'],
    'leave'          => ['POST', 'user', 'api_chat_leave'],
    'messages'       => ['GET', 'user', 'api_chat_messages'],
    'send'           => ['POST', 'user', 'api_chat_send'],
    'delete-message' => ['POST', 'user', 'api_chat_delete_message'],
    'read'           => ['POST', 'user', 'api_chat_read'],
];
