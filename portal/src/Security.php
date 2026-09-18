<?php
declare(strict_types=1);
namespace SolportalCloud;
use PDO;
use RuntimeException;
final class Security
{
    public static function session(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('__Host-solportal');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public static function csrf(string $token): void
    {
        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) throw new RuntimeException('Siden er udløbet. Genindlæs og prøv igen.');
    }
    public static function limit(PDO $db, string $secret, string $key, int $max, int $seconds): void
    {
        $bucket = hash_hmac('sha256', $key . ':' . intdiv(time(), $seconds), $secret);
        $q=$db->prepare('INSERT INTO cloud_limits(bucket,attempts,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=attempts+1');
        $q->execute([$bucket,gmdate('Y-m-d H:i:s',time()+$seconds)]);
        $q=$db->prepare('SELECT attempts FROM cloud_limits WHERE bucket=?'); $q->execute([$bucket]);
        if ((int)$q->fetchColumn()>$max) { http_response_code(429); throw new RuntimeException('For mange forsøg. Vent lidt og prøv igen.'); }
    }
    public static function password(string $value): void
    {
        if (strlen($value)<12 || strlen($value)>72) throw new RuntimeException('Adgangskoden skal være 12–72 bytes. Brug gerne en lang sætning.');
    }
    public static function email(string $email): string
    {
        $email=strtolower(trim($email));
        if (strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Indtast en gyldig e-mailadresse.');
        return $email;
    }
    public static function audit(PDO $db, ?int $user, ?string $installation, string $action): void
    {
        $q=$db->prepare('INSERT INTO cloud_audit(user_id,installation_id,action) VALUES(?,?,?)');
        $q->execute([$user,$installation,$action]);
    }
}
