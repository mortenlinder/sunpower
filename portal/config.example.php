<?php
// Copy to portal/config.php OUTSIDE the document root. Never commit credentials.
return [
    'url' => 'https://solpanel.linder.dk',
    'dsn' => 'mysql:host=localhost;dbname=solportal_cloud;charset=utf8mb4',
    'db_user' => 'solportal_cloud',
    'db_password' => '',
    'secret' => '', // bin2hex(random_bytes(32))
    'mail_from' => 'noreply@solpanel.linder.dk',
    // Local sendmail must have working delivery, SPF and DKIM before enabling signup.
    'registration_enabled' => false,
    'mail_transport' => 'sendmail',
    'sendmail_path' => '/usr/sbin/sendmail',
    'public_min_installations' => 5,
];
