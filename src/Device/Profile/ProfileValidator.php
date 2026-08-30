<?php
declare(strict_types=1);

namespace Solportalen\Device\Profile;

use RuntimeException;

final class ProfileValidator
{
    private const DENIED = ['country_code','grid_standard','anti_islanding','voltage_limit','frequency_limit','protection_relay','battery_chemistry','battery_type','bms','calibration','factory','installer_password','firmware'];

    public function validate(array $profile): void
    {
        foreach (['id', 'schema_version', 'manufacturer', 'model_family', 'verified', 'registers', 'capabilities'] as $field) {
            if (!array_key_exists($field, $profile)) {
                throw new RuntimeException('Profil mangler feltet ' . $field);
            }
        }
        foreach ($profile['registers'] as $register) {
            if (($register['writable'] ?? false) && in_array($register['safety_category'] ?? '', self::DENIED, true)) {
                throw new RuntimeException('Profil indeholder forbudt write-kategori.');
            }
            if (($register['writable'] ?? false) && ($profile['verified'] ?? false) !== true) {
                throw new RuntimeException('En ikke-verificeret profil må ikke indeholde aktive writes.');
            }
        }
    }
}
