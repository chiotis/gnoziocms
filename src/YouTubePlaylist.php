<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * The videos of a YouTube playlist, for the Playlist block. With an API key (Admin > Settings > APIs) the YouTube Data API gives
 * the whole list (up to 50 videos) with lengths and views; without one the playlist's public feed gives its 15 newest videos with
 * their views. What was fetched is kept for a number of hours (so a page never waits on YouTube), a playlist that cannot be fetched
 * again is shown from what was kept, and a failed fetch is not tried again for five minutes. Nothing here runs in the visitor's
 * browser: the block shows pictures and loads the player only when a visitor plays a video.
 */
final class YouTubePlaylist
{
    public const FEED = 'https://www.youtube.com/feeds/videos.xml';
    public const API = 'https://www.googleapis.com/youtube/v3';
    /** The most videos kept for one playlist (one page of the API). */
    public const MAX = 50;
    private const RETRY_SECONDS = 300;

    /**
     * @param \Closure(string): array{0: int, 1: string} $http asks for an address: the status (0 when there was no answer) and the body
     */
    public function __construct(
        private SystemMetaRepository $meta,
        private string $key,
        private \Closure $http,
        private int $ttlHours = 6,
        private string $feedBase = self::FEED,
        private string $apiBase = self::API,
        private ?\Closure $now = null
    ) {
    }

