<?php

namespace App\Support;

/**
 * Normalize formatted numeric input before validation. Precision, sign and
 * range are still enforced by the receiving request validator.
 */
final class LocalizedDecimal
{
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = strtr($value, [
            '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4',
            '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9',
            '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4',
            '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
            '٫'=>'.', '٬'=>',', '،'=>',',
        ]);

        return str_replace(',', '', $value);
    }

    public static function quantities(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return array_map(self::normalize(...), $value);
    }
}
