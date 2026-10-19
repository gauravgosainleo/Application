<?php
/*
 * Catalogue of vehicles, weapons, equipment ... (categories are admin-defined).
 */

function catalogue_item_out(array $i, array $favSet = [], $full = false)
{
    $stats = json_decode((string)$i['stats'], true);
    $out = [
        'id'           => (int)$i['id'],
        'category_id'  => (int)$i['category_id'],
        'category'     => isset($i['category_name']) ? $i['category_name'] : null,
        'category_icon'=> isset($i['category_icon']) ? $i['category_icon'] : null,
        'name'         => $i['name'],
        'subtype'      => $i['subtype'],
        'manufacturer' => $i['manufacturer'],
        'image'        => $i['image'],
        'price'        => $i['price'] !== null ? (int)$i['price'] : null,
        'status'       => $i['status'],
        'stats'        => is_array($stats) ? $stats : [],
        'favorite'     => isset($favSet[(int)$i['id']]),
    ];
    if ($full) {
        $out['description'] = $i['description'];
    } else {
        $out['excerpt'] = str_limit((string)$i['description'], 120);
    }
    return $out;
}

function api_catalogue_categories()
{
    current_user();
    return array_map(function ($c) {
        return [
            'id'          => (int)$c['id'],
            'name'        => $c['name'],
            'slug'        => $c['slug'],
            'icon'        => $c['icon'],
            'description' => $c['description'],
            'items'       => (int)$c['items'],
        ];
    }, q_all('SELECT c.*, (SELECT COUNT(*) FROM catalogue_items i WHERE i.category_id = c.id) AS items
        FROM catalogue_categories c ORDER BY c.sort_order ASC, c.name ASC'));
}

function api_catalogue_items()
{
    $me = uid();
    $page = max(1, in_int('page', 1));
    $per = 24;
    $where = ['1 = 1'];
    $params = [];
    $category = in_str('category', 70);
    if ($category !== '') {
        if (ctype_digit($category)) {
            $where[] = 'i.category_id = ?';
            $params[] = (int)$category;
        } else {
            $where[] = 'c.slug = ?';
            $params[] = $category;
        }
    }
    $search = in_str('q', 80);
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(i.name LIKE ? OR i.subtype LIKE ? OR i.manufacturer LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (in_bool('favorites')) {
        $where[] = 'EXISTS (SELECT 1 FROM catalogue_favorites f WHERE f.item_id = i.id AND f.user_id = ?)';
        $params[] = $me;
    }
    $sort = in_enum('sort', ['name', 'newest', 'price_high', 'price_low'], 'name');
    $order = [
        'name'       => 'i.sort_order ASC, i.name ASC',
        'newest'     => 'i.created_at DESC',
        'price_high' => 'i.price IS NULL, i.price DESC',
        'price_low'  => 'i.price IS NULL, i.price ASC',
    ][$sort];
    $whereSql = implode(' AND ', $where);
    $from = 'FROM catalogue_items i JOIN catalogue_categories c ON c.id = i.category_id';
    $total = (int)q_val("SELECT COUNT(*) {$from} WHERE {$whereSql}", $params);
    $rows = q_all("SELECT i.*, c.name AS category_name, c.icon AS category_icon {$from} WHERE {$whereSql} ORDER BY {$order} LIMIT ? OFFSET ?",
        array_merge($params, [$per, ($page - 1) * $per]));
    $fav = [];
    if ($rows) {
        $ids = array_map('intval', array_column($rows, 'id'));
        foreach (q_col('SELECT item_id FROM catalogue_favorites WHERE user_id = ? AND item_id IN (' . placeholders($ids) . ')', array_merge([$me], $ids)) as $fid) {
            $fav[(int)$fid] = true;
        }
    }
    $items = [];
    foreach ($rows as $r) {
        $items[] = catalogue_item_out($r, $fav);
    }
    return ['items' => $items, 'page' => $page, 'total' => $total, 'has_more' => $page * $per < $total];
}

function api_catalogue_item()
{
    $me = uid();
    $row = q_one('SELECT i.*, c.name AS category_name, c.icon AS category_icon FROM catalogue_items i
        JOIN catalogue_categories c ON c.id = i.category_id WHERE i.id = ?', [require_id()]);
    if (!$row) {
        fail('Item not found.', 404);
    }
    $fav = q_val('SELECT 1 FROM catalogue_favorites WHERE item_id = ? AND user_id = ?', [$row['id'], $me]) ? [(int)$row['id'] => true] : [];
    $related = array_map(function ($r) {
        return catalogue_item_out($r);
    }, q_all('SELECT i.*, c.name AS category_name, c.icon AS category_icon FROM catalogue_items i
        JOIN catalogue_categories c ON c.id = i.category_id WHERE i.category_id = ? AND i.id <> ? ORDER BY RAND() LIMIT 4', [$row['category_id'], $row['id']]));
    $item = catalogue_item_out($row, $fav, true);
    $item['favorites_count'] = (int)q_val('SELECT COUNT(*) FROM catalogue_favorites WHERE item_id = ?', [$row['id']]);
    return ['item' => $item, 'related' => $related];
}

function api_catalogue_favorite()
{
    $me = uid();
    $id = require_id('item_id');
    if (!q_val('SELECT 1 FROM catalogue_items WHERE id = ?', [$id])) {
        fail('Item not found.', 404);
    }
    if (q_exec('DELETE FROM catalogue_favorites WHERE item_id = ? AND user_id = ?', [$id, $me])) {
        return ['favorite' => false];
    }
    q('INSERT IGNORE INTO catalogue_favorites (item_id, user_id, created_at) VALUES (?, ?, ?)', [$id, $me, now()]);
    return ['favorite' => true];
}

return [
    'categories' => ['GET', 'user', 'api_catalogue_categories'],
    'items'      => ['GET', 'user', 'api_catalogue_items'],
    'item'       => ['GET', 'user', 'api_catalogue_item'],
    'favorite'   => ['POST', 'user', 'api_catalogue_favorite'],
];
