<?php
/*
 * Community events: join, leave and claim the event reward (XP and/or a badge).
 */

function event_phase(array $e)
{
    $now = time();
    if (strtotime($e['starts_at'] . ' UTC') > $now) {
        return 'upcoming';
    }
    if ($e['ends_at'] && strtotime($e['ends_at'] . ' UTC') <= $now) {
        return 'ended';
    }
    return 'live';
}

function event_out(array $e, $me)
{
    $phase = event_phase($e);
    $participation = q_one('SELECT * FROM event_participants WHERE event_id = ? AND user_id = ?', [$e['id'], (int)$me]);
    $participants = (int)q_val('SELECT COUNT(*) FROM event_participants WHERE event_id = ?', [$e['id']]);
    $reward = $e['reward_id'] ? q_one('SELECT id, title, reward_type, icon, style FROM rewards WHERE id = ?', [$e['reward_id']]) : null;
    $hasReward = (int)$e['reward_xp'] > 0 || $reward;
    $claimed = $participation && $participation['reward_claimed_at'];
    return [
        'id'               => (int)$e['id'],
        'title'            => $e['title'],
        'description'      => $e['description'],
        'event_type'       => $e['event_type'],
        'banner'           => $e['banner'],
        'location'         => $e['location'],
        'starts_at'        => iso($e['starts_at']),
        'ends_at'          => iso($e['ends_at']),
        'reward_xp'        => (int)$e['reward_xp'],
        'reward'           => $reward ? ['id' => (int)$reward['id'], 'title' => $reward['title'], 'reward_type' => $reward['reward_type'],
            'icon' => $reward['icon'], 'style' => $reward['style']] : null,
        'max_participants' => $e['max_participants'] !== null ? (int)$e['max_participants'] : null,
        'participants'     => $participants,
        'phase'            => $phase,
        'joined'           => (bool)$participation,
        'claimed'          => (bool)$claimed,
        'can_claim'        => $participation && !$claimed && $phase !== 'upcoming' && $hasReward,
        'is_full'          => $e['max_participants'] !== null && $participants >= (int)$e['max_participants'],
    ];
}

