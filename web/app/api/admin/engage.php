<?php
/*
 * Admin: polls, events and rewards.
 */

/* -------------------------------------------------------------- polls */

function admin_poll_row(array $p)
{
    $options = array_map(function ($o) {
        return ['id' => (int)$o['id'], 'label' => $o['label'], 'votes' => (int)$o['votes_count']];
    }, q_all('SELECT * FROM poll_options WHERE poll_id = ? ORDER BY sort_order, id', [$p['id']]));
    $ended = $p['status'] === 'closed' || ($p['ends_at'] && strtotime($p['ends_at'] . ' UTC') <= time());
    return [
        'id'                 => (int)$p['id'],
        'question'           => $p['question'],
        'description'        => $p['description'],
        'is_multiple'        => (int)$p['is_multiple'] === 1,
        'results_visibility' => $p['results_visibility'],
        'status'             => $p['status'],
        'is_ended'           => $ended,
        'ends_at'            => iso($p['ends_at']),
        'created_at'         => iso($p['created_at']),
        'voters'             => (int)q_val('SELECT COUNT(DISTINCT user_id) FROM poll_votes WHERE poll_id = ?', [$p['id']]),
        'options'            => $options,
    ];
}

function api_admin_polls()
{
    current_admin();
    return array_map('admin_poll_row', q_all('SELECT * FROM polls ORDER BY id DESC'));
}

function api_admin_poll_save()
{
    $admin = current_admin();
    $id = in_int('id');
    $question = in_str('question', 255, true, 'Question');
    $description = in_text('description', 1000);
    $isMultiple = in_bool('is_multiple') ? 1 : 0;
    $visibility = in_enum('results_visibility', ['always', 'after_vote', 'after_close'], 'after_vote');
    $endsAt = parse_dt(input('ends_at', ''));
    $status = in_enum('status', ['open', 'closed'], 'open');

    $optionsIn = input('options', []);
    if (is_string($optionsIn)) {
        $optionsIn = json_decode($optionsIn, true);
    }
    $options = [];
    foreach ((array)$optionsIn as $o) {
        $label = is_array($o) ? (isset($o['label']) ? $o['label'] : '') : $o;
        $label = str_limit(clean_string($label), 150, '');
        if ($label === '') {
            continue;
        }
        $options[] = ['id' => is_array($o) && isset($o['id']) ? (int)$o['id'] : 0, 'label' => $label];
    }
    if (count($options) < 2) {
        fail('A poll needs at least two options.', 422);
    }
    if (count($options) > 12) {
        fail('A poll can have up to 12 options.', 422);
    }

    return tx(function () use ($admin, $id, $question, $description, $isMultiple, $visibility, $endsAt, $status, $options) {
        if ($id > 0) {
            $poll = q_one('SELECT * FROM polls WHERE id = ?', [$id]);
            if (!$poll) {
                fail('Poll not found.', 404);
            }
            q('UPDATE polls SET question = ?, description = ?, is_multiple = ?, results_visibility = ?, ends_at = ?, status = ? WHERE id = ?',
                [$question, $description ?: null, $isMultiple, $visibility, $endsAt, $status, $id]);
            $keep = [];
            foreach ($options as $i => $o) {
                if ($o['id'] > 0 && q_val('SELECT 1 FROM poll_options WHERE id = ? AND poll_id = ?', [$o['id'], $id])) {
                    q('UPDATE poll_options SET label = ?, sort_order = ? WHERE id = ?', [$o['label'], $i, $o['id']]);
                    $keep[] = $o['id'];
                } else {
                    $keep[] = q_insert('INSERT INTO poll_options (poll_id, label, sort_order, votes_count) VALUES (?, ?, ?, 0)', [$id, $o['label'], $i]);
                }
            }
            // Options removed in the editor are deleted only when nobody voted for them.
            foreach (q_all('SELECT id, votes_count FROM poll_options WHERE poll_id = ? AND id NOT IN (' . placeholders($keep) . ')', array_merge([$id], $keep)) as $old) {
                if ((int)$old['votes_count'] > 0) {
                    fail('You cannot remove an option that already has votes.', 422);
                }
                q('DELETE FROM poll_options WHERE id = ?', [$old['id']]);
            }
        } else {
            $id = q_insert('INSERT INTO polls (question, description, is_multiple, results_visibility, status, ends_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$question, $description ?: null, $isMultiple, $visibility, $status, $endsAt, $admin['id'], now()]);
            foreach ($options as $i => $o) {
                q('INSERT INTO poll_options (poll_id, label, sort_order, votes_count) VALUES (?, ?, ?, 0)', [$id, $o['label'], $i]);
            }
            if (in_bool('notify')) {
                notify_all('poll', 'New poll: ' . $question, 'Cast your vote and earn 10 XP.', '#/polls');
            }
        }
        return admin_poll_row(q_one('SELECT * FROM polls WHERE id = ?', [$id]));
    });
}

function api_admin_poll_status()
{
    current_admin();
    $id = require_id();
    q('UPDATE polls SET status = ? WHERE id = ?', [in_enum('status', ['open', 'closed']), $id]);
    return admin_poll_row(q_one('SELECT * FROM polls WHERE id = ?', [$id]));
}

function api_admin_poll_delete()
{
    current_admin();
    q('DELETE FROM polls WHERE id = ?', [require_id()]);
    return ['deleted' => true];
}

/* ------------------------------------------------------------- events */

function admin_event_row(array $e)
{
    $reward = $e['reward_id'] ? q_one('SELECT id, title FROM rewards WHERE id = ?', [$e['reward_id']]) : null;
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
        'reward_id'        => $e['reward_id'] ? (int)$e['reward_id'] : null,
        'reward_title'     => $reward ? $reward['title'] : null,
        'max_participants' => $e['max_participants'] !== null ? (int)$e['max_participants'] : null,
        'is_published'     => (int)$e['is_published'] === 1,
        'participants'     => (int)q_val('SELECT COUNT(*) FROM event_participants WHERE event_id = ?', [$e['id']]),
        'claimed'          => (int)q_val('SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND reward_claimed_at IS NOT NULL', [$e['id']]),
        'created_at'       => iso($e['created_at']),
    ];
}

