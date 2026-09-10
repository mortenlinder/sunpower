<?php
declare(strict_types=1);
namespace Solportalen\Energy\Planning;

final class SolarPowerForecast
{
    /** Approximate daylight/cloud model, not a calibrated production forecast. */
    public static function watts(int $at,float $cloud,float $latitude,float $longitude,int $peak):int
    {
        $sun=date_sun_info($at,$latitude,$longitude);
        if(!is_int($sun['sunrise'])||!is_int($sun['sunset'])||$at<=$sun['sunrise']||$at>=$sun['sunset'])return 0;
        $daylight=max(0,sin(M_PI*($at-$sun['sunrise'])/max(1,$sun['sunset']-$sun['sunrise'])));
        return (int)round(max(0,$peak)*$daylight*(1-.82*max(0,min(100,$cloud))/100));
    }
}
