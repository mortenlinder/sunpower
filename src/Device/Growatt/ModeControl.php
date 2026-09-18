<?php
declare(strict_types=1);
namespace Solportalen\Device\Growatt;
interface ModeControl
{
    public function applySchedule(array $schedule): array;
    public function setFallbackMode(string $mode): array;
}
