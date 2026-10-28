<?php
/**
 * Tourfecto - مفاتيح التكاملات المحفوظة من لوحة الأدمن ← التكاملات
 *
 * لوحة الأدمن بتحفظ المفاتيح في system_settings (integration_openai_api_key ...)
 * لكن خدمات الذكاء الاصطناعي وغيرها بتقرا من .env بس (env('OPENAI_API_KEY')).
 * النتيجة كانت: الأدمن يحفظ المفتاح، واللوحة تقول "مهيأ"، والخدمة نفسها
 * ماتشوفهوش.
 *
 * الملف ده بيتحمّل بعد database.php و encryption.php وقبل ملفات إعداد
 * المزوّدين (gemini.php / openai.php ...)، وبيحط القيم المحفوظة في $_ENV
 * فكل حاجة بتقرا env() بتشوفها.
 *
 * - اللي اتحفظ من اللوحة ليه الأولوية على .env (زي IntegrationManager).
 * - بس أسماء المفاتيح المسجّلة في app/Config/integrations.php، عشان إعداد
 *   محفوظ مايقدرش يغيّر حاجة حساسة زي DB_PASSWORD أو ENCRYPTION_KEY.
 * - لو قاعدة البيانات مش متاحة أو الجدول مش موجود: بيكمّل على .env عادي.
 */

(static function (): void {
    $registryFile = __DIR__ . '/integrations.php';
    if (!is_file($registryFile) || !class_exists('Database')) {
        return;
    }
    try {
        $registry = require $registryFile;
    } catch (Throwable $e) {
        return;
    }
    $allowed = [];
    foreach ((array) $registry as $meta) {
        foreach ((array) ($meta['env_keys'] ?? []) as $k) {
            $allowed[strtolower((string) $k)] = (string) $k;
        }
        if (!empty($meta['enabled_env'])) {
            $allowed[strtolower((string) $meta['enabled_env'])] = (string) $meta['enabled_env'];
        }
    }
    if (!$allowed) {
        return;
    }

    try {
        $rows = Database::getInstance()->query(
            "SELECT setting_key, setting_value, is_secret FROM system_settings WHERE setting_key LIKE 'integration\\_%'"
        );
    } catch (Throwable $e) {
        return;
    }
    if (!is_array($rows) || !$rows) {
        return;
    }

    $enc = null;
    $canPutenv = function_exists('putenv') && !in_array('putenv', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    foreach ($rows as $r) {
        $name = $allowed[substr((string) $r['setting_key'], strlen('integration_'))] ?? null;
        $val = (string) ($r['setting_value'] ?? '');
        if ($name === null || $val === '') {
            continue;
        }
        if (!empty($r['is_secret'])) {
            try {
                $enc = $enc ?: new Encryption();
                $val = (string) $enc->decrypt($val);
            } catch (Throwable $e) {
                continue;
            }
            if ($val === '') {
                continue;
            }
        }
        $_ENV[$name] = $val;
        $_SERVER[$name] = $val;
        // خدمات قديمة بتقرا بـ getenv() مباشرة (Paymob, Keyword Research ...)
        if ($canPutenv) {
            @putenv($name . '=' . $val);
        }
    }
})();
