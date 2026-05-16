<?php

declare(strict_types=1);

session_start();

if (!defined('DOWNLOADS_APP_INIT')) {
    http_response_code(500);
    exit('Downloads app not initialized.');
}

$composerAutoload = DOWNLOADS_PRIVATE_ROOT . '/vendor/autoload.php';
if (is_readable($composerAutoload)) {
    require_once $composerAutoload;
}

require_once DOWNLOADS_PRIVATE_ROOT . '/lib/stats_enrichment.php';

const DOC_BATCH_LIMIT = 100;

function docupload_config(): array
{
    $path = DOWNLOADS_PRIVATE_ROOT . '/docupload_config.php';
    if (!is_readable($path)) {
        return [];
    }

    $c = require $path;

    return is_array($c) ? $c : [];
}

function docupload_config_valid(array $c): bool
{
    $p = isset($c['password']) ? trim((string) $c['password']) : '';
    if (strlen($p) < 12) {
        return false;
    }

    return strcasecmp($p, 'CHANGE_ME_LONG_RANDOM_PASSPHRASE') !== 0;
}

function docupload_default_expiry(array $c): int
{
    $d = isset($c['default_expiry_days']) ? (int) $c['default_expiry_days'] : 14;

    return max(0, min(3650, $d));
}

function docupload_max_expiry(array $c): int
{
    $d = isset($c['max_expiry_days']) ? (int) $c['max_expiry_days'] : 365;

    return max(1, min(3650, $d));
}

/**
 * One footer sentence for a batch of links sharing the same UTC calendar expiry day.
 *
 * Uses exact clock time from the stored expiry only when every link in the group shares the same instant;
 * otherwise states the calendar date only and clarifies that expiry is per-link (not a shared midnight).
 */
function doc_footer_expiry_line_for_group(int $linkCount, int $minTs, int $maxTs): string
{
    $datePart = gmdate('F j, Y', $minTs);
    $sameInstant = $minTs === $maxTs;

    if ($linkCount === 1) {
        $timePart = gmdate('H:i:s', $minTs);

        return 'Download link expires on ' . $datePart . ' at ' . $timePart . ' UTC.';
    }

    if ($sameInstant) {
        $timePart = gmdate('H:i:s', $minTs);

        return 'Download links expire on ' . $datePart . ' at ' . $timePart . ' UTC.';
    }

    return 'Download links expire on ' . $datePart . ' (UTC). Each link uses its own expiry time on that calendar day—not uniformly at 00:00:00 or 23:59:59 UTC.';
}

/**
 * Footer under the client email table: exact config text if set; otherwise expiry lines from the batch.
 * Expiring links are grouped by UTC calendar day so one upload session usually yields a single line.
 *
 * @param list<array{nice: string, expires_iso: ?string}> $batchRows
 */
function doc_batch_email_footer_for_export(array $config, array $batchRows): string
{
    $t = $config['batch_email_footer'] ?? '';
    if (is_string($t) && trim($t) !== '') {
        return trim($t);
    }

    /** @var array<string, list<int>> $byDay */
    $byDay = [];
    $noExpiryLines = [];
    $fallbackLines = [];

    foreach ($batchRows as $row) {
        $name = trim((string) ($row['nice'] ?? ''));
        if ($name === '') {
            $name = 'Document';
        }
        $exp = isset($row['expires_iso']) ? trim((string) $row['expires_iso']) : '';
        if ($exp === '') {
            $noExpiryLines[] = $name . ': link does not expire.';

            continue;
        }

        $ts = strtotime($exp);
        if ($ts === false) {
            $fallbackLines[] = $name . ': link expires ' . $exp . '.';

            continue;
        }

        $day = gmdate('Y-m-d', $ts);
        if (!isset($byDay[$day])) {
            $byDay[$day] = [];
        }
        $byDay[$day][] = $ts;
    }

    ksort($byDay);

    $lines = [];
    foreach ($byDay as $timestamps) {
        $lines[] = doc_footer_expiry_line_for_group(count($timestamps), min($timestamps), max($timestamps));
    }

    $lines = array_merge($lines, $fallbackLines, $noExpiryLines);

    return implode("\n", $lines);
}

/** @param array<string, mixed> $meta Registry entry */
function doc_library_expiry_line(array $meta): string
{
    if (empty($meta['expires_at']) || !is_string($meta['expires_at'])) {
        return 'No expiry';
    }

    if (registry_entry_is_expired($meta)) {
        return 'Expired';
    }

    $ts = strtotime($meta['expires_at']);
    if ($ts === false) {
        return '—';
    }

    $days = (int) ceil(($ts - time()) / 86400);
    if ($days < 1) {
        $days = 1;
    }

    return $days === 1 ? 'Expiring in 1 day' : 'Expiring in ' . (string) $days . ' days';
}

/** @return list<array{stored: string, nice: string, created: string, expires_at: string, until: string, expired: bool, missing: bool, expiry_detail: string}> */
function doc_build_library_rows(): array
{
    $registry = load_registry();
    $library = [];
    foreach ($registry as $stored => $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $path = resolve_document_path((string) $stored);
        $expired = registry_entry_is_expired($meta);
        $missing = $path === null;
        $detail = $missing ? 'File missing on server' : doc_library_expiry_line($meta);
        $library[] = [
            'stored' => (string) $stored,
            'nice' => registry_entry_display_name($meta),
            'created' => isset($meta['created']) ? (string) $meta['created'] : '',
            'expires_at' => isset($meta['expires_at']) ? (string) $meta['expires_at'] : '',
            'until' => format_until_label(isset($meta['expires_at']) && is_string($meta['expires_at']) ? $meta['expires_at'] : null),
            'expired' => $expired,
            'missing' => $missing,
            'expiry_detail' => $detail,
        ];
    }

    usort($library, static function (array $a, array $b): int {
        return strcmp($b['created'], $a['created']);
    });

    return $library;
}

