<?php
/*
 * Automatic news finder.
 * Reads the RSS/Atom feeds of trusted gaming sites, keeps the stories
 * that mention GTA 6 and queues them as "pending" for admin approval.
 * Every stored story keeps the name and link of its original source.
 *
 * Runs from: cron/fetch-news.php (Hostinger cron job), the admin
 * "Fetch now" button, or automatically after a normal app request when
 * the selected interval (6 / 12 / 24 hours) has passed.
 */

class NewsFetcher
{
    const MAX_NEW_PER_SOURCE = 10;
    const MAX_AGE_DAYS = 21;

    public static function keywords()
    {
        $raw = (string)setting('news_keywords', 'GTA 6, GTA VI, GTA6, Grand Theft Auto VI, Grand Theft Auto 6');
        $list = array_filter(array_map('trim', explode(',', $raw)));
        return $list ?: ['GTA 6', 'GTA VI'];
    }

    public static function intervalHours()
    {
        $h = (int)setting('news_interval_hours', 12);
        return in_array($h, [6, 12, 24], true) ? $h : 12;
    }

    public static function isDue()
    {
        if (setting('news_auto_enabled', '0') !== '1') {
            return false;
        }
        $last = setting('news_last_run_at');
        return !$last || strtotime($last . ' UTC') + self::intervalHours() * 3600 <= time();
    }

    public static function nextRunAt()
    {
        if (setting('news_auto_enabled', '0') !== '1') {
            return null;
        }
        $last = setting('news_last_run_at');
        return $last ? gmdate('Y-m-d H:i:s', strtotime($last . ' UTC') + self::intervalHours() * 3600) : now();
    }

    /** Called on normal requests: runs the fetch after the response if it is due. */
    public static function scheduleIfDue()
    {
        if (self::isDue()) {
            defer_job(function () {
                NewsFetcher::run('auto');
            });
        }
    }

    public static function run($trigger = 'manual')
    {
        @set_time_limit(300);
        $lockFile = STORAGE_DIR . '/news-fetch.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['trigger' => $trigger, 'skipped' => 'A fetch is already running.', 'added' => 0, 'sources' => []];
        }
        set_setting('news_last_run_at', now());
        $keywords = self::keywords();
        $summary = ['trigger' => $trigger, 'started_at' => iso(now()), 'added' => 0, 'sources' => []];

