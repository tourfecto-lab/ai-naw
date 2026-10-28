<?php

/**
 * Tourfecto - Formatting Helper
 * دوال تنسيق النصوص والأرقام والتواريخ
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

if (!function_exists('format_currency')) {
    /**
     * تنسيق العملة
     * @param float $amount
     * @param string $currency
     * @param int $decimals
     * @return string
     */
    function format_currency(float $amount, string $currency = 'USD', int $decimals = 2): string
    {
        $symbols = CURRENCY_SYMBOLS ?? [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£',
            'EGP' => 'E£', 'SAR' => '﷼', 'AED' => 'د.إ'
        ];

        $symbol = $symbols[$currency] ?? $currency;

        return $symbol . number_format($amount, $decimals);
    }
}

if (!function_exists('format_number')) {
    /**
     * تنسيق الأرقام
     * @param float $number
     * @param int $decimals
     * @param string $decimalSeparator
     * @param string $thousandsSeparator
     * @return string
     */
    function format_number(
        float $number,
        int $decimals = 0,
        string $decimalSeparator = '.',
        string $thousandsSeparator = ','
    ): string {
        return number_format($number, $decimals, $decimalSeparator, $thousandsSeparator);
    }
}

if (!function_exists('format_percentage')) {
    /**
     * تنسيق النسبة المئوية
     * @param float $value
     * @param int $decimals
     * @return string
     */
    function format_percentage(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals) . '%';
    }
}

if (!function_exists('format_money')) {
    /**
     * تنسيق المبلغ بصيغة عربية
     * @param float $amount
     * @param string $currency
     * @return string
     */
    function format_money(float $amount, string $currency = 'USD'): string
    {
        $formatted = format_currency($amount, $currency);

        // إضافة مسافة بين الرمز والمبلغ
        if (in_array($currency, ['EGP', 'SAR', 'AED', 'KWD', 'BHD'])) {
            return $formatted . ' ' . $currency;
        }

        return $formatted;
    }
}

if (!function_exists('format_phone')) {
    /**
     * تنسيق رقم الهاتف
     * @param string $phone
     * @param string $format
     * @return string
     */
    function format_phone(string $phone, string $format = 'default'): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if ($format === 'international') {
            if (strlen($phone) === 10) {
                return '+1 ' . substr($phone, 0, 3) . '-' . substr($phone, 3, 3) . '-' . substr($phone, 6, 4);
            }
            if (strlen($phone) === 11) {
                return '+' . substr($phone, 0, 1) . ' ' . substr($phone, 1, 3) . '-' . substr($phone, 4, 3) . '-' . substr($phone, 7, 4);
            }
        }

        // تنسيق افتراضي
        if (strlen($phone) === 10) {
            return substr($phone, 0, 3) . '-' . substr($phone, 3, 3) . '-' . substr($phone, 6, 4);
        }

        if (strlen($phone) === 11) {
            return substr($phone, 0, 1) . ' ' . substr($phone, 1, 3) . ' ' . substr($phone, 4, 3) . ' ' . substr($phone, 7, 4);
        }

        return $phone;
    }
}

if (!function_exists('format_address')) {
    /**
     * تنسيق العنوان
     * @param array $address
     * @param string $separator
     * @return string
     */
    function format_address(array $address, string $separator = ', '): string
    {
        $parts = [];

        if (isset($address['street'])) {
            $parts[] = $address['street'];
        }

        if (isset($address['city'])) {
            $parts[] = $address['city'];
        }

        if (isset($address['state'])) {
            $parts[] = $address['state'];
        }

        if (isset($address['postal_code'])) {
            $parts[] = $address['postal_code'];
        }

        if (isset($address['country'])) {
            $parts[] = $address['country'];
        }

        return implode($separator, $parts);
    }
}

if (!function_exists('format_json')) {
    /**
     * تنسيق JSON
     * @param mixed $data
     * @param bool $pretty
     * @return string
     */
    function format_json($data, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($data, $flags);
    }
}

if (!function_exists('format_xml')) {
    /**
     * تنسيق XML
     * @param array $data
     * @param string $root
     * @param bool $pretty
     * @return string
     */
    function format_xml(array $data, string $root = 'root', bool $pretty = true): string
    {
        $xml = new SimpleXMLElement('<' . $root . '/>');
        array_to_xml($data, $xml);

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = $pretty;

        return $dom->saveXML();
    }
}

/**
 * تحويل المصفوفة إلى XML
 * @param array $data
 * @param SimpleXMLElement $xml
 */
function array_to_xml(array $data, SimpleXMLElement $xml): void
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if (isset($value['@attributes'])) {
                $attributes = $value['@attributes'];
                unset($value['@attributes']);

                $child = $xml->addChild($key);
                foreach ($attributes as $attrKey => $attrValue) {
                    $child->addAttribute($attrKey, $attrValue);
                }
                array_to_xml($value, $child);
            } else {
                $child = $xml->addChild($key);
                array_to_xml($value, $child);
            }
        } else {
            $xml->addChild($key, htmlspecialchars((string) $value));
        }
    }
}

if (!function_exists('format_slug')) {
    /**
     * تنسيق النص إلى Slug
     * @param string $text
     * @param string $separator
     * @return string
     */
    function format_slug(string $text, string $separator = '-'): string
    {
        return slugify($text, $separator);
    }
}

if (!function_exists('format_truncate')) {
    /**
     * قص النص مع الحفاظ على الكلمات الكاملة
     * @param string $text
     * @param int $length
     * @param string $suffix
     * @return string
     */
    function format_truncate(string $text, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $text = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($text, ' ');

        if ($lastSpace !== false) {
            $text = mb_substr($text, 0, $lastSpace);
        }

        return $text . $suffix;
    }
}

if (!function_exists('format_camel_case')) {
    /**
     * تحويل النص إلى CamelCase
     * @param string $text
     * @param bool $capitalizeFirst
     * @return string
     */
    function format_camel_case(string $text, bool $capitalizeFirst = false): string
    {
        $words = explode(' ', str_replace(['-', '_'], ' ', $text));

        foreach ($words as &$word) {
            $word = ucfirst(strtolower($word));
        }

        $result = implode('', $words);

        if (!$capitalizeFirst) {
            $result = lcfirst($result);
        }

        return $result;
    }
}

if (!function_exists('format_snake_case')) {
    /**
     * تحويل النص إلى Snake Case
     * @param string $text
     * @return string
     */
    function format_snake_case(string $text): string
    {
        $text = preg_replace('/\s+/', '_', $text);
        $text = preg_replace('/-/', '_', $text);
        return strtolower($text);
    }
}

if (!function_exists('format_kebab_case')) {
    /**
     * تحويل النص إلى Kebab Case
     * @param string $text
     * @return string
     */
    function format_kebab_case(string $text): string
    {
        $text = preg_replace('/\s+/', '-', $text);
        $text = preg_replace('/_/', '-', $text);
        return strtolower($text);
    }
}

if (!function_exists('format_duration')) {
    /**
     * تنسيق المدة الزمنية
     * @param int $seconds
     * @param string $format
     * @return string
     */
    function format_duration(int $seconds, string $format = 'hms'): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        switch ($format) {
            case 'hms':
                return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
            case 'hm':
                return sprintf('%02d:%02d', $hours, $minutes);
            case 'ms':
                return sprintf('%02d:%02d', $minutes, $secs);
            default:
                return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        }
    }
}
