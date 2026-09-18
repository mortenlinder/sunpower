<?php
declare(strict_types=1);
namespace Solportalen\Application;
use PDO;
use Solportalen\Config\Env;
use Solportalen\Device\Growatt\ModeControl;
use Solportalen\Energy\Planning\AutomationSettings;
use Solportalen\Integration\Cloud\{CloudAgent,RemoteMode};
final class RemoteModeCommandProcessor
{
    public function __construct(private PDO $pdo,private ModeControl $control) {}
    /** Returns true while a remote command owns control; the normal queue must wait. */
    public function tick(): bool
    {
        $store=new AutomationSettings($this->pdo);
        $raw=$this->pdo->query("SELECT state_value FROM operational_state WHERE state_key='remote_override'")->fetchColumn();
        $override=$raw?json_decode($raw,true):null;
        if ($override) {
            $stillAllowed=CloudAgent::allowsControl();
            if (($override['phase']??'')==='active' && (int)$override['until']>time() && $stillAllowed) return true;
            if (!Env::bool('WRITES_ENABLED')) throw new \RuntimeException('Fjernmode skal afsluttes, men lokale writes er deaktiveret. Kontrollér inverteren.');
            // Includes crash recovery: an interrupted apply is never silently replayed.
            $fallback=$store->get()['fallback_mode']; $result=$this->control->setFallbackMode($fallback);
            $this->pdo->beginTransaction();
            try {
                $store->put('remote_override','','Fjernmode udløbet eller tilbagekaldt; fallback verificeret');
                $store->put('manual_schedule','','Gammel plan erstattet af fjernmode');
                $store->put('requested_battery_mode',$fallback,'Fjernmode afsluttet');
                $this->pdo->exec("UPDATE commands SET status='cancelled',completed_at=UTC_TIMESTAMP(6),error_message='Plan i kø blev forældet under fjernoverstyring' WHERE status='pending' AND command_type IN ('apply_approved_plan','apply_fallback_mode')");
                $q=$this->pdo->prepare("UPDATE commands SET status='failed',completed_at=UTC_TIMESTAMP(6),error_message='Afbrudt anvendelse; lokal fallback verificeret' WHERE id=? AND status='claimed'"); $q->execute([$override['command_id']]);
                $this->audit('remote_mode_expired',(string)$override['command_id'],$result); $this->pdo->commit();
            } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
        }
        $c=$this->pdo->query("SELECT * FROM commands WHERE command_type='remote_mode' AND status='pending' ORDER BY id LIMIT 1")->fetch();
        if (!$c) return false;
        $q=$this->pdo->prepare("UPDATE commands SET status='claimed',claimed_at=UTC_TIMESTAMP(6) WHERE id=? AND status='pending'"); $q->execute([$c['id']]); if ($q->rowCount()!==1) return true;
        $intent=false;
        try {
            if (!CloudAgent::allowsControl() || !Env::bool('WRITES_ENABLED')) throw new \RuntimeException('Lokale rettigheder tillader ikke fjernstyring.');
            $payload=json_decode($c['payload_json'],true,16,JSON_THROW_ON_ERROR); $schedule=RemoteMode::schedule($payload,time());
            $state=['until'=>$schedule['until'],'mode'=>$payload['mode'],'command_id'=>$c['id'],'phase'=>'applying'];
            $store->put('remote_override',json_encode($state,JSON_THROW_ON_ERROR),'Fjernmode under anvendelse'); $intent=true;
            $result=$this->control->applySchedule($schedule);
            // applySchedule verifies each register. Priority can legitimately differ at full SOC.
            $state['phase']='active';
            $this->pdo->beginTransaction();
            $store->put('remote_override',json_encode($state,JSON_THROW_ON_ERROR),'Fjernmode verificeret');
            $store->put('manual_schedule','','Tidligere plan erstattet af tidsbegrænset fjernmode');
            $store->put('requested_battery_mode',$payload['mode'],'Fjernmode fra ejerens portal');
            $q=$this->pdo->prepare("UPDATE commands SET status='cancelled',completed_at=UTC_TIMESTAMP(6),error_message='Erstattet af ejerens midlertidige mode' WHERE status='pending' AND command_type IN ('apply_approved_plan','apply_fallback_mode')"); $q->execute();
            $q=$this->pdo->prepare("UPDATE commands SET status='verified',completed_at=UTC_TIMESTAMP(6) WHERE id=?"); $q->execute([$c['id']]);
            $this->audit('remote_mode_verified',(string)$c['id'],['readback'=>$result,'until'=>$state['until']]); $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            // Keep applying intent after a write failure; next tick verifies safe fallback before releasing control.
            $q=$this->pdo->prepare("UPDATE commands SET status='failed',completed_at=UTC_TIMESTAMP(6),error_message=? WHERE id=?"); $q->execute([substr($e->getMessage(),0,500),$c['id']]);
            $this->audit('remote_mode_failed',(string)$c['id'],['error'=>$e->getMessage()]);
            if (!$intent) return false;
        }
        return true;
    }
    private function audit(string $action,string $id,array $data): void
    {
        $q=$this->pdo->prepare('INSERT INTO audit_log(actor,action,object_type,object_id,after_json,reason,correlation_id,created_at) VALUES("cloud-owner",?,"command",?,?,"Tidsbegrænset fjernmode",UUID(),UTC_TIMESTAMP(6))'); $q->execute([$action,$id,json_encode($data,JSON_THROW_ON_ERROR)]);
    }
}
