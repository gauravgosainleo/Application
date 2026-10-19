<?php
/*
 * Starter content inserted by install.php (only into empty tables).
 * Everything here can be edited or removed from the admin panel.
 */

function gvi_seed_admin($username, $password)
{
    $existing = q_one('SELECT id FROM admins WHERE username = ?', [$username]);
    if ($existing) {
        q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $existing['id']]);
        return;
    }
    q("INSERT INTO admins (username, password_hash, display_name, created_at) VALUES (?, ?, 'Administrator', ?)",
        [$username, password_hash($password, PASSWORD_DEFAULT), now()]);
}

function gvi_seed_settings()
{
    $defaults = [
        'release_at'                => '2026-11-19T00:00:00',
        'release_title'             => 'Grand Theft Auto VI',
        'release_platforms'         => 'PlayStation 5 · Xbox Series X|S',
        'announcement'              => 'Welcome to G VI Fan Hub! GTA VI launches on 19 November 2026 — pin your must-visit spots on the Leonida map.',
        'login_otp_mode'            => 'optional',
        'gallery_requires_approval' => '1',
        'map_image'                 => '',
        'map_width'                 => '2000',
        'map_height'                => '2600',
        'news_auto_enabled'         => '1',
        'news_interval_hours'       => '12',
        'news_keywords'             => 'GTA 6, GTA VI, GTA6, Grand Theft Auto VI, Grand Theft Auto 6',
        'news_last_run_at'          => '',
    ];
    foreach ($defaults as $key => $value) {
        q('INSERT IGNORE INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)', [$key, $value, now()]);
    }
}

function gvi_table_empty($table)
{
    return (int)q_val('SELECT COUNT(*) FROM ' . $table) === 0;
}

