<?php
/*
 * Admin: player management - search, details, block for X days, unblock,
 * bonus XP and account deletion.
 */

function admin_user_row(array $u)
{
    $banned = user_is_banned($u);
    return [
        'id'            => (int)$u['id'],
        'name'          => $u['name'],
        'username'      => $u['username'],
        'email'         => $u['email'],
        'avatar'        => $u['avatar'],
        'level'         => level_for_xp($u['xp']),
        'xp'            => (int)$u['xp'],
        'platform'      => $u['platform'],
        'created_at'    => iso($u['created_at']),
        'last_seen_at'  => iso($u['last_seen_at']),
        'online'        => !empty($u['last_seen_at']) && strtotime($u['last_seen_at'] . ' UTC') > time() - ONLINE_WINDOW,
        'banned'        => $banned,
        'ban'           => $banned ? ban_info($u) : null,
        'discussions'   => isset($u['discussions']) ? (int)$u['discussions'] : null,
    ];
}

function api_admin_users()
{
    current_admin();
    $page = max(1, in_int('page', 1));
    $per = 25;
    $where = ['1 = 1'];
    $params = [];
    $search = in_str('q', 100);
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $filter = in_enum('filter', ['all', 'banned', 'active', 'online'], 'all');
    if ($filter === 'banned') {
        $where[] = 'u.banned_until > ?';
        $params[] = now();
    } elseif ($filter === 'active') {
        $where[] = '(u.banned_until IS NULL OR u.banned_until <= ?)';
        $params[] = now();
    } elseif ($filter === 'online') {
        $where[] = 'u.last_seen_at > ?';
        $params[] = gmdate('Y-m-d H:i:s', time() - ONLINE_WINDOW);
    }
    $whereSql = implode(' AND ', $where);
    $total = (int)q_val("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $params);
    $rows = q_all("SELECT u.*, (SELECT COUNT(*) FROM discussions d WHERE d.user_id = u.id) AS discussions
        FROM users u WHERE {$whereSql} ORDER BY u.id DESC LIMIT ? OFFSET ?", array_merge($params, [$per, ($page - 1) * $per]));
    return ['items' => array_map('admin_user_row', $rows), 'page' => $page, 'total' => $total, 'has_more' => $page * $per < $total];
}

function api_admin_user()
{
    current_admin();
    $u = q_one('SELECT * FROM users WHERE id = ?', [require_id()]);
    if (!$u) {
        fail('User not found.', 404);
    }
    $out = admin_user_row($u);
    $out += [
        'bio'           => $u['bio'],
        'country'       => $u['country'],
        'cover'         => $u['cover'],
        'last_login_at' => iso($u['last_login_at']),
        'login_streak'  => (int)$u['login_streak'],
        'two_step'      => (int)$u['login_otp_enabled'] === 1,
        'stats'         => user_stats($u['id']),
    ];
    $bans = array_map(function ($b) {
        return [
            'id'           => (int)$b['id'],
            'reason'       => $b['reason'],
            'days'         => $b['days'] !== null ? (int)$b['days'] : null,
            'context'      => $b['context'],
            'context_ref'  => $b['context_ref'],
            'banned_until' => (int)substr($b['banned_until'], 0, 4) >= 9000 ? null : iso($b['banned_until']),
            'created_at'   => iso($b['created_at']),
            'lifted_at'    => iso($b['lifted_at']),
            'admin'        => $b['admin_name'],
        ];
    }, q_all('SELECT b.*, a.username AS admin_name FROM user_bans b LEFT JOIN admins a ON a.id = b.admin_id WHERE b.user_id = ? ORDER BY b.id DESC', [$u['id']]));
    $discussions = array_map(function ($d) {
        return ['id' => (int)$d['id'], 'title' => $d['title'], 'created_at' => iso($d['created_at']), 'comments_count' => (int)$d['comments_count']];
    }, q_all('SELECT id, title, created_at, comments_count FROM discussions WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$u['id']]));
    return ['user' => $out, 'bans' => $bans, 'discussions' => $discussions];
}

function api_admin_ban()
{
    current_admin();
    $userId = require_id('user_id');
    $days = in_int('days', -1);
    $reason = in_str('reason', 255, true, 'Reason');
    if ($days < 0) {
        fail('Choose how many days to block the account (0 = permanent).', 422);
    }
    $until = admin_ban_user($userId, $days, $reason, in_str('context', 30) ?: 'general', in_str('context_ref', 160) ?: null);
    return ['banned_until' => (int)substr($until, 0, 4) >= 9000 ? null : iso($until), 'permanent' => $days === 0];
}

function api_admin_unban()
{
    current_admin();
    $userId = require_id('user_id');
    q('UPDATE users SET banned_until = NULL, ban_reason = NULL, updated_at = ? WHERE id = ?', [now(), $userId]);
    q('UPDATE user_bans SET lifted_at = ? WHERE user_id = ? AND lifted_at IS NULL', [now(), $userId]);
    notify($userId, 'account', 'Your account has been unblocked', 'Welcome back! Please follow the community rules.', '#/home');
    return ['unbanned' => true];
}

function api_admin_user_xp()
{
    current_admin();
    $userId = require_id('user_id');
    $amount = in_int('amount');
    if ($amount === 0 || abs($amount) > 100000) {
        fail('Enter an XP amount between -100000 and 100000.', 422);
    }
    if (!q_val('SELECT 1 FROM users WHERE id = ?', [$userId])) {
        fail('User not found.', 404);
    }
    award_xp($userId, $amount, 'admin', 'admin-' . random_token(6));
    if ($amount > 0) {
        notify($userId, 'reward', 'You received ' . $amount . ' bonus XP!', in_str('reason', 200) ?: 'A gift from the admins.', '#/events?tab=xp');
    }
    return ['xp' => (int)q_val('SELECT xp FROM users WHERE id = ?', [$userId])];
}

function api_admin_user_delete()
{
    current_admin();
    $id = require_id('user_id');
    $u = q_one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$u) {
        fail('User not found.', 404);
    }
    delete_user_account($u);
    return ['deleted' => true];
}

return [
    'users'       => ['GET', 'admin', 'api_admin_users'],
    'user'        => ['GET', 'admin', 'api_admin_user'],
    'ban'         => ['POST', 'admin', 'api_admin_ban'],
    'unban'       => ['POST', 'admin', 'api_admin_unban'],
    'user-xp'     => ['POST', 'admin', 'api_admin_user_xp'],
    'user-delete' => ['POST', 'admin', 'api_admin_user_delete'],
];
