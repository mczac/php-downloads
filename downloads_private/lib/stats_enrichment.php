<?php

declare(strict_types=1);

/**
 * Statistics UI helpers: UA hints, Accept-Language primary tag, optional GeoIP / online lookup.
 */

/** @var array<string, string> */
function downloads_country_name_from_iso3166_alpha2(string $code): string
{
    $code = strtoupper($code);
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }

    if (extension_loaded('intl')) {
        $name = \Locale::getDisplayRegion('-' . $code, 'en');
        if ($name !== false && $name !== '') {
            return $name;
        }
    }

    return $code;
}

function downloads_primary_accept_language(string $header): string
{
    if ($header === '' || $header === '-') {
        return '—';
    }

    $first = explode(',', $header, 2)[0];
    $first = trim(explode(';', $first, 2)[0]);

    return $first !== '' ? substr($first, 0, 48) : '—';
}

function downloads_referrer_short(string $ref): string
{
    if ($ref === '' || $ref === '-') {
        return '—';
    }

    $t = preg_replace('#^https?://#i', '', $ref);
    $t = str_replace(["\t", "\r", "\n"], ' ', (string) $t);

    return strlen($t) <= 56 ? $t : substr($t, 0, 53) . '…';
}

function downloads_client_hint_from_ua(string $ua): string
{
    if ($ua === '' || $ua === '-') {
        return '—';
    }

    $os = 'Other';
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
        $os = 'iOS';
    } elseif (stripos($ua, 'Android') !== false) {
        $os = 'Android';
    } elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
        $os = 'macOS';
    } elseif (stripos($ua, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }

    $browser = 'Browser';
    if (stripos($ua, 'Edg/') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'Chrome/') !== false && stripos($ua, 'Chromium') === false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Safari/') !== false && stripos($ua, 'Chrome') === false) {
        $browser = 'Safari';
    } elseif (stripos($ua, 'Firefox/') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'curl') !== false) {
        $browser = 'curl';
    }

    return $browser . ' · ' . $os;
}

