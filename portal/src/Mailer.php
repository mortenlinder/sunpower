<?php
declare(strict_types=1);
namespace SolportalCloud;
use RuntimeException;
final class Mailer
{
    public function __construct(private array $config) {}
    public static function message(string $from,string $to,string $subject,string $body): string
    {
        foreach ([$from,$to] as $address) {
            if (preg_match('/[\r\n]/',$address) || !filter_var($address,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid mail address');
        }
        $domain=substr(strrchr($from,'@'),1);
        return 'Date: '.gmdate('D, d M Y H:i:s').' +0000'."\r\n"
            .'Message-ID: <'.bin2hex(random_bytes(16)).'@'.$domain.">\r\n"
            .'From: Solportalen <'.$from.">\r\nTo: ".$to."\r\n"
            .'Subject: =?UTF-8?B?'.base64_encode($subject)."?=\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($body),76,"\r\n");
    }
    public function send(string $to,string $subject,string $body): bool
    {
        $from=(string)($this->config['mail_from']??'');
        $message=self::message($from,$to,$subject,$body);
        if (($this->config['mail_transport']??'')==='smtp') return $this->smtp($from,$to,$message);
        if (($this->config['mail_transport']??'')!=='sendmail') throw new RuntimeException('Unknown mail transport');
        $process=proc_open([$this->config['sendmail_path'],'-i','-t','-f',$from],[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
        if (!is_resource($process)) return false;
        $sent=fwrite($pipes[0],$message); fclose($pipes[0]); $status=proc_close($process);
        return $sent===strlen($message) && $status===0;
    }
    private function smtp(string $from,string $to,string $message): bool
    {
        $host=(string)($this->config['smtp_host']??'');$port=(int)($this->config['smtp_port']??587);
        if (!preg_match('/^[a-zA-Z0-9.-]+$/',$host) || !in_array($port,[465,587],true)) throw new RuntimeException('Invalid SMTP endpoint');
        $password=$this->config['smtp_password']??getenv('SOLPORTAL_SMTP_PASSWORD');
        if (!is_string($password) || $password==='') throw new RuntimeException('SMTP password missing');
        $stream=fopen('php://temp','w+'); if (!$stream) return false;
        fwrite($stream,$message); rewind($stream);
        $curl=curl_init(($port===465?'smtps://':'smtp://').$host.':'.$port);
        try {
            curl_setopt_array($curl,[
                CURLOPT_USERNAME=>(string)$this->config['smtp_user'],CURLOPT_PASSWORD=>$password,
                CURLOPT_USE_SSL=>CURLUSESSL_ALL,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROTOCOLS=>CURLPROTO_SMTP|CURLPROTO_SMTPS,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAIL_FROM=>$from,CURLOPT_MAIL_RCPT=>[$to],
                CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$stream,CURLOPT_INFILESIZE=>strlen($message),
                CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_RETURNTRANSFER=>true,
            ]);
            $result=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            // Never log the SMTP transcript, payload, credentials or verification links.
            if ($result===false || $status<200 || $status>=300) {
                error_log('Solportal SMTP failed: curl='.curl_errno($curl).' smtp='.$status); return false;
            }
            return true;
        } finally { curl_close($curl);fclose($stream); }
    }
}
