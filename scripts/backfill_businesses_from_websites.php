<?php

/**
 * Tourfecto - Backfill: Business records from existing Websites
 * Phase 2 (Business Control Center) - سكريبت مرة واحدة، Idempotent بالكامل
 * @version 1.0.0
 *
 * ليه سكريبت PHP مش SQL خام؟
 * لأن الأعمدة الحقيقية لجدول `websites` مؤكد إنها مختلفة عن
 * database/schema.sql (موثّق في تعليق داخل app/Models/Website.php،
 * تحقق فعلي من phpMyAdmin بتاريخ 2026-07-13): company_name -> brand_name،
 * industry -> industry_niche، target_country -> target_countries،
 * وأعمدة competitor_1/2/3_url وlast_analysis_at مش موجودة خالص في
 * الجدول الحقيقي. أي SQL خام هنا كان هيقرأ/يكتب أعمدة غلط بصمت أو يفشل.
 * الـWebsite Model عنده نظام Column Alias Auto-Detection جاهز وشغال -
 * استخدامه هنا مش اختياري، هو الطريقة الوحيدة الصحيحة للتعامل مع
 * الجدول ده حاليًا.
 *
 * الاستخدام (من سطر الأوامر على السيرفر، مش من المتصفح):
 *   php scripts/backfill_businesses_from_websites.php
 *
 * آمن للتشغيل أكتر من مرة: أي website معاه business_id بالفعل يتجاهله.
 */

require_once dirname(__DIR__) . '/cron/bootstrap.php';

// Business class جديد مش مسجّل في classmap القديم بتاع composer (نفس
// القيد الموثّق في BUSINESS_CONTROL_CENTER_CHANGELOG.md). الـWebsite Model
// قديم ومتسجّل، لكن الـBusiness لازم يتحمّل يدويًا هنا وإلا هتقع
// "Class Business not found" على أول website في اللوب.
if (!class_exists('Business', false)) {
    require_once APP_PATH . '/Models/Business.php';
}

echo "=== Tourfecto: Backfill Businesses from Websites ===" . PHP_EOL;

$websiteModel = new Website();
$allWebsites = $websiteModel->all(['id' => 'ASC']);

$created = 0;
$skipped = 0;
$failed = 0;

foreach ($allWebsites as $website) {
    $websiteId = (int) $website->getAttribute('id');

    if (!empty($website->getAttribute('business_id'))) {
        $skipped++;
        continue; // Idempotent: business_id موجود بالفعل، متجاهلش
    }

    $ownerUserId = (int) $website->getAttribute('user_id');
    if ($ownerUserId <= 0) {
        echo "  [تخطي] Website #{$websiteId} - مفيش user_id صالح." . PHP_EOL;
        $failed++;
        continue;
    }

    try {
        $business = new Business();
        // كل getAttribute() هنا بيمر عبر columnAliases الخاصة بـWebsite
        // تلقائيًا - القيمة اللي بترجع هي القيمة الحقيقية مهما كان اسم
        // العمود الفعلي في الداتابيز.
        $business->setAttribute('owner_user_id', $ownerUserId);
        $business->setAttribute('trade_name', $website->getAttribute('company_name'));
        $business->setAttribute('website_url', $website->getAttribute('main_url'));

        // industry_niche/target_countries عمومًا نصوص حرة حاليًا (مش ISO
        // مضبوطة) - بنخزنها كما هي هنا كنقطة بداية، مش بنفترض إنها متوافقة
        // فعليًا مع القيم المسموحة الجديدة (business_type ISO-like list).
        // تنضيف/تحويل هذه القيم مهمة منفصلة لاحقًا، مش جزء من الـBackfill.
        $rawIndustry = $website->getAttribute('industry');
        if (!empty($rawIndustry)) {
            $business->setAttribute('description', 'Industry (legacy, unmapped): ' . $rawIndustry);
        }

        if ($business->save() === false) {
            echo "  [فشل] Website #{$websiteId} - تعذر إنشاء Business." . PHP_EOL;
            $failed++;
            continue;
        }

        $newBusinessId = (int) $business->getAttribute('id');
        $website->setAttribute('business_id', $newBusinessId);
        if ($website->save() === false) {
            echo "  [فشل جزئي] Website #{$websiteId} - اتعمل Business #{$newBusinessId} لكن الربط فشل." . PHP_EOL;
            $failed++;
            continue;
        }

        $created++;
        echo "  [تم] Website #{$websiteId} -> Business #{$newBusinessId}" . PHP_EOL;
    } catch (\Throwable $e) {
        echo "  [استثناء] Website #{$websiteId}: " . $e->getMessage() . PHP_EOL;
        $failed++;
    }
}

echo PHP_EOL . "=== النتيجة ===" . PHP_EOL;
echo "تم إنشاؤها: {$created}" . PHP_EOL;
echo "تم تخطيها (موجودة بالفعل): {$skipped}" . PHP_EOL;
echo "فشلت: {$failed}" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
