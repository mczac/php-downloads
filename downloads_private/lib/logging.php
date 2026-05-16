<?php

declare(strict_types=1);

function downloads_monthly_log_path(string $basename, string $yearMonth): string
{
    $cfg = $GLOBALS['DOWNLOADS_BOOTSTRAP'] ?? [];
    $dir = isset($cfg['log_dir']) ? (string) $cfg['log_dir'] : '';
    if ($dir === '' || !preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
        return '';
    }

    $safe = preg_replace('/[^a-z0-9_-]/i', '_', $basename);

    return rtrim($dir, '/') . '/' . $safe . '_' . $yearMonth . '.log';
}

/**
 * Read up to the last N complete lines from a text file without loading the whole file into memory.
 *
 * @return list<string>
 */
function downloads_tail_text_file_lines(string $absolutePath, int $maxLines): array
{
    if ($maxLines < 1 || !is_readable($absolutePath)) {
        return [];
    }

    $size = filesize($absolutePath);
    if ($size === false || $size === 0) {
        return [];
    }

    $fh = fopen($absolutePath, 'rb');
    if ($fh === false) {
        return [];
    }

    $chunkSize = 262144;
    $gather = '';
    $pos = $size;

    while ($pos > 0 && substr_count($gather, "\n") <= $maxLines + 1) {
        $readLen = min($chunkSize, $pos);
        $pos -= $readLen;
        fseek($fh, $pos);
        $chunk = fread($fh, $readLen);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $gather = $chunk . $gather;
        if (strlen($gather) > $chunkSize * 8) {
            break;
        }
    }

    fclose($fh);

    $lines = explode("\n", $gather);
    if ($pos > 0 && $lines !== []) {
        array_shift($lines);
    }

    $lines = array_values(array_filter($lines, static fn(string $l): bool => $l !== ''));

    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }

    return $lines;
}

/**
 * Months (YYYY-MM desc) that have a downloads_*.log file in log_dir, capped at $limit.
 *
 * @return list<string>
 */
function downloads_available_download_log_months(int $limit = 24): array
{
    $cfg = $GLOBALS['DOWNLOADS_BOOTSTRAP'] ?? [];
    $dir = isset($cfg['log_dir']) ? rtrim((string) $cfg['log_dir'], '/') : '';
    if ($dir === '' || !is_dir($dir)) {
        return [gmdate('Y-m')];
    }

    $months = [];
    $dh = @scandir($dir);
    if (!is_array($dh)) {
        return [gmdate('Y-m')];
    }

    foreach ($dh as $name) {
        if (!is_string($name) || !str_starts_with($name, 'downloads_') || !str_ends_with($name, '.log')) {
            continue;
        }
        $inner = substr($name, strlen('downloads_'), -strlen('.log'));
        if (preg_match('/^\d{4}-\d{2}$/', $inner)) {
            $months[] = $inner;
        }
    }

    $months = array_values(array_unique($months));
    rsort($months, SORT_STRING);

    if ($months === []) {
        return [gmdate('Y-m')];
    }

    return array_slice($months, 0, max(1, $limit));
}

/**
 * Parse one downloads log row written by downloads_log('downloads', …).
 *
 * @return array{iso:string,ip:string,ua:string,payload:string,result:string,label:string,file_note:string}|null
 */
function downloads_parse_download_log_line(string $line): ?array
{
    $parts = explode("\t", $line, 4);
    if (count($parts) < 4) {
        return null;
    }

    [$iso, $ip, $ua, $payload] = $parts;
    $payload = trim($payload);
    if ($payload === '') {
        return null;
    }

    if (preg_match('/^ok\|([^|]*)\|as\|(.*)$/', $payload, $m)) {
        $stored = $m[1];
        $as = $m[2];
        $note = $as !== '' ? $as : $stored;

        return [
            'iso' => $iso,
            'ip' => $ip,
            'ua' => $ua,
            'payload' => $payload,
            'result' => 'ok',
            'label' => 'Success',
            'file_note' => $note !== '' ? $note : '—',
        ];
    }

    $kind = explode('|', $payload, 2)[0] ?? '';

    static $labels = [
        'bad_request' => 'Bad request',
        'bad_password' => 'Wrong password',
        'not_found' => 'Not found',
        'forbidden' => 'Wrong password',
        'gone' => 'Expired',
        'error' => 'Server error',
    ];

    $label = $labels[$kind] ?? ($kind !== '' ? $kind : 'Error');

    $file_note = '—';
    if (preg_match('/file=([^|\t\r\n]*)/', $payload, $fm) && $fm[1] !== '') {
        $file_note = $fm[1];
    }

    return [
        'iso' => $iso,
        'ip' => $ip,
        'ua' => $ua,
        'payload' => $payload,
        'result' => 'err',
        'label' => $label,
        'file_note' => $file_note,
    ];
}

/**
 * Append one line to monthly log files under log_dir.
 */
function downloads_log(string $basename, string $line): void
{
    $cfg = $GLOBALS['DOWNLOADS_BOOTSTRAP'] ?? [];
    $dir = isset($cfg['log_dir']) ? (string) $cfg['log_dir'] : '';
    if ($dir === '') {
        return;
    }

    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $file = downloads_monthly_log_path($basename, gmdate('Y-m'));
    if ($file === '') {
        return;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 200) : '-';
    $row = gmdate('c') . "\t" . $ip . "\t" . $ua . "\t" . $line . "\n";

    @file_put_contents($file, $row, FILE_APPEND | LOCK_EX);

    if (is_file($file) && filesize($file) > 5000000) {
        @rename($file, $file . '.' . gmdate('YmdHis') . '.old');
    }
}

function downloads_log_error(Throwable $e): void
{
    downloads_log(
        'php_errors',
        $e->getMessage() . ' in ' . $e->getFile() . ':' . (string) $e->getLine()
    );
}
