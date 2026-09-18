<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'SolportalCloud\\')) {
        $path = __DIR__ . '/src/' . substr($class, strlen('SolportalCloud\\')) . '.php';
        if (is_file($path)) require $path;
    }
});
date_default_timezone_set('UTC');
$configFile = getenv('SOLPORTAL_CLOUD_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($configFile)) throw new RuntimeException('Portal configuration missing');
$config = require $configFile;
if (!preg_match('/^[a-f0-9]{64}$/', $config['secret'] ?? '') || !str_starts_with($config['url'] ?? '', 'https://')) {
    throw new RuntimeException('Portal requires HTTPS and a 32-byte application secret');
}
$db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$db->exec("SET time_zone='+00:00'");
