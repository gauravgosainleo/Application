<?php
/*
 * App-wide endpoints: public config, the lightweight "pulse" poll,
 * the home dashboard and global search.
 */

function api_app_config()
{
    return public_config();
}

function api_app_pulse()
{
    $u = current_user();
    require_once APP_DIR . '/lib/news_fetcher.php';
    NewsFetcher::scheduleIfDue();
    if (mt_rand(1, 60) === 1) {
        defer_job('maintenance');
    }
    return [
        'counts'      => pulse_counts($u['id']),
        'server_time' => iso(now()),
        'xp'          => (int)$u['xp'],
    ];
}

function api_app_home()
{
    $u = current_user();
    $me = (int)$u['id'];

    $news = array_map('news_card', q_all("SELECT * FROM news WHERE status = 'published'
        ORDER BY is_featured DESC, published_at DESC LIMIT 6"));

    $trailer = q_one('SELECT * FROM trailers ORDER BY is_featured DESC, sort_order ASC, released_on DESC, id DESC LIMIT 1');

    $friendIds = friend_ids($me);
    $online = [];
    if ($friendIds) {
        foreach (hydrate_users($friendIds) as $card) {
            if ($card['presence'] !== 'offline') {
                $online[] = $card;
            }
        }
    }

    $events = q_all('SELECT e.*, (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id) AS participants,
            (SELECT 1 FROM event_participants p2 WHERE p2.event_id = e.id AND p2.user_id = ?) AS joined
        FROM events e WHERE e.is_published = 1 AND (e.ends_at IS NULL OR e.ends_at > ?) AND e.starts_at < ?
        ORDER BY e.starts_at ASC LIMIT 3', [$me, now(), gmdate('Y-m-d H:i:s', time() + 120 * 86400)]);
    $eventsOut = [];
    foreach ($events as $e) {
        $eventsOut[] = [
            'id'           => (int)$e['id'],
            'title'        => $e['title'],
            'event_type'   => $e['event_type'],
            'banner'       => $e['banner'],
            'starts_at'    => iso($e['starts_at']),
            'ends_at'      => iso($e['ends_at']),
            'reward_xp'    => (int)$e['reward_xp'],
            'participants' => (int)$e['participants'],
            'joined'       => (bool)$e['joined'],
        ];
    }

    $poll = q_one("SELECT id, question, ends_at FROM polls p WHERE status = 'open' AND (ends_at IS NULL OR ends_at > ?)
        AND NOT EXISTS (SELECT 1 FROM poll_votes v WHERE v.poll_id = p.id AND v.user_id = ?)
        ORDER BY created_at DESC LIMIT 1", [now(), $me]);
    $openPolls = (int)q_val("SELECT COUNT(*) FROM polls p WHERE status = 'open' AND (ends_at IS NULL OR ends_at > ?)
        AND NOT EXISTS (SELECT 1 FROM poll_votes v WHERE v.poll_id = p.id AND v.user_id = ?)", [now(), $me]);

    $discussions = q_all('SELECT id, user_id, title, category, likes_count, comments_count, created_at FROM discussions
        WHERE created_at > ? ORDER BY (likes_count * 2 + comments_count * 3) DESC, created_at DESC LIMIT 4',
        [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    if (!$discussions) {
        $discussions = q_all('SELECT id, user_id, title, category, likes_count, comments_count, created_at FROM discussions ORDER BY created_at DESC LIMIT 4');
    }
    $authors = hydrate_users(array_column($discussions, 'user_id'));
    $discOut = [];
    foreach ($discussions as $d) {
        $discOut[] = [
            'id'             => (int)$d['id'],
            'title'          => $d['title'],
            'category'       => $d['category'],
            'likes_count'    => (int)$d['likes_count'],
            'comments_count' => (int)$d['comments_count'],
            'created_at'     => iso($d['created_at']),
            'author'         => isset($authors[(int)$d['user_id']]) ? $authors[(int)$d['user_id']] : deleted_user_card(),
        ];
    }

    $statusCount = (int)q_val('SELECT COUNT(DISTINCT s.user_id) FROM statuses s WHERE s.expires_at > ? AND s.user_id <> ?
        AND (s.visibility = \'public\' OR s.user_id IN (' . placeholders($friendIds) . '))',
        array_merge([now(), $me], in_values($friendIds)));

    return [
        'news'           => $news,
        'trailer'        => $trailer ? trailer_card($trailer) : null,
        'online_friends' => $online,
        'events'         => $eventsOut,
        'poll'           => $poll ? ['id' => (int)$poll['id'], 'question' => $poll['question'], 'ends_at' => iso($poll['ends_at'])] : null,
        'open_polls'     => $openPolls,
        'discussions'    => $discOut,
        'status_users'   => $statusCount,
        'level'          => level_info($u['xp']),
        'streak'         => (int)$u['login_streak'],
        'counts'         => pulse_counts($me),
    ];
}

function api_app_search()
{
    $u = current_user();
    $qstr = in_str('q', 80);
    if (str_len($qstr) < 2) {
        return ['users' => [], 'discussions' => [], 'news' => [], 'items' => []];
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qstr) . '%';
    $blocked = blocked_ids($u['id']);

    $userIds = q_col('SELECT id FROM users WHERE (name LIKE ? OR username LIKE ?) AND id NOT IN (' . placeholders($blocked) . ')
        AND (banned_until IS NULL OR banned_until < ?) ORDER BY last_seen_at DESC LIMIT 6',
        array_merge([$like, $like], in_values($blocked), [now()]));
    $users = array_values(hydrate_users($userIds));

    $discussions = array_map(function ($d) {
        return ['id' => (int)$d['id'], 'title' => $d['title'], 'category' => $d['category']];
    }, q_all('SELECT id, title, category FROM discussions WHERE title LIKE ? ORDER BY created_at DESC LIMIT 5', [$like]));

    $news = array_map(function ($n) {
        return ['id' => (int)$n['id'], 'title' => $n['title'], 'source_name' => $n['source_name']];
    }, q_all("SELECT id, title, source_name FROM news WHERE status = 'published' AND title LIKE ? ORDER BY published_at DESC LIMIT 5", [$like]));

    $items = array_map(function ($i) {
        return ['id' => (int)$i['id'], 'name' => $i['name'], 'category' => $i['category'], 'image' => $i['image']];
    }, q_all('SELECT i.id, i.name, i.image, c.name AS category FROM catalogue_items i JOIN catalogue_categories c ON c.id = i.category_id
        WHERE i.name LIKE ? OR i.manufacturer LIKE ? ORDER BY i.name LIMIT 5', [$like, $like]));

    return ['users' => $users, 'discussions' => $discussions, 'news' => $news, 'items' => $items];
}

return [
    'config' => ['GET', 'public', 'api_app_config'],
    'pulse'  => ['GET', 'user', 'api_app_pulse'],
    'home'   => ['GET', 'user', 'api_app_home'],
    'search' => ['GET', 'user', 'api_app_search'],
];
