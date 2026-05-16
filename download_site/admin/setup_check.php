<?php

declare(strict_types=1);

/**
 * ONE-TIME diagnostic — DELETE THIS FILE after everything works.
 *
 * 1. Set $checkRoot to the SAME path as DOWNLOADS_PRIVATE_ROOT in file.php / admin/docupload.php
 * 2. Set $secret to a random string and open:
 *    https://download.sumbaprop.com/admin/setup_check.php?key=YOUR_SECRET
 */
$checkRoot = '/home/sumbap/private_files/downloads';
$secret = 'CHANGE_THIS_SETUP_KEY';

header('Content-Type: text/plain; charset=UTF-8');

if (($secret === 'CHANGE_THIS_SETUP_KEY') || (($_GET['key'] ?? '') !== $secret)) {
    http_response_code(404);
    echo 'Not found';

    exit;
}

echo "DOWNLOADS_PRIVATE_ROOT (check): {$checkRoot}\n\n";

$checks = [
    'Directory exists' => is_dir($checkRoot),
    'bootstrap.php readable' => is_readable($checkRoot . '/bootstrap.php'),
    'init.php readable' => is_readable($checkRoot . '/init.php'),
    'lib/common.php readable' => is_readable($checkRoot . '/lib/common.php'),
    'lib/logging.php readable' => is_readable($checkRoot . '/lib/logging.php'),
    'lib/docupload_app.php readable' => is_readable($checkRoot . '/lib/docupload_app.php'),
    'lib/serve_download.php readable' => is_readable($checkRoot . '/lib/serve_download.php'),
    'docupload_config.php readable' => is_readable($checkRoot . '/docupload_config.php'),
];

foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n";
}

echo "\n--- Loading init.php ---\n";

try {
    if (!defined('DOWNLOADS_PRIVATE_ROOT')) {
        define('DOWNLOADS_PRIVATE_ROOT', $checkRoot);
    }

    require DOWNLOADS_PRIVATE_ROOT . '/init.php';

    echo "[OK] init.php loaded\n";

    $cfg = $GLOBALS['DOWNLOADS_BOOTSTRAP'] ?? [];

    foreach (['documents_dir', 'registry_file', 'log_dir', 'public_download_url'] as $k) {
        $v = $cfg[$k] ?? null;

        echo '[bootstrap] ' . $k . ': ' . (is_string($v) ? $v : json_encode($v)) . "\n";

        if ($k === 'documents_dir' || $k === 'log_dir') {
            echo '            exists: ' . (is_dir((string) $v) ? 'yes' : 'NO — create it or fix path') . "\n";
        }

        if ($k === 'registry_file') {
            $dir = dirname((string) $v);

            echo '            parent dir exists: ' . (is_dir($dir) ? 'yes' : 'NO — create parent folder') . "\n";
        }
    }
} catch (Throwable $e) {
    echo '[FAIL] ' . $e->getMessage() . "\n";
    echo 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
