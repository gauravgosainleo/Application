<?php
/*
 * In-app notifications (friend requests, comments, rewards, announcements ...).
 */

function api_notifications_list()
{
    $me = uid();
    $before = in_int('before_id');
    $params = [$me];
    $extra = '';
    if ($before > 0) {
        $extra = ' AND id < ?';
        $params[] = $before;
    }
    $rows = q_all('SELECT * FROM notifications WHERE user_id = ?' . $extra . ' ORDER BY id DESC LIMIT 30', $params);
    $actors = hydrate_users(array_filter(array_column($rows, 'actor_id')));
    $items = [];
    foreach ($rows as $n) {
        $items[] = [
            'id'         => (int)$n['id'],
            'type'       => $n['type'],
            'title'      => $n['title'],
            'body'       => $n['body'],
            'link'       => $n['link'],
            'is_read'    => (int)$n['is_read'] === 1,
            'actor'      => $n['actor_id'] && isset($actors[(int)$n['actor_id']]) ? $actors[(int)$n['actor_id']] : null,
            'created_at' => iso($n['created_at']),
        ];
    }
    return [
        'items'    => $items,
        'unread'   => (int)q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$me]),
        'has_more' => count($rows) === 30,
    ];
}

function api_notifications_read()
{
    $me = uid();
    if (in_bool('all')) {
        q('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$me]);
    } else {
        q('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id = ?', [$me, require_id()]);
    }
    return ['unread' => (int)q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$me])];
}

function api_notifications_clear()
{
    q('DELETE FROM notifications WHERE user_id = ?', [uid()]);
    return ['cleared' => true];
}

return [
    'list'  => ['GET', 'user', 'api_notifications_list'],
    'read'  => ['POST', 'user', 'api_notifications_read'],
    'clear' => ['POST', 'user', 'api_notifications_clear'],
];
