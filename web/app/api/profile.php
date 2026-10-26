<?php
/*
 * Player profiles: view, edit, avatar/cover, presence, password,
 * 2-step login, equipped rewards, sessions and account deletion.
 */

function user_badges($userId)
{
    return array_map(function ($r) {
        return [
            'id'          => (int)$r['id'],
            'title'       => $r['title'],
            'description' => $r['description'],
            'reward_type' => $r['reward_type'],
            'icon'        => $r['icon'],
            'style'       => $r['style'],
            'claimed_at'  => iso($r['claimed_at']),
        ];
    }, q_all('SELECT r.id, r.title, r.description, r.reward_type, r.icon, r.style, ur.claimed_at
        FROM user_rewards ur JOIN rewards r ON r.id = ur.reward_id WHERE ur.user_id = ? ORDER BY ur.claimed_at DESC', [(int)$userId]));
}

function api_profile_get()
{
    $me = current_user();
    $id = in_int('id');
    $username = strtolower(in_str('username', 30));
    if ($id > 0) {
        $row = q_one('SELECT * FROM users WHERE id = ?', [$id]);
    } elseif ($username !== '') {
        $row = q_one('SELECT * FROM users WHERE username = ?', [$username]);
    } else {
        $row = $me;
    }
    if (!$row) {
        fail('Player not found.', 404);
    }
    $recent = function ($userId) {
        return array_map(function ($d) {
            return ['id' => (int)$d['id'], 'title' => $d['title'], 'category' => $d['category'],
                'likes_count' => (int)$d['likes_count'], 'comments_count' => (int)$d['comments_count'], 'created_at' => iso($d['created_at'])];
        }, q_all('SELECT id, title, category, likes_count, comments_count, created_at FROM discussions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', [(int)$userId]));
    };

    if ((int)$row['id'] === (int)$me['id']) {
        $profile = self_profile($me);
        $profile['relation'] = 'self';
        return ['profile' => $profile, 'badges' => user_badges($me['id']), 'recent_discussions' => $recent($me['id'])];
    }
    if (q_val('SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [(int)$row['id'], (int)$me['id']])) {
        fail('Player not found.', 404);
    }
    $relation = relation_to($me['id'], $row['id']);
    $cards = hydrate_users([(int)$row['id']]);
    $private = $row['profile_privacy'] === 'friends' && $relation !== 'friends';
    $profile = $cards[(int)$row['id']] + [
        'cover'         => $row['cover'],
        'bio'           => $private ? null : $row['bio'],
        'country'       => $private ? null : $row['country'],
        'fav_character' => $private ? null : $row['fav_character'],
        'rockstar_id'   => $private ? null : $row['rockstar_id'],
        'psn_id'        => $private ? null : $row['psn_id'],
        'xbox_id'       => $private ? null : $row['xbox_id'],
        'level_info'    => level_info($row['xp']),
        'created_at'    => iso($row['created_at']),
        'stats'         => $private ? null : user_stats($row['id']),
        'relation'      => $relation,
        'is_private'    => $private,
        'is_banned'     => user_is_banned($row),
        'can_message'   => $relation !== 'blocked' && ($relation === 'friends' || $row['dm_privacy'] === 'everyone'),
    ];
    return [
        'profile'            => $profile,
        'badges'             => $private ? [] : user_badges($row['id']),
        'recent_discussions' => $private ? [] : $recent($row['id']),
    ];
}

function api_profile_update()
{
    $u = current_user();
    $set = [];
    $optional = function ($value) {
        return $value === '' ? null : $value;
    };

    if (has_input('name')) {
        $name = in_str('name', 80, true, 'Name');
        if (str_len($name) < 2) {
            fail('Name must be at least 2 characters long.', 422);
        }
        $set['name'] = $name;
    }
    if (has_input('username')) {
        $username = strtolower(in_str('username', 30, true, 'Username'));
        if (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {
            fail('Username can use letters, numbers and underscores only (3-30 characters).', 422);
        }
        if (q_val('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $u['id']])) {
            fail('That username is already taken.', 409, 'username_taken');
        }
        $set['username'] = $username;
    }
    if (has_input('bio')) {
        $set['bio'] = $optional(in_text('bio', 300, false, 'Bio'));
    }
    if (has_input('platform')) {
        $platform = in_str('platform', 30);
        if ($platform !== '' && !in_array($platform, PLATFORMS, true)) {
            fail('Please choose a platform from the list.', 422);
        }
        $set['platform'] = $optional($platform);
    }
    if (has_input('fav_character')) {
        $set['fav_character'] = $optional(in_enum('fav_character', ['', 'Lucia', 'Jason', 'Both'], ''));
    }
    foreach (['country' => 60, 'rockstar_id' => 40, 'psn_id' => 40, 'xbox_id' => 40, 'presence_text' => 80] as $field => $max) {
        if (has_input($field)) {
            $set[$field] = $optional(in_str($field, $max));
        }
    }
    if (has_input('presence')) {
        $set['presence'] = in_enum('presence', ['online', 'away', 'busy', 'invisible']);
    }
    if (has_input('dm_privacy')) {
        $set['dm_privacy'] = in_enum('dm_privacy', ['everyone', 'friends']);
    }
    if (has_input('profile_privacy')) {
        $set['profile_privacy'] = in_enum('profile_privacy', ['public', 'friends']);
    }
    if (has_input('theme')) {
        $set['theme'] = in_enum('theme', THEMES);
    }
    if ($set) {
        $cols = [];
        $params = [];
        foreach ($set as $col => $value) {
            $cols[] = "`{$col}` = ?";
            $params[] = $value;
        }
        $params[] = now();
        $params[] = (int)$u['id'];
        q('UPDATE users SET ' . implode(', ', $cols) . ', updated_at = ? WHERE id = ?', $params);
        unset($GLOBALS['__gvi_user']);
    }
    return ['user' => self_profile(current_user())];
}

function api_profile_avatar()
{
    $u = current_user();
    rate_limit_or_fail('avatar:' . $u['id'], 30, 3600);
    $path = Upload::image('file', 'avatars', ['square' => 512]);
    Upload::delete($u['avatar']);
    q('UPDATE users SET avatar = ?, updated_at = ? WHERE id = ?', [$path, now(), $u['id']]);
    return ['avatar' => $path];
}

function api_profile_avatar_remove()
{
    $u = current_user();
    Upload::delete($u['avatar']);
    q('UPDATE users SET avatar = NULL, updated_at = ? WHERE id = ?', [now(), $u['id']]);
    return ['avatar' => null];
}

function api_profile_cover()
{
    $u = current_user();
    rate_limit_or_fail('cover:' . $u['id'], 30, 3600);
    $path = Upload::image('file', 'covers', ['max' => 1600]);
    Upload::delete($u['cover']);
    q('UPDATE users SET cover = ?, updated_at = ? WHERE id = ?', [$path, now(), $u['id']]);
    return ['cover' => $path];
}

function api_profile_cover_remove()
{
    $u = current_user();
    Upload::delete($u['cover']);
    q('UPDATE users SET cover = NULL, updated_at = ? WHERE id = ?', [now(), $u['id']]);
    return ['cover' => null];
}

function api_profile_presence()
{
    $u = current_user();
    $presence = in_enum('presence', ['online', 'away', 'busy', 'invisible']);
    $text = in_str('presence_text', 80);
    q('UPDATE users SET presence = ?, presence_text = ?, last_seen_at = ? WHERE id = ?', [$presence, $text === '' ? null : $text, now(), $u['id']]);
    return ['presence' => $presence, 'presence_text' => $text === '' ? null : $text];
}

function api_profile_password()
{
    $u = current_user();
    rate_limit_or_fail('pwchange:' . $u['id'], 10, 900);
    if (!password_verify((string)input('current_password', ''), $u['password_hash'])) {
        fail('Your current password is incorrect.', 422, 'bad_password');
    }
    $password = (string)input('password', '');
    validate_password($password, (string)input('confirm_password', ''));
    if (password_verify($password, $u['password_hash'])) {
        fail('The new password must be different from the current one.', 422);
    }
    q('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), now(), $u['id']]);
    $others = sessions_revoke_all('user', $u['id'], $u['_session_id']);
    notify($u['id'], 'security', 'Password changed', 'Your password was changed from your profile settings.', '#/profile?tab=security');
    return ['changed' => true, 'signed_out_sessions' => $others];
}

function api_profile_security()
{
    $u = current_user();
    $enabled = in_bool('login_otp_enabled');
    $mode = setting('login_otp_mode', 'optional');
    if ($enabled && $mode === 'off') {
        fail('Two-step login is currently disabled by the admin.', 422);
    }
    q('UPDATE users SET login_otp_enabled = ?, updated_at = ? WHERE id = ?', [$enabled ? 1 : 0, now(), $u['id']]);
    return ['login_otp_enabled' => $enabled];
}

function api_profile_equip()
{
    $u = current_user();
    $set = [];
    foreach (['title' => 'equipped_title_id', 'frame' => 'equipped_frame_id'] as $type => $col) {
        $key = $type . '_id';
        if (!has_input($key)) {
            continue;
        }
        $rid = in_int($key);
        if ($rid > 0) {
            $owned = q_val('SELECT 1 FROM user_rewards ur JOIN rewards r ON r.id = ur.reward_id
                WHERE ur.user_id = ? AND ur.reward_id = ? AND r.reward_type = ?', [$u['id'], $rid, $type]);
            if (!$owned) {
                fail('You have not unlocked that ' . $type . ' yet.', 403);
            }
        }
        $set[$col] = $rid > 0 ? $rid : null;
    }
    foreach ($set as $col => $value) {
        q("UPDATE users SET `{$col}` = ? WHERE id = ?", [$value, $u['id']]);
    }
    unset($GLOBALS['__gvi_user']);
    return ['user' => self_profile(current_user())];
}

function api_profile_sessions()
{
    $u = current_user();
    $rows = q_all("SELECT id, user_agent, ip, created_at, last_used_at, remember FROM auth_sessions
        WHERE subject_type = 'user' AND subject_id = ? AND expires_at > ? ORDER BY last_used_at DESC", [$u['id'], now()]);
    return array_map(function ($s) use ($u) {
        return [
            'id'           => (int)$s['id'],
            'device'       => $s['user_agent'],
            'ip'           => $s['ip'],
            'created_at'   => iso($s['created_at']),
            'last_used_at' => iso($s['last_used_at']),
            'current'      => (int)$s['id'] === (int)$u['_session_id'],
        ];
    }, $rows);
}

function api_profile_logout_others()
{
    $u = current_user();
    return ['signed_out' => sessions_revoke_all('user', $u['id'], $u['_session_id'])];
}

function api_profile_delete()
{
    $u = current_user();
    if (!password_verify((string)input('password', ''), $u['password_hash'])) {
        fail('Incorrect password.', 422, 'bad_password');
    }
    delete_user_account($u);
    auth_clear_cookie(USER_COOKIE);
    return ['deleted' => true];
}

return [
    'get'           => ['GET', 'user', 'api_profile_get'],
    'update'        => ['POST', 'user', 'api_profile_update'],
    'avatar'        => ['POST', 'user', 'api_profile_avatar'],
    'avatar-remove' => ['POST', 'user', 'api_profile_avatar_remove'],
    'cover'         => ['POST', 'user', 'api_profile_cover'],
    'cover-remove'  => ['POST', 'user', 'api_profile_cover_remove'],
    'presence'      => ['POST', 'user', 'api_profile_presence'],
    'password'      => ['POST', 'user', 'api_profile_password'],
    'security'      => ['POST', 'user', 'api_profile_security'],
    'equip'         => ['POST', 'user', 'api_profile_equip'],
    'sessions'      => ['GET', 'user', 'api_profile_sessions'],
    'logout-others' => ['POST', 'user', 'api_profile_logout_others'],
    'delete'        => ['POST', 'user', 'api_profile_delete'],
];
