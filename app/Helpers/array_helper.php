<?php

/**
 * Tourfecto - Array Helper
 * دوال مساعدة للتعامل مع المصفوفات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

if (!function_exists('array_get')) {
    /**
     * الحصول على قيمة من المصفوفة باستخدام النقطة (Dot Notation)
     * @param array $array
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function array_get(array $array, string $key, $default = null)
    {
        if (strpos($key, '.') === false) {
            return $array[$key] ?? $default;
        }

        $keys = explode('.', $key);
        $current = $array;

        foreach ($keys as $segment) {
            if (!is_array($current) || !isset($current[$segment])) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}

if (!function_exists('array_set')) {
    /**
     * تعيين قيمة في المصفوفة باستخدام النقطة (Dot Notation)
     * @param array $array
     * @param string $key
     * @param mixed $value
     * @return array
     */
    function array_set(array $array, string $key, $value): array
    {
        if (strpos($key, '.') === false) {
            $array[$key] = $value;
            return $array;
        }

        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }

        $current = $value;

        return $array;
    }
}

if (!function_exists('array_has')) {
    /**
     * التحقق من وجود مفتاح في المصفوفة باستخدام النقطة
     * @param array $array
     * @param string $key
     * @return bool
     */
    function array_has(array $array, string $key): bool
    {
        return array_get($array, $key, '__NOT_FOUND__') !== '__NOT_FOUND__';
    }
}

if (!function_exists('array_pull')) {
    /**
     * الحصول على قيمة من المصفوفة وحذفها
     * @param array $array
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function array_pull(array &$array, string $key, $default = null)
    {
        $value = array_get($array, $key, $default);
        array_forget($array, $key);
        return $value;
    }
}

if (!function_exists('array_forget')) {
    /**
     * حذف مفتاح من المصفوفة باستخدام النقطة
     * @param array $array
     * @param string $key
     * @return array
     */
    function array_forget(array &$array, string $key): array
    {
        if (strpos($key, '.') === false) {
            unset($array[$key]);
            return $array;
        }

        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $segment) {
            if (!isset($current[$segment])) {
                return $array;
            }

            if ($i === count($keys) - 1) {
                unset($current[$segment]);
            } else {
                $current = &$current[$segment];
            }
        }

        return $array;
    }
}

if (!function_exists('array_only')) {
    /**
     * الحصول على مفاتيح محددة فقط من المصفوفة
     * @param array $array
     * @param array $keys
     * @return array
     */
    function array_only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }
}

if (!function_exists('array_except')) {
    /**
     * استبعاد مفاتيح محددة من المصفوفة
     * @param array $array
     * @param array $keys
     * @return array
     */
    function array_except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }
}

