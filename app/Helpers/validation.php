<?php

/**
 * Tourfecto - Validation Helper
 * دوال التحقق من صحة البيانات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

if (!function_exists('validate_required')) {
    /**
     * التحقق من وجود قيمة
     * @param mixed $value
     * @return bool
     */
    function validate_required($value): bool
    {
        if (is_null($value)) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return !empty($value);
        }

        return true;
    }
}

if (!function_exists('validate_email')) {
    /**
     * التحقق من صحة البريد الإلكتروني
     * @param string $email
     * @return bool
     */
    function validate_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('validate_url')) {
    /**
     * التحقق من صحة الرابط
     * @param string $url
     * @param bool $allowRelative
     * @return bool
     */
    function validate_url(string $url, bool $allowRelative = false): bool
    {
        if ($allowRelative && strpos($url, '/') === 0) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

if (!function_exists('validate_phone')) {
    /**
     * التحقق من صحة رقم الهاتف
     * @param string $phone
     * @return bool
     */
    function validate_phone(string $phone): bool
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        return strlen($phone) >= 8 && strlen($phone) <= 15;
    }
}

if (!function_exists('validate_numeric')) {
    /**
     * التحقق من القيمة الرقمية
     * @param mixed $value
     * @return bool
     */
    function validate_numeric($value): bool
    {
        return is_numeric($value);
    }
}

if (!function_exists('validate_integer')) {
    /**
     * التحقق من القيمة الصحيحة
     * @param mixed $value
     * @return bool
     */
    function validate_integer($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }
}

if (!function_exists('validate_float')) {
    /**
     * التحقق من القيمة العشرية
     * @param mixed $value
     * @return bool
     */
    function validate_float($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }
}

if (!function_exists('validate_boolean')) {
    /**
     * التحقق من القيمة المنطقية
     * @param mixed $value
     * @return bool
     */
    function validate_boolean($value): bool
    {
        return is_bool($value) || in_array($value, ['true', 'false', '1', '0', 1, 0], true);
    }
}

if (!function_exists('validate_string')) {
    /**
     * التحقق من النص
     * @param mixed $value
     * @return bool
     */
    function validate_string($value): bool
    {
        return is_string($value);
    }
}

if (!function_exists('validate_array')) {
    /**
     * التحقق من المصفوفة
     * @param mixed $value
     * @return bool
     */
    function validate_array($value): bool
    {
        return is_array($value);
    }
}

if (!function_exists('validate_min')) {
    /**
     * التحقق من الحد الأدنى
     * @param mixed $value
     * @param int $min
     * @return bool
     */
    function validate_min($value, int $min): bool
    {
        if (is_numeric($value)) {
            return $value >= $min;
        }

        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }

        if (is_array($value)) {
            return count($value) >= $min;
        }

        return false;
    }
}

if (!function_exists('validate_max')) {
    /**
     * التحقق من الحد الأقصى
     * @param mixed $value
     * @param int $max
     * @return bool
     */
    function validate_max($value, int $max): bool
    {
        if (is_numeric($value)) {
            return $value <= $max;
        }

        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }

        if (is_array($value)) {
            return count($value) <= $max;
        }

        return false;
    }
}

if (!function_exists('validate_between')) {
    /**
     * التحقق من القيمة بين حدين
     * @param mixed $value
     * @param int $min
     * @param int $max
     * @return bool
     */
    function validate_between($value, int $min, int $max): bool
    {
        if (is_numeric($value)) {
            return $value >= $min && $value <= $max;
        }

        if (is_string($value)) {
            $len = mb_strlen($value);
            return $len >= $min && $len <= $max;
        }

        if (is_array($value)) {
            $count = count($value);
            return $count >= $min && $count <= $max;
        }

        return false;
    }
}

if (!function_exists('validate_in')) {
    /**
     * التحقق من وجود القيمة في القائمة المسموحة
     * @param mixed $value
     * @param array $allowed
     * @return bool
     */
    function validate_in($value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }
}

