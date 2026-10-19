<?php
/*
 * Shared "game" logic: XP & levels, ranks, notifications, public user
 * cards, presence, friendships and blocking.
 */

const ONLINE_WINDOW = 150; // seconds since last activity to count as online

/* ------------------------------------------------------- XP & level */

function level_for_xp($xp)
{
    return (int)floor(sqrt(max(0, (int)$xp) / 50)) + 1;
}

function xp_for_level($level)
{
    $level = max(1, (int)$level);
    return 50 * ($level - 1) * ($level - 1);
}

function rank_for_level($level)
{
    $ranks = [
        40 => 'Living Legend',
        30 => 'Leonida Kingpin',
        20 => 'Vice City Boss',
        15 => 'Heist Planner',
        10 => 'Getaway Driver',
        5  => 'Corner Hustler',
        1  => 'Street Rookie',
    ];
    foreach ($ranks as $min => $name) {
        if ($level >= $min) {
            return $name;
        }
    }
    return 'Street Rookie';
}

function level_info($xp)
{
    $level = level_for_xp($xp);
    $current = xp_for_level($level);
    $next = xp_for_level($level + 1);
    return [
        'xp'         => (int)$xp,
        'level'      => $level,
        'rank'       => rank_for_level($level),
        'level_xp'   => $current,
        'next_xp'    => $next,
        'progress'   => $next > $current ? round(((int)$xp - $current) / ($next - $current), 4) : 1,
    ];
}

/**
 * Grants XP once per (reason, ref). Returns true when XP was added.
 * Sends a notification on level-up.
 */
function award_xp($userId, $amount, $reason, $ref = '')
{
    $amount = (int)$amount;
    if ($amount === 0) {
        return false;
    }
    try {
        q('INSERT INTO xp_log (user_id, amount, reason, ref_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [(int)$userId, $amount, $reason, (string)$ref, now()]);
    } catch (PDOException $e) {
        if (is_duplicate_error($e)) {
            return false;
        }
        throw $e;
    }
    $before = (int)q_val('SELECT xp FROM users WHERE id = ?', [(int)$userId]);
    $after = max(0, $before + $amount);
    q('UPDATE users SET xp = ? WHERE id = ?', [$after, (int)$userId]);
    if (isset($GLOBALS['__gvi_user']['id']) && (int)$GLOBALS['__gvi_user']['id'] === (int)$userId) {
        $GLOBALS['__gvi_user']['xp'] = $after;
    }
    $oldLevel = level_for_xp($before);
    $newLevel = level_for_xp($after);
    if ($newLevel > $oldLevel) {
        notify($userId, 'level_up', 'Level up! You reached level ' . $newLevel,
            'New rank: ' . rank_for_level($newLevel) . '. Check the rewards you can unlock.', '#/events?tab=rewards');
    }
    return true;
}

/** How many times XP was granted today for a reason (used for daily caps). */
function xp_count_today($userId, $reason)
{
    return (int)q_val('SELECT COUNT(*) FROM xp_log WHERE user_id = ? AND reason = ? AND created_at >= ?',
        [(int)$userId, $reason, gmdate('Y-m-d 00:00:00')]);
}

/* ---------------------------------------------------- notifications */

function notify($userId, $type, $title, $body = null, $link = null, $actorId = null)
{
    q('INSERT INTO notifications (user_id, type, title, body, link, actor_id, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)', [
        (int)$userId, $type, str_limit($title, 160), $body !== null ? str_limit($body, 300) : null, $link, $actorId, now(),
    ]);
}

/** Sends a notification to every active (not banned) user. */
function notify_all($type, $title, $body = null, $link = null)
{
    q('INSERT INTO notifications (user_id, type, title, body, link, actor_id, is_read, created_at)
       SELECT id, ?, ?, ?, ?, NULL, 0, ? FROM users WHERE banned_until IS NULL OR banned_until < ?', [
        $type, str_limit($title, 160), $body !== null ? str_limit($body, 300) : null, $link, now(), now(),
    ]);
}

/* ------------------------------------------------------ public users */