function api_admin_events()
{
    current_admin();
    return array_map('admin_event_row', q_all('SELECT * FROM events ORDER BY starts_at DESC'));
}

function api_admin_event_save()
{
    current_admin();
    $id = in_int('id');
    $title = in_str('title', 150, true, 'Title');
    $description = in_text('description', 5000);
    $type = in_str('event_type', 30) ?: 'Community';
    $location = in_str('location', 150);
    $startsAt = parse_dt(input('starts_at', ''));
    if (!$startsAt) {
        fail('Start date/time is required.', 422);
    }
    $endsAt = parse_dt(input('ends_at', ''));
    if ($endsAt && strtotime($endsAt) <= strtotime($startsAt)) {
        fail('The end time must be after the start time.', 422);
    }
    $rewardXp = max(0, min(100000, in_int('reward_xp')));
    $rewardId = in_int('reward_id');
    if ($rewardId > 0 && !q_val('SELECT 1 FROM rewards WHERE id = ?', [$rewardId])) {
        fail('The selected reward does not exist.', 422);
    }
    $max = in_int('max_participants');
    $published = in_bool('is_published', true) ? 1 : 0;

    $existing = $id > 0 ? q_one('SELECT * FROM events WHERE id = ?', [$id]) : null;
    if ($id > 0 && !$existing) {
        fail('Event not found.', 404);
    }
    $banner = in_url('banner_url', 700, false, 'Banner link');
    $oldBanner = $existing ? (string)$existing['banner'] : '';
    if (Upload::has('banner')) {
        $banner = Upload::image('banner', 'events', ['max' => 1600]);
    } elseif ($banner === '' && !in_bool('remove_banner')) {
        $banner = $oldBanner;
    }
    if ($oldBanner !== '' && $oldBanner !== $banner) {
        Upload::delete($oldBanner);
    }
    $params = [$title, $description ?: null, $type, $banner ?: null, $location ?: null, $startsAt, $endsAt, $rewardXp,
        $rewardId > 0 ? $rewardId : null, $max > 0 ? $max : null, $published];
    if ($existing) {
        $params[] = $id;
        q('UPDATE events SET title = ?, description = ?, event_type = ?, banner = ?, location = ?, starts_at = ?, ends_at = ?, reward_xp = ?,
            reward_id = ?, max_participants = ?, is_published = ? WHERE id = ?', $params);
    } else {
        $params[] = now();
        $id = q_insert('INSERT INTO events (title, description, event_type, banner, location, starts_at, ends_at, reward_xp, reward_id,
            max_participants, is_published, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $params);
        if ($published && in_bool('notify')) {
            notify_all('event', 'New event: ' . $title, $rewardXp ? 'Join and earn ' . $rewardXp . ' XP.' : 'Join the event!', '#/events');
        }
    }
    return admin_event_row(q_one('SELECT * FROM events WHERE id = ?', [$id]));
}

function api_admin_event_delete()
{
    current_admin();
    $e = q_one('SELECT * FROM events WHERE id = ?', [require_id()]);
    if (!$e) {
        fail('Event not found.', 404);
    }
    Upload::delete($e['banner']);
    q('DELETE FROM events WHERE id = ?', [$e['id']]);
    return ['deleted' => true];
}

function api_admin_event_participants()
{
    current_admin();
    $rows = q_all('SELECT p.*, u.name, u.username, u.email, u.avatar FROM event_participants p JOIN users u ON u.id = p.user_id
        WHERE p.event_id = ? ORDER BY p.joined_at', [require_id()]);
    return array_map(function ($r) {
        return ['user_id' => (int)$r['user_id'], 'name' => $r['name'], 'username' => $r['username'], 'email' => $r['email'],
            'avatar' => $r['avatar'], 'joined_at' => iso($r['joined_at']), 'claimed_at' => iso($r['reward_claimed_at'])];
    }, $rows);
}

/* ------------------------------------------------------------ rewards */

function admin_reward_row(array $r)
{
    return [
        'id'             => (int)$r['id'],
        'title'          => $r['title'],
        'description'    => $r['description'],
        'reward_type'    => $r['reward_type'],
        'icon'           => $r['icon'],
        'style'          => $r['style'],
        'required_level' => (int)$r['required_level'],
        'secret_code'    => $r['secret_code'],
        'quantity'       => $r['quantity'] !== null ? (int)$r['quantity'] : null,
        'claimed_count'  => (int)$r['claimed_count'],
        'is_event_only'  => (int)$r['is_event_only'] === 1,
        'is_active'      => (int)$r['is_active'] === 1,
        'expires_at'     => iso($r['expires_at']),
        'created_at'     => iso($r['created_at']),
    ];
}

function api_admin_rewards()
{
    current_admin();
    return array_map('admin_reward_row', q_all('SELECT * FROM rewards ORDER BY required_level, id'));
}

function api_admin_reward_save()
{
    current_admin();
    $id = in_int('id');
    $title = in_str('title', 120, true, 'Title');
    $description = in_str('description', 500);
    $type = in_enum('reward_type', ['badge', 'title', 'frame', 'code'], 'badge');
    $style = in_str('style', 30);
    $level = max(1, min(200, in_int('required_level', 1)));
    $code = in_str('secret_code', 120);
    if ($type === 'code' && $code === '') {
        fail('Enter the code that players receive when they claim this reward.', 422);
    }
    $quantity = in_int('quantity');
    $expires = parse_dt(input('expires_at', ''));
    $existing = $id > 0 ? q_one('SELECT * FROM rewards WHERE id = ?', [$id]) : null;
    if ($id > 0 && !$existing) {
        fail('Reward not found.', 404);
    }
    $icon = in_str('icon', 700);
    $oldIcon = $existing ? (string)$existing['icon'] : '';
    if (Upload::has('icon_file')) {
        $icon = Upload::image('icon_file', 'rewards', ['square' => 256]);
    } elseif ($icon === '' && $existing) {
        $icon = $oldIcon;
    }
    if (strpos($oldIcon, 'uploads/') === 0 && $oldIcon !== $icon) {
        Upload::delete($oldIcon);
    }
    $params = [$title, $description ?: null, $type, $icon ?: null, $style ?: null, $level, $code ?: null, $quantity > 0 ? $quantity : null,
        in_bool('is_event_only') ? 1 : 0, in_bool('is_active', true) ? 1 : 0, $expires];
    if ($existing) {
        $params[] = $id;
        q('UPDATE rewards SET title = ?, description = ?, reward_type = ?, icon = ?, style = ?, required_level = ?, secret_code = ?, quantity = ?,
            is_event_only = ?, is_active = ?, expires_at = ? WHERE id = ?', $params);
    } else {
        $params[] = now();
        $id = q_insert('INSERT INTO rewards (title, description, reward_type, icon, style, required_level, secret_code, quantity, is_event_only,
            is_active, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $params);
        if (in_bool('notify') && !in_bool('is_event_only')) {
            notify_all('reward', 'New reward: ' . $title, 'Unlocks at level ' . $level . '. Claim it in Events & Rewards.', '#/events?tab=rewards');
        }
    }
    return admin_reward_row(q_one('SELECT * FROM rewards WHERE id = ?', [$id]));
}

function api_admin_reward_delete()
{
    current_admin();
    $r = q_one('SELECT * FROM rewards WHERE id = ?', [require_id()]);
    if (!$r) {
        fail('Reward not found.', 404);
    }
    if (strpos((string)$r['icon'], 'uploads/') === 0) {
        Upload::delete($r['icon']);
    }
    q('UPDATE users SET equipped_title_id = NULL WHERE equipped_title_id = ?', [$r['id']]);
    q('UPDATE users SET equipped_frame_id = NULL WHERE equipped_frame_id = ?', [$r['id']]);
    q('UPDATE events SET reward_id = NULL WHERE reward_id = ?', [$r['id']]);
    q('DELETE FROM rewards WHERE id = ?', [$r['id']]);
    return ['deleted' => true];
}

function api_admin_reward_claims()
{
    current_admin();
    $rows = q_all('SELECT ur.*, u.name, u.username, u.email FROM user_rewards ur JOIN users u ON u.id = ur.user_id
        WHERE ur.reward_id = ? ORDER BY ur.claimed_at DESC', [require_id()]);
    return array_map(function ($r) {
        return ['user_id' => (int)$r['user_id'], 'name' => $r['name'], 'username' => $r['username'], 'email' => $r['email'],
            'claimed_at' => iso($r['claimed_at']), 'source' => $r['source']];
    }, $rows);
}

function api_admin_reward_grant()
{
    current_admin();
    $rewardId = require_id('reward_id');
    $username = strtolower(in_str('username', 190, true, 'Username or email'));
    $user = q_one('SELECT id, name FROM users WHERE username = ? OR email = ?', [$username, $username]);
    if (!$user) {
        fail('Player not found.', 404);
    }
    $reward = q_one('SELECT * FROM rewards WHERE id = ?', [$rewardId]);
    if (!$reward) {
        fail('Reward not found.', 404);
    }
    if (!q_exec('INSERT IGNORE INTO user_rewards (user_id, reward_id, claimed_at, source) VALUES (?, ?, ?, ?)', [$user['id'], $rewardId, now(), 'admin'])) {
        fail($user['name'] . ' already has this reward.', 409);
    }
    q('UPDATE rewards SET claimed_count = claimed_count + 1 WHERE id = ?', [$rewardId]);
    notify($user['id'], 'reward', 'You received a reward: ' . $reward['title'], 'A gift from the admins!', '#/events?tab=rewards');
    return ['granted' => true];
}

return [
    'polls'              => ['GET', 'admin', 'api_admin_polls'],
    'poll-save'          => ['POST', 'admin', 'api_admin_poll_save'],
    'poll-status'        => ['POST', 'admin', 'api_admin_poll_status'],
    'poll-delete'        => ['POST', 'admin', 'api_admin_poll_delete'],
    'events'             => ['GET', 'admin', 'api_admin_events'],
    'event-save'         => ['POST', 'admin', 'api_admin_event_save'],
    'event-delete'       => ['POST', 'admin', 'api_admin_event_delete'],
    'event-participants' => ['GET', 'admin', 'api_admin_event_participants'],
    'rewards'            => ['GET', 'admin', 'api_admin_rewards'],
    'reward-save'        => ['POST', 'admin', 'api_admin_reward_save'],
    'reward-delete'      => ['POST', 'admin', 'api_admin_reward_delete'],
    'reward-claims'      => ['GET', 'admin', 'api_admin_reward_claims'],
    'reward-grant'       => ['POST', 'admin', 'api_admin_reward_grant'],
];