    /** The id of a playlist from what was typed: the id itself, or any YouTube address that carries `list=`. Nothing when it is neither. */
    public static function parseId(string $input): string
    {
        $input = trim($input);
        if (preg_match('/^[A-Za-z0-9_-]{13,64}$/', $input) === 1) {
            return $input;
        }
        $parts = parse_url($input);
        $host = strtolower((string)($parts['host'] ?? ''));
        $host = preg_replace('/^(www\.|m\.|music\.)/', '', $host) ?? $host;
        if (!in_array($host, ['youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
            return '';
        }
        parse_str((string)($parts['query'] ?? ''), $query);
        $id = (string)($query['list'] ?? '');
        return preg_match('/^[A-Za-z0-9_-]{13,64}$/', $id) === 1 ? $id : '';
    }

    /** "3:05", "1:02:09": seconds written as a length. */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /** Seconds in an ISO 8601 length such as PT1H2M3S, or null when it is not one (a live stream has none). */
    public static function isoSeconds(string $iso): ?int
    {
        if (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $iso, $m) !== 1 || $iso === 'P0D') {
            return null;
        }
        return ((int)($m[1] ?? 0)) * 86400 + ((int)($m[2] ?? 0)) * 3600 + ((int)($m[3] ?? 0)) * 60 + (int)($m[4] ?? 0);
    }

    /** Whether the key still works: one question of the lowest cost. @return array{ok: bool, message: string} */
    public function test(): array
    {
        if ($this->key === '') {
            return ['ok' => false, 'message' => 'There is no key to test.'];
        }
        [$status, $body] = ($this->http)($this->apiBase . '/i18nLanguages?part=snippet&hl=en&key=' . rawurlencode($this->key));
        if ($status === 0) {
            return ['ok' => false, 'message' => 'YouTube did not answer. Check that this server can reach the internet.'];
        }
        $error = self::apiError($status, $body);
        return $error === null ? ['ok' => true, 'message' => 'The key works.'] : ['ok' => false, 'message' => $error];
    }

    /**
     * The playlist, from what was kept when that is recent enough.
     *
     * @return array{ok: bool, source: string, title: string, channel: string, items: array<int, array{id: string, title: string, description: string, published: int, views: ?int, seconds: ?int, duration: string, watch_url: string}>, stale: bool, note: string, error: string}
     */
    public function fetch(string $playlistId): array
    {
        $now = $this->time();
        $mode = $this->key !== '' ? 'api' : 'feed';
        $cacheKey = 'youtube.' . substr(sha1($playlistId . '|' . $mode), 0, 24);
        $entry = $this->meta->getJson($cacheKey);
        $kept = is_array($entry) && is_array($entry['data'] ?? null) ? $entry['data'] : null;
        $at = is_array($entry) ? (int)($entry['at'] ?? 0) : 0;
        $fresh = $kept !== null && $now < $at + $this->ttlHours * 3600;
        if ($fresh) {
            return $kept + ['stale' => false];
        }
        if ($entry !== null && (int)($entry['retry'] ?? 0) > $now) {
            return $kept !== null ? ['stale' => true] + $kept : self::failure((string)($entry['error'] ?? 'The playlist could not be fetched.'));
        }

        $result = $this->load($playlistId);
        if ($result['ok']) {
            $this->meta->setJson($cacheKey, ['at' => $now, 'data' => $result]);
            return $result + ['stale' => false];
        }
        // It could not be fetched: show what was kept, if anything, and wait before asking again.
        $this->meta->setJson($cacheKey, ['at' => $at, 'data' => $kept, 'retry' => $now + self::RETRY_SECONDS, 'error' => $result['error']]);
        return $kept !== null ? ['stale' => true, 'error' => $result['error']] + $kept : $result;
    }

    /** @return array<string, mixed> */
    private function load(string $playlistId): array
    {
        if ($this->key !== '') {
            $result = $this->fromApi($playlistId);
            if ($result['ok'] || $result['error'] === self::NOT_FOUND) {
                return $result;
            }
            // The key does not work (wrong, over its limit, not allowed): the public feed still can.
            $feed = $this->fromFeed($playlistId);
            return $feed['ok'] ? ['note' => 'The key did not work (' . $result['error'] . '), so the public feed was used.'] + $feed : $result;
        }
        return $this->fromFeed($playlistId);
    }

    private const NOT_FOUND = 'The playlist was not found. It may be private, or the address is not a playlist.';

    /** @return array<string, mixed> */
    private function fromApi(string $id): array
    {
        $get = function (string $path, array $query): array {
            [$status, $body] = ($this->http)($this->apiBase . $path . '?' . http_build_query($query + ['key' => $this->key]));
            if ($status === 0) {
                return [null, 'YouTube did not answer.'];
            }
            $error = self::apiError($status, $body);
            $data = json_decode($body, true);
            return $error !== null || !is_array($data) ? [null, $error ?? 'YouTube sent something unreadable.'] : [$data, null];
        };
        [$playlist, $error] = $get('/playlists', ['part' => 'snippet', 'id' => $id, 'maxResults' => 1]);
        if ($playlist === null) {
            return self::failure($error);
        }
        if (($playlist['items'] ?? []) === []) {
            return self::failure(self::NOT_FOUND);
        }
        $snippet = $playlist['items'][0]['snippet'] ?? [];
        [$list, $error] = $get('/playlistItems', ['part' => 'contentDetails,status,snippet', 'playlistId' => $id, 'maxResults' => self::MAX]);
        if ($list === null) {
            return self::failure($error);
        }
        $entries = [];
        foreach ($list['items'] ?? [] as $item) {
            $videoId = (string)($item['contentDetails']['videoId'] ?? '');
            $privacy = (string)($item['status']['privacyStatus'] ?? 'public');
            if (preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1 || !in_array($privacy, ['public', 'unlisted'], true)) {
                continue;
            }
            $entries[$videoId] = [
                'id' => $videoId,
                'title' => self::text((string)($item['snippet']['title'] ?? ''), 200),
                'description' => self::text((string)($item['snippet']['description'] ?? ''), 2000),
                'published' => (int)strtotime((string)($item['contentDetails']['videoPublishedAt'] ?? $item['snippet']['publishedAt'] ?? '')),
                'views' => null,
                'seconds' => null,
            ];
        }
        if ($entries !== []) {
            [$videos, $error] = $get('/videos', ['part' => 'contentDetails,statistics', 'id' => implode(',', array_keys($entries)), 'maxResults' => self::MAX]);
            if ($videos !== null) {
                $found = [];
                foreach ($videos['items'] ?? [] as $video) {
                    $videoId = (string)($video['id'] ?? '');
                    if (!isset($entries[$videoId])) {
                        continue;
                    }
                    $found[$videoId] = true;
                    $entries[$videoId]['seconds'] = self::isoSeconds((string)($video['contentDetails']['duration'] ?? ''));
                    $views = $video['statistics']['viewCount'] ?? null;
                    $entries[$videoId]['views'] = is_numeric($views) ? (int)$views : null;
                }
                // A video that YouTube no longer lists has been deleted or made private.
                $entries = array_intersect_key($entries, $found);
            }
        }
        return self::result('api', self::text((string)($snippet['title'] ?? ''), 200), self::text((string)($snippet['channelTitle'] ?? ''), 200), array_values($entries));
    }

    /** @return array<string, mixed> */
    private function fromFeed(string $id): array
    {
        [$status, $body] = ($this->http)($this->feedBase . '?playlist_id=' . rawurlencode($id));
        if ($status === 0) {
            return self::failure('YouTube did not answer. Check that this server can reach the internet.');
        }
        if ($status === 404 || $status === 400) {
            return self::failure(self::NOT_FOUND);
        }
        if ($status !== 200 || !function_exists('simplexml_load_string')) {
            return self::failure($status !== 200 ? 'YouTube answered ' . $status . '.' : 'The server has no XML support (simplexml).');
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return self::failure('YouTube sent something unreadable.');
        }
        $entries = [];
        foreach ($xml->entry ?? [] as $entry) {
            $yt = $entry->children('yt', true);
            $media = $entry->children('media', true);
            $videoId = (string)($yt->videoId ?? '');
            if (preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
                continue;
            }
            $group = $media->group ?? null;
            $stats = $group !== null ? $group->children('media', true)->community->statistics ?? null : null;
            $views = $stats !== null ? (string)($stats->attributes()['views'] ?? '') : '';
            $entries[] = [
                'id' => $videoId,
                'title' => self::text((string)$entry->title, 200),
                'description' => $group !== null ? self::text((string)($group->children('media', true)->description ?? ''), 2000) : '',
                'published' => (int)strtotime((string)$entry->published),
                'views' => ctype_digit($views) ? (int)$views : null,
                'seconds' => null,
            ];
        }
        return self::result('feed', self::text((string)$xml->title, 200), self::text((string)($xml->author->name ?? ''), 200), $entries);
    }

    /** @param array<int, array<string, mixed>> $entries @return array<string, mixed> */
    private static function result(string $source, string $title, string $channel, array $entries): array
    {
        foreach ($entries as $i => $entry) {
            $entries[$i]['duration'] = $entry['seconds'] !== null ? self::duration((int)$entry['seconds']) : '';
            $entries[$i]['watch_url'] = 'https://www.youtube.com/watch?v=' . $entry['id'];
        }
        return ['ok' => $entries !== [], 'source' => $source, 'title' => $title, 'channel' => $channel, 'items' => $entries, 'stale' => false, 'note' => '', 'error' => $entries === [] ? 'The playlist has no videos that can be shown.' : ''];
    }

    /** @return array<string, mixed> */
    private static function failure(string $error): array
    {
        return ['ok' => false, 'source' => '', 'title' => '', 'channel' => '', 'items' => [], 'stale' => false, 'note' => '', 'error' => $error];
    }

    /** A plain sentence for the person who sees it from what the API says went wrong, or null when nothing did. */
    private static function apiError(int $status, string $body): ?string
    {
        if ($status >= 200 && $status < 300) {
            return null;
        }
        $data = json_decode($body, true);
        $reason = (string)($data['error']['errors'][0]['reason'] ?? '');
        return match (true) {
            in_array($reason, ['keyInvalid', 'badRequest'], true) && $status === 400 => 'YouTube does not accept the key.',
            $reason === 'quotaExceeded' || $reason === 'dailyLimitExceeded' => 'The key has used up its daily quota.',
            $reason === 'accessNotConfigured' || $reason === 'forbidden' && str_contains((string)($data['error']['message'] ?? ''), 'has not been used') => 'The YouTube Data API is not switched on for this key.',
            $reason === 'ipRefererBlocked' || $reason === 'forbidden' => 'The key is not allowed to be used from this server.',
            $reason === 'playlistNotFound' || $status === 404 => self::NOT_FOUND,
            default => 'YouTube answered ' . $status . ($reason !== '' ? ' (' . $reason . ')' : '') . '.',
        };
    }

    private static function text(string $text, int $max): string
    {
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '');
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    private function time(): int
    {
        return $this->now !== null ? (int)($this->now)() : time();
    }

    /** Asks an address with cURL, or PHP's own streams when cURL cannot be used. Only https (or this machine, for tests). @return array{0: int, 1: string} */
    public static function request(string $url, int $timeout = 5): array
    {
        if (!UpdateNetwork::isAllowedUrl($url)) {
            return [0, ''];
        }
        if (UpdateNetwork::hasCurl()) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 3_000_000, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; GnozioCMS)', CURLOPT_HTTPHEADER => ['Accept: application/json, application/atom+xml, application/xml;q=0.9']]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch);
            return is_string($body) ? [$status, $body] : [0, ''];
        }
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: Mozilla/5.0 (compatible; GnozioCMS)\r\n"]]));
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            }
        }
        return is_string($body) ? [$status, $body] : [0, ''];
    }
}
