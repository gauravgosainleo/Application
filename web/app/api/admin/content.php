<?php
/*
 * Admin: trailers, discussion moderation (delete + ban), reports and
 * gallery approval.
 */

/* ---------------------------------------------------------- trailers */

function api_admin_trailers()
{
    current_admin();
    return array_map('trailer_card', q_all('SELECT * FROM trailers ORDER BY is_featured DESC, sort_order ASC, released_on DESC, id DESC'));
}

function api_admin_trailer_save()
{
    current_admin();
    $id = in_int('id');
    $title = in_str('title', 200, true, 'Title');
    $url = in_str('url', 700, true, 'Video link');
    $description = in_text('description', 3000);
    $released = in_str('released_on', 10);
    if ($released !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $released)) {
        fail('Release date must be YYYY-MM-DD.', 422);
    }
    $youtube = youtube_id($url);
    $videoUrl = null;
    if (!$youtube) {
        if (!valid_url($url)) {
            fail('Paste a YouTube link or a direct video link (https://...mp4).', 422);
        }
        $videoUrl = $url;
    }
    $thumb = in_url('thumbnail_url', 700, false, 'Thumbnail link');
    $existing = $id > 0 ? q_one('SELECT * FROM trailers WHERE id = ?', [$id]) : null;
    if ($id > 0 && !$existing) {
        fail('Trailer not found.', 404);
    }
    if (Upload::has('thumbnail')) {
        $thumb = Upload::image('thumbnail', 'news', ['max' => 1280]);
    } elseif ($thumb === '' && $existing) {
        $thumb = (string)$existing['thumbnail_url'];
    }
    $featured = in_bool('is_featured') ? 1 : 0;
    $sort = in_int('sort_order');
    if ($featured) {
        q('UPDATE trailers SET is_featured = 0');
    }
    if ($existing) {
        q('UPDATE trailers SET title = ?, description = ?, youtube_id = ?, video_url = ?, thumbnail_url = ?, released_on = ?, is_featured = ?, sort_order = ? WHERE id = ?',
            [$title, $description ?: null, $youtube, $videoUrl, $thumb ?: null, $released ?: null, $featured, $sort, $id]);
    } else {
        $id = q_insert('INSERT INTO trailers (title, description, youtube_id, video_url, thumbnail_url, released_on, is_featured, sort_order, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$title, $description ?: null, $youtube, $videoUrl, $thumb ?: null, $released ?: null, $featured, $sort, now()]);
        if (in_bool('notify')) {
            notify_all('trailer', 'New trailer: ' . $title, 'Watch it now in the Trailers section.', '#/trailers');
        }
    }
    return trailer_card(q_one('SELECT * FROM trailers WHERE id = ?', [$id]));
}

function api_admin_trailer_delete()
{
    current_admin();
    $t = q_one('SELECT * FROM trailers WHERE id = ?', [require_id()]);
    if (!$t) {
        fail('Trailer not found.', 404);
    }
    if ($t['thumbnail_url'] && strpos($t['thumbnail_url'], 'uploads/') === 0) {
        Upload::delete($t['thumbnail_url']);
    }
    q('DELETE FROM trailers WHERE id = ?', [$t['id']]);
    return ['deleted' => true];
}

/* ------------------------------------------------------- discussions */

function admin_user_mini($userId)
{
    $u = q_one('SELECT id, name, username, email, avatar, banned_until, ban_reason FROM users WHERE id = ?', [(int)$userId]);
    if (!$u) {
        return null;
    }
    return ['id' => (int)$u['id'], 'name' => $u['name'], 'username' => $u['username'], 'email' => $u['email'],
        'avatar' => $u['avatar'], 'banned' => user_is_banned($u)];
}

