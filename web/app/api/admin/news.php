<?php
/*
 * Admin: news & articles - manual posts, approval queue for auto-found
 * stories, auto mode (every 6/12/24h), keywords and news sources.
 */

require_once APP_DIR . '/lib/news_fetcher.php';

function admin_news_row(array $n)
{
    return [
        'id'                    => (int)$n['id'],
        'title'                 => $n['title'],
        'summary'               => $n['summary'],
        'content'               => $n['content'],
        'image_url'             => $n['image_url'],
        'source_name'           => $n['source_name'],
        'source_url'            => $n['source_url'],
        'origin'                => $n['origin'],
        'status'                => $n['status'],
        'is_featured'           => (int)$n['is_featured'] === 1,
        'views'                 => (int)$n['views'],
        'external_published_at' => iso($n['external_published_at']),
        'published_at'          => iso($n['published_at']),
        'reviewed_at'           => iso($n['reviewed_at']),
        'created_at'            => iso($n['created_at']),
    ];
}

function api_admin_news()
{
    current_admin();
    $page = max(1, in_int('page', 1));
    $per = 20;
    $status = in_enum('status', ['all', 'pending', 'published', 'rejected'], 'pending');
    $where = ['1 = 1'];
    $params = [];
    if ($status !== 'all') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $search = in_str('q', 100);
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(title LIKE ? OR source_name LIKE ?)';
        array_push($params, $like, $like);
    }
    $whereSql = implode(' AND ', $where);
    $order = $status === 'published' ? 'published_at DESC' : 'COALESCE(external_published_at, created_at) DESC';
    $total = (int)q_val("SELECT COUNT(*) FROM news WHERE {$whereSql}", $params);
    $rows = q_all("SELECT * FROM news WHERE {$whereSql} ORDER BY {$order}, id DESC LIMIT ? OFFSET ?", array_merge($params, [$per, ($page - 1) * $per]));
    $counts = [];
    foreach (q_all('SELECT status, COUNT(*) AS c FROM news GROUP BY status') as $r) {
        $counts[$r['status']] = (int)$r['c'];
    }
    return [
        'items'    => array_map('admin_news_row', $rows),
        'page'     => $page,
        'total'    => $total,
        'has_more' => $page * $per < $total,
        'counts'   => $counts + ['pending' => 0, 'published' => 0, 'rejected' => 0],
    ];
}

function admin_publish_news($id, $adminId)
{
    q("UPDATE news SET status = 'published', published_at = COALESCE(published_at, ?), reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?",
        [now(), $adminId, now(), now(), $id]);
}

function api_admin_news_save()
{
    $admin = current_admin();
    $id = in_int('id');
    $title = in_str('title', 255, true, 'Title');
    $summary = in_text('summary', 1000, false, 'Summary');
    $content = in_text('content', 60000, false, 'Content');
    $sourceName = in_str('source_name', 120, true, 'Source name');
    $sourceUrl = in_url('source_url', 1000, false, 'Source link');
    $imageUrl = in_url('image_url', 700, false, 'Image link');
    $status = in_enum('status', ['published', 'pending', 'rejected'], 'published');
    $featured = in_bool('is_featured') ? 1 : 0;

    $existing = $id > 0 ? q_one('SELECT * FROM news WHERE id = ?', [$id]) : null;
    if ($id > 0 && !$existing) {
        fail('Article not found.', 404);
    }
    $image = $imageUrl !== '' ? $imageUrl : ($existing ? $existing['image_url'] : null);
    if (Upload::has('image')) {
        $image = Upload::image('image', 'news', ['max' => 1600]);
        if ($existing && $existing['image_url'] !== $image) {
            Upload::delete($existing['image_url']);
        }
    } elseif (in_bool('remove_image')) {
        if ($existing) {
            Upload::delete($existing['image_url']);
        }
        $image = null;
    }

    if ($existing) {
        q('UPDATE news SET title = ?, summary = ?, content = ?, image_url = ?, source_name = ?, source_url = ?, is_featured = ?,
            title_hash = ?, updated_at = ? WHERE id = ?', [
            $title, $summary ?: null, $content ?: null, $image, $sourceName, $sourceUrl ?: null, $featured,
            sha1(preg_replace('/[^a-z0-9]/', '', strtolower($title))), now(), $id,
        ]);
    } else {
        $id = q_insert("INSERT INTO news (title, summary, content, image_url, source_name, source_url, origin, status, is_featured,
                title_hash, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'manual', 'pending', ?, ?, ?, ?, ?)", [
            $title, $summary ?: null, $content ?: null, $image, $sourceName, $sourceUrl ?: null, $featured,
            sha1(preg_replace('/[^a-z0-9]/', '', strtolower($title))), $admin['id'], now(), now(),
        ]);
    }
    if ($status === 'published') {
        $wasPublished = $existing && $existing['status'] === 'published';
        admin_publish_news($id, $admin['id']);
        if (!$wasPublished && in_bool('notify')) {
            notify_all('news', 'News: ' . $title, 'Source: ' . $sourceName, '#/news/' . $id);
        }
    } else {
        q('UPDATE news SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?', [$status, $admin['id'], now(), $id]);
    }
    return admin_news_row(q_one('SELECT * FROM news WHERE id = ?', [$id]));
}

