<?php

namespace App\Support\Phone;

/**
 * Saudi mobile numbers, always stored in E.164 (+9665XXXXXXXX).
 *
 * People type numbers many ways: 05XXXXXXXX, 5XXXXXXXX, 9665XXXXXXXX,
 * 009665XXXXXXXX, with spaces or dashes, and in Arabic-Indic digits.
 * normalize() accepts those and returns null for anything else, including
 * landlines and foreign numbers.
 */
final class SaudiMobile
{
    public const PATTERN = '/^\+9665[0-9]{8}$/';

    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ASCII_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function normalize(mixed $input): ?string
    {
        if (! is_string($input) || mb_strlen($input) > 32) {
            return null;
        }

        $number = preg_replace('/[\s\-().\x{200E}\x{200F}]/u', '', self::asciiDigits($input));

        // Strip the country code, then the trunk 0 some people keep after it.
        $number = match (true) {
            str_starts_with($number, '+966') => substr($number, 4),
            str_starts_with($number, '00966') => substr($number, 5),
            str_starts_with($number, '966') && strlen($number) >= 12 => substr($number, 3),
            default => $number,
        };

        if (strlen($number) === 10 && str_starts_with($number, '05')) {
            $number = substr($number, 1);
        }

        $e164 = '+966'.$number;

        return preg_match(self::PATTERN, $e164) === 1 ? $e164 : null;
    }

    /**
     * Converts Arabic-Indic and Persian digits to 0-9. Other characters are kept.
     */
    public static function asciiDigits(string $input): string
    {
        return str_replace(self::ARABIC_DIGITS, self::ASCII_DIGITS, $input);
    }

    /**
     * The number with all but the last four digits hidden, for logs.
     */
    public static function mask(string $e164): string
    {
        return substr($e164, 0, 5).str_repeat('•', max(0, strlen($e164) - 9)).substr($e164, -4);
    }
}
