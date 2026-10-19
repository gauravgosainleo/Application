<?php
/*
 * Polls created by admins; every player can vote once.
 */

function poll_out(array $p, $me)
{
    $options = q_all('SELECT * FROM poll_options WHERE poll_id = ? ORDER BY sort_order ASC, id ASC', [$p['id']]);
    $mine = array_map('intval', q_col('SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ?', [$p['id'], (int)$me]));
    $voters = (int)q_val('SELECT COUNT(DISTINCT user_id) FROM poll_votes WHERE poll_id = ?', [$p['id']]);
    $totalVotes = 0;
    foreach ($options as $o) {
        $totalVotes += (int)$o['votes_count'];
    }
    $ended = $p['status'] === 'closed' || ($p['ends_at'] && strtotime($p['ends_at'] . ' UTC') <= time());
    $voted = count($mine) > 0;
    $visibility = $p['results_visibility'];
    $showResults = $visibility === 'always'
        || ($visibility === 'after_vote' && ($voted || $ended))
        || ($visibility === 'after_close' && $ended);
    $denominator = (int)$p['is_multiple'] === 1 ? $voters : $totalVotes;
    $opts = [];
    foreach ($options as $o) {
        $votes = (int)$o['votes_count'];
        $opts[] = [
            'id'      => (int)$o['id'],
            'label'   => $o['label'],
            'votes'   => $showResults ? $votes : null,
            'percent' => $showResults ? ($denominator > 0 ? round($votes * 100 / $denominator, 1) : 0) : null,
            'mine'    => in_array((int)$o['id'], $mine, true),
        ];
    }
    return [
        'id'                 => (int)$p['id'],
        'question'           => $p['question'],
        'description'        => $p['description'],
        'is_multiple'        => (int)$p['is_multiple'] === 1,
        'results_visibility' => $visibility,
        'status'             => $ended ? 'closed' : 'open',
        'ends_at'            => iso($p['ends_at']),
        'created_at'         => iso($p['created_at']),
        'voted'              => $voted,
        'voters'             => $voters,
        'show_results'       => $showResults,
        'options'            => $opts,
    ];
}

function api_polls_list()
{
    $me = uid();
    $filter = in_enum('filter', ['all', 'open', 'closed'], 'all');
    $rows = q_all('SELECT * FROM polls ORDER BY created_at DESC LIMIT 100');
    $open = [];
    $closed = [];
    foreach ($rows as $p) {
        $out = poll_out($p, $me);
        if ($out['status'] === 'open') {
            $open[] = $out;
        } else {
            $closed[] = $out;
        }
    }
    if ($filter === 'open') {
        return $open;
    }
    if ($filter === 'closed') {
        return $closed;
    }
    return array_merge($open, $closed);
}

function api_polls_vote()
{
    $me = current_user();
    $pollId = require_id('poll_id');
    $optionIds = in_ids('option_ids');
    $result = tx(function () use ($me, $pollId, $optionIds) {
        $p = q_one('SELECT * FROM polls WHERE id = ? FOR UPDATE', [$pollId]);
        if (!$p) {
            fail('Poll not found.', 404);
        }
        if ($p['status'] === 'closed' || ($p['ends_at'] && strtotime($p['ends_at'] . ' UTC') <= time())) {
            fail('This poll is closed.', 422);
        }
        if (q_val('SELECT 1 FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1', [$p['id'], $me['id']])) {
            fail('You have already voted in this poll.', 409);
        }
        if (!$optionIds) {
            fail('Choose an option first.', 422);
        }
        $valid = array_map('intval', q_col('SELECT id FROM poll_options WHERE poll_id = ?', [$p['id']]));
        foreach ($optionIds as $oid) {
            if (!in_array($oid, $valid, true)) {
                fail('Invalid option.', 422);
            }
        }
        if ((int)$p['is_multiple'] !== 1 && count($optionIds) !== 1) {
            fail('Choose only one option.', 422);
        }
        foreach ($optionIds as $oid) {
            q('INSERT INTO poll_votes (poll_id, option_id, user_id, created_at) VALUES (?, ?, ?, ?)', [$p['id'], $oid, $me['id'], now()]);
            q('UPDATE poll_options SET votes_count = votes_count + 1 WHERE id = ?', [$oid]);
        }
        return $p;
    });
    award_xp($me['id'], 10, 'poll', (string)$pollId);
    return poll_out($result, $me['id']);
}

return [
    'list' => ['GET', 'user', 'api_polls_list'],
    'vote' => ['POST', 'user', 'api_polls_vote'],
];
