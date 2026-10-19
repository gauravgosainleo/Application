<?php
/*
 * Interactive Leonida map: personal markers with notes, optionally
 * shared with friends or everyone.
 */

function marker_out(array $m, array $authors, $me)
{
    $own = (int)$m['user_id'] === (int)$me;
    return [
        'id'         => (int)$m['id'],
        'title'      => $m['title'],
        'note'       => $m['note'],
        'category'   => $m['category'],
        'x'          => (float)$m['pos_x'],
        'y'          => (float)$m['pos_y'],
        'visibility' => $m['visibility'],
        'is_done'    => (int)$m['is_done'] === 1,
        'own'        => $own,
        'layer'      => $own ? 'mine' : ($m['visibility'] === 'public' ? 'public' : 'friends'),
        'author'     => isset($authors[(int)$m['user_id']]) ? $authors[(int)$m['user_id']] : deleted_user_card(),
        'created_at' => iso($m['created_at']),
        'updated_at' => iso($m['updated_at']),
    ];
}

function api_map_markers()
{
    $me = uid();
    $friends = friend_ids($me);
    $blocked = blocked_ids($me);
    $rows = q_all('SELECT * FROM map_markers WHERE user_id = ? ORDER BY created_at DESC', [$me]);
    if ($friends) {
        $rows = array_merge($rows, q_all("SELECT * FROM map_markers WHERE user_id IN (" . placeholders($friends) . ")
            AND visibility IN ('friends', 'public') ORDER BY created_at DESC LIMIT 1000", $friends));
    }
    $exclude = array_merge([$me], $friends, $blocked);
    $rows = array_merge($rows, q_all("SELECT * FROM map_markers WHERE visibility = 'public' AND user_id NOT IN (" . placeholders($exclude) . ")
        ORDER BY created_at DESC LIMIT 500", $exclude));
    $authors = hydrate_users(array_column($rows, 'user_id'));
    $out = [];
    foreach ($rows as $m) {
        $out[] = marker_out($m, $authors, $me);
    }
    return $out;
}

function api_map_save()
{
    $me = uid();
    $id = in_int('id');
    $title = in_str('title', 100, true, 'Title');
    $note = in_text('note', 2000, false, 'Note');
    $category = in_enum('category', MARKER_CATEGORIES, 'destination');
    $visibility = in_enum('visibility', ['private', 'friends', 'public'], 'private');
    $isDone = in_bool('is_done') ? 1 : 0;
    $x = (float)input('x', -1);
    $y = (float)input('y', -1);
    if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
        fail('The marker must be placed on the map.', 422);
    }
    if ($id > 0) {
        $existing = q_one('SELECT * FROM map_markers WHERE id = ? AND user_id = ?', [$id, $me]);
        if (!$existing) {
            fail('Marker not found.', 404);
        }
        q('UPDATE map_markers SET title = ?, note = ?, category = ?, visibility = ?, is_done = ?, pos_x = ?, pos_y = ?, updated_at = ? WHERE id = ?',
            [$title, $note, $category, $visibility, $isDone, round($x, 6), round($y, 6), now(), $id]);
    } else {
        if ((int)q_val('SELECT COUNT(*) FROM map_markers WHERE user_id = ?', [$me]) >= 500) {
            fail('You have reached the limit of 500 markers. Delete some old ones first.', 422);
        }
        rate_limit_or_fail('marker:' . $me, 60, 3600);
        $id = q_insert('INSERT INTO map_markers (user_id, title, note, category, pos_x, pos_y, visibility, is_done, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$me, $title, $note, $category, round($x, 6), round($y, 6), $visibility, $isDone, now(), now()]);
        if (xp_count_today($me, 'marker') < 5) {
            award_xp($me, 3, 'marker', (string)$id);
        }
    }
    $row = q_one('SELECT * FROM map_markers WHERE id = ?', [$id]);
    return marker_out($row, hydrate_users([$me]), $me);
}

function api_map_delete()
{
    $me = uid();
    $id = require_id();
    if (!q_exec('DELETE FROM map_markers WHERE id = ? AND user_id = ?', [$id, $me])) {
        fail('Marker not found.', 404);
    }
    return ['deleted' => true];
}

return [
    'markers' => ['GET', 'user', 'api_map_markers'],
    'save'    => ['POST', 'user', 'api_map_save'],
    'delete'  => ['POST', 'user', 'api_map_delete'],
];
