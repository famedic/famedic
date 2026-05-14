<?php

/**
 * Returns price formatted number.
 *
 * @param  number
 * @return string
 */
if (! function_exists('formattedNumber')) {
    function formattedNumber($number, $currencyCode = 'MXN')
    {
        return '$'.number_format($number, 2, '.', ',').' '.$currencyCode;
    }
}

/**
 * Returns date in local time.
 *
 * @param  DateTime
 * @return DateTime
 */
if (! function_exists('localizedDate')) {
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
if (! function_exists('formattedCentsPrice')) {
    function formattedCentsPrice($number, $currencyCode = 'MXN')
    {
        return '$'.number_format($number / 100, 2, '.', ',').' '.$currencyCode;
    }
}

/**
 * Returns price formatted number.
 *
 * @param  number
 * @return string
 */
if (! function_exists('formattedPrice')) {
    function formattedPrice($number, $currencyCode = 'MXN')
    {
        return '$'.number_format($number, 2, '.', ',').' '.$currencyCode;
    }
}

/**
 * Returns cents as a float with optional decimal places.
 *
 * @param  number  $number
 * @param  int  $decimalPlaces
 * @return float
 */
if (! function_exists('numberCents')) {
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
if (! function_exists('formattedCents')) {
    function formattedCents($number)
    {
        return number_format($number / 100, 2, '.', ',');
    }
}

/**
 * Oculta un teléfono mostrando solo los últimos dígitos (ej. *** *** 1209).
 *
 * @param  mixed  $phone
 */
if (! function_exists('mask_phone')) {
    function mask_phone($phone): string
    {
        if ($phone === null || $phone === '') {
            return '';
        }

        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return '***';
        }

        $len = strlen($digits);
        if ($len <= 2) {
            return str_repeat('*', 6).substr($digits, -2);
        }

        $last = $len >= 4 ? substr($digits, -4) : substr($digits, -2);

        return $len >= 4
            ? '*** *** '.$last
            : '******'.$last;
    }
}

/**
 * Oculta un correo (ej. l*****@gmail.com).
 *
 * @param  mixed  $email
 */
if (! function_exists('mask_email')) {
    function mask_email($email): string
    {
        if ($email === null || $email === '') {
            return '';
        }

        $email = (string) $email;
        if (! str_contains($email, '@')) {
            return '***@***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $localLen = mb_strlen($local);
        if ($localLen <= 1) {
            return '*@'.$domain;
        }

        $first = mb_substr($local, 0, 1);
        $stars = str_repeat('*', max(3, $localLen - 1));

        return $first.$stars.'@'.$domain;
    }
}
