<?php

declare(strict_types=1);

function mime_for_download(string $path): string
{
    if (function_exists('mime_content_type')) {
        $t = @mime_content_type($path);
        if (is_string($t) && $t !== '') {
            return $t;
        }
    }

    if (class_exists('finfo')) {
        try {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $t = $fi->file($path);
            if (is_string($t) && $t !== '') {
                return $t;
            }
        } catch (Throwable $e) {
            // fall through
        }
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return 'application/pdf';
    }

    return 'application/octet-stream';
}

function discard_output_buffers(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

$fileParam = isset($_GET['file']) ? (string) $_GET['file'] : '';
if (isset($_GET['password']) && (string) $_GET['password'] !== '') {
    $passwordParam = (string) $_GET['password'];
} elseif (isset($_GET['pwd']) && (string) $_GET['pwd'] !== '') {
    $passwordParam = (string) $_GET['pwd'];
} else {
    $passwordParam = '';
}

if ($fileParam === '' || $passwordParam === '') {
    downloads_log('downloads', 'bad_request|missing_params|file=' . $fileParam);
    downloads_gate_render_exit(403, 'unauthorized');
}

if (strlen($passwordParam) !== 14) {
    downloads_log('downloads', 'bad_password|wrong_length|file=' . basename($fileParam));
    downloads_gate_render_exit(403, 'unauthorized');
}

$decoded = xor_hex_with_text_key($passwordParam, XOR_KEY_TEXT);
if (strlen($decoded) !== 7) {
    downloads_log('downloads', 'bad_password|decode_fail|file=' . basename($fileParam));
    downloads_gate_render_exit(403, 'unauthorized');
}

$path = resolve_document_path($fileParam);
if ($path === null || !is_readable($path)) {
    downloads_log('downloads', 'not_found|no_file|file=' . basename($fileParam));
    downloads_gate_render_exit(403, 'unauthorized');
}

$registry = load_registry();
$entry = $registry[basename($fileParam)] ?? null;
if ($entry === null || !is_array($entry) || !isset($entry['hash'])) {
    downloads_log('downloads', 'not_found|no_registry|file=' . basename($fileParam));
    downloads_gate_render_exit(403, 'unauthorized');
}

if (!hash_equals((string) $entry['hash'], secret_material_hash($decoded))) {
    downloads_log('downloads', 'forbidden|hash_mismatch|file=' . basename($fileParam));
    downloads_gate_render_exit(403, 'unauthorized');
}

if (registry_entry_is_expired($entry)) {
    downloads_log('downloads', 'gone|expired|file=' . basename($fileParam));
    downloads_gate_render_exit(410, 'expired');
}

$original = registry_entry_display_name($entry);
if ($original === '') {
    $original = basename($path);
}

$size = filesize($path);
if ($size === false) {
    downloads_log('downloads', 'error|no_size|file=' . basename($fileParam));
    downloads_gate_render_exit(503, 'unavailable');
}

$stream = @fopen($path, 'rb');
if ($stream === false) {
    downloads_log('downloads', 'error|open_fail|file=' . basename($fileParam));
    downloads_gate_render_exit(503, 'unavailable');
}

downloads_log('downloads', 'ok|' . basename($fileParam) . '|as|' . $original);

@ini_set('zlib.output_compression', '0');
discard_output_buffers();

$mime = mime_for_download($path);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $original) . '"');
header('Content-Length: ' . (string) $size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

fpassthru($stream);
fclose($stream);
exit;