function api_admin_news_status()
{
    $admin = current_admin();
    $id = require_id();
    $status = in_enum('status', ['published', 'pending', 'rejected']);
    $row = q_one('SELECT * FROM news WHERE id = ?', [$id]);
    if (!$row) {
        fail('Article not found.', 404);
    }
    if ($status === 'published') {
        if (trim((string)$row['source_name']) === '') {
            fail('Add the source name before publishing.', 422);
        }
        admin_publish_news($id, $admin['id']);
        if ($row['status'] !== 'published' && in_bool('notify')) {
            notify_all('news', 'News: ' . $row['title'], 'Source: ' . $row['source_name'], '#/news/' . $id);
        }
    } else {
        q('UPDATE news SET status = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?', [$status, $admin['id'], now(), now(), $id]);
    }
    return admin_news_row(q_one('SELECT * FROM news WHERE id = ?', [$id]));
}

function api_admin_news_bulk()
{
    $admin = current_admin();
    $ids = in_ids('ids');
    $action = in_enum('action', ['publish', 'reject', 'delete']);
    if (!$ids) {
        fail('Select at least one article.', 422);
    }
    foreach ($ids as $id) {
        if ($action === 'publish') {
            admin_publish_news($id, $admin['id']);
        } elseif ($action === 'reject') {
            q("UPDATE news SET status = 'rejected', reviewed_by = ?, reviewed_at = ? WHERE id = ?", [$admin['id'], now(), $id]);
        } else {
            $img = q_val('SELECT image_url FROM news WHERE id = ?', [$id]);
            Upload::delete($img);
            q('DELETE FROM news WHERE id = ?', [$id]);
        }
    }
    return ['updated' => count($ids)];
}

function api_admin_news_delete()
{
    current_admin();
    $row = q_one('SELECT * FROM news WHERE id = ?', [require_id()]);
    if (!$row) {
        fail('Article not found.', 404);
    }
    Upload::delete($row['image_url']);
    q('DELETE FROM news WHERE id = ?', [$row['id']]);
    return ['deleted' => true];
}

function api_admin_news_settings()
{
    current_admin();
    $log = json_decode((string)setting('news_last_run_log', ''), true);
    $sources = array_map(function ($s) {
        return [
            'id'              => (int)$s['id'],
            'name'            => $s['name'],
            'feed_url'        => $s['feed_url'],
            'site_url'        => $s['site_url'],
            'enabled'         => (int)$s['enabled'] === 1,
            'last_fetched_at' => iso($s['last_fetched_at']),
            'last_status'     => $s['last_status'],
            'stories'         => (int)$s['stories'],
        ];
    }, q_all('SELECT s.*, (SELECT COUNT(*) FROM news n WHERE n.news_source_id = s.id) AS stories FROM news_sources s ORDER BY s.id'));
    return [
        'auto_enabled'   => setting('news_auto_enabled', '0') === '1',
        'interval_hours' => NewsFetcher::intervalHours(),
        'keywords'       => implode(', ', NewsFetcher::keywords()),
        'last_run_at'    => iso(setting('news_last_run_at')),
        'next_run_at'    => iso(NewsFetcher::nextRunAt()),
        'last_run'       => is_array($log) ? $log : null,
        'sources'        => $sources,
        'cron_command'   => '/usr/bin/php ' . WEB_DIR . '/cron/fetch-news.php',
    ];
}

