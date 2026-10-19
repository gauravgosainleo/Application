<?php
/*
 * Rewards (badges, titles, avatar frames, promo codes) unlocked by
 * levelling up, plus the XP history.
 */

function reward_out(array $r, $level)
{
    $claimed = !empty($r['claimed_at']);
    $expired = $r['expires_at'] && strtotime($r['expires_at'] . ' UTC') <= time();
    $remaining = $r['quantity'] !== null ? max(0, (int)$r['quantity'] - (int)$r['claimed_count']) : null;
    $available = (int)$r['is_active'] === 1 && !$expired && ($remaining === null || $remaining > 0) && (int)$r['is_event_only'] === 0;
    return [
        'id'             => (int)$r['id'],
        'title'          => $r['title'],
        'description'    => $r['description'],
        'reward_type'    => $r['reward_type'],
        'icon'           => $r['icon'],
        'style'          => $r['style'],
        'required_level' => (int)$r['required_level'],
        'quantity'       => $r['quantity'] !== null ? (int)$r['quantity'] : null,
        'remaining'      => $remaining,
        'expires_at'     => iso($r['expires_at']),
        'is_event_only'  => (int)$r['is_event_only'] === 1,
        'claimed'        => $claimed,
        'claimed_at'     => iso(isset($r['claimed_at']) ? $r['claimed_at'] : null),
        'code'           => $claimed && $r['reward_type'] === 'code' ? $r['secret_code'] : null,
        'eligible'       => $level >= (int)$r['required_level'],
        'available'      => $available,
    ];
}

function api_rewards_list()
{
    $u = current_user();
    $level = level_for_xp($u['xp']);
    $rows = q_all('SELECT r.*, ur.claimed_at FROM rewards r
        LEFT JOIN user_rewards ur ON ur.reward_id = r.id AND ur.user_id = ?
        WHERE (r.is_active = 1 AND r.is_event_only = 0) OR ur.user_id IS NOT NULL
        ORDER BY r.required_level ASC, r.id ASC', [$u['id']]);
    return [
        'rewards'  => array_map(function ($r) use ($level) {
            return reward_out($r, $level);
        }, $rows),
        'level'    => level_info($u['xp']),
        'equipped' => ['title_id' => $u['equipped_title_id'] ? (int)$u['equipped_title_id'] : null,
            'frame_id' => $u['equipped_frame_id'] ? (int)$u['equipped_frame_id'] : null],
    ];
}

function api_rewards_claim()
{
    $u = current_user();
    $id = require_id('reward_id');
    $level = level_for_xp($u['xp']);
    $reward = tx(function () use ($u, $id, $level) {
        $r = q_one('SELECT * FROM rewards WHERE id = ? FOR UPDATE', [$id]);
        if (!$r || (int)$r['is_active'] !== 1) {
            fail('Reward not available.', 404);
        }
        if ((int)$r['is_event_only'] === 1) {
            fail('This reward can only be earned by joining its event.', 422);
        }
        if ($r['expires_at'] && strtotime($r['expires_at'] . ' UTC') <= time()) {
            fail('This reward has expired.', 422);
        }
        if ($level < (int)$r['required_level']) {
            fail('Reach level ' . (int)$r['required_level'] . ' to unlock this reward.', 422);
        }
        if ($r['quantity'] !== null && (int)$r['claimed_count'] >= (int)$r['quantity']) {
            fail('All of these rewards have already been claimed.', 422);
        }
        if (!q_exec('INSERT IGNORE INTO user_rewards (user_id, reward_id, claimed_at, source) VALUES (?, ?, ?, ?)', [$u['id'], $r['id'], now(), 'claim'])) {
            fail('You already claimed this reward.', 409);
        }
        q('UPDATE rewards SET claimed_count = claimed_count + 1 WHERE id = ?', [$r['id']]);
        return $r;
    });
    $reward['claimed_at'] = now();
    $reward['claimed_count'] = (int)$reward['claimed_count'] + 1;
    return reward_out($reward, $level);
}

function api_rewards_xp()
{
    $u = current_user();
    $labels = [
        'welcome' => 'Joined G VI Fan Hub', 'daily_login' => 'Daily login', 'discussion' => 'Started a discussion',
        'comment' => 'Commented', 'like_recv' => 'Your discussion got a like', 'poll' => 'Voted in a poll',
        'event' => 'Event reward', 'marker' => 'Added a map marker', 'status' => 'Posted a status',
        'friend' => 'New friend', 'gallery' => 'Gallery upload', 'admin' => 'Bonus from admins',
    ];
    $history = array_map(function ($x) use ($labels) {
        return [
            'amount'     => (int)$x['amount'],
            'reason'     => $x['reason'],
            'label'      => isset($labels[$x['reason']]) ? $labels[$x['reason']] : ucfirst(str_replace('_', ' ', $x['reason'])),
            'created_at' => iso($x['created_at']),
        ];
    }, q_all('SELECT amount, reason, created_at FROM xp_log WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$u['id']]));
    return [
        'level'   => level_info($u['xp']),
        'streak'  => (int)$u['login_streak'],
        'history' => $history,
        'earn'    => [
            ['label' => 'Daily login (bonus for streaks)', 'xp' => '10-25'],
            ['label' => 'Start a discussion (3 per day)', 'xp' => '20'],
            ['label' => 'Comment on a discussion (10 per day)', 'xp' => '5'],
            ['label' => 'Vote in a poll', 'xp' => '10'],
            ['label' => 'Join an event & claim its reward', 'xp' => 'varies'],
            ['label' => 'Post a status (3 per day)', 'xp' => '5'],
            ['label' => 'Add a map marker (5 per day)', 'xp' => '3'],
            ['label' => 'Make a new friend', 'xp' => '5'],
        ],
    ];
}

return [
    'list'  => ['GET', 'user', 'api_rewards_list'],
    'claim' => ['POST', 'user', 'api_rewards_claim'],
    'xp'    => ['GET', 'user', 'api_rewards_xp'],
];