function api_events_list()
{
    $me = uid();
    $filter = in_enum('filter', ['all', 'upcoming', 'live', 'past', 'mine'], 'all');
    if ($filter === 'mine') {
        $rows = q_all('SELECT e.* FROM events e JOIN event_participants p ON p.event_id = e.id AND p.user_id = ?
            WHERE e.is_published = 1 ORDER BY e.starts_at DESC', [$me]);
    } else {
        $rows = q_all('SELECT * FROM events WHERE is_published = 1 ORDER BY starts_at ASC LIMIT 300');
    }
    $buckets = ['live' => [], 'upcoming' => [], 'ended' => []];
    foreach ($rows as $e) {
        $out = event_out($e, $me);
        $buckets[$out['phase']][] = $out;
    }
    $buckets['ended'] = array_reverse($buckets['ended']);
    if ($filter === 'upcoming') {
        return $buckets['upcoming'];
    }
    if ($filter === 'live') {
        return $buckets['live'];
    }
    if ($filter === 'past') {
        return $buckets['ended'];
    }
    return array_merge($buckets['live'], $buckets['upcoming'], $buckets['ended']);
}

function api_events_get()
{
    $me = uid();
    $e = q_one('SELECT * FROM events WHERE id = ? AND is_published = 1', [require_id()]);
    if (!$e) {
        fail('Event not found.', 404);
    }
    $ids = q_col('SELECT user_id FROM event_participants WHERE event_id = ? ORDER BY joined_at ASC LIMIT 60', [$e['id']]);
    $cards = hydrate_users($ids);
    $participants = [];
    foreach ($ids as $id) {
        if (isset($cards[(int)$id])) {
            $participants[] = $cards[(int)$id];
        }
    }
    return ['event' => event_out($e, $me), 'participants' => $participants];
}

function api_events_join()
{
    $me = uid();
    return tx(function () use ($me) {
        $e = q_one('SELECT * FROM events WHERE id = ? AND is_published = 1 FOR UPDATE', [require_id('event_id')]);
        if (!$e) {
            fail('Event not found.', 404);
        }
        if (event_phase($e) === 'ended') {
            fail('This event has already ended.', 422);
        }
        if ($e['max_participants'] !== null && (int)q_val('SELECT COUNT(*) FROM event_participants WHERE event_id = ?', [$e['id']]) >= (int)$e['max_participants']) {
            fail('Sorry, this event is full.', 422);
        }
        q('INSERT IGNORE INTO event_participants (event_id, user_id, joined_at) VALUES (?, ?, ?)', [$e['id'], $me, now()]);
        return event_out($e, $me);
    });
}

function api_events_leave()
{
    $me = uid();
    $e = q_one('SELECT * FROM events WHERE id = ?', [require_id('event_id')]);
    if (!$e) {
        fail('Event not found.', 404);
    }
    $p = q_one('SELECT * FROM event_participants WHERE event_id = ? AND user_id = ?', [$e['id'], $me]);
    if ($p && $p['reward_claimed_at']) {
        fail('You already claimed the reward for this event.', 422);
    }
    if (event_phase($e) === 'ended') {
        fail('This event has already ended.', 422);
    }
    q('DELETE FROM event_participants WHERE event_id = ? AND user_id = ?', [$e['id'], $me]);
    return event_out($e, $me);
}

function api_events_claim()
{
    $me = current_user();
    $e = q_one('SELECT * FROM events WHERE id = ? AND is_published = 1', [require_id('event_id')]);
    if (!$e) {
        fail('Event not found.', 404);
    }
    if (event_phase($e) === 'upcoming') {
        fail('Rewards unlock when the event starts.', 422);
    }
    $p = q_one('SELECT * FROM event_participants WHERE event_id = ? AND user_id = ?', [$e['id'], $me['id']]);
    if (!$p) {
        fail('Join the event first.', 422);
    }
    if ($p['reward_claimed_at']) {
        fail('Reward already claimed.', 409);
    }
    if (!q_exec('UPDATE event_participants SET reward_claimed_at = ? WHERE event_id = ? AND user_id = ? AND reward_claimed_at IS NULL', [now(), $e['id'], $me['id']])) {
        fail('Reward already claimed.', 409);
    }
    $granted = ['xp' => 0, 'reward' => null];
    if ((int)$e['reward_xp'] > 0 && award_xp($me['id'], (int)$e['reward_xp'], 'event', (string)$e['id'])) {
        $granted['xp'] = (int)$e['reward_xp'];
    }
    if ($e['reward_id']) {
        $reward = q_one('SELECT * FROM rewards WHERE id = ?', [$e['reward_id']]);
        if ($reward && q_exec('INSERT IGNORE INTO user_rewards (user_id, reward_id, claimed_at, source) VALUES (?, ?, ?, ?)',
                [$me['id'], $reward['id'], now(), 'event:' . $e['id']])) {
            q('UPDATE rewards SET claimed_count = claimed_count + 1 WHERE id = ?', [$reward['id']]);
            $granted['reward'] = ['id' => (int)$reward['id'], 'title' => $reward['title'], 'reward_type' => $reward['reward_type'], 'icon' => $reward['icon']];
        }
    }
    notify($me['id'], 'reward', 'Event reward claimed: ' . $e['title'],
        trim(($granted['xp'] ? '+' . $granted['xp'] . ' XP ' : '') . ($granted['reward'] ? 'Unlocked "' . $granted['reward']['title'] . '"' : '')), '#/events?tab=rewards');
    return ['event' => event_out($e, $me['id']), 'granted' => $granted, 'level' => level_info(q_val('SELECT xp FROM users WHERE id = ?', [$me['id']]))];
}

return [
    'list'  => ['GET', 'user', 'api_events_list'],
    'get'   => ['GET', 'user', 'api_events_get'],
    'join'  => ['POST', 'user', 'api_events_join'],
    'leave' => ['POST', 'user', 'api_events_leave'],
    'claim' => ['POST', 'user', 'api_events_claim'],
];
