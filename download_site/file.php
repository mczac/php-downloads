<?php

declare(strict_types=1);

/**
 * Public download endpoint — deploy on download.sumbaprop.com as file.php
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
    error_log('[downloads file.php] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');

    if (defined('DOWNLOADS_SHOW_SETUP_ERRORS') && DOWNLOADS_SHOW_SETUP_ERRORS) {
        echo $e->getMessage();

        exit;
    }

    echo "Downloads unavailable (configuration error). Check PHP error_log and that bootstrap.php exists under DOWNLOADS_PRIVATE_ROOT.\n";
}
