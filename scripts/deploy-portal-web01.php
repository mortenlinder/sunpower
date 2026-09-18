<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0 || gethostname() !== 'web01') exit("Root on web01 required.\n");
$target = '/var/www/clients/client3/web63/web';
$source = dirname(__DIR__).'/portal';
if (realpath('/var/www/solpanel.linder.dk') !== dirname($target)) exit("Unexpected website path.\n");
if (is_file($target.'/config.php')) exit("Already configured; use a reviewed update, not initial provisioning.\n");
$allowed = ['bootstrap.php','config.example.php','src','bin','views','database','public'];
foreach ($allowed as $entry) {
    $path = $source.'/'.$entry;
    $files = is_dir($path) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) : [new SplFileInfo($path)];
    foreach ($files as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative = substr($file->getPathname(),strlen($source)+1);
        $dest = $target.'/'.$relative;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest),0755,true);
        if (!copy($file->getPathname(),$dest)) throw new RuntimeException('Copy failed.');
        chmod($dest,0644);
    }
}
require '/usr/local/ispconfig/server/lib/mysql_clientdb.conf';
$admin = new PDO('mysql:host='.$clientdb_host.';charset=utf8mb4',$clientdb_user,$clientdb_password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($admin->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='solportal_cloud'")->fetch()) exit("Existing database; refusing initial provisioning.\n");
$password = bin2hex(random_bytes(32));
$admin->exec('CREATE DATABASE solportal_cloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin->exec("CREATE USER 'solportal_cloud'@'localhost' IDENTIFIED BY ".$admin->quote($password));
$admin->exec("GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON solportal_cloud.* TO 'solportal_cloud'@'localhost'");
$config = require $source.'/config.example.php';
$config['db_password'] = $password;
$config['secret'] = bin2hex(random_bytes(32));
echo "SMTP password (stdin, hidden):\n";
$config['smtp_password'] = rtrim((string)fgets(STDIN),"\r\n");
if (strlen($config['smtp_password'])<12) throw new RuntimeException('SMTP password missing.');
umask(0077);
file_put_contents($target.'/config.php',"<?php\nreturn ".var_export($config,true).";\n");
chown($target.'/config.php','web63');
chmod($target.'/config.php',0600);
$cron = "# Solportalen: isolated cloud mail queue and retention\nMAILTO=\"\"\n* * * * * web63 /usr/bin/php8.2 /var/www/clients/client3/web63/web/bin/maintenance.php >> /var/www/clients/client3/web63/private/maintenance.log 2>&1\n";
file_put_contents('/etc/cron.d/solportal-cloud',$cron);
chmod('/etc/cron.d/solportal-cloud',0644);
echo "Portal files, private configuration, database and cron installed. Registration remains disabled.\n";
