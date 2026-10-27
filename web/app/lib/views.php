<?php
/*
 * Presenters shared by several API modules.
 */

/** Report reason for child sexual abuse or exploitation: shown first and flagged for admins. */
const CSAE_REPORT_REASON = 'Child sexual abuse or exploitation';

const DISCUSSION_CATEGORIES = [
    'General', 'News & Leaks', 'Theories', 'Gameplay', 'Map & Locations',
    'Vehicles', 'Characters', 'Online', 'Fan Art', 'Off-topic',
];

const MARKER_CATEGORIES = ['destination', 'mission', 'collectible', 'hideout', 'meetup', 'shop', 'vehicle', 'danger', 'other'];

const STATUS_BACKGROUNDS = ['sunset', 'vice', 'ocean', 'neon', 'gold', 'night', 'jungle', 'flame'];

const PLATFORMS = ['PlayStation 5', 'PlayStation 5 Pro', 'Xbox Series X', 'Xbox Series S', 'PC', 'Undecided'];

const THEMES = ['vice', 'leonida', 'sunset', 'midnight'];

function public_config()
{
    $mapImage = (string)setting('map_image', '');
    return [
        'app_name'                  => cfg('app.name', 'G VI Fan Hub'),
        'version'                   => APP_VERSION,
        'release_at'                => setting('release_at', '2026-11-19T00:00:00'),
        'release_title'             => setting('release_title', 'Grand Theft Auto VI'),
        'release_platforms'         => setting('release_platforms', 'PlayStation 5 · Xbox Series X|S'),
        'announcement'              => (string)setting('announcement', ''),
        'map'                       => [
            'image'  => $mapImage !== '' ? $mapImage : 'assets/img/map/leonida.svg',
            'width'  => (int)setting('map_width', 2000),
            'height' => (int)setting('map_height', 2600),
            'custom' => $mapImage !== '',
        ],
        'login_otp_mode'            => setting('login_otp_mode', 'optional'),
        'gallery_requires_approval' => setting('gallery_requires_approval', '1') === '1',
        'discussion_categories'     => DISCUSSION_CATEGORIES,
        'marker_categories'         => MARKER_CATEGORIES,
        'status_backgrounds'        => STATUS_BACKGROUNDS,
        'platforms'                 => PLATFORMS,
        'server_time'               => iso(now()),
    ];
}

function user_stats($userId)
{
    $userId = (int)$userId;
    return [
        'friends'     => count(friend_ids($userId)),
        'discussions' => (int)q_val('SELECT COUNT(*) FROM discussions WHERE user_id = ?', [$userId]),
        'comments'    => (int)q_val('SELECT COUNT(*) FROM discussion_comments WHERE user_id = ?', [$userId]),
        'markers'     => (int)q_val('SELECT COUNT(*) FROM map_markers WHERE user_id = ?', [$userId]),
        'rewards'     => (int)q_val('SELECT COUNT(*) FROM user_rewards WHERE user_id = ?', [$userId]),
    ];
}

/** Full profile of the logged-in user (includes private settings). */
function self_profile(array $u)
{
    $title = !empty($u['equipped_title_id']) ? q_one('SELECT id, title FROM rewards WHERE id = ?', [(int)$u['equipped_title_id']]) : null;
    $frame = !empty($u['equipped_frame_id']) ? q_one('SELECT id, style FROM rewards WHERE id = ?', [(int)$u['equipped_frame_id']]) : null;
    return [
        'id'                => (int)$u['id'],
        'name'              => $u['name'],
        'username'          => $u['username'],
        'email'             => $u['email'],
        'avatar'            => $u['avatar'],
        'cover'             => $u['cover'],
        'bio'               => $u['bio'],
        'platform'          => $u['platform'],
        'fav_character'     => $u['fav_character'],
        'country'           => $u['country'],
        'rockstar_id'       => $u['rockstar_id'],
        'psn_id'            => $u['psn_id'],
        'xbox_id'           => $u['xbox_id'],
        'presence'          => $u['presence'],
        'presence_text'     => $u['presence_text'],
        'level'             => level_info($u['xp']),
        'title'             => $title ? $title['title'] : null,
        'title_id'          => $title ? (int)$title['id'] : null,
        'frame'             => $frame ? $frame['style'] : null,
        'frame_id'          => $frame ? (int)$frame['id'] : null,
        'login_otp_enabled' => (int)$u['login_otp_enabled'] === 1,
        'dm_privacy'        => $u['dm_privacy'],
        'profile_privacy'   => $u['profile_privacy'],
        'theme'             => $u['theme'],
        'login_streak'      => (int)$u['login_streak'],
        'created_at'        => iso($u['created_at']),
        'stats'             => user_stats($u['id']),
    ];
}

