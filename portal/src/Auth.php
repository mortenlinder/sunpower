<?php
declare(strict_types=1);
namespace SolportalCloud;
use PDO;
use RuntimeException;
final class Auth
{
    public function __construct(private PDO $db, private array $config) {}
    public function register(string $email, string $password): void
    {
        if (empty($this->config['registration_enabled'])) throw new RuntimeException('Oprettelse åbner, når mailleveringen er verificeret.');
        $email=Security::email($email); Security::password($password);
        // Hash for both existing and new accounts; do not expose account existence.
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $q=$this->db->prepare('INSERT IGNORE INTO cloud_users(email,password_hash) VALUES(?,?)'); $q->execute([$email,$hash]);
        if ($q->rowCount()===1) $this->token((int)$this->db->lastInsertId(),'verify',$email);
    }
    public function requestToken(string $email,string $purpose): void
    {
        $email=Security::email($email);
        $q=$this->db->prepare('SELECT * FROM cloud_users WHERE email=?'); $q->execute([$email]); $u=$q->fetch();
        if ($u && ($purpose==='reset' || !$u['verified_at'])) $this->token((int)$u['id'],$purpose,$email);
    }
    private function token(int $user,string $purpose,string $email): void
    {
        $token=bin2hex(random_bytes(32)); $ttl=$purpose==='verify'?86400:1800;
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('UPDATE cloud_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND purpose=? AND used_at IS NULL'); $q->execute([$user,$purpose]);
            $q=$this->db->prepare('INSERT INTO cloud_tokens(token_hash,user_id,purpose,expires_at) VALUES(?,?,?,?)');
            $q->execute([hash('sha256',$token),$user,$purpose,gmdate('Y-m-d H:i:s',time()+$ttl)]);
            // Fragment keeps the secret out of HTTP access logs. Browser transfers it to a POST form.
            $url=$this->config['url'].'/?page='.$purpose.'#token='.$token;
            $title=$purpose==='verify'?'Bekræft din e-mail':'Nulstil din adgangskode';
            $this->mail($email,$title,"$title på Solportalen:\n\n$url\n\nLinket kan bruges én gang og udløber om ".($ttl/60)." minutter. Ignorér mailen, hvis du ikke bad om den.");
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function consume(string $token,string $purpose,?string $password=null): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/',$token)) throw new RuntimeException('Linket er ugyldigt eller udløbet.');
        if ($purpose==='reset') Security::password($password??'');
        $hash=$password!==null?password_hash($password,PASSWORD_DEFAULT):null;
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT * FROM cloud_tokens WHERE token_hash=? AND purpose=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
            $q->execute([hash('sha256',$token),$purpose]); $t=$q->fetch();
            if (!$t) throw new RuntimeException('Linket er ugyldigt eller udløbet.');
            $q=$this->db->prepare('UPDATE cloud_tokens SET used_at=UTC_TIMESTAMP() WHERE token_hash=?'); $q->execute([$t['token_hash']]);
            if ($purpose==='verify') {
                $q=$this->db->prepare('UPDATE cloud_users SET verified_at=COALESCE(verified_at,UTC_TIMESTAMP()) WHERE id=?'); $q->execute([$t['user_id']]);
            } else {
                $q=$this->db->prepare('UPDATE cloud_users SET password_hash=?,session_version=session_version+1 WHERE id=?'); $q->execute([$hash,$t['user_id']]);
                $q=$this->db->prepare('SELECT email FROM cloud_users WHERE id=?'); $q->execute([$t['user_id']]);
                $this->mail((string)$q->fetchColumn(),'Din adgangskode er ændret','Adgangskoden til Solportalen er ændret. Alle eksisterende web-sessioner er logget ud. Kontakt administrator, hvis det ikke var dig.');
            }
            Security::audit($this->db,(int)$t['user_id'],null,$purpose);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function login(string $email,string $password): void
    {
        $q=$this->db->prepare('SELECT * FROM cloud_users WHERE email=?'); $q->execute([Security::email($email)]); $u=$q->fetch();
        $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid=password_verify($password,$u['password_hash']??$dummy);
        if (!$u || !$valid) throw new RuntimeException('E-mail eller adgangskode er forkert.');
        if (!$u['verified_at']) throw new RuntimeException('Bekræft først din e-mail. Du kan få sendt et nyt link herunder.');
        session_regenerate_id(true);
        $_SESSION=['user_id'=>(int)$u['id'],'version'=>(int)$u['session_version'],'login_at'=>time(),'last_at'=>time(),'csrf'=>bin2hex(random_bytes(32))];
        Security::audit($this->db,(int)$u['id'],null,'login');
    }
    public function user(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        $q=$this->db->prepare('SELECT id,email,password_hash,session_version,verified_at FROM cloud_users WHERE id=?'); $q->execute([$_SESSION['user_id']]); $u=$q->fetch();
        if (!$u || !$u['verified_at'] || (int)$u['session_version']!==$_SESSION['version'] || time()-$_SESSION['login_at']>43200 || time()-$_SESSION['last_at']>1800) {
            $this->logout(); return null;
        }
        $_SESSION['last_at']=time(); return $u;
    }
    public function logout(): void { $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); }
    private function mail(string $recipient,string $subject,string $body): void
    {
        $q=$this->db->prepare('INSERT INTO cloud_mail(recipient,subject,body) VALUES(?,?,?)'); $q->execute([$recipient,$subject,$body]);
    }
}