function gvi_seed_content()
{
    $now = now();

    if (gvi_table_empty('news_sources')) {
        $sources = [
            ['Google News (GTA 6)', 'https://news.google.com/rss/search?q=%22GTA+6%22+OR+%22GTA+VI%22+OR+%22Grand+Theft+Auto+VI%22&hl=en-US&gl=US&ceid=US:en', 'https://news.google.com'],
            ['Rockstar Intel', 'https://www.rockstarintel.com/feed', 'https://www.rockstarintel.com'],
            ['IGN', 'https://feeds.feedburner.com/ign/games-all', 'https://www.ign.com'],
            ['GameSpot', 'https://www.gamespot.com/feeds/game-news', 'https://www.gamespot.com'],
            ['Polygon', 'https://www.polygon.com/rss/index.xml', 'https://www.polygon.com'],
            ['Eurogamer', 'https://www.eurogamer.net/feed', 'https://www.eurogamer.net'],
            ['PC Gamer', 'https://www.pcgamer.com/rss/', 'https://www.pcgamer.com'],
            ['GamesRadar+', 'https://www.gamesradar.com/rss/', 'https://www.gamesradar.com'],
            ['VGC', 'https://www.videogameschronicle.com/feed/', 'https://www.videogameschronicle.com'],
            ['Push Square', 'https://www.pushsquare.com/feeds/latest', 'https://www.pushsquare.com'],
        ];
        foreach ($sources as $s) {
            q('INSERT INTO news_sources (name, feed_url, site_url, enabled, created_at) VALUES (?, ?, ?, 1, ?)', [$s[0], $s[1], $s[2], $now]);
        }
    }

    if (gvi_table_empty('news')) {
        $articles = [
            [
                'Grand Theft Auto VI launches November 19, 2026',
                'Rockstar Games has confirmed that Grand Theft Auto VI will release on Thursday, November 19, 2026 for PlayStation 5 and Xbox Series X|S.',
                "Mark your calendars: Rockstar Games has set Thursday, November 19, 2026 as the launch date for Grand Theft Auto VI on PlayStation 5 and Xbox Series X|S.\n\nThe story is set in the state of Leonida, home to the neon-soaked streets of Vice City and beyond, and follows Lucia and Jason.\n\nUse the countdown on the home screen to keep track of the days left until launch.",
                'Rockstar Games', 'https://www.rockstargames.com/VI', '2026-10-01 12:00:00', 1,
                'https://i.ytimg.com/vi/VQRLujxTm3c/maxresdefault.jpg',
            ],
            [
                'Rockstar releases the official GTA VI Launch Trailer',
                'The Launch Trailer for Grand Theft Auto VI premiered on the official Rockstar Games YouTube channel on October 15, 2026.',
                "Rockstar Games has published the official Launch Trailer for Grand Theft Auto VI on its YouTube channel.\n\nWatch it in the Trailers section of the app and share your favourite moments in Discussions.",
                'Rockstar Games (YouTube)', 'https://www.youtube.com/watch?v=rz1B4B2yBZY', '2026-10-15 13:00:00', 0,
                'https://i.ytimg.com/vi/rz1B4B2yBZY/maxresdefault.jpg',
            ],
            [
                'Grand Theft Auto VI: An Extended Look is out now',
                'Rockstar Games premiered "Grand Theft Auto VI: An Extended Look" on August 27, 2026.',
                "Rockstar Games shared \"Grand Theft Auto VI: An Extended Look\" on August 27, 2026.\n\nYou can watch it from the Trailers section.",
                'Rockstar Games (YouTube)', 'https://www.youtube.com/watch?v=tJbzMqJGH4k', '2026-08-28 01:00:00', 0,
                'https://i.ytimg.com/vi/tJbzMqJGH4k/maxresdefault.jpg',
            ],
            [
                'Official GTA VI cover art revealed',
                'Rockstar Games revealed the official cover art for Grand Theft Auto VI on June 18, 2026.',
                "Rockstar Games unveiled the official cover art for Grand Theft Auto VI on June 18, 2026 with a reveal video on its YouTube channel.",
                'Rockstar Games (YouTube)', 'https://www.youtube.com/watch?v=INjHGpMRjRA', '2026-06-18 13:00:00', 0,
                'https://i.ytimg.com/vi/INjHGpMRjRA/maxresdefault.jpg',
            ],
        ];
        foreach ($articles as $a) {
            q("INSERT INTO news (title, summary, content, image_url, source_name, source_url, origin, status, is_featured, title_hash, published_at, created_at, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, 'manual', 'published', ?, ?, ?, ?, ?)",
                [$a[0], $a[1], $a[2], $a[7], $a[3], $a[4], $a[6], sha1(preg_replace('/[^a-z0-9]/', '', strtolower($a[0]))), $a[5], $a[5], $now]);
        }
    }

    if (gvi_table_empty('trailers')) {
        $trailers = [
            ['Grand Theft Auto VI Launch Trailer', 'The official Launch Trailer from Rockstar Games.', 'rz1B4B2yBZY', '2026-10-15', 1, 1],
            ['Grand Theft Auto VI: An Extended Look', 'An extended look at Grand Theft Auto VI from Rockstar Games.', 'tJbzMqJGH4k', '2026-08-27', 0, 2],
            ['Grand Theft Auto VI Trailer 2', 'The second official trailer, following Jason and Lucia across Leonida.', 'VQRLujxTm3c', '2025-05-06', 0, 3],
            ['Grand Theft Auto VI Trailer 1', 'The first official trailer that introduced Lucia and the state of Leonida.', 'QdBZY2fkU-0', '2023-12-04', 0, 4],
        ];
        foreach ($trailers as $t) {
            q('INSERT INTO trailers (title, description, youtube_id, released_on, is_featured, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $now]);
        }
    }

    if (gvi_table_empty('catalogue_categories')) {
        $expected = 'A series staple we expect to see in GTA VI. Final names, stats and prices will be updated after launch.';
        $catalogue = [
            ['Vehicles', '🚗', 'Cars, bikes and trucks of Leonida.', [
                ['Supercar', 'Super', 'Top-tier speed for the Vice City strip.', 2400000, [95, 92, 85, 80]],
                ['Sports Coupe', 'Sports', 'Balanced, quick and stylish.', 480000, [82, 78, 75, 70]],
                ['Muscle Car', 'Muscle', 'Loud, heavy and brutal off the line.', 210000, [80, 84, 60, 58]],
                ['Off-Road Truck', 'Off-road', 'Built for the backroads around Mount Kalaga.', 95000, [68, 64, 62, 60]],
                ['Police Cruiser', 'Emergency', 'The ride you will see in your rear-view mirror.', null, [79, 72, 70, 74]],
                ['Sport Bike', 'Motorcycle', 'Lightning acceleration on two wheels.', 120000, [90, 94, 72, 65]],
            ], ['Top Speed', 'Acceleration', 'Handling', 'Braking']],
            ['Boats & Aircraft', '🚤', 'Explore the coast, the Keys and the skies.', [
                ['Airboat', 'Boat', 'Glide across the swamps of the Grassrivers.', 65000, [55, 60, 70, 50]],
                ['Speedboat', 'Boat', 'Made for the Leonida Keys.', 310000, [85, 80, 65, 55]],
                ['Jet Ski', 'Personal watercraft', 'Beach-side fun around Vice City.', 18000, [70, 85, 80, 60]],
                ['Light Helicopter', 'Helicopter', 'Fast travel across the state.', 950000, [75, 70, 68, 60]],
            ], ['Top Speed', 'Acceleration', 'Handling', 'Braking']],
            ['Weapons', '🔫', 'Arsenal you are likely to find.', [
                ['Pistol', 'Handgun', 'Reliable sidearm.', 2500, [45, 55, 70, 40]],
                ['Pump Shotgun', 'Shotgun', 'Devastating at close range.', 3500, [85, 30, 40, 20]],
                ['Submachine Gun', 'SMG', 'High fire rate for drive-bys.', 6000, [40, 90, 50, 40]],
                ['Assault Rifle', 'Rifle', 'All-round firepower.', 9000, [65, 75, 60, 65]],
                ['Sniper Rifle', 'Sniper', 'Long range precision.', 15000, [95, 15, 90, 100]],
            ], ['Damage', 'Fire Rate', 'Accuracy', 'Range']],
            ['Equipment', '🎒', 'Gear and gadgets.', [
                ['Body Armor', 'Protection', 'Extra protection for heists.', 2500, [80]],
                ['Parachute', 'Utility', 'For skydiving and quick escapes.', 500, [60]],
                ['Smartphone', 'Utility', 'Your link to contacts, jobs and the in-game internet.', null, [70]],
                ['Scuba Gear', 'Utility', 'Explore under the waves of the Keys.', 4000, [65]],
            ], ['Usefulness']],
        ];
        $sort = 0;
        foreach ($catalogue as $cat) {
            $catId = q_insert('INSERT INTO catalogue_categories (name, slug, icon, description, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$cat[0], slugify($cat[0]), $cat[1], $cat[2], $sort++, $now]);
            $itemSort = 0;
            foreach ($cat[3] as $item) {
                $stats = [];
                foreach ($cat[4] as $i => $label) {
                    if (isset($item[4][$i])) {
                        $stats[] = ['label' => $label, 'value' => $item[4][$i]];
                    }
                }
                q("INSERT INTO catalogue_items (category_id, name, subtype, description, price, status, stats, sort_order, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, 'Expected', ?, ?, ?, ?)",
                    [$catId, $item[0], $item[1], $item[2] . "\n\n" . $expected, $item[3], json_encode($stats), $itemSort++, $now, $now]);
            }
        }
    }

    if (gvi_table_empty('rewards')) {
        $rewards = [
            // title, description, type, icon, style, level, event_only
            ['Welcome Recruit', 'You joined the G VI Fan Hub crew.', 'badge', '🎖️', 'pink', 1, 0],
            ['Vice City Local', 'Show off a profile title.', 'title', '🌴', null, 3, 0],
            ['Neon Frame', 'A glowing neon avatar frame.', 'frame', '💠', 'neon', 5, 0],
            ['Sunset Frame', 'Vice City sunset colours around your avatar.', 'frame', '🌅', 'sunset', 8, 0],
            ['Heist Planner', 'A badge for dedicated planners.', 'badge', '💰', 'gold', 10, 0],
            ['Leonida Legend', 'An elite profile title.', 'title', '👑', null, 15, 0],
            ['Gold Frame', 'A solid gold avatar frame.', 'frame', '🏆', 'gold', 20, 0],
            ['Day One Crew', 'Joined the launch night watch party.', 'badge', '🚀', 'teal', 1, 1],
            ['Map Explorer', 'Completed the Leonida Map Hunt.', 'badge', '🗺️', 'teal', 1, 1],
        ];
        foreach ($rewards as $r) {
            q('INSERT INTO rewards (title, description, reward_type, icon, style, required_level, is_event_only, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
                [$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $now]);
        }
    }

    if (gvi_table_empty('events')) {
        $dayOne = q_val("SELECT id FROM rewards WHERE title = 'Day One Crew'");
        $explorer = q_val("SELECT id FROM rewards WHERE title = 'Map Explorer'");
        $events = [
            ['Launch Night Watch Party', "Count down the final hours to Grand Theft Auto VI together. Join the party, hang out in chat with your crew and claim the exclusive Day One Crew badge once the event starts.",
                'Launch', 'G VI Fan Hub · Friends & Chat', '2026-11-18 22:00:00', '2026-11-19 06:00:00', 250, $dayOne],
            ['Leonida Map Hunt', "Pin your five must-visit spots from the trailers on the interactive map and share them publicly. Join the event and claim the Map Explorer badge.",
                'Contest', 'Interactive Map', gmdate('Y-m-d H:i:s', time() - 3600), '2026-11-18 23:59:00', 100, $explorer],
            ['Launch Trailer Breakdown', "Rewatch the Launch Trailer, post your best theory in Discussions and talk it through with the community.",
                'Community', 'Discussions', gmdate('Y-m-d H:i:s', time() + 5 * 86400), gmdate('Y-m-d H:i:s', time() + 5 * 86400 + 4 * 3600), 75, null],
        ];
        foreach ($events as $e) {
            q('INSERT INTO events (title, description, event_type, location, starts_at, ends_at, reward_xp, reward_id, is_published, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)', [$e[0], $e[1], $e[2], $e[3], $e[4], $e[5], $e[6], $e[7] ?: null, $now]);
        }
    }

    if (gvi_table_empty('polls')) {
        $polls = [
            ['Who are you most excited to play as?', null, 0, 'after_vote', ['Lucia', 'Jason', 'Both equally']],
            ['Where will you play GTA VI on day one?', null, 0, 'after_vote', ['PlayStation 5', 'PlayStation 5 Pro', 'Xbox Series X', 'Xbox Series S', 'Waiting for PC']],
            ['What is your launch day plan?', 'Pick everything that applies.', 1, 'always', ['Midnight launch, no sleep', 'Taking the day off', 'Weekend marathon', 'Waiting for reviews']],
        ];
        foreach ($polls as $p) {
            $pid = q_insert("INSERT INTO polls (question, description, is_multiple, results_visibility, status, created_at) VALUES (?, ?, ?, ?, 'open', ?)",
                [$p[0], $p[1], $p[2], $p[3], $now]);
            foreach ($p[4] as $i => $label) {
                q('INSERT INTO poll_options (poll_id, label, sort_order, votes_count) VALUES (?, ?, ?, 0)', [$pid, $label, $i]);
            }
        }
    }
}