function docupload_csrf(): string
{
    if (empty($_SESSION['docupload_csrf']) || !is_string($_SESSION['docupload_csrf'])) {
        $_SESSION['docupload_csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['docupload_csrf'];
}

function docupload_verify_csrf(?string $t): bool
{
    return is_string($t) && isset($_SESSION['docupload_csrf'])
        && hash_equals($_SESSION['docupload_csrf'], $t);
}

function docupload_signed_in(): bool
{
    return !empty($_SESSION['docupload_auth']);
}

function doc_redirect(): void
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? basename(str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME'])) : 'index.php';
    if ($script === '' || $script === '.' || $script === '..') {
        $script = 'index.php';
    }

    $loc = $script;
    if (isset($_POST['return_tab'])) {
        $t = (string) $_POST['return_tab'];
        if (in_array($t, ['portal', 'library', 'stats'], true)) {
            $loc .= '?tab=' . rawurlencode($t);
        }
    }

    header('Location: ' . $loc);
    exit;
}

function documents_dir(): string
{
    return rtrim(trim((string) ($GLOBALS['DOWNLOADS_BOOTSTRAP']['documents_dir'] ?? '')), '/');
}

function ensure_documents_dir(): void
{
    $dir = documents_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
}

function random_secret_7(): string
{
    return random_bytes(7);
}

function sanitize_display_name(string $raw, string $fallbackBasename): string
{
    $t = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $raw));
    if ($t === '') {
        return $fallbackBasename;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($t, 0, 255, 'UTF-8');
    }

    return substr($t, 0, 255);
}

function format_until_label(?string $expires_iso): string
{
    if ($expires_iso === null || $expires_iso === '') {
        return 'Does not expire';
    }

    $ts = strtotime($expires_iso);
    if ($ts === false) {
        return $expires_iso;
    }

    return gmdate('F j, Y \a\t H:i:s', $ts) . ' UTC';
}

/**
 * Email/UI: clickable text is the full URL (matches href — better for spam filters).
 */
function format_download_block_html(string $absoluteUrl): string
{
    $urlEsc = htmlspecialchars($absoluteUrl, ENT_QUOTES, 'UTF-8');

    return '<div style="margin:0;line-height:1.45;"><a href="' . $urlEsc . '" style="color:#2563eb;text-decoration:underline;word-break:break-all;">' . $urlEsc . '</a></div>';
}

/**
 * HTML fragment for email / preview: responsive table + footer (left-aligned; document names stay on one line on desktop).
 *
 * @param list<array{nice: string, link: string}> $rows
 */
function build_email_html_table(array $rows, string $footerPlain): string
{
    if ($rows === []) {
        return '';
    }

    $footerEsc = nl2br(htmlspecialchars($footerPlain, ENT_QUOTES, 'UTF-8'));

    $style = '<style>@media only screen and (max-width:600px){'
        . 'table.docs-table thead{display:none !important;}'
        . 'table.docs-table,table.docs-table tbody,table.docs-table tr,table.docs-table td{display:block !important;width:100% !important;box-sizing:border-box;}'
        . 'table.docs-table tr{border:1px solid #e5e7eb !important;border-radius:8px;margin-bottom:12px;background:#ffffff;}'
        . 'table.docs-table td.cell{border-bottom:1px solid #f3f4f6 !important;white-space:normal !important;padding:12px 14px !important;}'
        . 'table.docs-table td.cell.doc-col{white-space:normal !important;}'
        . 'table.docs-table tr td.cell:last-child{border-bottom:0 !important;}'
        . 'table.docs-table .mobile-label{display:block !important;margin-bottom:4px;}'
        . '}</style>';

    $wrapOpen = '<div style="text-align:left;margin:0;padding:0;width:100%;">';

    $tableOpen = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="docs-table" '
        . 'style="border-collapse:separate;border-spacing:0;width:100%;max-width:920px;margin:0;'
        . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;'
        . 'background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;table-layout:auto;">';

    $thead = '<thead><tr style="background:#f9fafb;">'
        . '<th align="left" style="padding:12px 16px;font-size:11px;font-weight:600;letter-spacing:0.6px;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #e5e7eb;white-space:nowrap;">Document</th>'
        . '<th align="left" style="padding:12px 16px;font-size:11px;font-weight:600;letter-spacing:0.6px;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #e5e7eb;">Link</th>'
        . '</tr></thead><tbody>';

    $tbody = '';
    foreach ($rows as $r) {
        $nice = htmlspecialchars($r['nice'], ENT_QUOTES, 'UTF-8');
        $hrefEsc = htmlspecialchars($r['link'], ENT_QUOTES, 'UTF-8');

        $tbody .= '<tr>';
        $tbody .= '<td class="cell doc-col" style="padding:14px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#111827;font-weight:600;vertical-align:top;text-align:left;white-space:nowrap;">';
        $tbody .= '<span class="mobile-label" style="display:none;font-size:11px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;color:#6b7280;">Document<br></span>';
        $tbody .= $nice;
        $tbody .= '</td>';
        $tbody .= '<td class="cell" style="padding:14px 16px;border-bottom:1px solid #e5e7eb;font-size:13px;vertical-align:top;text-align:left;word-break:break-all;overflow-wrap:anywhere;white-space:normal;">';
        $tbody .= '<span class="mobile-label" style="display:none;font-size:11px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;color:#6b7280;">Link<br></span>';
        $tbody .= '<a href="' . $hrefEsc . '" style="color:#2563eb;text-decoration:underline;word-break:break-all;overflow-wrap:anywhere;">' . $hrefEsc . '</a>';
        $tbody .= '</td>';
        $tbody .= '</tr>';
    }

    $tableClose = '</tbody></table>';

    $footer = '<p style="margin:16px 0 0;padding:0;text-align:left;font-size:12px;line-height:1.5;color:#6b7280;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;">'
        . $footerEsc . '</p>';

    $wrapClose = '</div>';

    return $wrapOpen . $style . $tableOpen . $thead . $tbody . $tableClose . $footer . $wrapClose;
}

/**
 * @param list<array{nice: string, link: string}> $rows
 */
function build_email_plain(array $rows, string $footerPlain): string
{
    if ($rows === []) {
        return '';
    }

    $blocks = [];
    foreach ($rows as $r) {
        $blocks[] = $r['nice'] . "\n" . $r['link'];
    }

    return implode("\n\n---\n\n", $blocks) . "\n\n---\n\n" . $footerPlain;
}

