<?php
/*
 * Admin: catalogue categories and items (vehicles, weapons, equipment ...).
 */

function api_admin_categories()
{
    current_admin();
    return array_map(function ($c) {
        return [
            'id'          => (int)$c['id'],
            'name'        => $c['name'],
            'slug'        => $c['slug'],
            'icon'        => $c['icon'],
            'description' => $c['description'],
            'sort_order'  => (int)$c['sort_order'],
            'items'       => (int)$c['items'],
        ];
    }, q_all('SELECT c.*, (SELECT COUNT(*) FROM catalogue_items i WHERE i.category_id = c.id) AS items
        FROM catalogue_categories c ORDER BY c.sort_order, c.name'));
}

function api_admin_category_save()
{
    current_admin();
    $id = in_int('id');
    $name = in_str('name', 60, true, 'Category name');
    $icon = in_str('icon', 16);
    $description = in_str('description', 255);
    $sort = in_int('sort_order');
    $slug = slugify($name);
    $base = $slug;
    $n = 2;
    while (q_val('SELECT 1 FROM catalogue_categories WHERE slug = ? AND id <> ?', [$slug, $id])) {
        $slug = $base . '-' . $n++;
    }
    if ($id > 0) {
        if (!q_val('SELECT 1 FROM catalogue_categories WHERE id = ?', [$id])) {
            fail('Category not found.', 404);
        }
        q('UPDATE catalogue_categories SET name = ?, slug = ?, icon = ?, description = ?, sort_order = ? WHERE id = ?',
            [$name, $slug, $icon ?: null, $description ?: null, $sort, $id]);
    } else {
        q_insert('INSERT INTO catalogue_categories (name, slug, icon, description, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $slug, $icon ?: null, $description ?: null, $sort, now()]);
    }
    return api_admin_categories();
}

function api_admin_category_delete()
{
    current_admin();
    $id = require_id();
    $count = (int)q_val('SELECT COUNT(*) FROM catalogue_items WHERE category_id = ?', [$id]);
    if ($count > 0 && !in_bool('force')) {
        fail('This category still has ' . $count . ' item(s). Delete or move them first, or confirm deleting everything.', 409, 'category_not_empty', ['items' => $count]);
    }
    foreach (q_col('SELECT image FROM catalogue_items WHERE category_id = ?', [$id]) as $img) {
        Upload::delete($img);
    }
    q('DELETE FROM catalogue_categories WHERE id = ?', [$id]);
    return api_admin_categories();
}

function admin_item_row(array $i)
{
    $stats = json_decode((string)$i['stats'], true);
    return [
        'id'           => (int)$i['id'],
        'category_id'  => (int)$i['category_id'],
        'category'     => isset($i['category_name']) ? $i['category_name'] : null,
        'name'         => $i['name'],
        'subtype'      => $i['subtype'],
        'manufacturer' => $i['manufacturer'],
        'description'  => $i['description'],
        'image'        => $i['image'],
        'price'        => $i['price'] !== null ? (int)$i['price'] : null,
        'status'       => $i['status'],
        'stats'        => is_array($stats) ? $stats : [],
        'sort_order'   => (int)$i['sort_order'],
        'favorites'    => isset($i['favorites']) ? (int)$i['favorites'] : 0,
        'updated_at'   => iso($i['updated_at'] ?: $i['created_at']),
    ];
}

function api_admin_items()
{
    current_admin();
    $page = max(1, in_int('page', 1));
    $per = 30;
    $where = ['1 = 1'];
    $params = [];
    $cat = in_int('category_id');
    if ($cat > 0) {
        $where[] = 'i.category_id = ?';
        $params[] = $cat;
    }
    $search = in_str('q', 100);
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(i.name LIKE ? OR i.subtype LIKE ? OR i.manufacturer LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);
    $total = (int)q_val("SELECT COUNT(*) FROM catalogue_items i WHERE {$whereSql}", $params);
    $rows = q_all("SELECT i.*, c.name AS category_name, (SELECT COUNT(*) FROM catalogue_favorites f WHERE f.item_id = i.id) AS favorites
        FROM catalogue_items i JOIN catalogue_categories c ON c.id = i.category_id
        WHERE {$whereSql} ORDER BY c.sort_order, i.sort_order, i.name LIMIT ? OFFSET ?", array_merge($params, [$per, ($page - 1) * $per]));
    return ['items' => array_map('admin_item_row', $rows), 'page' => $page, 'total' => $total, 'has_more' => $page * $per < $total];
}

function api_admin_item_save()
{
    current_admin();
    $id = in_int('id');
    $categoryId = require_id('category_id');
    if (!q_val('SELECT 1 FROM catalogue_categories WHERE id = ?', [$categoryId])) {
        fail('Choose a valid category.', 422);
    }
    $name = in_str('name', 120, true, 'Name');
    $subtype = in_str('subtype', 60);
    $manufacturer = in_str('manufacturer', 60);
    $description = in_text('description', 5000);
    $status = in_str('status', 30) ?: 'Confirmed';
    $priceRaw = trim((string)input('price', ''));
    $price = $priceRaw === '' ? null : (int)preg_replace('/[^0-9]/', '', $priceRaw);
    $sort = in_int('sort_order');

    $statsIn = input('stats', '[]');
    $statsArr = is_array($statsIn) ? $statsIn : json_decode((string)$statsIn, true);
    $stats = [];
    if (is_array($statsArr)) {
        foreach (array_slice($statsArr, 0, 12) as $s) {
            if (!is_array($s) || !isset($s['label']) || trim((string)$s['label']) === '') {
                continue;
            }
            $value = isset($s['value']) ? $s['value'] : '';
            $stats[] = [
                'label' => str_limit(clean_string($s['label']), 40, ''),
                'value' => is_numeric($value) ? max(0, min(100, (float)$value)) : str_limit(clean_string($value), 60, ''),
            ];
        }
    }

    $existing = $id > 0 ? q_one('SELECT * FROM catalogue_items WHERE id = ?', [$id]) : null;
    if ($id > 0 && !$existing) {
        fail('Item not found.', 404);
    }
    $image = in_url('image_url', 700, false, 'Image link');
    $oldImage = $existing ? (string)$existing['image'] : '';
    if (Upload::has('image')) {
        $image = Upload::image('image', 'catalogue', ['max' => 1400]);
    } elseif ($image === '' && !in_bool('remove_image')) {
        $image = $oldImage;
    }
    if ($oldImage !== '' && $oldImage !== $image) {
        Upload::delete($oldImage);
    }

    $params = [$categoryId, $name, $subtype ?: null, $manufacturer ?: null, $description ?: null, $image ?: null, $price,
        str_limit($status, 30, ''), json_encode($stats, JSON_UNESCAPED_UNICODE), $sort, now()];
    if ($existing) {
        $params[] = $id;
        q('UPDATE catalogue_items SET category_id = ?, name = ?, subtype = ?, manufacturer = ?, description = ?, image = ?, price = ?,
            status = ?, stats = ?, sort_order = ?, updated_at = ? WHERE id = ?', $params);
    } else {
        $params[] = now();
        $id = q_insert('INSERT INTO catalogue_items (category_id, name, subtype, manufacturer, description, image, price, status, stats, sort_order, updated_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $params);
    }
    return admin_item_row(q_one('SELECT i.*, c.name AS category_name FROM catalogue_items i JOIN catalogue_categories c ON c.id = i.category_id WHERE i.id = ?', [$id]));
}

function api_admin_item_delete()
{
    current_admin();
    $i = q_one('SELECT * FROM catalogue_items WHERE id = ?', [require_id()]);
    if (!$i) {
        fail('Item not found.', 404);
    }
    Upload::delete($i['image']);
    q('DELETE FROM catalogue_items WHERE id = ?', [$i['id']]);
    return ['deleted' => true];
}

return [
    'categories'      => ['GET', 'admin', 'api_admin_categories'],
    'category-save'   => ['POST', 'admin', 'api_admin_category_save'],
    'category-delete' => ['POST', 'admin', 'api_admin_category_delete'],
    'items'           => ['GET', 'admin', 'api_admin_items'],
    'item-save'       => ['POST', 'admin', 'api_admin_item_save'],
    'item-delete'     => ['POST', 'admin', 'api_admin_item_delete'],
];
