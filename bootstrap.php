<?php
declare(strict_types=1);

define('SOLPORTAL_ROOT', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Solportalen\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = SOLPORTAL_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

Solportalen\Config\Env::load(SOLPORTAL_ROOT . '/.env');

date_default_timezone_set('UTC');
