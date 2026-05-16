<?php

declare(strict_types=1);

/**
 * Public download endpoint — deploy at the document root of download.sumbaprop.com as index.php
 *
 * 1) Set DOWNLOADS_PRIVATE_ROOT to the absolute path of private_files/downloads.
 * 2) Set DOWNLOADS_SHOW_SETUP_ERRORS to true temporarily if you see a blank page —
 *    then reload to read the error message (set back to false afterward).
 */
define('DOWNLOADS_PRIVATE_ROOT', '/home/sumbap/private_files/downloads');
define('DOWNLOADS_SHOW_SETUP_ERRORS', false);

try {
    require DOWNLOADS_PRIVATE_ROOT . '/init.php';
    require DOWNLOADS_PRIVATE_ROOT . '/lib/serve_download.php';
} catch (Throwable $e) {
    error_log('[downloads index.php] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (defined('DOWNLOADS_SHOW_SETUP_ERRORS') && DOWNLOADS_SHOW_SETUP_ERRORS) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $e->getMessage();
        exit;
    }

    $gateRoot = defined('DOWNLOADS_PRIVATE_ROOT') ? DOWNLOADS_PRIVATE_ROOT : '';
    $gateCommon = ($gateRoot !== '' && is_readable($gateRoot . '/lib/common.php')) ? $gateRoot . '/lib/common.php' : '';

    if ($gateCommon !== '') {
        require_once $gateCommon;
        if (function_exists('downloads_gate_render_exit')) {
            downloads_gate_render_exit(503, 'unavailable');
        }
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Downloads unavailable.\n";
}
