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
    // Optional: exact footer for client emails (HTML + plain). If omitted, each link line lists its expiry from the upload record.
    // 'batch_email_footer' => 'Thank you.',
];