function downloads_ip_is_public_routable(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * Cached GeoIP resolution for Statistics (admin-only). Prefer CF column from log, then MMDB, then optional online API.
 *
 * @return string Human-readable country label or "—"
 */
function downloads_geo_country_label(string $ip, string $cfFromLog): string
{
    static $memo = [];

    $cfFromLog = strtoupper(trim($cfFromLog));
    if ($cfFromLog !== '' && $cfFromLog !== '-') {
        if (preg_match('/^[A-Z]{2}$/', $cfFromLog)) {
            $name = downloads_country_name_from_iso3166_alpha2($cfFromLog);

            return $name !== '' ? ($name . ' (' . $cfFromLog . ')') : $cfFromLog;
        }

        return $cfFromLog;
    }

    if ($ip === '' || $ip === '-' || !downloads_ip_is_public_routable($ip)) {
        return $ip === '127.0.0.1' || str_starts_with($ip, '192.168.') ? 'Private' : '—';
    }

    if (isset($memo[$ip])) {
        return $memo[$ip];
    }

    $cfg = $GLOBALS['DOWNLOADS_BOOTSTRAP'] ?? [];
    $mmdbPath = isset($cfg['geoip_country_mmdb']) ? trim((string) $cfg['geoip_country_mmdb']) : '';

    if ($mmdbPath !== '' && is_readable($mmdbPath) && class_exists(\GeoIp2\Database\Reader::class)) {
        try {
            $reader = new \GeoIp2\Database\Reader($mmdbPath);
            $rec = $reader->country($ip);
            $code = $rec->country->isoCode ?? '';
            $name = $rec->country->name ?? '';
            $reader->close();
            if ($code !== '') {
                $memo[$ip] = $name !== '' ? ($name . ' (' . $code . ')') : $code;

                return $memo[$ip];
            }
        } catch (Throwable $e) {
            // fall through
        }
    }

    if (!array_key_exists('geoip_allow_online_lookup', $cfg)) {
        $allowOnline = true;
    } else {
        $allowOnline = filter_var($cfg['geoip_allow_online_lookup'], FILTER_VALIDATE_BOOLEAN);
    }
    if ($allowOnline) {
        $online = downloads_geo_online_lookup_cached($ip);
        if ($online !== null) {
            $memo[$ip] = $online;

            return $memo[$ip];
        }
    }

    $memo[$ip] = '—';

    return $memo[$ip];
}

function downloads_geo_online_lookup_cached(string $ip): ?string
{
    $cfg = $GLOBALS['DOWNLOADS_BOOTSTRAP'] ?? [];
    $logDir = isset($cfg['log_dir']) ? rtrim((string) $cfg['log_dir'], '/') : '';
    if ($logDir === '' || !is_dir($logDir) || !is_writable($logDir)) {
        return null;
    }

    $cacheDir = $logDir . '/.geo_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0750, true);
    }
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return null;
    }

    $cacheFile = $cacheDir . '/' . hash('sha256', $ip) . '.json';
    $ttl = 86400 * 30;

    if (is_readable($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['ts'], $data['label']) && is_numeric($data['ts'])) {
                if ((time() - (int) $data['ts']) < $ttl) {
                    return (string) $data['label'];
                }
            }
        }
    }

    $url = 'https://ipwho.is/' . rawurlencode($ip);
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 4,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || $body === '') {
        return null;
    }

    $j = json_decode($body, true);
    if (!is_array($j) || empty($j['success'])) {
        return null;
    }

    $code = isset($j['country_code']) ? strtoupper((string) $j['country_code']) : '';
    $name = isset($j['country']) ? trim((string) $j['country']) : '';
    $label = '—';
    if ($code !== '' && $name !== '') {
        $label = $name . ' (' . $code . ')';
    } elseif ($code !== '') {
        $disp = downloads_country_name_from_iso3166_alpha2($code);
        $label = ($disp !== '' && $disp !== $code) ? ($disp . ' (' . $code . ')') : $code;
    }

    $enc = json_encode(['ts' => time(), 'label' => $label]);
    if ($enc !== false) {
        @file_put_contents($cacheFile, $enc, LOCK_EX);
    }

    return $label;
}

/**
 * Add display-only keys for the Statistics table.
 *
 * @param array<string, mixed> $row From downloads_parse_download_log_line()
 * @return array<string, mixed>
 */
function downloads_stats_row_enrich(array $row): array
{
    $cf = isset($row['cf_country']) ? (string) $row['cf_country'] : '';
    $row['country_display'] = downloads_geo_country_label((string) $row['ip'], $cf);
    $row['lang_primary'] = downloads_primary_accept_language((string) ($row['accept_language'] ?? ''));
    $row['referrer_short'] = downloads_referrer_short((string) ($row['referer'] ?? ''));
    $row['client_hint'] = downloads_client_hint_from_ua((string) ($row['ua'] ?? ''));

    return $row;
}

/**
 * Cached ipwho.is fetch for map coordinates (Statistics hover popover).
 *
 * @return array<string, mixed>|null Decoded JSON or null on failure
 */
function downloads_geo_ipwho_fetch_json(string $ip): ?array
{
    $url = 'https://ipwho.is/' . rawurlencode($ip);
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || $body === '') {
        return null;
    }

    $j = json_decode($body, true);

    return is_array($j) ? $j : null;
}

/**
 * Admin Statistics: approximate lat/lng + locality fields for IP map popover.
 *
 * @return array{
 *   ok: bool,
 *   ip: string,
 *   lat: float|null,
 *   lng: float|null,
 *   city: string,
 *   region: string,
 *   country: string,
 *   isp: string,
 *   accuracy: string,
 *   message?: string
 * }
 */
