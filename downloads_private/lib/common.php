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

/** Canonical public URL for file.php (no trailing slash). From bootstrap.php */
function private_download_script_url(): string
{
    return rtrim((string) ($GLOBALS['DOWNLOADS_BOOTSTRAP']['public_download_url'] ?? ''), '/');
}