function wants_json_response(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

    return (strpos($accept, 'application/json') !== false)
        || (isset($_GET['format']) && $_GET['format'] === 'json');
}

function respond_json(bool $ok, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $extra), JSON_THROW_ON_ERROR);
}

/** @return list<array{stored: string, nice: string, link: string, until: string, expires_iso: ?string}> */
function doc_batch_get(): array
{
    $b = $_SESSION['doc_batch'] ?? [];
    if (!is_array($b)) {
        return [];
    }

    $out = [];
    foreach ($b as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (!isset($item['stored'], $item['nice'], $item['link'], $item['until'])) {
            continue;
        }
        $out[] = [
            'stored' => (string) $item['stored'],
            'nice' => (string) $item['nice'],
            'link' => (string) $item['link'],
            'until' => (string) $item['until'],
            'expires_iso' => isset($item['expires_iso']) ? (string) $item['expires_iso'] : null,
        ];
    }

    return $out;
}

/** @param array{stored: string, nice: string, link: string, until: string, expires_iso: ?string} $row */
function doc_batch_append(array $row): void
{
    $b = doc_batch_get();
    $b[] = $row;
    if (count($b) > DOC_BATCH_LIMIT) {
        $b = array_slice($b, -DOC_BATCH_LIMIT);
    }

    $_SESSION['doc_batch'] = $b;
}

function doc_batch_clear(): void
{
    unset($_SESSION['doc_batch']);
}

function doc_batch_remove_stored(string $stored): void
{
    $b = doc_batch_get();
    $stored = basename($stored);
    $b = array_values(array_filter($b, static function (array $row) use ($stored): bool {
        return $row['stored'] !== $stored;
    }));
    $_SESSION['doc_batch'] = $b;
}

$config = docupload_config();

if (!docupload_config_valid($config)) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(503);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doc upload — setup</title>
</head>
<body style="font-family:system-ui,sans-serif;padding:2rem;max-width:40rem;margin:auto;line-height:1.5;">
    <h1>Set up docupload</h1>
    <p>Edit <code>docupload_config.php</code> inside your private app folder (<code>private_files/downloads</code>).</p>
    <p>Set <code>password</code> to a strong passphrase (12+ characters). Optionally adjust <code>default_expiry_days</code> and <code>max_expiry_days</code>.</p>
</body>
</html>
    <?php
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['docupload_auth'], $_SESSION['doc_batch'], $_SESSION['docupload_flash'], $_SESSION['docupload_csrf']);
    doc_redirect();
}

