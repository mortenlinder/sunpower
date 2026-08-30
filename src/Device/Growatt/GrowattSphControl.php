<?php
declare(strict_types=1);

namespace Solportalen\Device\Growatt;

use RuntimeException;
use Solportalen\Device\Modbus\RtuCodec;
use Solportalen\Device\Serial\LinuxSerialTransport;

final class GrowattSphControl
{
    private const ENABLE_REGISTERS=[1082,1085,1088,1102,1105,1108];
    private const MANAGED_REGISTERS=[1070,1071,1080,1081,1082,1085,1088,1090,1091,1092,1100,1101,1102,1105,1108];
    private const PRIORITIES=['load_first'=>0,'battery_first'=>1,'grid_first'=>2];

    public function __construct(private readonly LinuxSerialTransport $transport,private readonly int $slaveId=1,private readonly bool $writesEnabled=false)
    {
    }

    public function testMode(string $mode,int $seconds=30):array
    {
        if(!isset(self::PRIORITIES[$mode]))throw new RuntimeException('Mode skal være load_first, battery_first eller grid_first.');
        if(function_exists('pcntl_async_signals')){
            pcntl_async_signals(true);
            pcntl_signal(SIGINT,static function():void{throw new RuntimeException('Testen blev afbrudt; baseline gendannes.');});
            pcntl_signal(SIGTERM,static function():void{throw new RuntimeException('Testen blev stoppet; baseline gendannes.');});
        }
        $seconds=max(10,min(120,$seconds));$baseline=$this->readHolding(1070,39);$beforePriority=$this->readPriority();$applied=null;$restored=null;
        try{
            $this->applyTestMode($mode,$baseline);
            sleep(2);$applied=['priority_code'=>$this->readPriority(),'holding'=>$this->readHolding(1070,39)];
            if($applied['priority_code']!==self::PRIORITIES[$mode])throw new RuntimeException('Inverterens priority-readback matchede ikke den ønskede mode.');
            sleep($seconds);
        }finally{
            $this->restore($baseline);
            sleep(2);$restored=['priority_code'=>$this->readPriority(),'holding'=>$this->readHolding(1070,39)];
        }
        foreach(self::MANAGED_REGISTERS as$address){if($restored['holding'][$address]!==$baseline[$address])throw new RuntimeException("Rollback kunne ikke verificeres for register $address.");}
        return ['mode'=>$mode,'test_seconds'=>$seconds,'before_priority_code'=>$beforePriority,'applied'=>$this->summary($applied),'restored'=>$this->summary($restored),'rollback_verified'=>true,'completed_at'=>gmdate(DATE_ATOM)];
    }

    public function setLoadFirst():array
    {
        $before=$this->readHolding(1070,39);
        foreach([1082,1085,1088,1102,1105,1108,1092]as$address)$this->writeVerified($address,0);
        sleep(2);$priority=$this->readPriority();
        if($priority!==0)throw new RuntimeException('Load First blev skrevet, men priority-readback er ikke 0.');
        return ['mode'=>'load_first','priority_code'=>$priority,'before'=>$this->selected($before),'after'=>$this->selected($this->readHolding(1070,39)),'verified'=>true,'completed_at'=>gmdate(DATE_ATOM)];
    }

    /** @param array<int,int> $baseline */
    private function applyTestMode(string $mode,array $baseline):void
    {
        foreach(self::ENABLE_REGISTERS as$address)$this->writeVerified($address,0);
        $this->writeVerified(1092,0);
        if($mode==='grid_first'){
            $this->writeVerified(1070,20);$this->writeVerified(1071,max(30,min(90,$baseline[1071])));
            $this->writeVerified(1080,0);$this->writeVerified(1081,(23<<8)|59);$this->writeVerified(1082,1);
        }elseif($mode==='battery_first'){
            $this->writeVerified(1090,20);$this->writeVerified(1091,max(30,min(95,$baseline[1091])));
            $this->writeVerified(1100,0);$this->writeVerified(1101,(23<<8)|59);$this->writeVerified(1102,1);
        }
    }

    /** @param array<int,int> $baseline */
    private function restore(array $baseline):void
    {
        foreach(self::ENABLE_REGISTERS as$address)$this->writeVerified($address,0);
        $this->writeVerified(1092,0);
        foreach([1070,1071,1080,1081,1090,1091,1100,1101]as$address)$this->writeVerified($address,$baseline[$address]);
        foreach([1092,1082,1085,1088,1102,1105,1108]as$address)$this->writeVerified($address,$baseline[$address]);
    }

    private function writeVerified(int $address,int $value):void
    {
        if(!in_array($address,self::MANAGED_REGISTERS,true))throw new RuntimeException("Register $address er ikke på write-whitelisten.");
        // Undlad unødvendige writes. Flere SPH-firmwares kvitterer for FC06 på
        // tidsregistre, men publicerer først værdien senere - og ved rollback er
        // de fleste registre allerede identiske med baseline.
        $before=$this->readHolding($address,1)[$address];
        if($before===$value)return;
        $request=RtuCodec::writeSingleRequest($this->slaveId,$address,$value,$this->writesEnabled);
        $echo=RtuCodec::decodeWriteSingleResponse($this->transport->exchange($request),$this->slaveId);
        if($echo!==['address'=>$address,'value'=>$value])throw new RuntimeException("FC06-ekko matchede ikke register $address.");
        $actual=null;
        for($attempt=1;$attempt<=8;$attempt++){
            usleep(150000);
            $actual=$this->readHolding($address,1)[$address];
            if($actual===$value)return;
        }
        throw new RuntimeException("FC03-readback fejlede for register $address: forventede $value, læste $actual (før write: $before).");
    }

    /** @return array<int,int> */
    private function readHolding(int $start,int $count):array
    {
        $request=RtuCodec::readRequest($this->slaveId,3,$start,$count);$values=RtuCodec::decodeReadResponse($this->transport->exchange($request),$this->slaveId,3);
        return array_combine(range($start,$start+$count-1),$values);
    }

    private function readPriority():int
    {
        $request=RtuCodec::readRequest($this->slaveId,4,118,1);$values=RtuCodec::decodeReadResponse($this->transport->exchange($request),$this->slaveId,4);return(int)$values[0];
    }

    /** @param array{priority_code:int,holding:array<int,int>}|null $state */
    private function summary(?array $state):?array{return$state===null?null:['priority_code'=>$state['priority_code'],'registers'=>$this->selected($state['holding'])];}
    /** @param array<int,int> $values */
    private function selected(array $values):array{$result=[];foreach(self::MANAGED_REGISTERS as$a)$result[(string)$a]=$values[$a];return$result;}
}
