<?php
declare(strict_types=1);

use Solportalen\Device\Modbus\Crc16;
use Solportalen\Device\Modbus\ModbusException;
use Solportalen\Device\Modbus\RtuCodec;
use Solportalen\Device\Profile\RegisterDecoder;
use Solportalen\Device\Simulator\EnergySimulator;
use Solportalen\Energy\Optimizer\DynamicProgrammingOptimizer;

$tests = [];
$test = static function (string $name, callable $case) use (&$tests): void { try { $case(); $tests[] = [true,$name]; } catch (Throwable $e) { $tests[] = [false,$name . ': ' . $e->getMessage()]; } };
$assert = static function (bool $condition, string $message='Assertion failed'): void { if (!$condition) throw new RuntimeException($message); };
$test('CRC16 known frame', static fn () => $assert(Crc16::calculate(hex2bin('01030000000A')) === 0xCDC5));
$test('Read request CRC', static fn () => $assert(bin2hex(RtuCodec::readRequest(1,3,0,10)) === '01030000000ac5cd'));
$test('Read decode', static fn () => $assert(RtuCodec::decodeReadResponse(hex2bin('010304000a0014da3e'),1,3) === [10,20]));
$test('Writes default denied', static function () use ($assert): void { try { RtuCodec::writeSingleRequest(1,1,1,false); } catch (ModbusException) { $assert(true); return; } $assert(false); });
$test('Signed decoding', static fn () => $assert((new RegisterDecoder())->decode([0xFF9C],['type'=>'int16']) === -100));
$test('Scaling', static fn () => $assert((new RegisterDecoder())->decode([645],['type'=>'uint16','scale'=>0.1]) === 64.5));
$test('Simulator balance', static function () use ($assert): void { $s=(new EnergySimulator())->state(); $assert(abs($s['pv_power_w'] + max(0,$s['grid_power_w']) + max(0,$s['battery_power_w']) - $s['load_power_w'] - max(0,-$s['grid_power_w']) - max(0,-$s['battery_power_w'])) < 2); });
$test('Optimizer moves cheap energy to expensive interval', static function () use ($assert): void {
    $intervals=[['starts_at'=>'2026-01-01 00:00:00','ends_at'=>'2026-01-01 01:00:00','buy_price'=>.5,'load_kwh'=>0,'solar_kwh'=>0],['starts_at'=>'2026-01-01 17:00:00','ends_at'=>'2026-01-01 18:00:00','buy_price'=>5.0,'load_kwh'=>2,'solar_kwh'=>0]];
    $rows=(new DynamicProgrammingOptimizer())->optimize($intervals,['soc_pct'=>20,'capacity_kwh'=>5,'min_soc_pct'=>20,'reserve_pct'=>20,'max_soc_pct'=>90,'max_charge_w'=>2500,'max_discharge_w'=>2500,'round_trip_efficiency'=>.9,'wear_dkk_kwh'=>.05]);
    $assert($rows[0]['action']==='charge_grid','Expected cheap grid charging');$assert($rows[1]['action']==='discharge','Expected expensive-period discharge');$assert(array_sum(array_column($rows,'optimized_cost'))<array_sum(array_column($rows,'baseline_cost')),'Expected a saving');
});
foreach ($tests as [$ok,$name]) echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
$failed = count(array_filter($tests, static fn ($t) => !$t[0])); echo sprintf("%d tests, %d fejl\n", count($tests), $failed); if ($failed) exit(1);
