<?php
declare(strict_types=1);
// Integration test with a fake inverter, a separate DB and a private temporary root.
$source=dirname(__DIR__);
define('SOLPORTAL_ROOT',sys_get_temp_dir().'/solportal-worker-test-'.bin2hex(random_bytes(8)));
mkdir(SOLPORTAL_ROOT.'/var',0700,true);
spl_autoload_register(static function(string $c) use($source):void {
    if(str_starts_with($c,'Solportalen\\'))require $source.'/src/'.str_replace('\\','/',substr($c,12)).'.php';
});
date_default_timezone_set('UTC');putenv('WRITES_ENABLED=true');
use Solportalen\Application\RemoteModeCommandProcessor;
use Solportalen\Integration\Cloud\CloudAgent;
$db=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$schema='solportal_worker_test_'.bin2hex(random_bytes(6));$db->exec('CREATE DATABASE '.$schema);$db->exec('USE '.$schema);
$control=new class implements Solportalen\Device\Growatt\ModeControl {
    public array $writes=[];public bool $failApply=false;public bool $failFallback=false;
    public function applySchedule(array $s):array { if($this->failApply)throw new RuntimeException('Simulated readback failure');$this->writes[]=$s;return ['verified'=>true]; }
    public function setFallbackMode(string $mode):array { if($this->failFallback)throw new RuntimeException('Simulated fallback failure');$this->writes[]=['fallback'=>$mode];return ['verified'=>true]; }
};
$n=0;
function expect(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException($name);$n++;echo 'PASS '.$name.PHP_EOL;}
function queue(PDO $db,int $expiry=120):int{
    $c=['id'=>bin2hex(random_bytes(16)),'mode'=>'charge_grid','duration_minutes'=>15,'expires_at'=>time()+$expiry];
    $q=$db->prepare("INSERT INTO commands(command_type,payload_json,status,reason,idempotency_key,created_at) VALUES('remote_mode',?,'pending','test',?,UTC_TIMESTAMP())");$q->execute([json_encode($c),'cloud-'.$c['id']]);return (int)$db->lastInsertId();
}
function status(PDO $db,int $id):string{return $db->query('SELECT status FROM commands WHERE id='.$id)->fetchColumn();}
try{
    $db->exec(file_get_contents($source.'/database/migrations/001_initial.sql'));
    $db->exec(file_get_contents($source.'/database/migrations/003_plan_approval.sql'));
    $db->exec(file_get_contents($source.'/database/migrations/004_plan_lifecycle.sql'));
    $db->exec(file_get_contents($source.'/database/migrations/005_operational_state_payload.sql'));
    $db->exec("INSERT INTO operational_state(state_key,state_value,reason,updated_at) VALUES('fallback_mode','battery_first','test',UTC_TIMESTAMP())");
    $worker=new RemoteModeCommandProcessor($db,$control);
    $id=queue($db);$worker->tick();expect(status($db,$id)==='failed' && count($control->writes)===0,'No pairing means no writes');
    file_put_contents(CloudAgent::credentialsPath(),json_encode(['token'=>str_repeat('a',64),'allow_control'=>true]));
    $id=queue($db,-1);$worker->tick();expect(status($db,$id)==='failed' && count($control->writes)===0,'Expired command cannot write');
    $id=queue($db);$worker->tick();expect(status($db,$id)==='verified' && count($control->writes)===1,'Valid command written and acknowledged');
    expect($worker->tick() && count($control->writes)===1,'Active override does not repeat writes');
    $auto=new Solportalen\Energy\Planning\AutomaticPlanService($db);expect($auto->run(null)['status']==='remote_override','Automatic planner waits for override');
    $raw=json_decode($db->query("SELECT state_value FROM operational_state WHERE state_key='remote_override'")->fetchColumn(),true);$raw['until']=time()-1;
    $q=$db->prepare("UPDATE operational_state SET state_value=? WHERE state_key='remote_override'");$q->execute([json_encode($raw)]);
    $control->failFallback=true;$thrown=false;try{$worker->tick();}catch(RuntimeException $e){$thrown=true;}
    expect($thrown && (bool)$db->query("SELECT state_value FROM operational_state WHERE state_key='remote_override'")->fetchColumn(),'Failed fallback retains override lock');
    $control->failFallback=false;$worker->tick();expect(end($control->writes)['fallback']==='battery_first','Expiry verifies configured Battery First fallback');
    expect(!$db->query("SELECT state_value FROM operational_state WHERE state_key='remote_override'")->fetchColumn(),'Control released only after fallback success');
    $control->failApply=true;$id=queue($db);$worker->tick();expect(status($db,$id)==='failed','Write failure is not acknowledged as success');
    $worker->tick();expect(end($control->writes)['fallback']==='battery_first','Interrupted apply recovers to fallback');
    $control->failApply=false;$id=queue($db);$worker->tick();CloudAgent::control(false);$worker->tick();expect(end($control->writes)['fallback']==='battery_first','Local permission revocation ends active override');
    $id=queue($db);$before=count($control->writes);$worker->tick();expect(status($db,$id)==='failed' && count($control->writes)===$before,'Revoked permission blocks future commands');
    echo "$n fake-inverter integration tests passed\n";
}finally{
    if(preg_match('/^solportal_worker_test_[a-f0-9]{12}$/',$schema))$db->exec('DROP DATABASE '.$schema);
    if(is_file(CloudAgent::credentialsPath()))unlink(CloudAgent::credentialsPath());rmdir(SOLPORTAL_ROOT.'/var');rmdir(SOLPORTAL_ROOT);
}