function api_admin_discussions()
{
    current_admin();
    $page = max(1, in_int('page', 1));
    $per = 20;
    $where = ['1 = 1'];
    $params = [];
    $search = in_str('q', 100);
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(d.title LIKE ? OR d.body LIKE ? OR u.username LIKE ? OR u.name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    $filter = in_enum('filter', ['all', 'reported', 'pinned', 'locked'], 'all');
    if ($filter === 'reported') {
        $where[] = "EXISTS (SELECT 1 FROM reports r WHERE r.target_type = 'discussion' AND r.target_id = d.id AND r.status = 'open')";
    } elseif ($filter === 'pinned') {
        $where[] = 'd.is_pinned = 1';
    } elseif ($filter === 'locked') {
        $where[] = 'd.is_locked = 1';
    }
    $whereSql = implode(' AND ', $where);
    $from = 'FROM discussions d LEFT JOIN users u ON u.id = d.user_id';
    $total = (int)q_val("SELECT COUNT(*) {$from} WHERE {$whereSql}", $params);
    $rows = q_all("SELECT d.*, (SELECT COUNT(*) FROM reports r WHERE r.target_type = 'discussion' AND r.target_id = d.id AND r.status = 'open') AS reports
        {$from} WHERE {$whereSql} ORDER BY d.created_at DESC LIMIT ? OFFSET ?", array_merge($params, [$per, ($page - 1) * $per]));
    $items = [];
    foreach ($rows as $d) {
        $items[] = [
            'id'             => (int)$d['id'],
            'title'          => $d['title'],
            'category'       => $d['category'],
            'excerpt'        => str_limit(preg_replace('/\s+/', ' ', $d['body']), 180),
            'likes_count'    => (int)$d['likes_count'],
            'comments_count' => (int)$d['comments_count'],
            'views'          => (int)$d['views'],
            'is_pinned'      => (int)$d['is_pinned'] === 1,
            'is_locked'      => (int)$d['is_locked'] === 1,
            'reports'        => (int)$d['reports'],
            'author'         => admin_user_mini($d['user_id']),
            'created_at'     => iso($d['created_at']),
        ];
    }
    return ['items' => $items, 'page' => $page, 'total' => $total, 'has_more' => $page * $per < $total];
}

function api_admin_discussion()
{
    current_admin();
    $d = q_one('SELECT * FROM discussions WHERE id = ?', [require_id()]);
    if (!$d) {
        fail('Discussion not found.', 404);
    }
    $comments = [];
    foreach (q_all('SELECT * FROM discussion_comments WHERE discussion_id = ? ORDER BY created_at ASC', [$d['id']]) as $c) {
        $comments[] = [
            'id'         => (int)$c['id'],
            'parent_id'  => $c['parent_id'] !== null ? (int)$c['parent_id'] : null,
            'body'       => $c['body'],
            'author'     => admin_user_mini($c['user_id']),
            'created_at' => iso($c['created_at']),
        ];
    }
    return [
        'discussion' => [
            'id'          => (int)$d['id'],
            'title'       => $d['title'],
            'body'        => $d['body'],
            'category'    => $d['category'],
            'cover_image' => $d['cover_image'],
            'is_pinned'   => (int)$d['is_pinned'] === 1,
            'is_locked'   => (int)$d['is_locked'] === 1,
            'author'      => admin_user_mini($d['user_id']),
            'created_at'  => iso($d['created_at']),
        ],
        'comments'   => $comments,
    ];
}

/** Optional ban attached to a moderation action: ban_days = -1 (no ban), 0 (permanent) or N days. */
function admin_optional_ban($userId, $context, $ref)
{
    $days = in_int('ban_days', -1);
    if ($days < 0) {
        return null;
    }
    $reason = in_str('ban_reason', 255) ?: 'Posting content that breaks the community rules.';
    return admin_ban_user($userId, $days, $reason, $context, $ref);
}

function api_admin_discussion_delete()
{
    current_admin();
    $d = q_one('SELECT * FROM discussions WHERE id = ?', [require_id()]);
    if (!$d) {
        fail('Discussion not found.', 404);
    }
    $reason = in_str('reason', 255) ?: 'It broke the community rules.';
    Upload::delete($d['cover_image']);
    q('DELETE FROM discussions WHERE id = ?', [$d['id']]);
    q("UPDATE reports SET status = 'resolved', resolved_at = ? WHERE target_type = 'discussion' AND target_id = ? AND status = 'open'", [now(), $d['id']]);
    $until = admin_optional_ban($d['user_id'], 'discussion', 'Discussion #' . $d['id'] . ': ' . $d['title']);
    if ($until === null) {
        notify($d['user_id'], 'moderation', 'Your discussion was removed', '"' . str_limit($d['title'], 80) . '" — ' . $reason, '#/discussions');
    }
    return ['deleted' => true, 'banned' => $until !== null];
}

function api_admin_discussion_flag()
{
    current_admin();
    $id = require_id();
    $field = in_enum('field', ['is_pinned', 'is_locked']);
    q("UPDATE discussions SET `{$field}` = ? WHERE id = ?", [in_bool('value') ? 1 : 0, $id]);
    return ['updated' => true];
}

function api_admin_comment_delete()
{
    current_admin();
    $c = q_one('SELECT * FROM discussion_comments WHERE id = ?', [require_id()]);
    if (!$c) {
        fail('Comment not found.', 404);
    }
    discussion_delete_comment($c);
    q("UPDATE reports SET status = 'resolved', resolved_at = ? WHERE target_type = 'comment' AND target_id = ? AND status = 'open'", [now(), $c['id']]);
    $until = admin_optional_ban($c['user_id'], 'comment', 'Comment #' . $c['id'] . ': ' . str_limit($c['body'], 100));
    return ['deleted' => true, 'banned' => $until !== null];
}

/* ------------------------------------------------------------ reports */

function admin_report_target($type, $id)
{
    $id = (int)$id;
    switch ($type) {
        case 'discussion':
            $r = q_one('SELECT id, user_id, title FROM discussions WHERE id = ?', [$id]);
            return $r ? ['exists' => true, 'owner_id' => (int)$r['user_id'], 'preview' => $r['title'], 'link' => 'discussion:' . $id] : null;
        case 'comment':
            $r = q_one('SELECT id, user_id, discussion_id, body FROM discussion_comments WHERE id = ?', [$id]);
            return $r ? ['exists' => true, 'owner_id' => (int)$r['user_id'], 'preview' => str_limit($r['body'], 200), 'link' => 'discussion:' . $r['discussion_id']] : null;
        case 'user':
            $r = q_one('SELECT id, name, username FROM users WHERE id = ?', [$id]);
            return $r ? ['exists' => true, 'owner_id' => (int)$r['id'], 'preview' => $r['name'] . ' (@' . $r['username'] . ')', 'link' => 'user:' . $id] : null;
        case 'status':
            $r = q_one('SELECT id, user_id, text_body, image FROM statuses WHERE id = ?', [$id]);
            return $r ? ['exists' => true, 'owner_id' => (int)$r['user_id'], 'preview' => $r['text_body'] ?: '[photo status]', 'image' => $r['image'], 'link' => 'status:' . $id] : null;
        case 'gallery':
            $r = q_one('SELECT id, user_id, title, file_path, youtube_id FROM gallery_items WHERE id = ?', [$id]);
            return $r ? ['exists' => true, 'owner_id' => (int)$r['user_id'], 'preview' => $r['title'] ?: '[gallery item]', 'image' => $r['file_path'], 'link' => 'gallery:' . $id] : null;
        case 'message':
            $r = q_one('SELECT id, user_id, body, attachment FROM messages WHERE id = ?', [$id]);
            return $r ? ['exists' => true, 'owner_id' => (int)$r['user_id'], 'preview' => $r['body'] ?: '[image]', 'image' => $r['attachment'], 'link' => 'message:' . $id] : null;
    }
    return null;
}

function api_admin_reports()
{
    current_admin();
    $status = in_enum('status', ['open', 'resolved', 'dismissed', 'all'], 'open');
    $rows = $status === 'all'
        ? q_all('SELECT * FROM reports ORDER BY id DESC LIMIT 200')
        : q_all('SELECT * FROM reports WHERE status = ? ORDER BY id DESC LIMIT 200', [$status]);
    $out = [];
    foreach ($rows as $r) {
        $target = admin_report_target($r['target_type'], $r['target_id']);
        $out[] = [
            'id'          => (int)$r['id'],
            'target_type' => $r['target_type'],
            'target_id'   => (int)$r['target_id'],
            'reason'      => $r['reason'],
            'status'      => $r['status'],
            'created_at'  => iso($r['created_at']),
            'reporter'    => $r['reporter_id'] ? admin_user_mini($r['reporter_id']) : null,
            'target'      => $target ?: ['exists' => false, 'preview' => 'Content already removed'],
            'owner'       => $target && $target['owner_id'] ? admin_user_mini($target['owner_id']) : null,
        ];
    }
    return $out;
}

function api_admin_report_resolve()
{
    $admin = current_admin();
    $status = in_enum('status', ['resolved', 'dismissed', 'open']);
    q('UPDATE reports SET status = ?, resolved_at = ?, resolved_by = ? WHERE id = ?',
        [$status, $status === 'open' ? null : now(), $status === 'open' ? null : $admin['id'], require_id()]);
    return ['updated' => true];
}

/** Removes the reported content itself (status, gallery item or chat message). */
function api_admin_report_remove_content()
{
    current_admin();
    $r = q_one('SELECT * FROM reports WHERE id = ?', [require_id()]);
    if (!$r) {
        fail('Report not found.', 404);
    }
    $id = (int)$r['target_id'];
    switch ($r['target_type']) {
        case 'status':
            $s = q_one('SELECT * FROM statuses WHERE id = ?', [$id]);
            if ($s) {
                Upload::delete($s['image']);
                q('DELETE FROM statuses WHERE id = ?', [$id]);
            }
            break;
        case 'gallery':
            $g = q_one('SELECT * FROM gallery_items WHERE id = ?', [$id]);
            if ($g) {
                Upload::delete($g['file_path']);
                q('DELETE FROM gallery_items WHERE id = ?', [$id]);
            }
            break;
        case 'message':
            $m = q_one('SELECT * FROM messages WHERE id = ?', [$id]);
            if ($m) {
                Upload::delete($m['attachment']);
                q('UPDATE messages SET body = NULL, attachment = NULL, deleted_at = ? WHERE id = ?', [now(), $id]);
            }
            break;
        case 'discussion':
            $d = q_one('SELECT * FROM discussions WHERE id = ?', [$id]);
            if ($d) {
                Upload::delete($d['cover_image']);
                q('DELETE FROM discussions WHERE id = ?', [$id]);
            }
            break;
        case 'comment':
            $c = q_one('SELECT * FROM discussion_comments WHERE id = ?', [$id]);
            if ($c) {
                discussion_delete_comment($c);
            }
            break;
        default:
            fail('Use the Users page to act on a reported player.', 422);
    }
    q("UPDATE reports SET status = 'resolved', resolved_at = ? WHERE target_type = ? AND target_id = ? AND status = 'open'", [now(), $r['target_type'], $id]);
    return ['removed' => true];
}

/* ------------------------------------------------------------ gallery */

function admin_gallery_row(array $g)
{
    $thumb = $g['thumbnail'] ?: ($g['youtube_id'] ? 'https://i.ytimg.com/vi/' . $g['youtube_id'] . '/hqdefault.jpg' : ($g['media_type'] === 'photo' ? $g['file_path'] : null));
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
        'author'      => $g['user_id'] ? admin_user_mini($g['user_id']) : null,
        'created_at'  => iso($g['created_at']),
    ];
}

function api_admin_gallery()
{
    current_admin();
    $page = max(1, in_int('page', 1));
    $per = 30;
    $status = in_enum('status', ['all', 'pending', 'approved', 'rejected'], 'pending');
    $params = [];
    $where = '1 = 1';
    if ($status !== 'all') {
        $where = 'status = ?';
        $params[] = $status;
    }
    $total = (int)q_val("SELECT COUNT(*) FROM gallery_items WHERE {$where}", $params);
    $rows = q_all("SELECT * FROM gallery_items WHERE {$where} ORDER BY id DESC LIMIT ? OFFSET ?", array_merge($params, [$per, ($page - 1) * $per]));
    return ['items' => array_map('admin_gallery_row', $rows), 'page' => $page, 'total' => $total, 'has_more' => $page * $per < $total];
}

function api_admin_gallery_save()
{
    current_admin();
    $title = in_str('title', 150);
    $caption = in_text('caption', 500);
    $videoUrl = in_url('video_url', 700, false, 'Video link');
    $mediaType = 'photo';
    $file = null;
    $youtube = null;
    if (Upload::has('file')) {
        $mime = (string)Upload::typeOf('file');
        if (strpos($mime, 'video/') === 0 || $mime === 'application/mp4') {
            $mediaType = 'video';
            $file = Upload::video('file', 'gallery');
        } else {
            $file = Upload::image('file', 'gallery', ['max' => 2400]);
        }
    } elseif ($videoUrl !== '') {
        $youtube = youtube_id($videoUrl);
        if (!$youtube) {
            fail('Paste a YouTube link or upload a file.', 422);
        }
        $mediaType = 'video';
    } else {
        fail('Choose a photo/video file or paste a YouTube link.', 422);
    }
    $id = q_insert("INSERT INTO gallery_items (user_id, is_official, media_type, title, caption, file_path, video_url, youtube_id, status, created_at)
        VALUES (NULL, 1, ?, ?, ?, ?, ?, ?, 'approved', ?)", [$mediaType, $title ?: null, $caption ?: null, $file, $youtube ? $videoUrl : null, $youtube, now()]);
    return admin_gallery_row(q_one('SELECT * FROM gallery_items WHERE id = ?', [$id]));
}

function api_admin_gallery_status()
{
    current_admin();
    $g = q_one('SELECT * FROM gallery_items WHERE id = ?', [require_id()]);
    if (!$g) {
        fail('Item not found.', 404);
    }
    $status = in_enum('status', ['approved', 'rejected', 'pending']);
    q('UPDATE gallery_items SET status = ? WHERE id = ?', [$status, $g['id']]);
    if ($g['user_id'] && $g['status'] !== $status) {
        if ($status === 'approved') {
            award_xp($g['user_id'], 10, 'gallery', (string)$g['id']);
            notify($g['user_id'], 'gallery', 'Your upload was approved!', $g['title'] ?: 'It is now visible in the gallery. +10 XP', '#/gallery?scope=mine');
        } elseif ($status === 'rejected') {
            notify($g['user_id'], 'gallery', 'Your upload was not approved', $g['title'] ?: 'It did not meet the gallery guidelines.', '#/gallery?scope=mine');
        }
    }
    return admin_gallery_row(q_one('SELECT * FROM gallery_items WHERE id = ?', [$g['id']]));
}

function api_admin_gallery_delete()
{
    current_admin();
    $g = q_one('SELECT * FROM gallery_items WHERE id = ?', [require_id()]);
    if (!$g) {
        fail('Item not found.', 404);
    }
    Upload::delete($g['file_path']);
    q('DELETE FROM gallery_items WHERE id = ?', [$g['id']]);
    return ['deleted' => true];
}

return [
    'trailers'              => ['GET', 'admin', 'api_admin_trailers'],
    'trailer-save'          => ['POST', 'admin', 'api_admin_trailer_save'],
    'trailer-delete'        => ['POST', 'admin', 'api_admin_trailer_delete'],
    'discussions'           => ['GET', 'admin', 'api_admin_discussions'],
    'discussion'            => ['GET', 'admin', 'api_admin_discussion'],
    'discussion-delete'     => ['POST', 'admin', 'api_admin_discussion_delete'],
    'discussion-flag'       => ['POST', 'admin', 'api_admin_discussion_flag'],
    'comment-delete'        => ['POST', 'admin', 'api_admin_comment_delete'],
    'reports'               => ['GET', 'admin', 'api_admin_reports'],
    'report-resolve'        => ['POST', 'admin', 'api_admin_report_resolve'],
    'report-remove-content' => ['POST', 'admin', 'api_admin_report_remove_content'],
    'gallery'               => ['GET', 'admin', 'api_admin_gallery'],
    'gallery-save'          => ['POST', 'admin', 'api_admin_gallery_save'],
    'gallery-status'        => ['POST', 'admin', 'api_admin_gallery_status'],
    'gallery-delete'        => ['POST', 'admin', 'api_admin_gallery_delete'],
];