function presence_of(array $u, $forSelf = false)
{
    $chosen = $u['presence'] ?: 'online';
    if ($chosen === 'invisible' && !$forSelf) {
        return 'offline';
    }
    $recent = !empty($u['last_seen_at']) && strtotime($u['last_seen_at'] . ' UTC') > time() - ONLINE_WINDOW;
    if (!$recent) {
        return $forSelf && $chosen === 'invisible' ? 'invisible' : 'offline';
    }
    return $chosen;
}

/** Compact public representation of a user (used everywhere in lists). */
function user_card(array $u)
{
    $level = level_for_xp($u['xp']);
    $self = isset($GLOBALS['__gvi_user']['id']) && (int)$GLOBALS['__gvi_user']['id'] === (int)$u['id'];
    $presence = presence_of($u, $self);
    return [
        'id'            => (int)$u['id'],
        'name'          => $u['name'],
        'username'      => $u['username'],
        'avatar'        => $u['avatar'],
        'level'         => $level,
        'rank'          => rank_for_level($level),
        'presence'      => $presence,
        'presence_text' => $presence === 'offline' ? null : $u['presence_text'],
        'last_seen'     => ($u['presence'] === 'invisible' && !$self) ? null : iso($u['last_seen_at']),
        'platform'      => $u['platform'],
        'title'         => isset($u['title_text']) ? $u['title_text'] : null,
        'frame'         => isset($u['frame_style']) ? $u['frame_style'] : null,
    ];
}

const USER_CARD_SQL = 'SELECT u.id, u.name, u.username, u.avatar, u.xp, u.presence, u.presence_text, u.last_seen_at, u.platform,
        tr.title AS title_text, fr.style AS frame_style
    FROM users u
    LEFT JOIN rewards tr ON tr.id = u.equipped_title_id
    LEFT JOIN rewards fr ON fr.id = u.equipped_frame_id';

/** Returns [id => user_card] for the given ids. */
function hydrate_users(array $ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (q_all(USER_CARD_SQL . ' WHERE u.id IN (' . placeholders($ids) . ')', $ids) as $row) {
        $out[(int)$row['id']] = user_card($row);
    }
    return $out;
}

function deleted_user_card()
{
    return ['id' => 0, 'name' => 'Deleted user', 'username' => 'deleted', 'avatar' => null, 'level' => 1,
        'rank' => '', 'presence' => 'offline', 'presence_text' => null, 'last_seen' => null, 'platform' => null,
        'title' => null, 'frame' => null];
}

/* ------------------------------------------------- friends & blocks */

function pair_key($a, $b)
{
    $a = (int)$a;
    $b = (int)$b;
    return min($a, $b) . ':' . max($a, $b);
}

function friendship_between($a, $b)
{
    return q_one('SELECT * FROM friendships WHERE pair_key = ?', [pair_key($a, $b)]);
}

function are_friends($a, $b)
{
    $f = friendship_between($a, $b);
    return $f && $f['status'] === 'accepted';
}

function friend_ids($userId)
{
    $userId = (int)$userId;
    return array_map('intval', q_col(
        "SELECT CASE WHEN requester_id = ? THEN addressee_id ELSE requester_id END
         FROM friendships WHERE status = 'accepted' AND (requester_id = ? OR addressee_id = ?)",
        [$userId, $userId, $userId]
    ));
}

/** True when either user has blocked the other. */
function is_blocked_between($a, $b)
{
    return (bool)q_val('SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1',
        [(int)$a, (int)$b, (int)$b, (int)$a]);
}

/** Ids of users hidden from $userId (blocked by them or blocking them). */
function blocked_ids($userId)
{
    $userId = (int)$userId;
    return array_map('intval', q_col(
        'SELECT blocked_id FROM user_blocks WHERE blocker_id = ? UNION SELECT blocker_id FROM user_blocks WHERE blocked_id = ?',
        [$userId, $userId]
    ));
}

/** Relationship of the current user to $otherId: self | friends | outgoing | incoming | blocked | none */
function relation_to($me, $otherId)
{
    if ((int)$me === (int)$otherId) {
        return 'self';
    }
    if (q_val('SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [(int)$me, (int)$otherId])) {
        return 'blocked';
    }
    $f = friendship_between($me, $otherId);
    if (!$f) {
        return 'none';
    }
    if ($f['status'] === 'accepted') {
        return 'friends';
    }
    return (int)$f['requester_id'] === (int)$me ? 'outgoing' : 'incoming';
}
