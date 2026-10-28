<?php

/**
 * Tourfecto - Env File Manager
 * @version 1.0.0
 *
 * إدارة آمنة لملف .env من داخل كود التطبيق (مش من الترمينال)، تُستخدم
 * حصريًا من AdminController (super_admin فقط) عشان تسمح بتعديل مفاتيح
 * الـ API/التكاملات من لوحة الأدمن بدل ما تتعدل يدويًا على السيرفر.
 *
 * قواعد أساسية اتبعناها هنا:
 *  - أي تعديل بياخد نسخة احتياطية كاملة من الملف قبل الكتابة (storage/backups/env).
 *  - الكتابة بتستخدم flock() عشان منمنعش تعارض لو حصل طلبين تعديل في نفس اللحظة.
 *  - بنحافظ على شكل الملف الأصلي (التعليقات والترتيب) وبنعدّل بس قيم
 *    المفاتيح المطلوبة، وأي مفتاح مش موجود أصلاً بيتضاف في الآخر.
 *  - القيم الحساسة (secrets) منعرضهاش كاملة أبدًا لأي طلب - دايمًا مقنّعة
 *    (mask) - التعديل بيتم بس بإدخال قيمة جديدة كاملة.
 */
class EnvFileManager
{
    private static function envPath(): string
    {
        return ROOT_PATH . '/.env';
    }

    private static function backupDir(): string
    {
        return TOURFECTO_STORAGE . '/backups/env';
    }

    /** القيمة الفعلية المحمّلة حاليًا في الذاكرة لمفتاح معيّن ($_ENV/$_SERVER/getenv) */
    public static function currentValue(string $key): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER)) {
            return (string) $_SERVER[$key];
        }
        $value = getenv($key);
        return $value === false ? null : (string) $value;
    }

    /**
     * تقنيع قيمة حساسة: بيعرض آخر 4 خانات بس والباقي نقط.
     * لو القيمة فاضية أو غير موجودة بيرجع سلسلة فاضية.
     */
    public static function mask(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $len = mb_strlen($value);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }
        $dots = min($len - 4, 24);
        return str_repeat('•', $dots) . mb_substr($value, -4);
    }

    /**
     * تحديث مجموعة مفاتيح داخل .env مع الحفاظ على باقي الملف زي ما هو.
     * @param array<string,string> $updates key => new value
     * @throws Exception
     */
    public static function updateKeys(array $updates): void
    {
        if (empty($updates)) {
            return;
        }

        $path = self::envPath();
        if (!file_exists($path)) {
            throw new Exception('ملف .env غير موجود على السيرفر');
        }
        if (!is_writable($path)) {
            throw new Exception('لا توجد صلاحية كتابة على ملف .env (راجع صلاحيات الملفات على السيرفر)');
        }

        $fp = fopen($path, 'r+');
        if (!$fp || !flock($fp, LOCK_EX)) {
            if ($fp) {
                fclose($fp);
            }
            throw new Exception('تعذر قفل ملف .env للتعديل، حاول مرة أخرى');
        }

        try {
            $content = stream_get_contents($fp);
            if ($content === false) {
                throw new Exception('تعذر قراءة محتوى ملف .env');
            }

            // نسخة احتياطية قبل أي تعديل
            self::backup($content);

            $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
            $lines = preg_split('/\r\n|\r|\n/', $content);

            $remaining = $updates;
            foreach ($lines as $i => $line) {
                $trimmed = ltrim($line);
                if ($trimmed === '' || $trimmed[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                $k = trim(explode('=', $line, 2)[0]);
                if (array_key_exists($k, $remaining)) {
                    $lines[$i] = $k . '=' . self::formatValue($remaining[$k]);
                    unset($remaining[$k]);
                }
            }

            foreach ($remaining as $k => $v) {
                $lines[] = $k . '=' . self::formatValue($v);
            }

            $newContent = implode($eol, $lines);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $newContent);
            fflush($fp);

            // نعكس التغيير في الذاكرة كمان عشان أي كود في نفس الطلب الحالي
            // (ولو نادر) يشوف القيمة الجديدة فورًا بدل ما ينتظر الطلب الجاي
            foreach ($updates as $k => $v) {
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
                // putenv ممنوعة في disable_functions على استضافات كتير (زي aaPanel)،
                // وفي PHP 8 مناداة دالة ممنوعة = Fatal Error حتى مع @
                if (function_exists('putenv')) {
                    @putenv("{$k}={$v}");
                }
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private static function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/\s|#|"/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        return $value;
    }

    private static function backup(string $content): void
    {
        $dir = self::backupDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $file = $dir . '/env_' . date('Ymd_His') . '.bak';
        @file_put_contents($file, $content);
        @chmod($file, 0640);

        // الاحتفاظ بآخر 20 نسخة بس عشان الفولدر ميكبرش من غير داعي
        $backups = glob($dir . '/env_*.bak') ?: [];
        if (count($backups) > 20) {
            sort($backups);
            foreach (array_slice($backups, 0, count($backups) - 20) as $old) {
                @unlink($old);
            }
        }
    }
}