        foreach (q_all('SELECT * FROM news_sources WHERE enabled = 1 ORDER BY id') as $src) {
            $result = ['source' => $src['name'], 'found' => 0, 'matching' => 0, 'added' => 0, 'error' => null];
            try {
                $items = self::parse(self::download($src['feed_url']));
                $result['found'] = count($items);
                foreach ($items as $item) {
                    if (!self::matches($item, $keywords)) {
                        continue;
                    }
                    $result['matching']++;
                    if ($result['added'] < self::MAX_NEW_PER_SOURCE && self::store($item, $src)) {
                        $result['added']++;
                    }
                }
                $status = 'OK - ' . $result['found'] . ' items, ' . $result['matching'] . ' about GTA 6, ' . $result['added'] . ' new';
            } catch (Exception $e) {
                $result['error'] = $e->getMessage();
                $status = 'Error - ' . str_limit($e->getMessage(), 200);
            }
            q('UPDATE news_sources SET last_fetched_at = ?, last_status = ? WHERE id = ?', [now(), $status, $src['id']]);
            $summary['added'] += $result['added'];
            $summary['sources'][] = $result;
        }
        $summary['finished_at'] = iso(now());
        set_setting('news_last_run_log', json_encode($summary));
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $summary;
    }

    public static function download($url)
    {
        $ua = 'Mozilla/5.0 (compatible; GVIFanHub/' . APP_VERSION . '; +news-reader)';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => ['Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.5'],
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new Exception('Download failed: ' . $err);
            }
            if ($code >= 400) {
                throw new Exception('Feed returned HTTP ' . $code);
            }
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 25, 'user_agent' => $ua, 'follow_location' => 1, 'max_redirects' => 5]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                throw new Exception('Download failed (cURL is not available)');
            }
        }
        if (strlen($body) > 8 * 1048576) {
            throw new Exception('Feed is too large');
        }
        return $body;
    }

    /** Parses RSS 2.0 / RSS 1.0 / Atom into a list of plain arrays. */
    public static function parse($xml)
    {
        if (!function_exists('simplexml_load_string')) {
            throw new Exception('The PHP SimpleXML extension is not available');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new Exception('Not a valid RSS/Atom feed');
        }
        $items = [];
        $nodes = [];
        if (isset($doc->channel->item)) {
            foreach ($doc->channel->item as $n) {
                $nodes[] = $n;
            }
        }
        if (isset($doc->item)) {
            foreach ($doc->item as $n) {
                $nodes[] = $n;
            }
        }
        if (isset($doc->entry)) {
            foreach ($doc->entry as $n) {
                $nodes[] = $n;
            }
        }
        foreach ($nodes as $n) {
            $item = self::parseNode($n);
            if ($item['title'] !== '' && $item['link'] !== '') {
                $items[] = $item;
            }
        }
        return $items;
    }

    private static function parseNode(SimpleXMLElement $n)
    {
        $ns = $n->getNamespaces(true);
        $title = self::clean((string)$n->title);

        $link = trim((string)$n->link);
        if ($link === '' && isset($n->link)) {
            foreach ($n->link as $l) {
                $rel = (string)$l['rel'];
                if ($rel === '' || $rel === 'alternate') {
                    $link = (string)$l['href'];
                    break;
                }
            }
        }
        if ($link === '' && isset($n->guid) && valid_url((string)$n->guid)) {
            $link = (string)$n->guid;
        }

        $html = (string)$n->description;
        if ($html === '') {
            $html = (string)$n->summary;
        }
        $full = '';
        if (isset($ns['content'])) {
            $full = (string)$n->children($ns['content'])->encoded;
        }
        if ($full === '' && isset($n->content)) {
            $full = (string)$n->content;
        }
        if ($html === '') {
            $html = $full;
        }

        $date = (string)$n->pubDate;
        if ($date === '') {
            $date = (string)$n->published ?: (string)$n->updated;
        }
        if ($date === '' && isset($ns['dc'])) {
            $date = (string)$n->children($ns['dc'])->date;
        }

        $image = '';
        if (isset($ns['media'])) {
            $media = $n->children($ns['media']);
            if (isset($media->content)) {
                foreach ($media->content as $mc) {
                    $type = (string)$mc->attributes()->type;
                    $medium = (string)$mc->attributes()->medium;
                    if ($type === '' || strpos($type, 'image/') === 0 || $medium === 'image') {
                        $image = (string)$mc->attributes()->url;
                        break;
                    }
                }
            }
            if ($image === '' && isset($media->thumbnail)) {
                $image = (string)$media->thumbnail->attributes()->url;
            }
            if ($image === '' && isset($media->group->content)) {
                $image = (string)$media->group->content->attributes()->url;
            }
        }
        if ($image === '' && isset($n->enclosure)) {
            $type = (string)$n->enclosure['type'];
            if ($type === '' || strpos($type, 'image/') === 0) {
                $image = (string)$n->enclosure['url'];
            }
        }
        if ($image === '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html . ' ' . $full, $m)) {
            $image = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if ($image !== '' && !valid_url($image)) {
            $image = '';
        }

        $publisher = '';
        if (isset($n->source)) {
            $publisher = self::clean((string)$n->source);
        }

        return [
            'title'     => $title,
            'link'      => trim($link),
            'summary'   => str_limit(self::clean($html), 600),
            'date'      => $date,
            'image'     => $image,
            'publisher' => $publisher,
        ];
    }

    private static function clean($html)
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', (string)$html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string)$text);
    }

    public static function matches(array $item, array $keywords)
    {
        $haystack = $item['title'] . ' ' . $item['summary'];
        foreach ($keywords as $kw) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}])/iu', $haystack)) {
                return true;
            }
        }
        return false;
    }

    private static function store(array $item, array $src)
    {
        if (!valid_url($item['link'])) {
            return false;
        }
        $title = $item['title'];
        $sourceName = $src['name'];
        if ($item['publisher'] !== '') {
            // Aggregators (e.g. Google News) name the real publisher.
            $sourceName = $item['publisher'];
            $suffix = ' - ' . $item['publisher'];
            if (substr($title, -strlen($suffix)) === $suffix) {
                $title = trim(substr($title, 0, -strlen($suffix)));
            }
        }
        $title = str_limit($title, 250);
        $titleHash = sha1(preg_replace('/[^a-z0-9]/', '', strtolower($title)));
        $extHash = sha1(strtolower(preg_replace('/[?#].*$/', '', $item['link'])) . '|' . $item['link']);

        $ts = $item['date'] !== '' ? strtotime($item['date']) : false;
        if ($ts && $ts < time() - self::MAX_AGE_DAYS * 86400) {
            return false;
        }
        if (q_val('SELECT 1 FROM news WHERE external_hash = ? OR title_hash = ? LIMIT 1', [$extHash, $titleHash])) {
            return false;
        }
        $summary = $item['summary'];
        if ($summary === $title || stripos($summary, $title) === 0 && str_len($summary) < str_len($title) + 40) {
            $summary = '';
        }
        try {
            q("INSERT INTO news (title, summary, content, image_url, source_name, source_url, origin, status, news_source_id,
                    external_hash, title_hash, external_published_at, created_at, updated_at)
               VALUES (?, ?, NULL, ?, ?, ?, 'auto', 'pending', ?, ?, ?, ?, ?, ?)", [
                $title, $summary === '' ? null : $summary, $item['image'] === '' ? null : str_limit($item['image'], 700, ''),
                str_limit($sourceName, 120), str_limit($item['link'], 1000, ''), (int)$src['id'], $extHash, $titleHash,
                $ts ? gmdate('Y-m-d H:i:s', $ts) : null, now(), now(),
            ]);
        } catch (PDOException $e) {
            if (is_duplicate_error($e)) {
                return false;
            }
            throw $e;
        }
        return true;
    }
}