function unread_messages_total($userId)
{
    return (int)q_val(
        "SELECT COUNT(*) FROM messages m
         JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ?
         WHERE m.id > cm.last_read_id AND m.deleted_at IS NULL AND m.kind <> 'system'
           AND (m.user_id IS NULL OR m.user_id <> ?)",
        [(int)$userId, (int)$userId]
    );
}

function pulse_counts($userId)
{
    $userId = (int)$userId;
    return [
        'notifications'   => (int)q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]),
        'messages'        => unread_messages_total($userId),
        'friend_requests' => (int)q_val("SELECT COUNT(*) FROM friendships WHERE addressee_id = ? AND status = 'pending'", [$userId]),
    ];
}

/** Payload returned by auth/me and after login. */
function me_payload()
{
    $u = current_user();
    return [
        'user'   => self_profile($u),
        'csrf'   => csrf_token('user'),
        'config' => public_config(),
        'counts' => pulse_counts($u['id']),
    ];
}

function unique_username($name)
{
    $base = $name;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($converted !== false) {
            $base = $converted;
        }
    }
    $base = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $base)));
    $base = trim($base, '_');
    if (strlen($base) < 3) {
        $base = 'player' . $base;
    }
    $base = substr($base, 0, 20);
    $candidate = $base;
    $tries = 0;
    while (q_val('SELECT 1 FROM users WHERE username = ?', [$candidate])) {
        $candidate = $base . random_int(10, 9999);
        if (++$tries > 15) {
            $candidate = 'player' . random_int(100000, 9999999);
        }
    }
    return $candidate;
}

function mask_email($email)
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }
    $name = $parts[0];
    $visible = substr($name, 0, min(2, strlen($name)));
    return $visible . str_repeat('•', max(2, strlen($name) - 2)) . '@' . $parts[1];
}

/** Housekeeping that is cheap enough to run occasionally (cron or random requests). */
function maintenance()
{
    $old = gmdate('Y-m-d H:i:s', time() - 3600);
    foreach (q_all('SELECT id, image FROM statuses WHERE expires_at < ? LIMIT 200', [$old]) as $s) {
        Upload::delete($s['image']);
        q('DELETE FROM statuses WHERE id = ?', [$s['id']]);
    }
    q('DELETE FROM auth_sessions WHERE expires_at < ?', [now()]);
    q('DELETE FROM email_otps WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
    q('DELETE FROM rate_limits WHERE window_start < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
    q('UPDATE users SET banned_until = NULL, ban_reason = NULL WHERE banned_until IS NOT NULL AND banned_until < ?', [now()]);
    q('UPDATE user_bans SET lifted_at = banned_until WHERE lifted_at IS NULL AND banned_until < ?', [now()]);
    q("DELETE FROM notifications WHERE is_read = 1 AND created_at < ?", [gmdate('Y-m-d H:i:s', time() - 60 * 86400)]);
}

/* ------------------------------------------- news & trailer cards */

function news_card(array $n)
{
    return [
        'id'           => (int)$n['id'],
        'title'        => $n['title'],
        'summary'      => $n['summary'],
        'image_url'    => $n['image_url'],
        'source_name'  => $n['source_name'],
        'source_url'   => $n['source_url'],
        'origin'       => $n['origin'],
        'is_featured'  => (int)$n['is_featured'] === 1,
        'views'        => (int)$n['views'],
        'published_at' => iso($n['published_at'] ?: $n['created_at']),
    ];
}

function trailer_card(array $t)
{
    $thumb = $t['thumbnail_url'];
    if (!$thumb && $t['youtube_id']) {
        $thumb = 'https://i.ytimg.com/vi/' . $t['youtube_id'] . '/hqdefault.jpg';
    }
    return [
        'id'          => (int)$t['id'],
        'title'       => $t['title'],
        'description' => $t['description'],
        'youtube_id'  => $t['youtube_id'],
        'video_url'   => $t['video_url'],
        'thumbnail'   => $thumb,
        'released_on' => $t['released_on'],
        'is_featured' => (int)$t['is_featured'] === 1,
    ];
}

/** Deletes a comment (and its replies) and keeps the counter in sync. */
function discussion_delete_comment(array $c)
{
    $replies = (int)q_val('SELECT COUNT(*) FROM discussion_comments WHERE parent_id = ?', [$c['id']]);
    q('DELETE FROM discussion_comments WHERE id = ?', [$c['id']]);
    q('UPDATE discussions SET comments_count = GREATEST(comments_count, ?) - ? WHERE id = ?', [1 + $replies, 1 + $replies, $c['discussion_id']]);
}
