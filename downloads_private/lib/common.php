<?php

declare(strict_types=1);

const XOR_KEY_TEXT = '1743';

function xor_hex_with_text_key(string $hexInput, string $keyText): string
{
    $hexInput = preg_replace('/\s+/', '', $hexInput);
    if ($hexInput === '' || (strlen($hexInput) % 2) !== 0 || !ctype_xdigit($hexInput)) {
        return '';
    }

    $data = hex2bin($hexInput);
    if ($data === false) {
        return '';
    }

    $keyLen = strlen($keyText);
    if ($keyLen === 0) {
        return '';
    }

    $out = '';
    $dataLen = strlen($data);
    for ($i = 0; $i < $dataLen; $i++) {
        $out .= $data[$i] ^ $keyText[$i % $keyLen];
    }

    return $out;
}

function xor_secret_to_hex_password(string $secret7, string $keyText): string
{
    $keyLen = strlen($keyText);
    if ($keyLen === 0 || strlen($secret7) !== 7) {
        return '';
    }

    $xorred = '';
    for ($i = 0; $i < 7; $i++) {
        $xorred .= $secret7[$i] ^ $keyText[$i % $keyLen];
    }

    return bin2hex($xorred);
}

function registry_path(): string
{
    return (string) ($GLOBALS['DOWNLOADS_BOOTSTRAP']['registry_file'] ?? '');
}

/**
 * @return array<string, array<string, mixed>>
 */
