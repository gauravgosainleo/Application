<?php
/*
 * Friends: list, search players, requests, remove, block/unblock.
 */

function sort_cards_online_first(array $cards)
{
    $rank = ['online' => 0, 'busy' => 1, 'away' => 2, 'offline' => 3];
    usort($cards, function ($a, $b) use ($rank) {
        $ra = isset($rank[$a['presence']]) ? $rank[$a['presence']] : 3;
        $rb = isset($rank[$b['presence']]) ? $rank[$b['presence']] : 3;
        if ($ra !== $rb) {
            return $ra - $rb;
        }
        return strcmp((string)$b['last_seen'], (string)$a['last_seen']);
    });
    return $cards;
}

function api_friends_list()
{
    $me = uid();
    $friends = array_values(hydrate_users(friend_ids($me)));

    $incoming = q_all("SELECT requester_id AS uid, created_at FROM friendships WHERE addressee_id = ? AND status = 'pending' ORDER BY created_at DESC", [$me]);
    $outgoing = q_all("SELECT addressee_id AS uid, created_at FROM friendships WHERE requester_id = ? AND status = 'pending' ORDER BY created_at DESC", [$me]);
    $blocked = q_all('SELECT blocked_id AS uid, created_at FROM user_blocks WHERE blocker_id = ? ORDER BY created_at DESC', [$me]);
    $cards = hydrate_users(array_merge(array_column($incoming, 'uid'), array_column($outgoing, 'uid'), array_column($blocked, 'uid')));
    $attach = function (array $rows) use ($cards) {
        $out = [];
        foreach ($rows as $r) {
            if (isset($cards[(int)$r['uid']])) {
                $out[] = $cards[(int)$r['uid']] + ['since' => iso($r['created_at'])];
            }
        }
        return $out;
    };
    return [
        'friends'  => sort_cards_online_first($friends),
        'incoming' => $attach($incoming),
        'outgoing' => $attach($outgoing),
        'blocked'  => $attach($blocked),
    ];
}

function api_friends_search()
{
    $me = uid();
    $search = in_str('q', 60);
    $exclude = array_merge([$me], blocked_ids($me));
    if (str_len($search) < 2) {
        // Suggestions: recently active players who are not friends yet.
        $exclude = array_merge($exclude, friend_ids($me));
        $ids = q_col('SELECT id FROM users WHERE id NOT IN (' . placeholders($exclude) . ') AND (banned_until IS NULL OR banned_until < ?)
            ORDER BY last_seen_at DESC LIMIT 12', array_merge($exclude, [now()]));
    } else {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $ids = q_col('SELECT id FROM users WHERE (name LIKE ? OR username LIKE ?) AND id NOT IN (' . placeholders($exclude) . ')
            AND (banned_until IS NULL OR banned_until < ?) ORDER BY (username = ?) DESC, last_seen_at DESC LIMIT 25',
            array_merge([$like, $like], $exclude, [now(), strtolower($search)]));
    }
    $cards = hydrate_users($ids);
    $out = [];
    foreach ($ids as $id) {
        if (isset($cards[(int)$id])) {
            $out[] = $cards[(int)$id] + ['relation' => relation_to($me, $id)];
        }
    }
    return $out;
}

function target_user_or_fail($me)
{
    $id = require_id('user_id');
    if ($id === (int)$me) {
        fail('That is you!', 422);
    }
    $user = q_one('SELECT id, name, banned_until FROM users WHERE id = ?', [$id]);
    if (!$user) {
        fail('Player not found.', 404);
    }
    return $user;
}

function api_friends_request()
{
    $me = current_user();
    $target = target_user_or_fail($me['id']);
    if (is_blocked_between($me['id'], $target['id'])) {
        fail('You cannot send a friend request to this player.', 403);
    }
    $existing = friendship_between($me['id'], $target['id']);
    if ($existing) {
        if ($existing['status'] === 'accepted') {
            return ['relation' => 'friends'];
        }
        if ((int)$existing['requester_id'] === (int)$me['id']) {
            return ['relation' => 'outgoing'];
        }
        // They already asked us: accept right away.
        q("UPDATE friendships SET status = 'accepted', responded_at = ? WHERE id = ?", [now(), $existing['id']]);
        notify($target['id'], 'friend_accepted', $me['name'] . ' accepted your friend request', null, '#/profile/' . $me['id'], $me['id']);
        return ['relation' => 'friends'];
    }
    rate_limit_or_fail('friendreq:' . $me['id'], 50, 3600);
    q("INSERT INTO friendships (requester_id, addressee_id, pair_key, status, created_at) VALUES (?, ?, ?, 'pending', ?)",
        [$me['id'], $target['id'], pair_key($me['id'], $target['id']), now()]);
    notify($target['id'], 'friend_request', $me['name'] . ' sent you a friend request', '@' . $me['username'], '#/friends?tab=requests', $me['id']);
    return ['relation' => 'outgoing'];
}

function api_friends_respond()
{
    $me = current_user();
    $target = target_user_or_fail($me['id']);
    $action = in_enum('action', ['accept', 'decline']);
    $f = q_one("SELECT * FROM friendships WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'", [$target['id'], $me['id']]);
    if (!$f) {
        fail('Friend request not found.', 404);
    }
    if ($action === 'accept') {
        q("UPDATE friendships SET status = 'accepted', responded_at = ? WHERE id = ?", [now(), $f['id']]);
        notify($target['id'], 'friend_accepted', $me['name'] . ' accepted your friend request', 'You can now chat and share map markers.', '#/profile/' . $me['id'], $me['id']);
        award_xp($me['id'], 5, 'friend', (string)$target['id']);
        award_xp($target['id'], 5, 'friend', (string)$me['id']);
        return ['relation' => 'friends'];
    }
    q('DELETE FROM friendships WHERE id = ?', [$f['id']]);
    return ['relation' => 'none'];
}

function api_friends_cancel()
{
    $me = uid();
    $target = target_user_or_fail($me);
    q("DELETE FROM friendships WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'", [$me, $target['id']]);
    return ['relation' => 'none'];
}

function api_friends_remove()
{
    $me = uid();
    $target = target_user_or_fail($me);
    q('DELETE FROM friendships WHERE pair_key = ?', [pair_key($me, $target['id'])]);
    return ['relation' => 'none'];
}

function api_friends_block()
{
    $me = uid();
    $target = target_user_or_fail($me);
    q('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id, created_at) VALUES (?, ?, ?)', [$me, $target['id'], now()]);
    q('DELETE FROM friendships WHERE pair_key = ?', [pair_key($me, $target['id'])]);
    return ['relation' => 'blocked'];
}

function api_friends_unblock()
{
    $me = uid();
    $target = target_user_or_fail($me);
    q('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [$me, $target['id']]);
    return ['relation' => 'none'];
}

return [
    'list'    => ['GET', 'user', 'api_friends_list'],
    'search'  => ['GET', 'user', 'api_friends_search'],
    'request' => ['POST', 'user', 'api_friends_request'],
    'respond' => ['POST', 'user', 'api_friends_respond'],
    'cancel'  => ['POST', 'user', 'api_friends_cancel'],
    'remove'  => ['POST', 'user', 'api_friends_remove'],
    'block'   => ['POST', 'user', 'api_friends_block'],
    'unblock' => ['POST', 'user', 'api_friends_unblock'],
];