function api_admin_news_settings_save()
{
    current_admin();
    $wasEnabled = setting('news_auto_enabled', '0') === '1';
    if (has_input('auto_enabled')) {
        set_setting('news_auto_enabled', in_bool('auto_enabled') ? '1' : '0');
    }
    if (has_input('interval_hours')) {
        $h = in_int('interval_hours');
        if (!in_array($h, [6, 12, 24], true)) {
            fail('Interval must be 6, 12 or 24 hours.', 422);
        }
        set_setting('news_interval_hours', (string)$h);
    }
    if (has_input('keywords')) {
        $keywords = array_filter(array_map('trim', explode(',', in_str('keywords', 500, true, 'Keywords'))));
        if (!$keywords) {
            fail('Add at least one keyword.', 422);
        }
        set_setting('news_keywords', implode(', ', $keywords));
    }
    if (!$wasEnabled && setting('news_auto_enabled') === '1') {
        // Turning auto mode on: run the first check soon.
        set_setting('news_last_run_at', '');
    }
    return api_admin_news_settings();
}

function api_admin_news_source_save()
{
    current_admin();
    $id = in_int('id');
    $name = in_str('name', 100, true, 'Source name');
    $feed = in_url('feed_url', 500, true, 'Feed URL');
    $site = in_url('site_url', 255, false, 'Website');
    $enabled = in_bool('enabled', true) ? 1 : 0;
    if ($id > 0) {
        if (!q_exec('UPDATE news_sources SET name = ?, feed_url = ?, site_url = ?, enabled = ? WHERE id = ?', [$name, $feed, $site ?: null, $enabled, $id])
            && !q_val('SELECT 1 FROM news_sources WHERE id = ?', [$id])) {
            fail('Source not found.', 404);
        }
    } else {
        q_insert('INSERT INTO news_sources (name, feed_url, site_url, enabled, created_at) VALUES (?, ?, ?, ?, ?)', [$name, $feed, $site ?: null, $enabled, now()]);
    }
    return api_admin_news_settings();
}

function api_admin_news_source_delete()
{
    current_admin();
    q('DELETE FROM news_sources WHERE id = ?', [require_id()]);
    return api_admin_news_settings();
}

function api_admin_news_source_test()
{
    current_admin();
    $feed = in_url('feed_url', 500, true, 'Feed URL');
    try {
        $items = NewsFetcher::parse(NewsFetcher::download($feed));
    } catch (Exception $e) {
        fail('Feed test failed: ' . $e->getMessage(), 422);
    }
    $keywords = NewsFetcher::keywords();
    $matching = array_values(array_filter($items, function ($i) use ($keywords) {
        return NewsFetcher::matches($i, $keywords);
    }));
    return [
        'items'    => count($items),
        'matching' => count($matching),
        'sample'   => array_map(function ($i) {
            return ['title' => $i['title'], 'link' => $i['link']];
        }, array_slice($matching ?: $items, 0, 6)),
    ];
}

function api_admin_news_fetch()
{
    current_admin();
    return NewsFetcher::run('manual');
}

return [
    'news'               => ['GET', 'admin', 'api_admin_news'],
    'news-save'          => ['POST', 'admin', 'api_admin_news_save'],
    'news-status'        => ['POST', 'admin', 'api_admin_news_status'],
    'news-bulk'          => ['POST', 'admin', 'api_admin_news_bulk'],
    'news-delete'        => ['POST', 'admin', 'api_admin_news_delete'],
    'news-settings'      => ['GET', 'admin', 'api_admin_news_settings'],
    'news-settings-save' => ['POST', 'admin', 'api_admin_news_settings_save'],
    'news-source-save'   => ['POST', 'admin', 'api_admin_news_source_save'],
    'news-source-delete' => ['POST', 'admin', 'api_admin_news_source_delete'],
    'news-source-test'   => ['POST', 'admin', 'api_admin_news_source_test'],
    'news-fetch'         => ['POST', 'admin', 'api_admin_news_fetch'],
];
