<?php

/**
 * Copy to docupload_config.php alongside bootstrap.php.
 *
 * @return array{
 *   password: string,
 *   default_expiry_days?: int,
 *   max_expiry_days?: int,
 *   batch_email_footer?: string,
 * }
 */
return [
    'password' => 'CHANGE_ME_LONG_RANDOM_PASSPHRASE',
    'default_expiry_days' => 14,
    'max_expiry_days' => 365,
    // Optional: exact footer for client emails (HTML + plain). If omitted, expiry is summarized (grouped by UTC day).
    // 'batch_email_footer' => 'Thank you.',
];
