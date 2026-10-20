<?php
// ============================================================================
// includes/app_feed.php - "what became public" for the Android app  (v1.8.0)
//
// One list of everything residents can newly see since a moment in time:
// notices, events, photo albums and uploads, polls, society ads, AOA updates,
// vendors, SPOC contacts and finance entries. It has two consumers:
//
//   - api/app_updates.php, which phones poll in the background, and
//   - includes/app_push.php, which sends the same items to every phone
//     instantly through Firebase Cloud Messaging once push is set up.
//
// Keeping a single source means a new kind of public content is added here
// once and both delivery paths pick it up.
//
// Neither path is individually authenticated: the poll key ships inside the
// APK and a push goes to every phone with the app installed. So items carry
// only generic text and a link - never notice titles, resident names, album
// names or vendor details. The resident signs in to read the real content.
//
// Items: {id, tag, type, title, body, url, ts}
//   id   unique per notification; phones never show the same id twice.
//   tag  the notification "slot". Usually equal to id. Batched kinds (photos
//        per album, SPOC edits, finance entries) share one tag, so a later
//        batch replaces the earlier notification instead of stacking up.
// ============================================================================

/** Every kind of public content, with the label the admin sees and its page. */
function uv_app_feed_types(): array {
    return [
        'notice'  => ['label' => 'Society notices',       'page' => 'modules/notices.php'],
        'event'   => ['label' => 'Events',                'page' => 'modules/calendar.php'],
        'gallery' => ['label' => 'Photo gallery',         'page' => 'modules/gallery.php'],
        'poll'    => ['label' => 'Polls',                 'page' => 'modules/polls.php'],
        'ad'      => ['label' => 'Society ads',           'page' => 'modules/ads.php'],
        'aoa'     => ['label' => 'AOA changes',           'page' => 'modules/aoa_changes.php'],
        'vendor'  => ['label' => 'Vendor directory',      'page' => 'modules/vendors.php'],
        'spoc'    => ['label' => 'SPOC contacts',         'page' => 'modules/spocs.php'],
        'finance' => ['label' => 'Finance & expenses',    'page' => 'modules/finance.php'],
    ];
}

/**
 * Minimum gap between two pushes for the same batched tag, in seconds.
 * A resident uploading twenty photos one by one should produce one alert and
 * a later silent update, not twenty alerts. Per-item kinds have no cooldown.
 */
function uv_app_feed_cooldown(string $type): int {
    switch ($type) {
        case 'gallery': return 600;      // photo batches: at most every 10 minutes per album
        case 'spoc':    return 1800;     // SPOC edits usually come several at a time
        case 'finance': return 3 * 3600; // the accounts are entered in one sitting
        default:        return 0;
    }
}

/**
 * Items that became public at or after $since (unix time, database clock),
 * newest first, at most $limit. $types limits the kinds (null = all).
 */
