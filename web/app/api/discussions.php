<?php
/*
 * Discussion articles written by players, with likes and comments.
 */

function discussion_out(array $d, array $authors, array $likedSet, $withBody = false)
{
    $out = [
        'id'               => (int)$d['id'],
        'title'            => $d['title'],
        'category'         => $d['category'],
        'excerpt'          => str_limit(preg_replace('/\s+/', ' ', $d['body']), 220),
        'cover_image'      => $d['cover_image'],
        'likes_count'      => (int)$d['likes_count'],
        'comments_count'   => (int)$d['comments_count'],
        'views'            => (int)$d['views'],
        'is_pinned'        => (int)$d['is_pinned'] === 1,
        'is_locked'        => (int)$d['is_locked'] === 1,
        'liked'            => isset($likedSet[(int)$d['id']]),
        'author'           => isset($authors[(int)$d['user_id']]) ? $authors[(int)$d['user_id']] : deleted_user_card(),
        'created_at'       => iso($d['created_at']),
        'updated_at'       => iso($d['updated_at']),
        'last_activity_at' => iso($d['last_activity_at']),
    ];
    if ($withBody) {
        $out['body'] = $d['body'];
    }
    return $out;
}

function api_discussions_list()
{
    $me = uid();
    $page = max(1, in_int('page', 1));
    $per = 15;
    $where = ['1 = 1'];
    $params = [];
    $category = in_str('category', 30);
    if ($category !== '' && in_array($category, DISCUSSION_CATEGORIES, true)) {
        $where[] = 'd.category = ?';
        $params[] = $category;
    }
    $search = in_str('q', 80);
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(d.title LIKE ? OR d.body LIKE ?)';
        array_push($params, $like, $like);
    }
    $author = in_int('user_id');
    if ($author > 0) {
        $where[] = 'd.user_id = ?';
        $params[] = $author;
    }
    $blocked = blocked_ids($me);
    if ($blocked) {
        $where[] = 'd.user_id NOT IN (' . placeholders($blocked) . ')';
        $params = array_merge($params, $blocked);
    }
    $sort = in_enum('sort', ['new', 'top', 'active'], 'new');
    $order = [
        'new'    => 'd.is_pinned DESC, d.created_at DESC',
        'top'    => '(d.likes_count * 2 + d.comments_count) DESC, d.created_at DESC',
        'active' => 'd.last_activity_at DESC',
    ][$sort];
    $whereSql = implode(' AND ', $where);
    $total = (int)q_val("SELECT COUNT(*) FROM discussions d WHERE {$whereSql}", $params);
    $rows = q_all("SELECT d.* FROM discussions d WHERE {$whereSql} ORDER BY {$order} LIMIT ? OFFSET ?",
        array_merge($params, [$per, ($page - 1) * $per]));
    $authors = hydrate_users(array_column($rows, 'user_id'));
    $liked = [];
    if ($rows) {
        $ids = array_map('intval', array_column($rows, 'id'));
        foreach (q_col('SELECT discussion_id FROM discussion_likes WHERE user_id = ? AND discussion_id IN (' . placeholders($ids) . ')', array_merge([$me], $ids)) as $lid) {
            $liked[(int)$lid] = true;
        }
    }
    $items = [];
    foreach ($rows as $d) {
        $items[] = discussion_out($d, $authors, $liked);
    }
    return ['items' => $items, 'page' => $page, 'total' => $total, 'has_more' => $page * $per < $total];
}

function api_discussions_get()
{
    $me = uid();
    $id = require_id();
    $d = q_one('SELECT * FROM discussions WHERE id = ?', [$id]);
    if (!$d) {
        fail('This discussion was removed or does not exist.', 404);
    }
    q('UPDATE discussions SET views = views + 1 WHERE id = ?', [$id]);
    $d['views'] = (int)$d['views'] + 1;
    $comments = q_all('SELECT * FROM discussion_comments WHERE discussion_id = ? ORDER BY created_at ASC, id ASC LIMIT 1000', [$id]);
    $blocked = array_flip(blocked_ids($me));
    $authors = hydrate_users(array_merge([(int)$d['user_id']], array_column($comments, 'user_id')));
    $likedComments = [];
    if ($comments) {
        $cids = array_map('intval', array_column($comments, 'id'));
        foreach (q_col('SELECT comment_id FROM comment_likes WHERE user_id = ? AND comment_id IN (' . placeholders($cids) . ')', array_merge([$me], $cids)) as $cid) {
            $likedComments[(int)$cid] = true;
        }
    }
    $commentsOut = [];
    foreach ($comments as $c) {
        if (isset($blocked[(int)$c['user_id']])) {
            continue;
        }
        $commentsOut[] = [
            'id'          => (int)$c['id'],
            'parent_id'   => $c['parent_id'] !== null ? (int)$c['parent_id'] : null,
            'body'        => $c['body'],
            'likes_count' => (int)$c['likes_count'],
            'liked'       => isset($likedComments[(int)$c['id']]),
            'author'      => isset($authors[(int)$c['user_id']]) ? $authors[(int)$c['user_id']] : deleted_user_card(),
            'own'         => (int)$c['user_id'] === $me,
            'created_at'  => iso($c['created_at']),
            'updated_at'  => iso($c['updated_at']),
        ];
    }
    $liked = q_val('SELECT 1 FROM discussion_likes WHERE discussion_id = ? AND user_id = ?', [$id, $me]) ? [$id => true] : [];
    $out = discussion_out($d, $authors, $liked, true);
    $out['own'] = (int)$d['user_id'] === $me;
    return ['discussion' => $out, 'comments' => $commentsOut];
}