if (!function_exists('array_flatten')) {
    /**
     * تسوية المصفوفة متعددة الأبعاد
     * @param array $array
     * @param int $depth
     * @return array
     */
    function array_flatten(array $array, int $depth = PHP_INT_MAX): array
    {
        $result = [];

        foreach ($array as $item) {
            if (is_array($item) && $depth > 0) {
                $result = array_merge($result, array_flatten($item, $depth - 1));
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }
}

if (!function_exists('array_unique_multidimensional')) {
    /**
     * إزالة المكررات من المصفوفة متعددة الأبعاد
     * @param array $array
     * @param string $key
     * @return array
     */
    function array_unique_multidimensional(array $array, ?string $key = null): array
    {
        if ($key === null) {
            return array_unique($array, SORT_REGULAR);
        }

        $seen = [];
        $result = [];

        foreach ($array as $item) {
            if (isset($item[$key]) && !in_array($item[$key], $seen, true)) {
                $seen[] = $item[$key];
                $result[] = $item;
            }
        }

        return $result;
    }
}

if (!function_exists('array_group_by')) {
    /**
     * تجميع المصفوفة حسب مفتاح معين
     * @param array $array
     * @param string $key
     * @return array
     */
    function array_group_by(array $array, string $key): array
    {
        $result = [];

        foreach ($array as $item) {
            $groupKey = is_array($item) ? ($item[$key] ?? '') : '';
            $result[$groupKey][] = $item;
        }

        return $result;
    }
}

if (!function_exists('array_sort_by')) {
    /**
     * ترتيب المصفوفة حسب مفتاح معين
     * @param array $array
     * @param string $key
     * @param int $order
     * @return array
     */
    function array_sort_by(array $array, string $key, int $order = SORT_ASC): array
    {
        usort($array, function ($a, $b) use ($key, $order) {
            $valA = is_array($a) ? ($a[$key] ?? null) : null;
            $valB = is_array($b) ? ($b[$key] ?? null) : null;

            if ($order === SORT_ASC) {
                return $valA <=> $valB;
            }

            return $valB <=> $valA;
        });

        return $array;
    }
}

if (!function_exists('array_is_associative')) {
    /**
     * التحقق من أن المصفوفة ترابطية (Associative)
     * @param array $array
     * @return bool
     */
    function array_is_associative(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}

if (!function_exists('array_merge_recursive_distinct')) {
    /**
     * دمج المصفوفات بشكل متكرر مع استبدال القيم المكررة
     * @param array $array1
     * @param array $array2
     * @return array
     */
    function array_merge_recursive_distinct(array $array1, array $array2): array
    {
        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($array1[$key]) && is_array($array1[$key])) {
                $array1[$key] = array_merge_recursive_distinct($array1[$key], $value);
            } else {
                $array1[$key] = $value;
            }
        }

        return $array1;
    }
}

if (!function_exists('array_search_recursive')) {
    /**
     * البحث في المصفوفة بشكل متكرر
     * @param mixed $needle
     * @param array $haystack
     * @param bool $strict
     * @return array|bool
     */
    function array_search_recursive($needle, array $haystack, bool $strict = false)
    {
        foreach ($haystack as $key => $value) {
            if (($strict ? $value === $needle : $value == $needle)) {
                return [$key];
            }

            if (is_array($value)) {
                $result = array_search_recursive($needle, $value, $strict);
                if ($result !== false) {
                    return array_merge([$key], $result);
                }
            }
        }

        return false;
    }
}

if (!function_exists('array_pluck')) {
    /**
     * استخراج قيم مفتاح معين من المصفوفة
     * @param array $array
     * @param string $key
     * @return array
     */
    function array_pluck(array $array, string $key): array
    {
        return array_map(function ($item) use ($key) {
            return is_array($item) ? ($item[$key] ?? null) : null;
        }, $array);
    }
}

if (!function_exists('array_zip')) {
    /**
     * دمج مصفوفات متعددة كمصفوفة من الأزواج
     * @param array ...$arrays
     * @return array
     */
    function array_zip(array ...$arrays): array
    {
        $result = [];
        $maxLength = max(array_map('count', $arrays));

        for ($i = 0; $i < $maxLength; $i++) {
            $row = [];
            foreach ($arrays as $array) {
                $row[] = $array[$i] ?? null;
            }
            $result[] = $row;
        }

        return $result;
    }
}

if (!function_exists('array_where')) {
    /**
     * تصفية المصفوفة حسب الشرط
     * @param array $array
     * @param callable $callback
     * @return array
     */
    function array_where(array $array, callable $callback): array
    {
        return array_values(array_filter($array, $callback));
    }
}

if (!function_exists('array_first')) {
    /**
     * الحصول على أول عنصر في المصفوفة
     * @param array $array
     * @param callable|null $callback
     * @param mixed $default
     * @return mixed
     */
    function array_first(array $array, ?callable $callback = null, $default = null)
    {
        if ($callback) {
            foreach ($array as $key => $value) {
                if ($callback($value, $key)) {
                    return $value;
                }
            }
            return $default;
        }

        return reset($array) ?: $default;
    }
}

if (!function_exists('array_last')) {
    /**
     * الحصول على آخر عنصر في المصفوفة
     * @param array $array
     * @param callable|null $callback
     * @param mixed $default
     * @return mixed
     */
    function array_last(array $array, ?callable $callback = null, $default = null)
    {
        if ($callback) {
            $reversed = array_reverse($array, true);
            foreach ($reversed as $key => $value) {
                if ($callback($value, $key)) {
                    return $value;
                }
            }
            return $default;
        }

        return end($array) ?: $default;
    }
}

if (!function_exists('array_random')) {
    /**
     * الحصول على عنصر عشوائي من المصفوفة
     * @param array $array
     * @param int $count
     * @return mixed|array
     */
    function array_random(array $array, int $count = 1)
    {
        $keys = array_rand($array, $count);

        if ($count === 1) {
            return is_array($keys) ? $array[$keys[0]] : $array[$keys];
        }

        $result = [];
        foreach ((array) $keys as $key) {
            $result[] = $array[$key];
        }

        return $result;
    }
}
