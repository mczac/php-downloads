<?php

/**
 * Copy to docupload_config.php alongside bootstrap.php.
 *
 * @return array{
 *   password: string,
 *   default_expiry_days?: int,
 *   max_expiry_days?: int,
 *   batch_email_footer?: string,
 *   login_lockout_threshold?: int,
 * }
 */
return [
    'password' => 'CHANGE_ME_LONG_RANDOM_PASSPHRASE',
    'default_expiry_days' => 14,
    'max_expiry_days' => 365,
    // Wrong passphrase attempts in a row before login is blocked until someone with server access resets state.
    // 0 = feature off. Any integer from 1–1000 is honoured (e.g. 3 locks after the 3rd consecutive wrong passphrase).
    // Reset: set consecutive_failures to 0 in .docupload_login_lockout.json next to bootstrap.php (or delete that file),
    // then change password here if it may have been exposed.
    'login_lockout_threshold' => 0,
    // Optional: exact footer for client emails (HTML + plain). If omitted, expiry is summarized (grouped by UTC day).
    // 'batch_email_footer' => 'Thank you.',
];