function uv_app_feed_items(PDO $pdo, int $since, ?array $types = null, int $limit = 20): array {
    $on = array_flip($types ?? array_keys(uv_app_feed_types()));
    $items = [];

    // $since is an int we cast ourselves, so it is inlined (native prepares
    // reject placeholders inside some date expressions). A missing table -
    // a module nobody has opened yet on this install - simply yields nothing.
    $q = function (string $sql) use ($pdo, $since): array {
        try {
            return $pdo->query(str_replace(':since', (string)$since, $sql))->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    };
    $add = function (string $id, string $type, string $title, string $body, string $page, int $ts, ?string $tag = null) use (&$items) {
        $items[] = [
            'id'    => $id,
            'tag'   => $tag ?? $id,
            'type'  => $type,
            'title' => $title,
            'body'  => $body,
            'url'   => APP_URL . '/' . $page,
            'ts'    => $ts,
        ];
    };
    $view = 'Tap to view it in Uni-Vishwas.';

    if (isset($on['notice'])) {
        foreach ($q('SELECT id, UNIX_TIMESTAMP(created_at) ts FROM notices
                     WHERE created_at >= FROM_UNIXTIME(:since) ORDER BY id DESC LIMIT 10') as $r) {
            $add('notice:' . $r['id'], 'notice', 'New society notice', $view, 'modules/notices.php', (int)$r['ts']);
        }
    }

    if (isset($on['event'])) {
        foreach ($q('SELECT id, UNIX_TIMESTAMP(created_at) ts FROM events
                     WHERE created_at >= FROM_UNIXTIME(:since) ORDER BY id DESC LIMIT 10') as $r) {
            $add('event:' . $r['id'], 'event', 'New society event', 'Tap to see it in the event calendar.', 'modules/calendar.php', (int)$r['ts']);
        }
    }

    if (isset($on['gallery'])) {
        foreach ($q('SELECT id, UNIX_TIMESTAMP(created_at) ts FROM gallery_albums
                     WHERE created_at >= FROM_UNIXTIME(:since) ORDER BY id DESC LIMIT 5') as $r) {
            $add('album:' . $r['id'], 'gallery', 'New photo album', 'Tap to open it and add your photos.', 'modules/gallery.php?album=' . (int)$r['id'], (int)$r['ts']);
        }

        // Uploads are batched per album: one notification slot per album
        // ("gallery:<album>") whose id moves on with the newest upload, so a
        // bulk upload is a single notification that updates in place.
        foreach ($q('SELECT m.album_id, COUNT(*) c, SUM(m.media_type = \'video\') v, UNIX_TIMESTAMP(MAX(m.created_at)) ts
                     FROM gallery_media m JOIN gallery_albums a ON a.id = m.album_id
                     WHERE m.created_at >= FROM_UNIXTIME(:since)
                     GROUP BY m.album_id ORDER BY ts DESC LIMIT 5') as $r) {
            $album = (int)$r['album_id'];
            $c = (int)$r['c'];
            $v = (int)$r['v'];
            if ($v === 0)      $what = $c === 1 ? '1 new photo' : "$c new photos";
            elseif ($v === $c) $what = $c === 1 ? '1 new video' : "$c new videos";
            else               $what = "$c new photos and videos";
            $add('photos:' . $album . ':' . (int)$r['ts'], 'gallery', $what . ' in the gallery', 'Tap to view them in Uni-Vishwas.',
                 'modules/gallery.php?album=' . $album, (int)$r['ts'], 'gallery:' . $album);
        }
    }

    if (isset($on['poll'])) {
        foreach ($q('SELECT id, UNIX_TIMESTAMP(created_at) ts FROM polls
                     WHERE active = 1 AND created_at >= FROM_UNIXTIME(:since) ORDER BY id DESC LIMIT 5') as $r) {
            $add('poll:' . $r['id'], 'poll', 'New society poll', 'Tap to cast your vote.', 'modules/polls.php', (int)$r['ts']);
        }
    }

    if (isset($on['ad'])) {
        foreach ($q('SELECT id, UNIX_TIMESTAMP(created_at) ts FROM advertisements
                     WHERE status = \'active\' AND created_at >= FROM_UNIXTIME(:since) ORDER BY id DESC LIMIT 5') as $r) {
            $add('ad:' . $r['id'], 'ad', 'New society ad', $view, 'modules/ads.php', (int)$r['ts']);
        }
    }

    if (isset($on['aoa'])) {
        foreach ($q('SELECT id, UNIX_TIMESTAMP(created_at) ts FROM aoa_changes
                     WHERE created_at >= FROM_UNIXTIME(:since) ORDER BY id DESC LIMIT 5') as $r) {
            $add('aoa:' . $r['id'], 'aoa', 'New AOA update', $view, 'modules/aoa_changes.php', (int)$r['ts']);
        }
    }

    if (isset($on['vendor'])) {
        foreach ($q('SELECT id, UNIX_TIMESTAMP(created_at) ts FROM vendors
                     WHERE created_at >= FROM_UNIXTIME(:since) ORDER BY id DESC LIMIT 5') as $r) {
            $add('vendor:' . $r['id'], 'vendor', 'Vendor directory updated', 'A new vendor was added. ' . $view, 'modules/vendors.php', (int)$r['ts']);
        }
    }

    // SPOC contacts change by editing a row as often as by adding one, so
    // updated_at (which equals created_at for a new row) drives this batch.
    if (isset($on['spoc'])) {
        foreach ($q('SELECT COUNT(*) c, UNIX_TIMESTAMP(MAX(updated_at)) ts FROM society_spocs
                     WHERE updated_at >= FROM_UNIXTIME(:since)') as $r) {
            if ((int)$r['c'] > 0) {
                $add('spocs:' . (int)$r['ts'], 'spoc', 'Society contacts updated', 'Tap to see the latest SPOC contacts.', 'modules/spocs.php', (int)$r['ts'], 'spocs');
            }
        }
    }

    // Each table is read separately so a missing finance_incomes table (an
    // older install that never opened the page) does not hide the others.
    if (isset($on['finance'])) {
        $latest = 0;
        foreach ([
            'SELECT UNIX_TIMESTAMP(MAX(created_at)) ts FROM finance_incomes  WHERE created_at >= FROM_UNIXTIME(:since)',
            'SELECT UNIX_TIMESTAMP(MAX(created_at)) ts FROM finance_expenses WHERE created_at >= FROM_UNIXTIME(:since)',
            'SELECT UNIX_TIMESTAMP(MAX(updated_at)) ts FROM finance_months   WHERE updated_at >= FROM_UNIXTIME(:since)',
        ] as $sql) {
            foreach ($q($sql) as $r) $latest = max($latest, (int)$r['ts']);
        }
        if ($latest > 0) {
            $add('finance:' . $latest, 'finance', 'Society accounts updated', 'Tap to see the finance report.', 'modules/finance.php', $latest, 'finance');
        }
    }

    usort($items, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    return array_slice($items, 0, $limit);
}
