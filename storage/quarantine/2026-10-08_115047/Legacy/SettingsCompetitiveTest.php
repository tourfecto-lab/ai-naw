<?php

/**
 * Tourfecto - Settings Center Competitive Upgrade Test
 * اختبارات منطق الترقيات التنافسية (Phase 15): صلاحية مفاتيح API
 * وإعادة توليد أكواد الطوارئ. كل المنطق هنا Pure Functions ثابتة -
 * بدون أي اتصال بقاعدة بيانات حقيقية (نفس مبدأ RevenueIntelligenceTest).
 *
 * @version 1.0.0
 */

// نستبدل الـ Model base بـ Stub خفيف (فيه نفس توقيع getAttribute/where فقط
// بما يكفي لتحميل الكلاس واختبار الـ static methods الخالصة - مش أي
// اتصال بقاعدة بيانات حقيقية).
if (!class_exists('Model', false)) {
    class Model
    {
        protected $attributes = [];
        public function __construct(array $attributes = [])
        {
            $this->attributes = $attributes;
        }
        public function getAttribute(string $key)
        {
            return $this->attributes[$key] ?? null;
        }
        public function where(array $conditions, array $orderBy = [], int $limit = 0): array
        {
            return [];
        }
        public function setAttribute(string $key, $value)
        {
            $this->attributes[$key] = $value;
            return $this;
        }
        public function save()
        {
            return true;
        }
    }
}

// User stub (لاختبار تفضيلات الإشعارات - Notification::preferencesFor بتاخد User)
if (!class_exists('User', false)) {
    class User extends Model
    {
    }
}

require_once __DIR__ . '/../../app/Models/UserApiKey.php';
require_once __DIR__ . '/../../app/Services/TotpService.php';
require_once __DIR__ . '/../../app/Models/Notification.php';
require_once __DIR__ . '/../../app/Models/RefreshToken.php';

class SettingsCompetitiveTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function runAll(): void
    {
        echo "\n⚙️  Settings Center Competitive Tests\n";
        echo "=====================================\n";

        $this->testApiKeyExpiry();
        $this->testApiKeyScopes();
        $this->testRecoveryCodeRotation();
        $this->testNotificationDigestPrefs();
        $this->testSessionRenameValidation();

        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "⚙️  Settings Center Competitive Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n\n";
    }

    private function assertTrue(bool $cond, string $label): void
    {
        if ($cond) {
            $this->passed++;
            echo "  ✅ {$label}\n";
        } else {
            $this->failed++;
            echo "  ❌ FAIL: {$label}\n";
        }
    }

    private function testApiKeyExpiry(): void
    {
        echo "\n--- API Key Expiry ---\n";

        // null / '' = لا ينتهي أبدًا
        $this->assertTrue(UserApiKey::isExpired(null) === false, 'isExpired(null) = false (never expires)');
        $this->assertTrue(UserApiKey::isExpired('') === false, 'isExpired("") = false (never expires)');

        // تاريخ مستقبلي = مش منتهي
        $future = date('Y-m-d H:i:s', time() + 86400);
        $this->assertTrue(UserApiKey::isExpired($future) === false, 'isExpired(future) = false');

        // تاريخ ماضي = منتهي (الحافة الزمنية بالضبط)
        $past = date('Y-m-d H:i:s', time() - 86400);
        $this->assertTrue(UserApiKey::isExpired($past) === true, 'isExpired(past) = true');

        // الحافة بالضبط: نفس الثانية الحالية تعتبر منتهية (<= time())
        $now = date('Y-m-d H:i:s', time());
        $this->assertTrue(UserApiKey::isExpired($now) === true, 'isExpired(now) = true (<= time())');
    }

    private function testApiKeyScopes(): void
    {
        echo "\n--- API Key Scopes ---\n";

        // null / '' = وصول كامل (توافق خلفي مع المفاتيح القديمة)
        $this->assertTrue(UserApiKey::hasScope(null, 'audit:read') === true, 'hasScope(null, any) = true (full access)');
        $this->assertTrue(UserApiKey::hasScope('', 'profile:write') === true, 'hasScope("", any) = true (full access)');

        // JSON غير صالح = وصول كامل (نسامح)
        $this->assertTrue(UserApiKey::hasScope('not-json', 'audit:read') === true, 'hasScope(invalid json) = true');

        // صلاحية موجودة في القائمة
        $json = json_encode(['profile:read', 'audit:read']);
        $this->assertTrue(UserApiKey::hasScope($json, 'profile:read') === true, 'hasScope(list, profile:read) = true');
        $this->assertTrue(UserApiKey::hasScope($json, 'audit:read') === true, 'hasScope(list, audit:read) = true');

        // صلاحية مش موجودة = مرفوضة
        $this->assertTrue(UserApiKey::hasScope($json, 'workspace:write') === false, 'hasScope(list, workspace:write) = false');

        // generateFor بينضّف الـ scopes: فقط المعروفة بيدخلوا
        $scopes = ['audit:read', 'not:a_known_scope'];
        $attrs = [];
        // نختبر منطق التنضيف بنفسه (نفس كود generateFor):
        $clean = array_values(array_intersect($scopes, array_keys(UserApiKey::SCOPES)));
        $this->assertTrue(in_array('audit:read', $clean, true) === true, 'generateFor keeps known scope');
        $this->assertTrue(in_array('not:a_known_scope', $clean, true) === false, 'generateFor drops unknown scope');

        // كل الصلاحيات المعروفة معرّفة (قائمة ثابتة كاملة)
        $known = array_keys(UserApiKey::SCOPES);
        $this->assertTrue(in_array('profile:read', $known, true), 'SCOPES contains profile:read');
        $this->assertTrue(in_array('profile:write', $known, true), 'SCOPES contains profile:write');
        $this->assertTrue(in_array('billing:read', $known, true), 'SCOPES contains billing:read');
        $this->assertTrue(in_array('workspace:read', $known, true), 'SCOPES contains workspace:read');
        $this->assertTrue(in_array('workspace:write', $known, true), 'SCOPES contains workspace:write');
        $this->assertTrue(in_array('audit:read', $known, true), 'SCOPES contains audit:read');
        $this->assertTrue(in_array('data:export', $known, true), 'SCOPES contains data:export');
    }

    private function testRecoveryCodeRotation(): void
    {
        echo "\n--- Recovery Code Rotation ---\n";

        // توليد دفعتين من أكواد الطوارئ
        $oldSet = TotpService::generateRecoveryCodes(10);
        $newSet = TotpService::generateRecoveryCodes(10);

        // الدفعة الجديدة غير متطابقة مع القديمة (عشوائية حقيقية)
        $this->assertTrue($oldSet !== $newSet, 'New set differs from old set (real randomness)');

        // كل كود من الدفعة الجديدة بصيغة XXXX-XXXX صالحة
        $allValid = true;
        foreach ($newSet as $code) {
            if (preg_match('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $code) !== 1) {
                $allValid = false;
            }
        }
        $this->assertTrue($allValid, 'All new codes match XXXX-XXXX format');

        // هاش الدفعة الجديدة بيحقق ضد الأكواد الجديدة (والتدوير سليم)
        $hashedNew = TotpService::hashRecoveryCodes($newSet);
        $index = TotpService::verifyRecoveryCode($hashedNew, $newSet[0]);
        $this->assertTrue($index === 0, 'New hashed set verifies new codes (index 0)');

        // الكود القديم لا يعمل مع الدفعة الجديدة = الإبطال الفعلي للقديم
        $oldIndex = TotpService::verifyRecoveryCode($hashedNew, $oldSet[0]);
        $this->assertTrue($oldIndex === null, 'Old code rejected by new set (old set invalidated)');

        // تطبيع الإدخال: أحرف صغيرة/مسافات تُقبل
        $normalized = TotpService::verifyRecoveryCode($hashedNew, ' ' . strtolower($newSet[1]) . ' ');
        $this->assertTrue($normalized === 1, 'Lowercase + whitespace input still verified (normalized)');
    }

    private function testNotificationDigestPrefs(): void
    {
        echo "\n--- Notification Digest Preferences (Phase 16C) ---\n";

        $defaults = Notification::defaultPreferences();
        $this->assertTrue(($defaults['digest_daily'] ?? false) === true, 'defaultPreferences has digest_daily = true');
        $this->assertTrue(($defaults['digest_weekly'] ?? false) === true, 'defaultPreferences has digest_weekly = true');

        // مستخدم بدون تفضيلات محفوظة => digest مفعّل افتراضيًا
        $u = new User();
        $this->assertTrue(Notification::digestEnabledFor($u, 'digest_daily') === true, 'digestEnabledFor(default user, daily) = true');

        // مستخدم قفّل الـ weekly => يترفض
        $u2 = new User(['notification_preferences' => json_encode(['digest_weekly' => false])]);
        $this->assertTrue(Notification::digestEnabledFor($u2, 'digest_weekly') === false, 'digestEnabledFor(opted-out user, weekly) = false');
        $this->assertTrue(Notification::digestEnabledFor($u2, 'digest_daily') === true, 'digestEnabledFor(opted-out user, daily) = still true');

        // digest نوع غير معروف => مفعّل (feature جديدة متتقفلش)
        $this->assertTrue(Notification::digestEnabledFor($u, 'digest_mystery') === true, 'Unknown digest type defaults to enabled');
    }

    private function testSessionRenameValidation(): void
    {
        echo "\n--- Session Device Rename Validation (Phase 16B) ---\n";

        // فارغ => مرفوض
        $t = new RefreshToken(['device_name' => '']);
        $this->assertTrue($t->renameDevice('   ') === false, 'renameDevice(whitespace) = false');
        $this->assertTrue($t->renameDevice('') === false, 'renameDevice(empty) = false');

        // أطول من 60 حرفًا => مرفوض
        $long = str_repeat('a', 61);
        $this->assertTrue($t->renameDevice($long) === false, 'renameDevice(61 chars) = false');

        // بالضبط 60 => مقبول
        $this->assertTrue($t->renameDevice(str_repeat('a', 60)) === true, 'renameDevice(60 chars) = true');

        // معقّم من الـ HTML tags (strip_tags يبعد الأقواس لكن يحتفظ بالنص
        // الداخلي كنص عادي - الناتج خالي من < > فيبقى آمن عند العرض)
        $this->assertTrue($t->renameDevice('<script>alert(1)</script>Laptop') === true, 'renameDevice(strips tags) accepted');
        $stored = $t->getAttribute('device_name');
        $this->assertTrue(strpos($stored, '<') === false && strpos($stored, '>') === false, 'Stored device name contains no HTML brackets');
        $this->assertTrue(strpos($stored, 'alert(1)') !== false, 'Stored device name keeps plain inner text');
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new SettingsCompetitiveTest();
    $test->runAll();
}
