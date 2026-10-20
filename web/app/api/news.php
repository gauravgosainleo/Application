<?php
/*
 * Published news & articles (every article shows its source).
 */

/** News filter chips: which words (in which columns) put a story under each topic. */
const NEWS_TOPICS = [
    'official'  => [[['source_name'], ['Rockstar Games', 'Rockstar Newswire', 'Take-Two']], [['source_url'], ['rockstargames.com', 'take2games.com']]],
    'analysis'  => [[['title', 'summary'], ['analysis', 'breakdown', 'explained', 'every detail', 'theory', 'theories', 'deep dive', 'spotted', 'everything we know', 'things we', 'review', 'compared']]],
    'community' => [[['source_name'], ['Forum', 'Reddit', 'Fan Hub', 'Community', 'GTABase', 'Intel']]],
    'rumours'   => [[['title', 'summary'], ['rumor', 'rumour', 'leak', 'reportedly', 'insider', 'allegedly', 'speculat']]],
];

function api_news_list()
{
    current_user();
    $page = max(1, in_int('page', 1));
    $per = 12;
    $search = in_str('q', 80);
    $where = "status = 'published'";
    $params = [];
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where .= ' AND (title LIKE ? OR summary LIKE ? OR source_name LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $topic = in_str('topic', 20);
    if (isset(NEWS_TOPICS[$topic])) {
        $or = [];
        foreach (NEWS_TOPICS[$topic] as $rule) {
            list($cols, $words) = $rule;
            foreach ($words as $w) {
                foreach ($cols as $c) {
                    $or[] = "{$c} LIKE ?";
                    $params[] = '%' . $w . '%';
                }
            }
        }
        if ($topic === 'community') {
            $or[] = "(origin = 'manual' AND source_name NOT LIKE '%Rockstar%')";
        }
        $where .= ' AND (' . implode(' OR ', $or) . ')';
    }
    $total = (int)q_val("SELECT COUNT(*) FROM news WHERE {$where}", $params);
    $rows = q_all("SELECT * FROM news WHERE {$where} ORDER BY is_featured DESC, published_at DESC, id DESC LIMIT ? OFFSET ?",
        array_merge($params, [$per, ($page - 1) * $per]));
    return [
        'items'    => array_map('news_card', $rows),
        'page'     => $page,
        'total'    => $total,
        'has_more' => $page * $per < $total,
    ];
}

function api_news_get()
{
    current_user();
    $id = require_id();
    $row = q_one("SELECT * FROM news WHERE id = ? AND status = 'published'", [$id]);
    if (!$row) {
        fail('Article not found.', 404);
    }
    q('UPDATE news SET views = views + 1 WHERE id = ?', [$id]);
    $article = news_card($row);
    $article['content'] = $row['content'];
    $article['views'] = (int)$row['views'] + 1;
    $related = array_map('news_card', q_all("SELECT * FROM news WHERE status = 'published' AND id <> ? ORDER BY published_at DESC LIMIT 3", [$id]));
    return ['article' => $article, 'related' => $related];
}

return [
    'list' => ['GET', 'user', 'api_news_list'],
    'get'  => ['GET', 'user', 'api_news_get'],
];
