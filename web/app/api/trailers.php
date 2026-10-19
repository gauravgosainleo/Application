<?php
/*
 * GTA VI trailers (managed by admins).
 */

function api_trailers_list()
{
    current_user();
    $rows = q_all('SELECT * FROM trailers ORDER BY is_featured DESC, sort_order ASC, released_on DESC, id DESC');
    return array_map('trailer_card', $rows);
}

return [
    'list' => ['GET', 'user', 'api_trailers_list'],
];