if (!function_exists('validate_not_in')) {
    /**
     * التحقق من عدم وجود القيمة في القائمة الممنوعة
     * @param mixed $value
     * @param array $forbidden
     * @return bool
     */
    function validate_not_in($value, array $forbidden): bool
    {
        return !in_array($value, $forbidden, true);
    }
}

if (!function_exists('validate_regex')) {
    /**
     * التحقق من مطابقة النمط
     * @param string $value
     * @param string $pattern
     * @return bool
     */
    function validate_regex(string $value, string $pattern): bool
    {
        return preg_match($pattern, $value) === 1;
    }
}

if (!function_exists('validate_alpha')) {
    /**
     * التحقق من أن النص يحتوي على أحرف فقط
     * @param string $value
     * @return bool
     */
    function validate_alpha(string $value): bool
    {
        return preg_match('/^[\p{L}]+$/u', $value) === 1;
    }
}

if (!function_exists('validate_alpha_num')) {
    /**
     * التحقق من أن النص يحتوي على أحرف وأرقام فقط
     * @param string $value
     * @return bool
     */
    function validate_alpha_num(string $value): bool
    {
        return preg_match('/^[\p{L}\p{N}]+$/u', $value) === 1;
    }
}

if (!function_exists('validate_alpha_dash')) {
    /**
     * التحقق من أن النص يحتوي على أحرف وأرقام وشرطات فقط
     * @param string $value
     * @return bool
     */
    function validate_alpha_dash(string $value): bool
    {
        return preg_match('/^[\p{L}\p{N}\-]+$/u', $value) === 1;
    }
}

if (!function_exists('validate_date')) {
    /**
     * التحقق من صحة التاريخ
     * @param string $date
     * @param string $format
     * @return bool
     */
    function validate_date(string $date, string $format = 'Y-m-d'): bool
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}

if (!function_exists('validate_datetime')) {
    /**
     * التحقق من صحة التاريخ والوقت
     * @param string $datetime
     * @param string $format
     * @return bool
     */
    function validate_datetime(string $datetime, string $format = 'Y-m-d H:i:s'): bool
    {
        $d = DateTime::createFromFormat($format, $datetime);
        return $d && $d->format($format) === $datetime;
    }
}

if (!function_exists('validate_ip')) {
    /**
     * التحقق من صحة عنوان IP
     * @param string $ip
     * @param int $type
     * @return bool
     */
    function validate_ip(string $ip, int $type = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, $type) !== false;
    }
}

if (!function_exists('validate_credit_card')) {
    /**
     * التحقق من صحة رقم بطاقة الائتمان (Luhn Algorithm)
     * @param string $number
     * @return bool
     */
    function validate_credit_card(string $number): bool
    {
        $number = preg_replace('/[^0-9]/', '', $number);

        if (strlen($number) < 13 || strlen($number) > 19) {
            return false;
        }

        $sum = 0;
        $alternate = false;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $digit = (int) $number[$i];

            if ($alternate) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $alternate = !$alternate;
        }

        return $sum % 10 === 0;
    }
}

if (!function_exists('validate_password_strength')) {
    /**
     * التحقق من قوة كلمة المرور
     * @param string $password
     * @param int $minLength
     * @param int $minComplexity
     * @return bool|array
     */
    function validate_password_strength(string $password, int $minLength = 8, int $minComplexity = 3): bool|array
    {
        $checks = [
            'length' => strlen($password) >= $minLength,
            'uppercase' => preg_match('/[A-Z]/', $password) === 1,
            'lowercase' => preg_match('/[a-z]/', $password) === 1,
            'number' => preg_match('/[0-9]/', $password) === 1,
            'special' => preg_match('/[^A-Za-z0-9]/', $password) === 1
        ];

        $score = array_sum($checks);

        if ($score >= $minComplexity) {
            return true;
        }

        return [
            'valid' => false,
            'score' => $score,
            'checks' => $checks,
            'min_complexity' => $minComplexity
        ];
    }
}