/* ---------- POST ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';

    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        if (!docupload_verify_csrf($csrf)) {
            doc_redirect();
        }
        $pass = isset($_POST['password']) ? (string) $_POST['password'] : '';
        if (hash_equals(trim((string) $config['password']), $pass)) {
            $_SESSION['docupload_auth'] = true;
            doc_redirect();
        }
        $_SESSION['docupload_flash'] = ['error' => 'Wrong passphrase.'];
        doc_redirect();
    }

    if (!docupload_signed_in()) {
        if (wants_json_response()) {
            respond_json(false, 'Unauthorized', [], 401);
            exit;
        }
        doc_redirect();
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    $jsonUploadBypassCsrf = wants_json_response() && $action === 'upload';
    if (!$jsonUploadBypassCsrf && !docupload_verify_csrf($csrf)) {
        if (wants_json_response()) {
            respond_json(false, 'Bad CSRF token', [], 403);
            exit;
        }
        doc_redirect();
    }

    if ($action === 'clear_batch') {
        doc_batch_clear();
        $_SESSION['docupload_flash'] = ['info' => 'Session list cleared.'];
        doc_redirect();
    }

    if ($action === 'remove_batch') {
        $s = isset($_POST['stored']) ? basename((string) $_POST['stored']) : '';
        if ($s !== '') {
            doc_batch_remove_stored($s);
        }
        doc_redirect();
    }

    if ($action === 'regenerate') {
        $storedBase = isset($_POST['file']) ? basename((string) $_POST['file']) : '';
        $path = resolve_document_path($storedBase);
        $registry = load_registry();
        if (
            $storedBase !== ''
            && $path !== null
            && isset($registry[$storedBase])
            && is_array($registry[$storedBase])
        ) {
            $secret = random_secret_7();
            $passwordHex = xor_secret_to_hex_password($secret, XOR_KEY_TEXT);
            if ($passwordHex !== '' && strlen($passwordHex) === 14) {
                $registry[$storedBase]['hash'] = secret_material_hash($secret);
                $registry[$storedBase]['password_rotated_at'] = gmdate('c');
                try {
                    save_registry($registry);
                    $link = private_download_script_url()
                        . '?file=' . rawurlencode($storedBase)
                        . '&password=' . rawurlencode($passwordHex);
                    $_SESSION['docupload_flash'] = [
                        'new_link' => $link,
                    ];
                } catch (Throwable $e) {
                    $_SESSION['docupload_flash'] = ['error' => 'Could not save registry.'];
                }
            }
        }
        doc_redirect();
    }

    if ($action === 'extend_expiry') {
        $storedBase = isset($_POST['file']) ? basename((string) $_POST['file']) : '';
        $addDays = isset($_POST['extend_days']) ? (int) $_POST['extend_days'] : 1;
        $maxE = docupload_max_expiry($config);
        if ($addDays < 1) {
            $addDays = 1;
        }
        if ($addDays > $maxE) {
            $addDays = $maxE;
        }

        $registry = load_registry();
        if (
            $storedBase !== ''
            && isset($registry[$storedBase])
            && is_array($registry[$storedBase])
        ) {
            $path = resolve_document_path($storedBase);
            if ($path !== null && is_readable($path)) {
                $meta = $registry[$storedBase];
                $createdTs = isset($meta['created']) && is_string($meta['created']) ? strtotime($meta['created']) : false;
                $rawCap = $createdTs !== false
                    ? $createdTs + $maxE * 86400
                    : time() + $maxE * 86400;
                $capTs = downloads_end_of_utc_day_timestamp($rawCap);

                $currentExp = isset($meta['expires_at']) && is_string($meta['expires_at']) ? strtotime($meta['expires_at']) : false;
                $baseTs = time();
                if ($currentExp !== false && $currentExp > $baseTs) {
                    $baseTs = $currentExp;
                }

                $newTs = downloads_end_of_utc_day_timestamp($baseTs + $addDays * 86400);
                if ($newTs > $capTs) {
                    $newTs = $capTs;
                }
                if ($newTs < $baseTs) {
                    $newTs = $baseTs;
                }

                $registry[$storedBase]['expires_at'] = gmdate('c', $newTs);
                try {
                    save_registry($registry);
                    $_SESSION['docupload_flash'] = ['info' => 'Expiry updated.'];
                } catch (Throwable $e) {
                    $_SESSION['docupload_flash'] = ['error' => 'Could not save registry.'];
                }
            }
        }
        doc_redirect();
    }

    if ($action === 'delete_document') {
        $storedBase = isset($_POST['file']) ? basename((string) $_POST['file']) : '';
        if ($storedBase !== '') {
            $registry = load_registry();
            if (isset($registry[$storedBase])) {
                unset($registry[$storedBase]);
                $path = resolve_document_path($storedBase);
                if ($path !== null && is_file($path)) {
                    @unlink($path);
                }
                try {
                    save_registry($registry);
                    doc_batch_remove_stored($storedBase);
                    $_SESSION['docupload_flash'] = ['info' => 'Document removed.'];
                } catch (Throwable $e) {
                    $_SESSION['docupload_flash'] = ['error' => 'Could not save registry.'];
                }
            }
        }
        doc_redirect();
    }

    if ($action === 'upload') {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            if (wants_json_response()) {
                respond_json(false, 'No file field "file"', [], 400);
                exit;
            }
            $_SESSION['docupload_flash'] = ['error' => 'No file uploaded.'];
            doc_redirect();
        }

        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $msg = 'Upload error code ' . (string) ($file['error'] ?? '?');
            if (wants_json_response()) {
                respond_json(false, $msg, [], 400);
                exit;
            }
            $_SESSION['docupload_flash'] = ['error' => $msg];
            doc_redirect();
        }

        $maxE = docupload_max_expiry($config);
        $expiryDays = isset($_POST['expiry_days']) ? (int) $_POST['expiry_days'] : docupload_default_expiry($config);
        if (!empty($_POST['no_expiry'])) {
            $expiryDays = 0;
        }
        if ($expiryDays < 0) {
            $expiryDays = 0;
        }
        if ($expiryDays > $maxE) {
            $expiryDays = $maxE;
        }

        ensure_documents_dir();

        $originalName = (string) ($file['name'] ?? 'upload.bin');
        $fallbackNice = basename($originalName);
        $niceInput = isset($_POST['nice_name']) ? (string) $_POST['nice_name'] : '';
        $displayName = sanitize_display_name($niceInput, $fallbackNice);

        $ext = safe_storage_extension($originalName);
        $storedBase = bin2hex(random_bytes(16)) . $ext;
        $targetPath = documents_dir() . '/' . $storedBase;

        if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
            if (wants_json_response()) {
                respond_json(false, 'Could not save file', [], 500);
                exit;
            }
            $_SESSION['docupload_flash'] = ['error' => 'Could not save file.'];
            doc_redirect();
        }

        @chmod($targetPath, 0640);

        $secret = random_secret_7();
        $passwordHex = xor_secret_to_hex_password($secret, XOR_KEY_TEXT);
        if ($passwordHex === '' || strlen($passwordHex) !== 14) {
            unlink($targetPath);
            if (wants_json_response()) {
                respond_json(false, 'Password generation failed', [], 500);
                exit;
            }
            $_SESSION['docupload_flash'] = ['error' => 'Password generation failed.'];
            doc_redirect();
        }

        $expiresAt = expires_at_from_days($expiryDays);

        try {
            $registry = load_registry();
            $registry[$storedBase] = [
                'hash' => secret_material_hash($secret),
                'original' => $originalName,
                'display_name' => $displayName,
                'created' => gmdate('c'),
                'expires_at' => $expiresAt,
            ];
            save_registry($registry);
        } catch (Throwable $e) {
            unlink($targetPath);
            if (wants_json_response()) {
                respond_json(false, 'Could not save registry', [], 500);
                exit;
            }
            $_SESSION['docupload_flash'] = ['error' => 'Could not save registry.'];
            doc_redirect();
        }

        $link = private_download_script_url()
            . '?file=' . rawurlencode($storedBase)
            . '&password=' . rawurlencode($passwordHex);
        $untilLabel = format_until_label($expiresAt);

        if (wants_json_response()) {
            respond_json(true, 'Uploaded', [
                'file' => $storedBase,
                'password' => $passwordHex,
                'display_name' => $displayName,
                'link' => $link,
                'expires_at' => $expiresAt,
            ]);
            exit;
        }

        doc_batch_append([
            'stored' => $storedBase,
            'nice' => $displayName,
            'link' => $link,
            'until' => $untilLabel,
            'expires_iso' => $expiresAt,
        ]);

        $_SESSION['docupload_flash'] = ['uploaded' => $displayName];
        doc_redirect();
    }

    doc_redirect();
}

/* ---------- GET (HTML) ---------- */

header('Content-Type: text/html; charset=UTF-8');

$csrf = docupload_csrf();
$flash = $_SESSION['docupload_flash'] ?? null;
unset($_SESSION['docupload_flash']);