function load_registry(): array
{
    $path = registry_path();
    if ($path === '' || !is_readable($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function registry_entry_display_name(array $entry): string
{
    if (!empty($entry['display_name']) && is_string($entry['display_name'])) {
        return $entry['display_name'];
    }

    return isset($entry['original']) ? basename((string) $entry['original']) : '';
}

function registry_entry_is_expired(array $entry): bool
{
    if (empty($entry['expires_at']) || !is_string($entry['expires_at'])) {
        return false;
    }

    $ts = strtotime($entry['expires_at']);
    if ($ts === false) {
        return false;
    }

    return $ts < time();
}

/**
 * Unix timestamp for 23:59:59 UTC on the same calendar day as $unixTimestamp (UTC date).
 */
function downloads_end_of_utc_day_timestamp(int $unixTimestamp): int
{
    $ymd = gmdate('Y-m-d', $unixTimestamp);
    try {
        $dt = new DateTimeImmutable($ymd . ' 23:59:59', new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return $unixTimestamp;
    }

    return $dt->getTimestamp();
}

/**
 * Expiry instant: end of UTC calendar day reached by adding $days × 24h to "now", then rounding up to that day's 23:59:59 UTC.
 */
function expires_at_from_days(int $days): ?string
{
    if ($days <= 0) {
        return null;
    }

    $anchor = time() + $days * 86400;

    return gmdate('c', downloads_end_of_utc_day_timestamp($anchor));
}

/**
 * @param array<string, array<string, mixed>> $registry
 */
function save_registry(array $registry): bool
{
    $path = registry_path();
    if ($path === '') {
        return false;
    }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        return false;
    }

    $payload = json_encode($registry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

    return file_put_contents($path, $payload, LOCK_EX) !== false;
}

function secret_material_hash(string $secret7): string
{
    return hash('sha256', XOR_KEY_TEXT . '|' . $secret7);
}

function safe_storage_extension(string $originalName): string
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === '' || strlen($ext) > 12 || !preg_match('/^[a-z0-9]+$/', $ext)) {
        return '';
    }

    return '.' . $ext;
}

function documents_real_dir(): ?string
{
    $raw = $GLOBALS['DOWNLOADS_BOOTSTRAP']['documents_dir'] ?? '';
    if ($raw === '' || !is_string($raw)) {
        return null;
    }

    $trimmed = rtrim(trim($raw), '/\\');
    if ($trimmed === '') {
        return null;
    }

    $resolved = realpath($trimmed);
    if ($resolved !== false) {
        return $resolved;
    }

    // realpath() can fail under open_basedir or odd mounts while the directory is still usable.
    return is_dir($trimmed) ? $trimmed : null;
}

function resolve_document_path(string $basename): ?string
{
    $documentsDir = documents_real_dir();
    if ($documentsDir === null) {
        return null;
    }

    $base = basename($basename);
    if ($base === '' || $base === '.' || $base === '..') {
        return null;
    }

    $full = realpath($documentsDir . DIRECTORY_SEPARATOR . $base);
    $prefix = $documentsDir . DIRECTORY_SEPARATOR;
    if ($full === false || strpos($full, $prefix) !== 0) {
        return null;
    }

    return $full;
}

/** Origin (scheme + host + port) for the public download vhost; path in bootstrap is ignored. */
function private_download_origin(): string
{
    $raw = rtrim((string) ($GLOBALS['DOWNLOADS_BOOTSTRAP']['public_download_url'] ?? ''), '/');
    if ($raw === '') {
        return '';
    }

    $parsed = parse_url($raw);
    if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
        return $raw;
    }

    $origin = $parsed['scheme'] . '://' . $parsed['host'];
    if (isset($parsed['port'])) {
        $origin .= ':' . $parsed['port'];
    }

    return $origin;
}

/** Full shareable download URL for a stored basename + hex password token. */
function public_download_link(string $storedBase, string $passwordHex): string
{
    $origin = private_download_origin();
    if ($origin === '') {
        return '';
    }

    return $origin . '/?file=' . rawurlencode($storedBase)
        . '&password=' . rawurlencode($passwordHex);
}

/**
 * Full-screen dark HTML response for the public download host (no sensitive hints).
 *
 * @param 'unauthorized'|'expired'|'unavailable' $kind
 */
function downloads_gate_render_exit(int $httpStatus, string $kind): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $pages = [
        'unauthorized' => [
            'title' => 'Unauthorized',
            'body' => 'Access denied. This link is invalid, incomplete, or not authorized.',
        ],
        'expired' => [
            'title' => 'Expired',
            'body' => 'This download link has expired and is no longer available.',
        ],
        'unavailable' => [
            'title' => 'Unavailable',
            'body' => 'Downloads cannot be served right now. Please try again later.',
        ],
    ];

    $page = $pages[$kind] ?? $pages['unauthorized'];

    http_response_code($httpStatus);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');

    $title = htmlspecialchars($page['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = htmlspecialchars($page['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="color-scheme" content="dark">';
    echo '<title>' . $title . '</title>';
    echo '<style>';
    echo ':root{--bg:#050508;--text:#f4f4f5;--muted:#a1a1aa;--accent:#8b5cf6;--accent2:#d946ef;--card:rgba(24,24,27,.72);--stroke:rgba(255,255,255,.08);--glow:radial-gradient(ellipse 80% 60% at 50% -30%,rgba(139,92,246,.22),transparent 55%),radial-gradient(ellipse 60% 45% at 100% 80%,rgba(217,70,239,.12),transparent 50%),radial-gradient(ellipse 50% 40% at 0% 100%,rgba(34,211,238,.08),transparent 45%);}';
    echo '*{box-sizing:border-box;}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:clamp(1.25rem,4vw,2.5rem);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,system-ui,sans-serif;background:var(--bg);background-image:var(--glow);color:var(--text);-webkit-font-smoothing:antialiased;}';
    echo '.panel{width:100%;max-width:26rem;padding:clamp(1.75rem,4vw,2.35rem);border-radius:1rem;border:1px solid var(--stroke);background:var(--card);backdrop-filter:blur(18px);box-shadow:0 24px 80px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.06);}';
    echo '.rule{height:3px;width:3rem;border-radius:999px;background:linear-gradient(90deg,var(--accent),var(--accent2));margin-bottom:1.35rem;}';
    echo 'h1{font-size:clamp(1.55rem,4vw,1.85rem);font-weight:650;letter-spacing:-.03em;margin:0 0 .85rem;line-height:1.15;}';
    echo 'p{margin:0;font-size:.98rem;line-height:1.55;color:var(--muted);}';
    echo '</style></head><body><div class="panel" role="status">';
    echo '<div class="rule" aria-hidden="true"></div>';
    echo '<h1>' . $title . '</h1>';
    echo '<p>' . $body . '</p>';
    echo '</div></body></html>';

    exit;
}