function downloads_geo_ip_detail_for_map(string $ip): array
{
    $empty = static fn(): array => [
        'city' => '',
        'region' => '',
        'country' => '',
        'isp' => '',
    ];

    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return [
            'ok' => false,
            'ip' => $ip,
            'lat' => null,
            'lng' => null,
            ...$empty(),
            'accuracy' => '',
            'message' => 'Invalid IP address',
        ];
    }

    if (!downloads_ip_is_public_routable($ip)) {
        return [
            'ok' => true,
            'ip' => $ip,
            'lat' => null,
            'lng' => null,
            ...$empty(),
            'accuracy' => 'Private or reserved range',
        ];
    }

    $cfg = $GLOBALS['DOWNLOADS_BOOTSTRAP'] ?? [];
    $logDir = isset($cfg['log_dir']) ? rtrim((string) $cfg['log_dir'], '/') : '';
    if ($logDir === '' || !is_dir($logDir)) {
        return [
            'ok' => false,
            'ip' => $ip,
            'lat' => null,
            'lng' => null,
            ...$empty(),
            'accuracy' => '',
            'message' => 'log_dir not configured',
        ];
    }

    if (!array_key_exists('geoip_allow_online_lookup', $cfg)) {
        $allowOnline = true;
    } else {
        $allowOnline = filter_var($cfg['geoip_allow_online_lookup'], FILTER_VALIDATE_BOOLEAN);
    }

    if (!$allowOnline) {
        return [
            'ok' => true,
            'ip' => $ip,
            'lat' => null,
            'lng' => null,
            ...$empty(),
            'accuracy' => 'Coordinates unavailable (online lookup disabled in bootstrap)',
        ];
    }

    $cacheDir = $logDir . '/.geo_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0750, true);
    }
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return [
            'ok' => false,
            'ip' => $ip,
            'lat' => null,
            'lng' => null,
            ...$empty(),
            'accuracy' => '',
            'message' => 'Geo cache directory not writable',
        ];
    }

    $cacheFile = $cacheDir . '/map_' . hash('sha256', $ip) . '.json';
    $ttl = 86400 * 30;

    if (is_readable($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $wrap = json_decode($raw, true);
            if (
                is_array($wrap)
                && isset($wrap['ts'], $wrap['detail'])
                && is_array($wrap['detail'])
                && is_numeric($wrap['ts'])
                && (time() - (int) $wrap['ts']) < $ttl
            ) {
                $d = $wrap['detail'];
                $d['ip'] = $ip;

                return $d;
            }
        }
    }

    $j = downloads_geo_ipwho_fetch_json($ip);
    if ($j === null || empty($j['success'])) {
        $detail = [
            'ok' => false,
            'ip' => $ip,
            'lat' => null,
            'lng' => null,
            ...$empty(),
            'accuracy' => '',
            'message' => isset($j['message']) && is_string($j['message']) ? $j['message'] : 'Lookup failed',
        ];
    } else {
        $lat = null;
        $lng = null;
        if (isset($j['latitude'], $j['longitude']) && is_numeric($j['latitude']) && is_numeric($j['longitude'])) {
            $lat = (float) $j['latitude'];
            $lng = (float) $j['longitude'];
        }
        $conn = isset($j['connection']) && is_array($j['connection']) ? $j['connection'] : [];
        $isp = '';
        if (isset($conn['isp']) && is_string($conn['isp'])) {
            $isp = trim($conn['isp']);
        }
        if ($isp === '' && isset($conn['org']) && is_string($conn['org'])) {
            $isp = trim($conn['org']);
        }

        $city = isset($j['city']) && is_string($j['city']) ? trim($j['city']) : '';
        $region = isset($j['region']) && is_string($j['region']) ? trim($j['region']) : '';
        $country = isset($j['country']) && is_string($j['country']) ? trim($j['country']) : '';

        $detail = [
            'ok' => true,
            'ip' => $ip,
            'lat' => $lat,
            'lng' => $lng,
            'city' => $city,
            'region' => $region,
            'country' => $country,
            'isp' => $isp,
            'accuracy' => $lat !== null && $lng !== null
                ? 'Approximate location (ipwho.is)'
                : 'No coordinates in response',
        ];
    }

    $enc = json_encode(['ts' => time(), 'detail' => $detail]);
    if ($enc !== false) {
        @file_put_contents($cacheFile, $enc, LOCK_EX);
    }

    return $detail;
}
