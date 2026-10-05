<?php
declare(strict_types=1);

namespace Broadcast\Domain;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

final class PhoneNumber
{
    public static function normalize(string $input): ?string
    {
        $phone = preg_replace('/[\s()\-]/u', '', $input);
        return preg_match('/^\+[1-9][0-9]{6,14}$/D', $phone) ? $phone : null;
    }

    public static function country(string $phone, string $language): ?string
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($phone, null);
            $regions = $util->getRegionCodesForCountryCode($number->getCountryCode());
            $region = count($regions) === 1 ? $regions[0] : $util->getRegionCodeForNumber($number);
            if ($region === null || $region === '001' || $region === 'ZZ') { return null; }
            return \Locale::getDisplayRegion('und_' . $region, strtolower(substr($language, 0, 2)));
        } catch (NumberParseException) {
            return null;
        }
    }
}
