<?php

/**
 * Returns price formatted number.
 *
 * @param  number
 * @return string
 */
if (!function_exists('formattedNumber')) {
    function formattedNumber($number, $currencyCode = 'MXN')
    {
        return '$' . number_format($number, 2, ".", ",") . ' ' . $currencyCode;
    }
}

/**
 * Returns date in local time.
 *
 * @param  DateTime
 * @return DateTime
 */
if (!function_exists('localizedDate')) {
    function localizedDate($date)
    {
        if ($date) {
            $date->setTimeZone('America/Monterrey');

            return $date;
        }

        return null;
    }
}

/**
 * Returns cents price formatted number.
 *
 * @param  number
 * @return string
 */
if (!function_exists('formattedCentsPrice')) {
    function formattedCentsPrice($number, $currencyCode = 'MXN')
    {
        return '$' . number_format($number / 100, 2, ".", ",") . ' ' . $currencyCode;
    }
}

/**
 * Returns price formatted number.
 *
 * @param  number
 * @return string
 */
if (!function_exists('formattedPrice')) {
    function formattedPrice($number, $currencyCode = 'MXN')
    {
        return '$' . number_format($number, 2, ".", ",") . ' ' . $currencyCode;
    }
}

/**
 * Returns cents as a float with optional decimal places.
 *
 * @param  number $number
 * @param  int $decimalPlaces
 * @return float
 */
if (!function_exists('numberCents')) {
    function numberCents($number, $decimalPlaces = 2)
    {
        return round($number / 100, $decimalPlaces);
    }
}

/**
 * Returns cents price formatted number.
 *
 * @param  number
 * @return string
 */
if (!function_exists('formattedCents')) {
    function formattedCents($number)
    {
        return number_format($number / 100, 2, ".", ",");
    }
}
