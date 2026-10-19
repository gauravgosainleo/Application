<?php
/*
 * Status: 24-hour stories (text on a colour background or a photo)
 * plus everyone's live presence (online / away / busy + activity text).
 */

const STATUS_TTL = 86400;

function status_out(array $s, $viewed = false, $views = null)
{
    $out = [
        'id'         => (int)$s['id'],
        'user_id'    => (int)$s['user_id'],
        'kind'       => $s['kind'],
        'text'       => $s['text_body'],
        'bg'         => $s['bg'],
        'image'      => $s['image'],
        'visibility' => $s['visibility'],
        'created_at' => iso($s['created_at']),
        'expires_at' => iso($s['expires_at']),
        'viewed'     => (bool)$viewed,
    ];
    if ($views !== null) {
        $out['views'] = (int)$views;
    }
    return $out;
}

function api_status_feed()
{
    $me = uid();
    $friends = friend_ids($me);
    $blocked = blocked_ids($me);

    $mine = q_all('SELECT s.*, (SELECT COUNT(*) FROM status_views v WHERE v.status_id = s.id) AS views
        FROM statuses s WHERE s.user_id = ? AND s.expires_at > ? ORDER BY s.created_at ASC', [$me, now()]);

    $exclude = array_merge([$me], $blocked);
    $others = q_all("SELECT * FROM statuses WHERE expires_at > ? AND user_id NOT IN (" . placeholders($exclude) . ")
        AND (visibility = 'public' OR user_id IN (" . placeholders($friends) . "))
        ORDER BY created_at ASC LIMIT 800", array_merge([now()], $exclude, in_values($friends)));

    $viewed = [];
    if ($others) {
        $ids = array_map('intval', array_column($others, 'id'));
        foreach (q_col('SELECT status_id FROM status_views WHERE viewer_id = ? AND status_id IN (' . placeholders($ids) . ')', array_merge([$me], $ids)) as $sid) {
            $viewed[(int)$sid] = true;
        }
    }

    $grouped = [];
    foreach ($others as $s) {
        $grouped[(int)$s['user_id']][] = $s;
    }
    $cards = hydrate_users(array_merge([$me], array_keys($grouped), $friends));

    $groups = [];
    foreach ($grouped as $userId => $list) {
        if (!isset($cards[$userId])) {
            continue;
        }
        $items = [];
        $allViewed = true;
        foreach ($list as $s) {
            $isViewed = isset($viewed[(int)$s['id']]);
            $allViewed = $allViewed && $isViewed;
            $items[] = status_out($s, $isViewed);
        }
        $groups[] = [
            'user'       => $cards[$userId],
            'is_friend'  => in_array($userId, $friends, true),
            'statuses'   => $items,
            'all_viewed' => $allViewed,
            'latest_at'  => iso(end($list)['created_at']),
        ];
    }
    usort($groups, function ($a, $b) {
        if ($a['all_viewed'] !== $b['all_viewed']) {
            return $a['all_viewed'] ? 1 : -1;
        }
        if ($a['is_friend'] !== $b['is_friend']) {
            return $a['is_friend'] ? -1 : 1;
        }
        return strcmp($b['latest_at'], $a['latest_at']);
    });

    $friendCards = [];
    foreach ($friends as $fid) {
        if (isset($cards[$fid])) {
            $friendCards[] = $cards[$fid];
        }
    }
    $rank = ['online' => 0, 'busy' => 1, 'away' => 2, 'offline' => 3];
    usort($friendCards, function ($a, $b) use ($rank) {
        $d = $rank[$a['presence']] - $rank[$b['presence']];
        return $d !== 0 ? $d : strcmp((string)$b['last_seen'], (string)$a['last_seen']);
    });

    $self = current_user();
    return [
        'me' => [
            'user'          => $cards[$me],
            'presence'      => $self['presence'],
            'presence_text' => $self['presence_text'],
            'statuses'      => array_map(function ($s) {
                return status_out($s, true, $s['views']);
            }, $mine),
        ],
        'groups'  => $groups,
        'friends' => $friendCards,
    ];
}

function api_status_create()
{
    $me = current_user();
    $isImage = Upload::has('file');
    $text = in_text('text', 500, false, 'Status text');
    if (!$isImage && $text === '') {
        fail('Write something for your status or add a photo.', 422);
    }
    $bg = in_enum('bg', STATUS_BACKGROUNDS, 'sunset');
    $visibility = in_enum('visibility', ['friends', 'public'], 'friends');
    if ((int)q_val('SELECT COUNT(*) FROM statuses WHERE user_id = ? AND expires_at > ?', [$me['id'], now()]) >= 30) {
        fail('You can have up to 30 active statuses at a time.', 422);
    }
    rate_limit_or_fail('status:' . $me['id'], 25, 3600);
    $image = $isImage ? Upload::image('file', 'status', ['max' => 1440]) : null;
    $id = q_insert('INSERT INTO statuses (user_id, kind, text_body, bg, image, visibility, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $me['id'], $isImage ? 'image' : 'text', $text === '' ? null : $text, $bg, $image, $visibility, now(), dt_in(STATUS_TTL),
    ]);
    if (xp_count_today($me['id'], 'status') < 3) {
        award_xp($me['id'], 5, 'status', (string)$id);
    }
    return status_out(q_one('SELECT * FROM statuses WHERE id = ?', [$id]), true, 0);
}

function api_status_view()
{
    $me = uid();
    $s = q_one('SELECT * FROM statuses WHERE id = ? AND expires_at > ?', [require_id('status_id'), now()]);
    if (!$s) {
        fail('This status has expired.', 404);
    }
    if ((int)$s['user_id'] === $me) {
        return ['viewed' => true];
    }
    if (is_blocked_between($me, $s['user_id']) || ($s['visibility'] === 'friends' && !are_friends($me, $s['user_id']))) {
        fail('You cannot view this status.', 403);
    }
    q('INSERT IGNORE INTO status_views (status_id, viewer_id, viewed_at) VALUES (?, ?, ?)', [$s['id'], $me, now()]);
    return ['viewed' => true];
}

function api_status_viewers()
{
    $me = uid();
    $s = q_one('SELECT * FROM statuses WHERE id = ? AND user_id = ?', [require_id('status_id'), $me]);
    if (!$s) {
        fail('Status not found.', 404);
    }
    $rows = q_all('SELECT viewer_id, viewed_at FROM status_views WHERE status_id = ? ORDER BY viewed_at DESC', [$s['id']]);
    $cards = hydrate_users(array_column($rows, 'viewer_id'));
    $out = [];
    foreach ($rows as $r) {
        if (isset($cards[(int)$r['viewer_id']])) {
            $out[] = $cards[(int)$r['viewer_id']] + ['viewed_at' => iso($r['viewed_at'])];
        }
    }
    return $out;
}

function api_status_delete()
{
    $me = uid();
    $s = q_one('SELECT * FROM statuses WHERE id = ? AND user_id = ?', [require_id('status_id'), $me]);
    if (!$s) {
        fail('Status not found.', 404);
    }
    Upload::delete($s['image']);
    q('DELETE FROM statuses WHERE id = ?', [$s['id']]);
    return ['deleted' => true];
}

return [
    'feed'    => ['GET', 'user', 'api_status_feed'],
    'create'  => ['POST', 'user', 'api_status_create'],
    'view'    => ['POST', 'user', 'api_status_view'],
    'viewers' => ['GET', 'user', 'api_status_viewers'],
    'delete'  => ['POST', 'user', 'api_status_delete'],
];
