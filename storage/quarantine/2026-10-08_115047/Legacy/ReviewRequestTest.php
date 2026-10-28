<?php

/**
 * Tourfecto - Review Request Integration Test
 * اختبارات نظام طلب المراجعات (Review Request)
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 *
 * ملحوظة: مبني بنفس نمط tests/Integration/ReputationTest.php الموجود
 * فعليًا (test harness بسيط، مش PHPUnit فعلي رغم وجود phpunit.xml في
 * نفس المجلد). محتاج اتصال قاعدة بيانات حقيقي يشتغل - ما تم تشغيله في
 * بيئة التنفيذ الحالية لعدم توفر PHP CLI ولا قاعدة بيانات هنا.
 *
 * تشغيل: php tests/Integration/ReviewRequestTest.php
 */

class ReviewRequestTest
{
    /** @var array $testResults */
    private $testResults = [];
    /** @var int $passed */
    private $passed = 0;
    /** @var int $failed */
    private $failed = 0;
    /** @var ReviewRequestService $service */
    private $service;
    /** @var Database $db */
    private $db;
    /** @var int $testUserId */
    private $testUserId;
    /** @var int $testWebsiteId */
    private $testWebsiteId;
    /** @var array $createdRequestIds - لعمل cleanup */
    private $createdRequestIds = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->service = new ReviewRequestService();
        $this->testUserId = $this->createTestUser();
        $this->testWebsiteId = $this->createTestWebsite();
    }

    private function createTestUser(): int
    {
        $sql = "INSERT INTO users (company_name, email, password, phone, is_active)
                VALUES (:company_name, :email, :password, :phone, :is_active)";

        return $this->db->query($sql, [
            ':company_name' => 'Test Review Request Company',
            ':email' => 'rr_test_' . uniqid() . '@example.com',
            ':password' => password_hash('Test@123', PASSWORD_ARGON2ID),
            ':phone' => '+966500000002',
            ':is_active' => 1,
        ]);
    }

    private function createTestWebsite(): int
    {
        $sql = "INSERT INTO websites (user_id, main_url, company_name, industry, is_verified)
                VALUES (:user_id, :main_url, :company_name, :industry, :is_verified)";

        return $this->db->query($sql, [
            ':user_id' => $this->testUserId,
            ':main_url' => 'https://rr-test-' . uniqid() . '.com',
            ':company_name' => 'Test Review Request Website',
            ':industry' => 'tourism',
            ':is_verified' => 1,
        ]);
    }

    /**
     * يزرع اتصال UltraMsg "متصل" وهمي بالتصميم بس صحيح في البنية
     * (نفس الجدول الحقيقي platform_connections) عشان isChannelConfigured()
     * يرجع true وقت الاختبار - من غير كده كل اختبارات الإنشاء هترمي
     * "قناة غير مفعّلة" لأن مفيش UltraMsg حقيقي متصل بموقع الاختبار.
     */
    private function connectTestWhatsappChannel(): void
    {
        $this->db->query(
            "INSERT INTO platform_connections (website_id, platform, status, created_at)
             VALUES (?, 'ultramsg', 'connected', NOW())",
            [$this->testWebsiteId]
        );
    }

    private function enableAndSetReviewLink(): void
    {
        $this->service->saveSettings($this->testWebsiteId, [
            'is_enabled' => 1,
            'default_review_link' => 'https://g.page/r/test-review-link',
            'default_delay_hours' => 4,
            'message_template' => 'مرحبًا {name}! {review_link}',
            'email_subject' => 'قيّم تجربتك',
            'reminder_enabled' => 1,
            'reminder_after_hours' => 48,
            'reminder_template' => 'تذكير {name}: {review_link}',
            'auto_from_crm_won' => 0,
        ]);
    }

    public function runAll(): void
    {
        echo "\n⭐ Review Request Integration Tests\n";
        echo "=====================================\n\n";

        $this->testCreateRequestBasic();
        $this->testCreateRequestMissingChannelConfig();
        $this->testDuplicateProtection();
        $this->testOptOutPreventsNewRequest();
        $this->testUpdateOnlyAllowedWhenScheduled();
        $this->testRetryCap();
        $this->testTenantIsolation();
        $this->testTemplatesSeedAndCrud();
        $this->testSmartTimingNotEnoughData();
        $this->testAnalyticsNotEnoughData();

        $this->cleanup();
        $this->printSummary();
    }

    /** إنشاء طلب أساسي لازم ينجح لما القناة مهيأة والإعدادات صح */
    private function testCreateRequestBasic(): void
    {
        $this->startTest('إنشاء طلب مراجعة أساسي (واتساب)');
        $this->connectTestWhatsappChannel();
        $this->enableAndSetReviewLink();

        try {
            $request = $this->service->createRequest(
                $this->testUserId,
                $this->testWebsiteId,
                'ضيف اختبار',
                '201000000001',
                date('Y-m-d H:i:s', strtotime('-1 hour')),
                'whatsapp'
            );
            $this->createdRequestIds[] = (int) $request->getAttribute('id');

            if ($request->getAttribute('status') === 'scheduled' && $request->getAttribute('channel') === 'whatsapp') {
                $this->pass('تم إنشاء الطلب بحالة scheduled وقناة whatsapp صحيحة');
            } else {
                $this->fail('حالة أو قناة الطلب غير متوقعة: ' . $request->getAttribute('status'));
            }
        } catch (Exception $e) {
            $this->fail('فشل إنشاء طلب أساسي: ' . $e->getMessage());
        }
    }

    /** لازم يرفض الإنشاء لو القناة (إيميل هنا) مش مهيأة */
    private function testCreateRequestMissingChannelConfig(): void
    {
        $this->startTest('رفض الإنشاء لقناة غير مهيأة (إيميل بدون Mailer)');

        try {
            $this->service->createRequest(
                $this->testUserId,
                $this->testWebsiteId,
                'ضيف إيميل',
                null,
                date('Y-m-d H:i:s'),
                'email',
                'guest@example.com'
            );
            $this->fail('كان المفروض يرمي Exception لأن قناة الإيميل غير مهيأة في بيئة الاختبار');
        } catch (Exception $e) {
            $this->pass('رفض الإنشاء صح: ' . $e->getMessage());
        }
    }

    /** لازم يرفض إنشاء طلب تاني لنفس الرقم خلال نافذة التكرار */
    private function testDuplicateProtection(): void
    {
        $this->startTest('منع تكرار طلب لنفس الضيف خلال 24 ساعة');

        try {
            $this->service->createRequest(
                $this->testUserId,
                $this->testWebsiteId,
                'ضيف اختبار',
                '201000000001',
                date('Y-m-d H:i:s'),
                'whatsapp'
            );
            $this->fail('كان المفروض يرمي Exception لتكرار نفس رقم الضيف');
        } catch (Exception $e) {
            $this->pass('تم منع التكرار صح: ' . $e->getMessage());
        }
    }

    /** بعد Opt-Out، أي طلب جديد لنفس الرقم لازم يتمنع حتى لو النافذة الزمنية عدّت */
    private function testOptOutPreventsNewRequest(): void
    {
        $this->startTest('Opt-Out دائم يمنع أي طلب جديد لنفس الضيف');

        try {
            $request = $this->service->createRequest(
                $this->testUserId,
                $this->testWebsiteId,
                'ضيف Opt-Out',
                '201000000099',
                date('Y-m-d H:i:s'),
                'whatsapp'
            );
            $requestId = (int) $request->getAttribute('id');
            $this->createdRequestIds[] = $requestId;

            $this->service->optOut($requestId, 'test_reason');

            try {
                $newRequest = $this->service->createRequest(
                    $this->testUserId,
                    $this->testWebsiteId,
                    'ضيف Opt-Out',
                    '201000000099',
                    date('Y-m-d H:i:s'),
                    'whatsapp'
                );
                $this->createdRequestIds[] = (int) $newRequest->getAttribute('id');
                $this->fail('كان المفروض يرمي Exception لأن الضيف ده Opted-Out');
            } catch (Exception $e) {
                $this->pass('تم منع الإنشاء بعد Opt-Out صح: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            $this->fail('فشل تجهيز اختبار Opt-Out: ' . $e->getMessage());
        }
    }

    /** التعديل مسموح بس للطلبات scheduled */
    private function testUpdateOnlyAllowedWhenScheduled(): void
    {
        $this->startTest('التعديل مسموح فقط لطلب scheduled');

        try {
            $request = $this->service->createRequest(
                $this->testUserId,
                $this->testWebsiteId,
                'ضيف تعديل',
                '201000000055',
                date('Y-m-d H:i:s'),
                'whatsapp'
            );
            $requestId = (int) $request->getAttribute('id');
            $this->createdRequestIds[] = $requestId;

            $updated = $this->service->updateRequest($requestId, $this->testWebsiteId, ['guest_name' => 'اسم معدّل']);
            if ($updated->getAttribute('guest_name') === 'اسم معدّل') {
                $this->pass('تم تعديل اسم الضيف بنجاح لطلب scheduled');
            } else {
                $this->fail('الاسم لم يتحدث بعد التعديل');
            }

            // نغيّر الحالة يدويًا لـ sent ونتأكد إن التعديل يترفض بعد كده
            $updated->setAttribute('status', 'sent');
            $updated->save();

            try {
                $this->service->updateRequest($requestId, $this->testWebsiteId, ['guest_name' => 'اسم تاني']);
                $this->fail('كان المفروض يرمي Exception لأن الطلب بقى sent');
            } catch (Exception $e) {
                $this->pass('تم رفض تعديل طلب sent صح: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            $this->fail('فشل اختبار التعديل: ' . $e->getMessage());
        }
    }

    /** Retry ممنوع بعد الوصول للحد الأقصى من المحاولات */
    private function testRetryCap(): void
    {
        $this->startTest('منع Retry بعد الوصول للحد الأقصى من المحاولات');

        try {
            $request = $this->service->createRequest(
                $this->testUserId,
                $this->testWebsiteId,
                'ضيف Retry',
                '201000000077',
                date('Y-m-d H:i:s'),
                'whatsapp'
            );
            $requestId = (int) $request->getAttribute('id');
            $this->createdRequestIds[] = $requestId;

            // نحاكي فشل الإرسال 3 مرات (نفس MAX_SEND_ATTEMPTS في الخدمة)
            $request->setAttribute('status', 'failed');
            $request->setAttribute('attempts', 3);
            $request->save();

            try {
                $this->service->retryRequest($requestId, $this->testWebsiteId);
                $this->fail('كان المفروض يرمي Exception لأن attempts وصلت للحد الأقصى');
            } catch (Exception $e) {
                $this->pass('تم منع Retry بعد الحد الأقصى صح: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            $this->fail('فشل تجهيز اختبار Retry: ' . $e->getMessage());
        }
    }

    /** التأكد إن مستخدم تاني مايقدرش يشوف/يعدّل طلب موقع مش بتاعه */
    private function testTenantIsolation(): void
    {
        $this->startTest('Multi-Tenant Isolation - موقع تاني ميشوفش طلبات الأول');

        try {
            $otherUserId = $this->createTestUser();
            $otherWebsiteId = $this->db->query(
                "INSERT INTO websites (user_id, main_url, company_name, industry, is_verified)
                 VALUES (?, ?, 'Other Website', 'tourism', 1)",
                [$otherUserId, 'https://other-rr-test-' . uniqid() . '.com']
            );

            $request = $this->service->createRequest(
                $this->testUserId,
                $this->testWebsiteId,
                'ضيف Tenant',
                '201000000088',
                date('Y-m-d H:i:s'),
                'whatsapp'
            );
            $requestId = (int) $request->getAttribute('id');
            $this->createdRequestIds[] = $requestId;

            $leaked = $this->service->getRequest($requestId, $otherWebsiteId);
            if ($leaked === null) {
                $this->pass('getRequest() رجّع null صح لما اتحاول موقع تاني يشوف الطلب');
            } else {
                $this->fail('تسريب بيانات! موقع تاني قدر يشوف طلب مش بتاعه');
            }

            $this->db->query("DELETE FROM websites WHERE id = ?", [$otherWebsiteId]);
            $this->db->query("DELETE FROM users WHERE id = ?", [$otherUserId]);
        } catch (Exception $e) {
            $this->fail('فشل اختبار Tenant Isolation: ' . $e->getMessage());
        }
    }

    /** أول استدعاء لازم يزرع 4 قوالب افتراضية، والحذف/الإضافة لازم يشتغلوا */
    private function testTemplatesSeedAndCrud(): void
    {
        $this->startTest('زرع القوالب الافتراضية + CRUD');

        try {
            $templates = $this->service->getTemplates($this->testWebsiteId);
            if (count($templates) === 4) {
                $this->pass('تم زرع 4 قوالب افتراضية صح');
            } else {
                $this->fail('عدد القوالب الافتراضية غير متوقع: ' . count($templates));
            }

            $custom = $this->service->createTemplate($this->testWebsiteId, 'قالب مخصص', 'أهلاً {name}: {review_link}');
            $afterCreate = $this->service->getTemplates($this->testWebsiteId);
            if (count($afterCreate) === 5) {
                $this->pass('تم إضافة قالب مخصص صح');
            } else {
                $this->fail('عدد القوالب بعد الإضافة غير متوقع: ' . count($afterCreate));
            }

            $this->service->deleteTemplate((int) $custom->getAttribute('id'), $this->testWebsiteId);
            $afterDelete = $this->service->getTemplates($this->testWebsiteId);
            if (count($afterDelete) === 4) {
                $this->pass('تم حذف القالب المخصص صح');
            } else {
                $this->fail('عدد القوالب بعد الحذف غير متوقع: ' . count($afterDelete));
            }
        } catch (Exception $e) {
            $this->fail('فشل اختبار القوالب: ' . $e->getMessage());
        }
    }

    /** مفيش بيانات "reviewed" كافية لموقع الاختبار - لازم يرجع not_enough_data */
    private function testSmartTimingNotEnoughData(): void
    {
        $this->startTest('Smart Timing يرجع not_enough_data لما العينة صغيرة');

        $timing = $this->service->getSmartTimingSuggestion($this->testWebsiteId);
        if (!empty($timing['not_enough_data'])) {
            $this->pass('رجع not_enough_data صح (مفيش طلبات reviewed كفاية)');
        } else {
            $this->fail('كان المفروض يرجع not_enough_data لأن مفيش بيانات كافية');
        }
    }

    /** الأنالتكس لازم يرجع not_enough_data لو العينة أقل من الحد الأدنى */
    private function testAnalyticsNotEnoughData(): void
    {
        $this->startTest('Analytics يرجع not_enough_data لعينة صغيرة');

        $analytics = $this->service->getAnalytics($this->testWebsiteId);
        if (isset($analytics['not_enough_data'])) {
            $this->pass('مفتاح not_enough_data موجود في نتيجة getAnalytics()');
        } else {
            $this->fail('مفتاح not_enough_data غير موجود في نتيجة getAnalytics()');
        }
    }

    private function cleanup(): void
    {
        foreach ($this->createdRequestIds as $id) {
            $this->db->query("DELETE FROM review_requests WHERE id = ?", [$id]);
        }
        $this->db->query("DELETE FROM review_request_opt_outs WHERE website_id = ?", [$this->testWebsiteId]);
        $this->db->query("DELETE FROM review_request_templates WHERE website_id = ?", [$this->testWebsiteId]);
        $this->db->query("DELETE FROM review_request_settings WHERE website_id = ?", [$this->testWebsiteId]);
        $this->db->query("DELETE FROM platform_connections WHERE website_id = ?", [$this->testWebsiteId]);
        $this->db->query("DELETE FROM websites WHERE id = ?", [$this->testWebsiteId]);
        $this->db->query("DELETE FROM users WHERE id = ?", [$this->testUserId]);
    }

    private function startTest(string $name): void
    {
        echo "\n  ▶ {$name}\n";
    }

    private function pass(string $message): void
    {
        echo "    ✅ {$message}\n";
        $this->passed++;
        $this->testResults[] = ['status' => 'PASS', 'message' => $message];
    }

    private function fail(string $message): void
    {
        echo "    ❌ {$message}\n";
        $this->failed++;
        $this->testResults[] = ['status' => 'FAIL', 'message' => $message];
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;

        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 Review Request Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

// ============================================
// تشغيل الاختبارات
// ============================================
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $test = new ReviewRequestTest();
    $test->runAll();
}
