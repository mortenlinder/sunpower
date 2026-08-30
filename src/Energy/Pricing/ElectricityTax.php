<?php
declare(strict_types=1);

namespace Solportalen\Energy\Pricing;

use Solportalen\Config\Env;

final class ElectricityTax
{
    public static function blendedRate(): float
    {
        $annual = max(0.0, (float) Env::get('ANNUAL_ELECTRICITY_CONSUMPTION_KWH', '12000'));
        $threshold = max(0.0, (float) Env::get('REDUCED_TAX_THRESHOLD_KWH', '4000'));
        $full = max(0.0, (float) Env::get('ENERGY_TAX_FULL_DKK_KWH', '.008'));
        $reduced = max(0.0, (float) Env::get('ENERGY_TAX_REDUCED_DKK_KWH', '.008'));
        if ($annual <= 0.0) return $full;
        return ((min($annual, $threshold) * $full) + (max(0.0, $annual - $threshold) * $reduced)) / $annual;
    }

    public static function description(): array
    {
        return [
            'annual_consumption_kwh' => (float) Env::get('ANNUAL_ELECTRICITY_CONSUMPTION_KWH', '12000'),
            'threshold_kwh' => (float) Env::get('REDUCED_TAX_THRESHOLD_KWH', '4000'),
            'full_rate_dkk_kwh_ex_vat' => (float) Env::get('ENERGY_TAX_FULL_DKK_KWH', '.008'),
            'reduced_rate_dkk_kwh_ex_vat' => (float) Env::get('ENERGY_TAX_REDUCED_DKK_KWH', '.008'),
            'blended_rate_dkk_kwh_ex_vat' => round(self::blendedRate(), 6),
            'method' => 'annual_blended',
        ];
    }
}
