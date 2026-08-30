<?php
declare(strict_types=1);

namespace Solportalen\Energy\Optimizer;

interface EnergyOptimizerInterface
{
    /** @return list<array<string,mixed>> */
    public function optimize(array $intervals, array $battery): array;
}
