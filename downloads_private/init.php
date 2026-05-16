<?php

declare(strict_types=1);

if (!defined('DOWNLOADS_PRIVATE_ROOT')) {
    throw new RuntimeException('Define DOWNLOADS_PRIVATE_ROOT before loading init.php (absolute path to this folder).');
}

$bootstrapPath = DOWNLOADS_PRIVATE_ROOT . '/bootstrap.php';
if (!is_readable($bootstrapPath)) {
    throw new RuntimeException('Missing ' . $bootstrapPath . ' — copy bootstrap.example.php to bootstrap.php.');
}

/** @var array<string, mixed> $cfg */
$cfg = require $bootstrapPath;

$required = ['documents_dir', 'registry_file', 'log_dir', 'public_download_url'];
foreach ($required as $key) {
    if (empty($cfg[$key]) || !is_string($cfg[$key])) {
        throw new RuntimeException('bootstrap.php must define string key: ' . $key);
    }
}

$GLOBALS['DOWNLOADS_BOOTSTRAP'] = $cfg;

require_once DOWNLOADS_PRIVATE_ROOT . '/lib/common.php';
require_once DOWNLOADS_PRIVATE_ROOT . '/lib/logging.php';

if (!defined('DOWNLOADS_APP_INIT')) {
    define('DOWNLOADS_APP_INIT', true);
}