if (!docupload_signed_in()) {
    $err = (is_array($flash) && isset($flash['error'])) ? (string) $flash['error'] : '';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document portal — sign in</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #09090b; color: #fafafa; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .box { width: 100%; max-width: 22rem; background: #18181b; border: 1px solid #27272a; border-radius: 14px; padding: 1.75rem; }
        h1 { font-size: 1.15rem; margin: 0 0 1rem; font-weight: 650; }
        label { display: block; font-size: 0.82rem; margin-bottom: 0.35rem; color: #a1a1aa; }
        input[type="password"] { width: 100%; box-sizing: border-box; padding: 0.55rem 0.65rem; border-radius: 8px; border: 1px solid #3f3f46; background: #09090b; color: inherit; margin-bottom: 1rem; }
        button { width: 100%; padding: 0.6rem; border-radius: 8px; border: none; background: #2563eb; color: #fff; font-weight: 600; cursor: pointer; font-size: 0.95rem; }
        .err { background: #450a0a; border: 1px solid #991b1b; padding: 0.65rem; border-radius: 8px; font-size: 0.88rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Document portal</h1>
        <?php if ($err !== ''): ?>
            <div class="err"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post" action="">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="login">
            <label for="pw">Passphrase</label>
            <input id="pw" name="password" type="password" autocomplete="current-password" required>
            <button type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
    <?php
    exit;
}

$tabRaw = isset($_GET['tab']) ? (string) $_GET['tab'] : 'portal';
$activeTab = in_array($tabRaw, ['portal', 'library', 'stats'], true) ? $tabRaw : 'portal';

if ($activeTab === 'portal') {
    $defExp = docupload_default_expiry($config);
    $maxExp = docupload_max_expiry($config);
    $batch = doc_batch_get();

    $batchFooter = doc_batch_email_footer_for_export($config, $batch);
    $emailRows = [];
    foreach ($batch as $b) {
        $emailRows[] = ['nice' => $b['nice'], 'link' => $b['link']];
    }
    $emailHtml = $emailRows !== [] ? build_email_html_table($emailRows, $batchFooter) : '';
    $emailPlain = $emailRows !== [] ? build_email_plain($emailRows, $batchFooter) : '';

    $library = [];
} elseif ($activeTab === 'library') {
    $defExp = 0;
    $maxExp = docupload_max_expiry($config);
    $batch = [];
    $emailHtml = '';
    $emailPlain = '';
    $library = doc_build_library_rows();
} else {
    $statsMonth = isset($_GET['log_month']) ? (string) $_GET['log_month'] : gmdate('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $statsMonth)) {
        $statsMonth = gmdate('Y-m');
    }
    $statsFilter = isset($_GET['filter']) ? (string) $_GET['filter'] : 'all';
    if (!in_array($statsFilter, ['all', 'ok', 'err'], true)) {
        $statsFilter = 'all';
    }

    $statsMonths = downloads_available_download_log_months(36);
    if (!in_array($statsMonth, $statsMonths, true)) {
        $statsMonths[] = $statsMonth;
        rsort($statsMonths, SORT_STRING);
        $statsMonths = array_slice(array_values(array_unique($statsMonths)), 0, 36);
    }

    $statsLogPath = downloads_monthly_log_path('downloads', $statsMonth);
    $statsLogReadable = $statsLogPath !== '' && is_readable($statsLogPath);
    $statsLogMissing = $statsLogPath !== '' && !is_file($statsLogPath);

    $statsTailLines = $statsLogReadable
        ? downloads_tail_text_file_lines($statsLogPath, 500)
        : [];
    $statsParsed = [];
    foreach ($statsTailLines as $ln) {
        $p = downloads_parse_download_log_line($ln);
        if ($p !== null) {
            $statsParsed[] = $p;
        }
    }
    $statsParsed = array_reverse($statsParsed);

    $statsCountOk = 0;
    $statsCountErr = 0;
    foreach ($statsParsed as $p) {
        if ($p['result'] === 'ok') {
            $statsCountOk++;
        } else {
            $statsCountErr++;
        }
    }

    $statsRows = $statsParsed;
    if ($statsFilter === 'ok') {
        $statsRows = array_values(array_filter($statsParsed, static function (array $r): bool {
            return $r['result'] === 'ok';
        }));
    } elseif ($statsFilter === 'err') {
        $statsRows = array_values(array_filter($statsParsed, static function (array $r): bool {
            return $r['result'] === 'err';
        }));
    }

    $statsRows = array_map('downloads_stats_row_enrich', $statsRows);

    $defExp = 0;
    $maxExp = 0;
    $batch = [];
    $emailHtml = '';
    $emailPlain = '';
    $library = [];
}

$pageTitle = $activeTab === 'stats' ? 'Download statistics' : ($activeTab === 'library' ? 'Documents' : 'Document portal');

$flashErr = (is_array($flash) && isset($flash['error'])) ? (string) $flash['error'] : '';
$flashInfo = (is_array($flash) && isset($flash['info'])) ? (string) $flash['info'] : '';
$flashUploaded = (is_array($flash) && isset($flash['uploaded'])) ? (string) $flash['uploaded'] : '';
$flashNewLink = (is_array($flash) && isset($flash['new_link'])) ? (string) $flash['new_link'] : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        :root {
            --bg: #fafafa;
            --card: #ffffff;
            --border: #e4e4e7;
            --text: #18181b;
            --muted: #71717a;
            --accent: #2563eb;
            --danger: #b91c1c;
            --warn-bg: #fffbeb;
            --warn-border: #fcd34d;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #09090b;
                --card: #18181b;
                --border: #27272a;
                --text: #fafafa;
                --muted: #a1a1aa;
                --warn-bg: #422006;
                --warn-border: #ca8a04;
            }
        }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 1.25rem 1rem 3rem; line-height: 1.45; }
        .wrap { max-width: 1100px; margin: 0 auto; }
        header { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.75rem; margin-bottom: 1.25rem; }
        header h1 { font-size: 1.35rem; margin: 0; font-weight: 650; }
        header nav { font-size: 0.9rem; display: flex; flex-wrap: wrap; align-items: center; gap: 0.65rem 1rem; }
        header nav a { color: var(--muted); }
        .tabs { display: inline-flex; gap: 0.35rem; align-items: center; margin-right: auto; }
        .tabs a {
            text-decoration: none;
            padding: 0.28rem 0.65rem;
            border-radius: 8px;
            border: 1px solid transparent;
            color: var(--muted);
            font-weight: 500;
        }
        .tabs a.tab-active {
            background: var(--card);
            border-color: var(--border);
            color: var(--text);
            font-weight: 650;
        }
        .stats-toolbar { display: flex; flex-wrap: wrap; gap: 0.75rem 1.25rem; align-items: flex-end; margin-bottom: 1rem; }
        .stats-toolbar .field { margin: 0; }
        .stats-toolbar select { max-width: 12rem; }
        .stat-chips { display: flex; flex-wrap: wrap; gap: 0.45rem; align-items: center; }
        .stats-table-wrap { overflow-x: auto; margin-top: 0.5rem; }
        td.ua-cell { max-width: 12rem; font-size: 0.78rem; color: var(--muted); word-break: break-word; }
        td.stats-meta-cell { max-width: 11rem; font-size: 0.78rem; color: var(--muted); word-break: break-word; }
        .stack-portal { display: flex; flex-direction: column; gap: 1.25rem; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.15rem 1.25rem;
        }
        .card h2 { font-size: 1rem; margin: 0 0 0.75rem; font-weight: 650; }
        .upload-form .field { margin-bottom: 1rem; }
        label { display: block; font-size: 0.82rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--muted); }
        input[type="text"], input[type="number"], input[type="file"] {
            width: 100%;
            max-width: 100%;
            padding: 0.5rem 0.6rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--card);
            color: inherit;
            font-size: 0.9rem;
        }
        input[type="file"] { padding: 0.4rem; font-size: 0.85rem; }
        input[type="checkbox"] { width: auto; margin: 0; }
        .row-check { margin-bottom: 0; font-size: 0.88rem; }
        .row-check label {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            font-weight: 500;
            color: var(--text);
            cursor: pointer;
            margin-bottom: 0;
        }
        .row-check input[type="checkbox"] { flex-shrink: 0; margin-top: 0.2rem; }
        button, .btn {
            appearance: none;
            border: none;
            border-radius: 8px;
            padding: 0.45rem 0.85rem;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            background: var(--accent);
            color: #fff;
        }
        button.secondary { background: transparent; color: var(--text); border: 1px solid var(--border); }
        button.danger { background: #dc2626; }
        button.upload-submit { margin-top: 0.25rem; width: 100%; padding: 0.55rem 0.85rem; font-size: 0.95rem; }
        button.small { padding: 0.22rem 0.45rem; font-size: 0.78rem; width: auto; }
        .banner {
            border-radius: 10px;
            padding: 0.85rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .banner.ok { background: var(--warn-bg); border: 1px solid var(--warn-border); }
        .banner.bad { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        @media (prefers-color-scheme: dark) {
            .banner.bad { background: #450a0a; border-color: #991b1b; color: #fecaca; }
        }
        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        th, td { text-align: left; padding: 0.45rem 0.35rem; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; }
        code { font-family: ui-monospace, monospace; font-size: 0.78rem; word-break: break-all; }
        .muted { color: var(--muted); font-size: 0.85rem; }
        .pill { display: inline-block; padding: 0.12rem 0.45rem; border-radius: 999px; font-size: 0.72rem; font-weight: 600; }
        .pill.bad { background: #fee2e2; color: #991b1b; }
        .pill.ok { background: #dcfce7; color: #166534; }
        .pill.warn { background: #fef3c7; color: #92400e; }
        @media (prefers-color-scheme: dark) {
            .pill.bad { background: #450a0a; color: #fecaca; }
            .pill.ok { background: #052e16; color: #bbf7d0; }
            .pill.warn { background: #422006; color: #fde68a; }
        }
        .session-export-card { position: relative; }
        .clipboard-src {
            position: absolute;
            left: -9999px;
            top: 0;
            width: 4px;
            height: 4px;
            opacity: 0;
            overflow: hidden;
        }
        .batch-email-preview-wrap {
            margin-top: 0.75rem;
            overflow-x: auto;
            max-width: 100%;
            border-radius: 10px;
            border: 1px solid var(--border);
            padding: 12px;
            background: #ffffff;
            text-align: left;
        }
        @media (prefers-color-scheme: dark) {
            .batch-email-preview-wrap {
                background: #fafafa;
            }
        }
        .batch-queue-table td.doc-cell {
            word-break: break-word;
            overflow-wrap: anywhere;
            white-space: normal;
            max-width: 14rem;
            font-weight: 600;
            font-size: 0.84rem;
        }
        .btn-row { display: flex; flex-wrap: wrap; gap: 0.45rem; margin-top: 0.35rem; }
        .library-actions { display: flex; flex-direction: column; align-items: flex-start; gap: 0.45rem; }
        .library-actions form { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; margin: 0; }
        .library-actions select { padding: 0.28rem 0.45rem; border-radius: 6px; border: 1px solid var(--border); background: var(--card); color: inherit; font-size: 0.78rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <h1><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <nav>
                <span class="tabs">
                    <a href="?tab=portal" class="<?php echo $activeTab === 'portal' ? 'tab-active' : ''; ?>">Portal</a>
                    <a href="?tab=library" class="<?php echo $activeTab === 'library' ? 'tab-active' : ''; ?>">Documents</a>
                    <a href="?tab=stats" class="<?php echo $activeTab === 'stats' ? 'tab-active' : ''; ?>">Statistics</a>
                </span>
                <a href="?logout=1">Sign out</a>
            </nav>
        </header>

        <?php if ($flashErr !== ''): ?>
            <div class="banner bad"><?php echo htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($flashInfo !== ''): ?>
            <div class="banner ok"><?php echo htmlspecialchars($flashInfo, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($flashUploaded !== ''): ?>
            <div class="banner ok">Uploaded <strong><?php echo htmlspecialchars($flashUploaded, ENT_QUOTES, 'UTF-8'); ?></strong>.</div>
        <?php endif; ?>
        <?php if ($flashNewLink !== ''): ?>
            <div class="banner ok">
                New link:
                <div style="margin-top:0.5rem;"><?php echo format_download_block_html($flashNewLink); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($activeTab === 'portal'): ?>
        <div class="stack-portal">
            <div class="card">
                <h2>Upload</h2>
                <p class="muted" style="margin:0 0 1rem;">Files you upload are queued below for email export.</p>
                <form class="upload-form" method="post" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="return_tab" value="portal">
                    <div class="field">
                        <label for="nice_name">Display name (optional)</label>
                        <input id="nice_name" name="nice_name" type="text" placeholder="Uses file name if empty" autocomplete="off">
                    </div>
                    <div class="field">
                        <label for="file">File</label>
                        <input id="file" name="file" type="file" required>
                    </div>
                    <div class="field">
                        <label for="expiry_days">Link expires in (days)</label>
                        <input id="expiry_days" name="expiry_days" type="number" min="1" max="<?php echo (string) $maxExp; ?>" value="<?php echo (string) $defExp; ?>">
                    </div>
                    <div class="field row-check">
                        <label for="no_expiry"><input id="no_expiry" type="checkbox" name="no_expiry" value="1"> No expiry</label>
                    </div>
                    <button type="submit" class="upload-submit">Upload</button>
                </form>
            </div>

            <div class="card session-export-card">
                <h2>Session queue</h2>
                <?php if ($batch === []): ?>
                    <p class="muted">Nothing queued yet.</p>
                <?php else: ?>
                    <p class="muted" style="margin-top:0;">Copy HTML or plain text into your email. New uploads and extensions use <strong>end of UTC calendar day</strong> (<code>23:59:59 UTC</code>) for expiry. Auto footer groups links that share that instant; older rows may still show per-link variance until renewed. Override with <code>batch_email_footer</code> in config.</p>

                    <table class="batch-queue-table" style="margin-top:0.75rem;">
                        <thead>
                            <tr>
                                <th>Document</th>
                                <th>Expires</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($batch as $b): ?>
                                <tr>
                                    <td class="doc-cell"><?php echo htmlspecialchars($b['nice'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($b['until'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <form class="inline" method="post" action="">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="remove_batch">
                                            <input type="hidden" name="return_tab" value="portal">
                                            <input type="hidden" name="stored" value="<?php echo htmlspecialchars($b['stored'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="small secondary">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p style="margin:1.25rem 0 0.5rem;font-size:0.82rem;font-weight:650;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;">Preview</p>
                    <div class="btn-row" style="margin-bottom:0;">
                        <button type="button" id="copy-html">Copy HTML</button>
                        <button type="button" class="secondary" id="copy-plain">Copy plain text</button>
                    </div>
                    <textarea id="email-html" class="clipboard-src" readonly tabindex="-1" aria-hidden="true"><?php echo $emailHtml; ?></textarea>
                    <textarea id="email-plain" class="clipboard-src" readonly tabindex="-1" aria-hidden="true"><?php echo $emailPlain; ?></textarea>
                    <div class="batch-email-preview-wrap">
                        <?php echo $emailHtml; ?>
                    </div>

                    <form method="post" action="" style="margin-top:1rem;" onsubmit="return confirm('Clear the queued list?');">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="clear_batch">
                        <input type="hidden" name="return_tab" value="portal">
                        <button type="submit" class="secondary">Clear queue</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($activeTab === 'library'): ?>
        <div class="card">
            <p class="muted" style="margin-top:0;">Manage stored files. Extend adds days from the current expiry (or from today). Total lifetime from original upload is capped at <?php echo (string) $maxExp; ?> days (<code>max_expiry_days</code>).</p>
            <?php if ($library === []): ?>
                <p class="muted">No documents yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Status</th>
                            <th>Until</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($library as $row): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($row['nice'] !== '' ? $row['nice'] : '—', ENT_QUOTES, 'UTF-8'); ?>
                                    <br><code><?php echo htmlspecialchars($row['stored'], ENT_QUOTES, 'UTF-8'); ?></code>
                                </td>
                                <td>
                                    <?php if ($row['missing']): ?>
                                        <span class="pill warn">Missing</span>
                                    <?php elseif ($row['expired']): ?>
                                        <span class="pill bad">Expired</span>
                                    <?php else: ?>
                                        <span class="pill ok">Active</span>
                                    <?php endif; ?>
                                    <div class="muted" style="margin-top:0.35rem;font-size:0.78rem;"><?php echo htmlspecialchars($row['expiry_detail'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($row['until'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <div class="library-actions">
                                        <?php if (!$row['missing']): ?>
                                            <form class="inline" method="post" action="" onsubmit="return confirm('Replace the download URL? Old links will stop working.');">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="regenerate">
                                                <input type="hidden" name="return_tab" value="library">
                                                <input type="hidden" name="file" value="<?php echo htmlspecialchars($row['stored'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="small">Regenerate</button>
                                            </form>
                                            <form class="inline" method="post" action="">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="extend_expiry">
                                                <input type="hidden" name="return_tab" value="library">
                                                <input type="hidden" name="file" value="<?php echo htmlspecialchars($row['stored'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <label class="muted" style="display:inline;font-weight:500;margin:0;">Extend</label>
                                                <select name="extend_days" aria-label="Days to extend">
                                                    <?php for ($di = 1; $di <= $maxExp; $di++): ?>
                                                        <option value="<?php echo (string) $di; ?>"><?php echo (string) $di; ?>d</option>
                                                    <?php endfor; ?>
                                                </select>
                                                <button type="submit" class="small secondary">Apply</button>
                                            </form>
                                        <?php endif; ?>
                                        <form class="inline" method="post" action="" onsubmit="return confirm('Delete this document and remove its link?');">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="delete_document">
                                            <input type="hidden" name="return_tab" value="library">
                                            <input type="hidden" name="file" value="<?php echo htmlspecialchars($row['stored'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="small danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <div class="card">
            <p class="muted" style="margin-top:0;">Download log (<code>downloads_YYYY-MM.log</code> in <code>log_dir</code>). Newest first, last 500 lines. Errors also go to <code>php_errors_YYYY-MM.log</code>.</p>
            <p class="muted" style="margin-top:0.5rem;font-size:0.85rem;">Each row stores <code>CF-IPCountry</code> (if behind Cloudflare), <code>Accept-Language</code>, and <code>Referer</code> when the client sends them. Country prefers that header, then optional GeoLite2 (<code>geoip_country_mmdb</code>), then cached HTTPS geolocation (ipwho.is) — enabled by default unless you set <code>geoip_allow_online_lookup</code> to <code>false</code> in <code>bootstrap.php</code>.</p>

            <?php if ($statsLogPath === ''): ?>
                <div class="banner bad"><code>log_dir</code> is not set — fix <code>bootstrap.php</code> and reload.</div>
            <?php else: ?>
                <div class="stats-toolbar">
                    <form method="get" action="" class="field">
                        <input type="hidden" name="tab" value="stats">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($statsFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <label for="log_month">Month (UTC)</label>
                        <select id="log_month" name="log_month" onchange="this.form.submit()" style="width:100%;max-width:12rem;padding:0.45rem 0.55rem;border-radius:8px;border:1px solid var(--border);background:var(--card);color:inherit;font-size:0.88rem;">
                            <?php foreach ($statsMonths as $ym): ?>
                                <option value="<?php echo htmlspecialchars($ym, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $ym === $statsMonth ? 'selected' : ''; ?>><?php echo htmlspecialchars($ym, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <div class="stat-chips">
                        <span class="muted" style="font-size:0.82rem;">Show:</span>
                        <?php
                        $uAll = '?' . http_build_query(['tab' => 'stats', 'log_month' => $statsMonth, 'filter' => 'all']);
                        $uOk = '?' . http_build_query(['tab' => 'stats', 'log_month' => $statsMonth, 'filter' => 'ok']);
                        $uErr = '?' . http_build_query(['tab' => 'stats', 'log_month' => $statsMonth, 'filter' => 'err']);
                        ?>
                        <a class="<?php echo $statsFilter === 'all' ? 'btn' : 'btn secondary'; ?>" style="padding:0.32rem 0.7rem;font-size:0.82rem;text-decoration:none;display:inline-block;border-radius:8px;" href="<?php echo htmlspecialchars($uAll, ENT_QUOTES, 'UTF-8'); ?>">All</a>
                        <a class="<?php echo $statsFilter === 'ok' ? 'btn' : 'btn secondary'; ?>" style="padding:0.32rem 0.7rem;font-size:0.82rem;text-decoration:none;display:inline-block;border-radius:8px;" href="<?php echo htmlspecialchars($uOk, ENT_QUOTES, 'UTF-8'); ?>">Success only</a>
                        <a class="<?php echo $statsFilter === 'err' ? 'btn' : 'btn secondary'; ?>" style="padding:0.32rem 0.7rem;font-size:0.82rem;text-decoration:none;display:inline-block;border-radius:8px;" href="<?php echo htmlspecialchars($uErr, ENT_QUOTES, 'UTF-8'); ?>">Errors only</a>
                    </div>
                </div>

                <?php if (!$statsLogReadable): ?>
                    <?php if ($statsLogMissing): ?>
                        <p class="muted" style="margin-top:1rem;">No log file for <?php echo htmlspecialchars($statsMonth, ENT_QUOTES, 'UTF-8'); ?> yet.</p>
                    <?php else: ?>
                        <div class="banner bad" style="margin-top:1rem;">The log file exists but is not readable by PHP — check permissions on <code>log_dir</code>.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="muted" style="margin:1rem 0 0.75rem;font-size:0.88rem;">
                        In this sample (up to 500 lines): <span class="pill ok"><?php echo (string) $statsCountOk; ?> success</span>
                        <span class="pill bad"><?php echo (string) $statsCountErr; ?> blocked / error</span>
                        <?php if ($statsFilter !== 'all'): ?>
                            · showing <?php echo (string) count($statsRows); ?> row<?php echo count($statsRows) === 1 ? '' : 's'; ?> after filter
                        <?php endif; ?>
                    </p>
                    <?php if ($statsRows === []): ?>
                        <p class="muted">No rows match the current filter.</p>
                    <?php else: ?>
                        <div class="stats-table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Time (UTC)</th>
                                        <th>Result</th>
                                        <th>Document / detail</th>
                                        <th>Country</th>
                                        <th>Language</th>
                                        <th>Referrer</th>
                                        <th>IP</th>
                                        <th>Browser</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($statsRows as $sr): ?>
                                        <?php
                                        $ts = strtotime($sr['iso']);
                                        $when = $ts !== false ? gmdate('M j, Y · H:i:s', $ts) : $sr['iso'];
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($when, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php if ($sr['result'] === 'ok'): ?>
                                                    <span class="pill ok"><?php echo htmlspecialchars($sr['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php else: ?>
                                                    <span class="pill bad"><?php echo htmlspecialchars($sr['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($sr['file_note'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="stats-meta-cell"><?php echo htmlspecialchars($sr['country_display'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="stats-meta-cell"><code><?php echo htmlspecialchars($sr['lang_primary'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                            <td class="stats-meta-cell" title="<?php echo htmlspecialchars($sr['referer'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sr['referrer_short'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><code><?php echo htmlspecialchars($sr['ip'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                            <td class="ua-cell" title="<?php echo htmlspecialchars($sr['ua'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sr['client_hint'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        var htmlTa = document.getElementById('email-html');
        var plainTa = document.getElementById('email-plain');
        var btnH = document.getElementById('copy-html');
        var btnP = document.getElementById('copy-plain');
        if (!btnH || !htmlTa || !plainTa || !btnP) return;

        btnH.addEventListener('click', async function () {
            var html = htmlTa.value;
            var plain = plainTa.value;
            try {
                if (navigator.clipboard && window.ClipboardItem) {
                    await navigator.clipboard.write([
                        new ClipboardItem({
                            'text/html': new Blob([html], {type: 'text/html'}),
                            'text/plain': new Blob([plain], {type: 'text/plain'})
                        })
                    ]);
                    btnH.textContent = 'Copied!';
                    setTimeout(function () { btnH.textContent = 'Copy HTML'; }, 1800);
                    return;
                }
            } catch (e) {}
            htmlTa.select();
            document.execCommand('copy');
            btnH.textContent = 'Copied!';
            setTimeout(function () { btnH.textContent = 'Copy HTML'; }, 1800);
        });

        btnP.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(plainTa.value);
            } catch (e) {
                try {
                    plainTa.focus();
                    plainTa.select();
                    document.execCommand('copy');
                } catch (ex2) {}
            }
            btnP.textContent = 'Copied!';
            setTimeout(function () { btnP.textContent = 'Copy plain text'; }, 1800);
        });
    })();
    </script>
</body>
</html>
