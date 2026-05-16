<?php

declare(strict_types=1);

/**
 * Alternate admin URL — same code as index.php.
 *
 * 1) DOWNLOADS_PRIVATE_ROOT must match file.php exactly.
 * 2) Set DOWNLOADS_SHOW_SETUP_ERRORS true briefly if this page is blank.
 */
define('DOWNLOADS_PRIVATE_ROOT', '/home/sumbap/private_files/downloads');
define('DOWNLOADS_SHOW_SETUP_ERRORS', false);

try {
    require DOWNLOADS_PRIVATE_ROOT . '/init.php';
    require DOWNLOADS_PRIVATE_ROOT . '/lib/docupload_app.php';
} catch (Throwable $e) {
    error_log('[downloads admin/docupload.php] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');

    if (defined('DOWNLOADS_SHOW_SETUP_ERRORS') && DOWNLOADS_SHOW_SETUP_ERRORS) {
        echo $e->getMessage();

        exit;
    }

    echo "Admin unavailable (configuration error). Check PHP error_log, bootstrap.php, and docupload_config.php.\n";
}