function discussion_input()
{
    $title = in_str('title', 200, true, 'Title');
    if (str_len($title) < 5) {
        fail('Title must be at least 5 characters long.', 422);
    }
    $body = in_text('body', 20000, true, 'Article text');
    if (str_len($body) < 10) {
        fail('The article text is too short.', 422);
    }
    $category = in_str('category', 30);
    if (!in_array($category, DISCUSSION_CATEGORIES, true)) {
        $category = 'General';
    }
    return [$title, $body, $category];
}

function api_discussions_create()
{
    $me = current_user();
    list($title, $body, $category) = discussion_input();
    rate_limit_or_fail('discussion:' . $me['id'], 6, 3600, 'You are posting too often. Please wait a while before starting another discussion.');
    $cover = Upload::has('file') ? Upload::image('file', 'discussions', ['max' => 1600]) : null;
    $id = q_insert('INSERT INTO discussions (user_id, title, body, category, cover_image, created_at, updated_at, last_activity_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)',
        [$me['id'], $title, $body, $category, $cover, now(), now()]);
    if (xp_count_today($me['id'], 'discussion') < 3) {
        award_xp($me['id'], 20, 'discussion', (string)$id);
    }
    return ['id' => $id];
}

function api_discussions_update()
{
    $me = uid();
    $d = q_one('SELECT * FROM discussions WHERE id = ? AND user_id = ?', [require_id(), $me]);
    if (!$d) {
        fail('Discussion not found.', 404);
    }
    if ((int)$d['is_locked'] === 1) {
        fail('This discussion was locked by a moderator.', 403);
    }
    list($title, $body, $category) = discussion_input();
    $cover = $d['cover_image'];
    if (Upload::has('file')) {
        $cover = Upload::image('file', 'discussions', ['max' => 1600]);
        Upload::delete($d['cover_image']);
    } elseif (in_bool('remove_cover')) {
        Upload::delete($d['cover_image']);
        $cover = null;
    }
    q('UPDATE discussions SET title = ?, body = ?, category = ?, cover_image = ?, updated_at = ? WHERE id = ?',
        [$title, $body, $category, $cover, now(), $d['id']]);
    return ['id' => (int)$d['id']];
}

function api_discussions_delete()
{
    $me = uid();
    $d = q_one('SELECT * FROM discussions WHERE id = ? AND user_id = ?', [require_id(), $me]);
    if (!$d) {
        fail('Discussion not found.', 404);
    }
    Upload::delete($d['cover_image']);
    q('DELETE FROM discussions WHERE id = ?', [$d['id']]);
    return ['deleted' => true];
}

function api_discussions_like()
{
    $me = current_user();
    $d = q_one('SELECT id, user_id, title FROM discussions WHERE id = ?', [require_id()]);
    if (!$d) {
        fail('Discussion not found.', 404);
    }
    if (q_exec('DELETE FROM discussion_likes WHERE discussion_id = ? AND user_id = ?', [$d['id'], $me['id']])) {
        q('UPDATE discussions SET likes_count = GREATEST(likes_count, 1) - 1 WHERE id = ?', [$d['id']]);
        $liked = false;
    } else {
        q('INSERT IGNORE INTO discussion_likes (discussion_id, user_id, created_at) VALUES (?, ?, ?)', [$d['id'], $me['id'], now()]);
        q('UPDATE discussions SET likes_count = likes_count + 1 WHERE id = ?', [$d['id']]);
        $liked = true;
        if ((int)$d['user_id'] !== (int)$me['id'] && !q_val("SELECT 1 FROM xp_log WHERE user_id = ? AND reason = 'like_recv' AND ref_id = ?", [$d['user_id'], $d['id'] . ':' . $me['id']])) {
            award_xp($d['user_id'], 2, 'like_recv', $d['id'] . ':' . $me['id']);
            notify($d['user_id'], 'discussion_like', $me['name'] . ' liked your discussion', $d['title'], '#/discussions/' . $d['id'], $me['id']);
        }
    }
    return ['liked' => $liked, 'likes_count' => (int)q_val('SELECT likes_count FROM discussions WHERE id = ?', [$d['id']])];
}

