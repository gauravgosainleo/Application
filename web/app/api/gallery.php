<?php
/*
 * Photo & video gallery: official media (admins) and community uploads
 * (optionally held for admin approval).
 */

function gallery_out(array $g, array $authors, array $likedSet)
{
    $thumb = $g['thumbnail'];
    if (!$thumb && $g['youtube_id']) {
        $thumb = 'https://i.ytimg.com/vi/' . $g['youtube_id'] . '/hqdefault.jpg';
    }
    if (!$thumb && $g['media_type'] === 'photo') {
        $thumb = $g['file_path'];
    }
    return [
        'id'          => (int)$g['id'],
        'media_type'  => $g['media_type'],
        'title'       => $g['title'],
        'caption'     => $g['caption'],
        'file'        => $g['file_path'],
        'video_url'   => $g['video_url'],
        'youtube_id'  => $g['youtube_id'],
        'thumbnail'   => $thumb,
        'is_official' => (int)$g['is_official'] === 1,
        'status'      => $g['status'],
        'likes_count' => (int)$g['likes_count'],
        'liked'       => isset($likedSet[(int)$g['id']]),
        'author'      => $g['user_id'] ? (isset($authors[(int)$g['user_id']]) ? $authors[(int)$g['user_id']] : deleted_user_card()) : null,
        'own'         => isset($GLOBALS['__gvi_user']['id']) && (int)$g['user_id'] === (int)$GLOBALS['__gvi_user']['id'],
        'created_at'  => iso($g['created_at']),
    ];
}

function api_gallery_list()
{
    $me = uid();
    $page = max(1, in_int('page', 1));
    $per = 24;
    $where = [];
    $params = [];
    $scope = in_enum('scope', ['all', 'official', 'community', 'mine'], 'all');
    if ($scope === 'mine') {
        $where[] = 'g.user_id = ?';
        $params[] = $me;
    } else {
        $where[] = "g.status = 'approved'";
        if ($scope === 'official') {
            $where[] = 'g.is_official = 1';
        } elseif ($scope === 'community') {
            $where[] = 'g.is_official = 0';
        }
        $blocked = blocked_ids($me);
        if ($blocked) {
            $where[] = '(g.user_id IS NULL OR g.user_id NOT IN (' . placeholders($blocked) . '))';
            $params = array_merge($params, $blocked);
        }
    }
    $type = in_enum('type', ['all', 'photo', 'video'], 'all');
    if ($type !== 'all') {
        $where[] = 'g.media_type = ?';
        $params[] = $type;
    }
    $whereSql = implode(' AND ', $where);
    $total = (int)q_val("SELECT COUNT(*) FROM gallery_items g WHERE {$whereSql}", $params);
    $rows = q_all("SELECT g.* FROM gallery_items g WHERE {$whereSql} ORDER BY g.created_at DESC, g.id DESC LIMIT ? OFFSET ?",
        array_merge($params, [$per, ($page - 1) * $per]));
    $authors = hydrate_users(array_filter(array_column($rows, 'user_id')));
    $liked = [];
    if ($rows) {
        $ids = array_map('intval', array_column($rows, 'id'));
        foreach (q_col('SELECT item_id FROM gallery_likes WHERE user_id = ? AND item_id IN (' . placeholders($ids) . ')', array_merge([$me], $ids)) as $lid) {
            $liked[(int)$lid] = true;
        }
    }
    $items = [];
    foreach ($rows as $r) {
        $items[] = gallery_out($r, $authors, $liked);
    }
    return ['items' => $items, 'page' => $page, 'total' => $total, 'has_more' => $page * $per < $total];
}

function api_gallery_upload()
{
    $me = current_user();
    $title = in_str('title', 150, false, 'Title');
    $caption = in_text('caption', 500, false, 'Caption');
    $videoUrl = in_url('video_url', 700, false, 'Video link');
    rate_limit_or_fail('gallery:' . $me['id'], 15, 3600, 'Upload limit reached. Please try again later.');

    $mediaType = 'photo';
    $file = null;
    $youtube = null;
    if (Upload::has('file')) {
        $mime = Upload::typeOf('file');
        if (strpos((string)$mime, 'video/') === 0 || $mime === 'application/mp4') {
            $mediaType = 'video';
            $file = Upload::video('file', 'gallery');
        } else {
            $file = Upload::image('file', 'gallery', ['max' => 2400]);
        }
    } elseif ($videoUrl !== '') {
        $youtube = youtube_id($videoUrl);
        if (!$youtube) {
            fail('Please paste a YouTube link (or upload a video file).', 422);
        }
        $mediaType = 'video';
    } else {
        fail('Choose a photo or video to upload, or paste a YouTube link.', 422);
    }
    $needsApproval = setting('gallery_requires_approval', '1') === '1';
    $id = q_insert('INSERT INTO gallery_items (user_id, is_official, media_type, title, caption, file_path, video_url, youtube_id, status, created_at)
        VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $me['id'], $mediaType, $title === '' ? null : $title, $caption === '' ? null : $caption, $file,
        $youtube ? $videoUrl : null, $youtube, $needsApproval ? 'pending' : 'approved', now(),
    ]);
    if (!$needsApproval) {
        award_xp($me['id'], 10, 'gallery', (string)$id);
    }
    $row = q_one('SELECT * FROM gallery_items WHERE id = ?', [$id]);
    return ['item' => gallery_out($row, hydrate_users([$me['id']]), []), 'pending' => $needsApproval];
}

function api_gallery_like()
{
    $me = uid();
    $g = q_one("SELECT id FROM gallery_items WHERE id = ? AND status = 'approved'", [require_id()]);
    if (!$g) {
        fail('Item not found.', 404);
    }
    if (q_exec('DELETE FROM gallery_likes WHERE item_id = ? AND user_id = ?', [$g['id'], $me])) {
        q('UPDATE gallery_items SET likes_count = GREATEST(likes_count, 1) - 1 WHERE id = ?', [$g['id']]);
        $liked = false;
    } else {
        q('INSERT IGNORE INTO gallery_likes (item_id, user_id, created_at) VALUES (?, ?, ?)', [$g['id'], $me, now()]);
        q('UPDATE gallery_items SET likes_count = likes_count + 1 WHERE id = ?', [$g['id']]);
        $liked = true;
    }
    return ['liked' => $liked, 'likes_count' => (int)q_val('SELECT likes_count FROM gallery_items WHERE id = ?', [$g['id']])];
}

function api_gallery_delete()
{
    $me = uid();
    $g = q_one('SELECT * FROM gallery_items WHERE id = ? AND user_id = ?', [require_id(), $me]);
    if (!$g) {
        fail('Item not found.', 404);
    }
    Upload::delete($g['file_path']);
    q('DELETE FROM gallery_items WHERE id = ?', [$g['id']]);
    return ['deleted' => true];
}

return [
    'list'   => ['GET', 'user', 'api_gallery_list'],
    'upload' => ['POST', 'user', 'api_gallery_upload'],
    'like'   => ['POST', 'user', 'api_gallery_like'],
    'delete' => ['POST', 'user', 'api_gallery_delete'],
];
