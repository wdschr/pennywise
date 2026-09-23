<?php

declare(strict_types=1);

final class Format
{
    private const SYMBOLS = [
        'GBP' => '£',
        'EUR' => '€',
        'USD' => '$',
    ];

    public static function money(float|string $amount, ?string $currency = null): string
    {
        $currency = $currency ?? (string) Config::get('default_currency', 'GBP');
        $symbol = self::SYMBOLS[$currency] ?? ($currency . ' ');

        $value = (float) $amount;
        $formatted = number_format(abs($value), 2);

        return ($value < 0 ? '-' : '') . $symbol . $formatted;
    }

    public static function date(string $date): string
    {
        $ts = strtotime($date);
        return $ts ? date('j M Y', $ts) : $date;
    }

    public static function monthLabel(string $yearMonth): string
    {
        $ts = strtotime($yearMonth . '-01');
        return $ts ? date('F Y', $ts) : $yearMonth;
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}
