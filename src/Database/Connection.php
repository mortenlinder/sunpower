<?php
declare(strict_types=1);

namespace Solportalen\Database;

use PDO;
use Solportalen\Config\Env;

final class Connection
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        return self::$pdo ??= new PDO(
            Env::get('DB_DSN', 'mysql:host=127.0.0.1;dbname=solportalen;charset=utf8mb4'),
            Env::get('DB_USER', 'solportal'),
            Env::get('DB_PASSWORD', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"]
        );
    }
}