function api_discussions_comment()
{
    $me = current_user();
    $d = q_one('SELECT * FROM discussions WHERE id = ?', [require_id('discussion_id')]);
    if (!$d) {
        fail('Discussion not found.', 404);
    }
    if ((int)$d['is_locked'] === 1) {
        fail('Comments are closed on this discussion.', 403);
    }
    if (is_blocked_between($me['id'], $d['user_id'])) {
        fail('You cannot comment on this discussion.', 403);
    }
    $body = in_text('body', 3000, true, 'Comment');
    $parentId = in_int('parent_id');
    $parent = null;
    if ($parentId > 0) {
        $parent = q_one('SELECT * FROM discussion_comments WHERE id = ? AND discussion_id = ?', [$parentId, $d['id']]);
        if (!$parent) {
            fail('The comment you are replying to was removed.', 404);
        }
        if ($parent['parent_id']) {
            $parent = q_one('SELECT * FROM discussion_comments WHERE id = ?', [$parent['parent_id']]) ?: $parent;
        }
    }
    rate_limit_or_fail('comment:' . $me['id'], 25, 600, 'You are commenting too fast. Please wait a moment.');
    $id = q_insert('INSERT INTO discussion_comments (discussion_id, user_id, parent_id, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$d['id'], $me['id'], $parent ? (int)$parent['id'] : null, $body, now()]);
    q('UPDATE discussions SET comments_count = comments_count + 1, last_activity_at = ? WHERE id = ?', [now(), $d['id']]);
    if (xp_count_today($me['id'], 'comment') < 10) {
        award_xp($me['id'], 5, 'comment', (string)$id);
    }
    $link = '#/discussions/' . $d['id'];
    if ((int)$d['user_id'] !== (int)$me['id']) {
        notify($d['user_id'], 'discussion_comment', $me['name'] . ' commented on your discussion', str_limit($body, 120), $link, $me['id']);
    }
    if ($parent && (int)$parent['user_id'] !== (int)$me['id'] && (int)$parent['user_id'] !== (int)$d['user_id']) {
        notify($parent['user_id'], 'comment_reply', $me['name'] . ' replied to your comment', str_limit($body, 120), $link, $me['id']);
    }
    return ['id' => $id];
}

function api_discussions_comment_update()
{
    $me = uid();
    $c = q_one('SELECT * FROM discussion_comments WHERE id = ? AND user_id = ?', [require_id(), $me]);
    if (!$c) {
        fail('Comment not found.', 404);
    }
    $body = in_text('body', 3000, true, 'Comment');
    q('UPDATE discussion_comments SET body = ?, updated_at = ? WHERE id = ?', [$body, now(), $c['id']]);
    return ['id' => (int)$c['id']];
}

function api_discussions_comment_delete()
{
    $me = uid();
    $c = q_one('SELECT * FROM discussion_comments WHERE id = ? AND user_id = ?', [require_id(), $me]);
    if (!$c) {
        fail('Comment not found.', 404);
    }
    discussion_delete_comment($c);
    return ['deleted' => true];
}

function api_discussions_comment_like()
{
    $me = uid();
    $c = q_one('SELECT id FROM discussion_comments WHERE id = ?', [require_id()]);
    if (!$c) {
        fail('Comment not found.', 404);
    }
    if (q_exec('DELETE FROM comment_likes WHERE comment_id = ? AND user_id = ?', [$c['id'], $me])) {
        q('UPDATE discussion_comments SET likes_count = GREATEST(likes_count, 1) - 1 WHERE id = ?', [$c['id']]);
        $liked = false;
    } else {
        q('INSERT IGNORE INTO comment_likes (comment_id, user_id, created_at) VALUES (?, ?, ?)', [$c['id'], $me, now()]);
        q('UPDATE discussion_comments SET likes_count = likes_count + 1 WHERE id = ?', [$c['id']]);
        $liked = true;
    }
    return ['liked' => $liked, 'likes_count' => (int)q_val('SELECT likes_count FROM discussion_comments WHERE id = ?', [$c['id']])];
}

return [
    'list'           => ['GET', 'user', 'api_discussions_list'],
    'get'            => ['GET', 'user', 'api_discussions_get'],
    'create'         => ['POST', 'user', 'api_discussions_create'],
    'update'         => ['POST', 'user', 'api_discussions_update'],
    'delete'         => ['POST', 'user', 'api_discussions_delete'],
    'like'           => ['POST', 'user', 'api_discussions_like'],
    'comment'        => ['POST', 'user', 'api_discussions_comment'],
    'comment-update' => ['POST', 'user', 'api_discussions_comment_update'],
    'comment-delete' => ['POST', 'user', 'api_discussions_comment_delete'],
    'comment-like'   => ['POST', 'user', 'api_discussions_comment_like'],
];
