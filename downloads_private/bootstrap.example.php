<?php

/**
 * Copy to bootstrap.php on the server (same folder as init.php).
 * Paths must be absolute on your hosting account.
 *
 * Deploy this entire folder to: private_files/downloads/
 * (outside the download.sumbaprop.com document root.)
 *
 * Main site (sumbaprop.com): point document root to public_html/main (or similar)
 * so nothing under the old /private URL stays reachable, OR keep root at public_html
 * and delete/move the old /private tree so only the subdomain serves downloads.
 */
return [
    // Stored uploads (not web-accessible)
    'documents_dir' => '/home/sumbap/private_files/downloads/documents',

    // Registry JSON (not web-accessible)
    'registry_file' => '/home/sumbap/private_files/downloads/.registry.json',

    // Logs (download attempts + PHP errors) — your webdisk path
    'log_dir' => '/home/sumbap/webdisk/peter/downloads',

    // Public download site origin (no path). Links use /?file=…&password=… → index.php at doc root.
    'public_download_url' => 'https://download.sumbaprop.com',

    // Optional: absolute path to GeoLite2-Country.mmdb (requires `composer install` in this folder).
    // 'geoip_country_mmdb' => '/home/sumbap/private_files/downloads/GeoLite2-Country.mmdb',

    // Country in Statistics: GeoLite2 MMDB path (optional), Cloudflare CF-IPCountry in logs (optional),
    // then HTTPS lookup via ipwho.is (cached under log_dir/.geo_cache/). Online lookup defaults ON when this key is omitted; set false to disable (air‑gapped hosts).
    // 'geoip_allow_online_lookup' => false,
];
