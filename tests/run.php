<?php
declare(strict_types=1);

use Solportalen\Device\Modbus\Crc16;
use Solportalen\Device\Modbus\ModbusException;
use Solportalen\Device\Modbus\RtuCodec;
use Solportalen\Device\Profile\RegisterDecoder;
use Solportalen\Device\Simulator\EnergySimulator;
use Solportalen\Energy\Optimizer\DynamicProgrammingOptimizer;
use Solportalen\Energy\Pricing\ElectricityTax;
use Solportalen\Device\Growatt\GrowattSphCommissioning;

$tests = [];
$test = static function (string $name, callable $case) use (&$tests): void { try { $case(); $tests[] = [true,$name]; } catch (Throwable $e) { $tests[] = [false,$name . ': ' . $e->getMessage()]; } };
$assert = static function (bool $condition, string $message='Assertion failed'): void { if (!$condition) throw new RuntimeException($message); };
$test('CRC16 known frame', static fn () => $assert(Crc16::calculate(hex2bin('01030000000A')) === 0xCDC5));
$test('Read request CRC', static fn () => $assert(bin2hex(RtuCodec::readRequest(1,3,0,10)) === '01030000000ac5cd'));
$test('Read decode', static fn () => $assert(RtuCodec::decodeReadResponse(hex2bin('010304000a0014da3e'),1,3) === [10,20]));
$test('Write response decode',static fn()=>$assert(RtuCodec::decodeWriteSingleResponse(hex2bin('010604380001c8f7'),1)===['address'=>1080,'value'=>1]));
$test('FC16 request and response',static function()use($assert):void{$request=RtuCodec::writeMultipleRequest(1,1080,[1793],true);$assert(substr(bin2hex($request),0,18)==='011004380001020701');$response=substr($request,0,6);$response=Solportalen\Device\Modbus\Crc16::append($response);$assert(RtuCodec::decodeWriteMultipleResponse($response,1)===['address'=>1080,'count'=>1]);});
$test('Writes default denied', static function () use ($assert): void { try { RtuCodec::writeSingleRequest(1,1,1,false); } catch (ModbusException) { $assert(true); return; } $assert(false); });
$test('Growatt holding snapshot decoding',static function()use($assert):void{$r=array_fill(0,39,0);$r[0]=80;$r[1]=20;$r[10]=(9<<8)|30;$r[11]=(11<<8);$r[12]=1;$r[20]=60;$r[21]=95;$r[22]=1;$r[30]=(1<<8)|15;$r[31]=(5<<8)|45;$r[32]=1;$d=GrowattSphCommissioning::decode($r);$assert($d['grid_first']['periods'][0]['start']['formatted']==='09:30');$assert($d['battery_first']['periods'][0]['stop']['formatted']==='05:45');$assert($d['battery_first']['ac_charge_enabled']===true);$assert($d['warnings']===[]);});
$test('Signed decoding', static fn () => $assert((new RegisterDecoder())->decode([0xFF9C],['type'=>'int16']) === -100));
$test('Scaling', static fn () => $assert((new RegisterDecoder())->decode([645],['type'=>'uint16','scale'=>0.1]) === 64.5));
$test('Simulator balance', static function () use ($assert): void { $s=(new EnergySimulator())->state(); $assert(abs($s['pv_power_w'] + max(0,$s['grid_power_w']) + max(0,$s['battery_power_w']) - $s['load_power_w'] - max(0,-$s['grid_power_w']) - max(0,-$s['battery_power_w'])) < 2); });
$test('Annual heat tax is blended across consumption', static function () use ($assert): void {
    putenv('ANNUAL_ELECTRICITY_CONSUMPTION_KWH=12000');putenv('REDUCED_TAX_THRESHOLD_KWH=4000');putenv('ENERGY_TAX_FULL_DKK_KWH=0.9');putenv('ENERGY_TAX_REDUCED_DKK_KWH=0.01');
    $assert(abs(ElectricityTax::blendedRate() - (3680/12000)) < .000001, 'Expected annual weighted tax rate');
});
$test('Optimizer moves cheap energy to expensive interval', static function () use ($assert): void {
    $intervals=[['starts_at'=>'2026-01-01 00:00:00','ends_at'=>'2026-01-01 01:00:00','buy_price'=>.5,'load_kwh'=>0,'solar_kwh'=>0],['starts_at'=>'2026-01-01 17:00:00','ends_at'=>'2026-01-01 18:00:00','buy_price'=>5.0,'load_kwh'=>2,'solar_kwh'=>0]];
    $rows=(new DynamicProgrammingOptimizer())->optimize($intervals,['soc_pct'=>20,'capacity_kwh'=>5,'min_soc_pct'=>20,'reserve_pct'=>20,'max_soc_pct'=>90,'max_charge_w'=>2500,'max_discharge_w'=>2500,'round_trip_efficiency'=>.9,'wear_dkk_kwh'=>.05]);
    $assert($rows[0]['action']==='charge_grid','Expected cheap grid charging');$assert($rows[1]['action']==='discharge','Expected expensive-period discharge');$assert(array_sum(array_column($rows,'optimized_cost'))<array_sum(array_column($rows,'baseline_cost')),'Expected a saving');
});
$test('Automation refreshes SOC/load without new market prices', static function()use($assert):void{
    $now=strtotime('2026-09-06 10:30:00 UTC');$price='2026-09-06 22:00:00';
    $last=['command_status'=>'verified','queued_at'=>'2026-09-06 10:10:00'];
    $assert(Solportalen\Energy\Planning\AutomaticPlanService::refreshDue($last,$price,$price,$now));
    $last['queued_at']='2026-09-06 10:29:00';
    $assert(!Solportalen\Energy\Planning\AutomaticPlanService::refreshDue($last,$price,$price,$now));
    $assert(Solportalen\Energy\Planning\AutomaticPlanService::refreshDue($last,'2026-09-07 22:00:00',$price,$now));
    $last['command_status']='claimed';
    $assert(!Solportalen\Energy\Planning\AutomaticPlanService::refreshDue($last,'2026-09-07 22:00:00',$price,$now));
});
$test('Current charge window survives Growatt three-slot limit',static function()use($assert):void{
    $groups=[];foreach([10,12,14,16]as$h)$groups[]=['start'=>new DateTimeImmutable("2026-09-06 $h:00:00 UTC"),'end'=>new DateTimeImmutable("2026-09-06 $h:30:00 UTC"),'benefit'=>$h===10?0:100];
    $selected=Solportalen\Energy\Planning\GrowattWindowCompiler::selectWindows($groups,strtotime('2026-09-06 10:15:00 UTC'));
    $assert(count($selected)===3);$assert($selected[0]['start']->format('H:i')==='10:00');
});
$test('Execution status distinguishes desired charging from measured charging and target reached',static function()use($assert):void{
    $now=strtotime('2026-09-06 10:30:00 UTC');
    $schedule=['plan_id'=>1,'valid_until'=>'2026-09-06T22:00:00Z','battery_periods'=>[['start'=>12<<8,'stop'=>14<<8,'enabled'=>1]],'charge_stop_soc_pct'=>93];
    $state=['received_timestamp'=>gmdate(DATE_ATOM,$now),'priority_mode'=>'load_first','ac_charge_enabled'=>true,'battery_charge_power_w'=>0,'battery_soc_pct'=>40];
    $assert(Solportalen\Energy\Planning\ExecutionStatus::describe($schedule,$state,$now)['status']==='mismatch');
    $state['priority_mode']='battery_first';$state['battery_charge_power_w']=2570;
    $assert(Solportalen\Energy\Planning\ExecutionStatus::describe($schedule,$state,$now)['status']==='charging');
    $state['battery_soc_pct']=93;$state['battery_charge_power_w']=0;
    $assert(Solportalen\Energy\Planning\ExecutionStatus::describe($schedule,$state,$now)['status']==='target_reached');
    $assert(Solportalen\Energy\Planning\ExecutionStatus::describe($schedule,$state,$now+60)['status']==='stale');
    $assert(Solportalen\Energy\Planning\ExecutionStatus::describe($schedule,$state,strtotime('2026-09-07 UTC'))['status']==='no_active_plan');
});
foreach ($tests as [$ok,$name]) echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
$failed = count(array_filter($tests, static fn ($t) => !$t[0])); echo sprintf("%d tests, %d fejl\n", count($tests), $failed); if ($failed) exit(1);
