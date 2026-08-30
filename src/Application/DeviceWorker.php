<?php
declare(strict_types=1);

namespace Solportalen\Application;

use Solportalen\Config\Env;
use Solportalen\Database\Connection;
use Solportalen\Device\Growatt\GrowattSphReader;
use Solportalen\Device\Serial\LinuxSerialTransport;
use Solportalen\Repository\StateRepository;
use Solportalen\Energy\Learning\ConsumptionDetector;
use Solportalen\Device\Growatt\GrowattSphControl;

final class DeviceWorker
{
    public function run(bool $once = false): void
    {
        $repository = new StateRepository(Connection::get());
        $transport = new LinuxSerialTransport(Env::get('SERIAL_DEVICE', '/dev/ttyUSB0'), (int) Env::get('SERIAL_BAUD', '9600'));
        $reader = new GrowattSphReader($transport, (int) Env::get('SERIAL_SLAVE_ID', '1'));
        $detector = new ConsumptionDetector(Connection::get());
        $commands = new ManualPlanCommandProcessor(Connection::get(),new GrowattSphControl($transport,(int)Env::get('SERIAL_SLAVE_ID','1'),Env::bool('WRITES_ENABLED')));
        $failures = 0;
        do {
            try {
                $state = $reader->readState();
                $repository->store($state);
                $detector->observe((float) ($state['load_power_w'] ?? 0));
                $commands->tick();
                $failures = 0;
                if ($once) {
                    echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
                }
            } catch (\Throwable $error) {
                $failures++;
                $repository->heartbeat('device', 'error', ['message' => $error->getMessage(), 'failures' => $failures]);
                fwrite(STDERR, gmdate(DATE_ATOM) . ' ' . $error->getMessage() . PHP_EOL);
                $transport->close();
                if ($once) {
                    throw $error;
                }
            }
            if (!$once) {
                sleep(min(60, max(5, 5 * $failures)));
            }
        } while (!$once);
    }
}
