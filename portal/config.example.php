<?php
// Copy to portal/config.php OUTSIDE the document root. Never commit credentials.
return [
    'url' => 'https://solpanel.linder.dk',
    'dsn' => 'mysql:host=localhost;dbname=solportal_cloud;charset=utf8mb4',
    'db_user' => 'solportal_cloud',
    'db_password' => '',
    'secret' => '', // bin2hex(random_bytes(32))
    'mail_from' => 'mail@systems.linder.dk',
    // Verify real delivery and DNS authentication before enabling signup.
    'registration_enabled' => false,
    'mail_transport' => 'smtp',
    'smtp_host' => 'web01.vipsupport.dk',
    'smtp_port' => 587, // STARTTLS required; 465 uses implicit TLS
    'smtp_user' => 'mail@systems.linder.dk',
    // Supply smtp_password ONLY in private config.php, or SOLPORTAL_SMTP_PASSWORD.
    'sendmail_path' => '/usr/sbin/sendmail',
    'public_min_installations' => 5,
];
