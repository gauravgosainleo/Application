<?php
/*
 * Players can report discussions, comments, players, statuses,
 * gallery items and messages for admins to review.
 */

function api_reports_create()
{
    $me = uid();
    $type = in_enum('target_type', ['discussion', 'comment', 'user', 'status', 'gallery', 'message']);
    $targetId = require_id('target_id');
    $reason = in_str('reason', 255, true, 'Reason');
    if (str_len($reason) < 3) {
        fail('Please describe the problem in a few words.', 422);
    }
    $tables = [
        'discussion' => 'discussions', 'comment' => 'discussion_comments', 'user' => 'users',
        'status' => 'statuses', 'gallery' => 'gallery_items', 'message' => 'messages',
    ];
    if (!q_val('SELECT 1 FROM ' . $tables[$type] . ' WHERE id = ?', [$targetId])) {
        fail('The reported content no longer exists.', 404);
    }
    if ($type === 'user' && $targetId === $me) {
        fail('You cannot report yourself.', 422);
    }
    if (q_val("SELECT 1 FROM reports WHERE reporter_id = ? AND target_type = ? AND target_id = ? AND status = 'open'", [$me, $type, $targetId])) {
        return ['reported' => true, 'duplicate' => true];
    }
    rate_limit_or_fail('report:' . $me, 15, 3600);
    q("INSERT INTO reports (reporter_id, target_type, target_id, reason, status, created_at) VALUES (?, ?, ?, ?, 'open', ?)",
        [$me, $type, $targetId, $reason, now()]);
    return ['reported' => true];
}

return [
    'create' => ['POST', 'user', 'api_reports_create'],
];
