# دمج ميزات 21 أكتوبر مع تحديث 22 أكتوبر

تحديث `Tourfecto_Update_2026-10-22` اتعمل على نسخة GitHub القديمة، فلما اترفع كتب فوق شغل 21 أكتوبر. الملف ده بيرجّع الشغل ده، ومن غير ما يشيل أي حاجة من تحديث 22.

## اللي بيرجع
- باقات السوشيال والاشتراك فيها، وصندوق التعليقات، والمنافسين، والنمو، ومشاركة الخطة، ومراجعة المنشورات (34 مسار)
- الإعلانات: Search Terms وSearch Assets (6 مسارات)
- موسم ذكاء الإيرادات (5 مسارات)
- Meta: data-deletion وdeauthorize (مسارين)
- تعديلات البريد التسويقي، وGoogle Business، ومنشئ المواقع، اللي اتشالت من الكنترولرز
- الطيار الآلي للسوشيال (من تحديث 22) اتنقل لصفحة السوشيال الجديدة `app/Views/social/index.php`
- تصليح زرار مسودة llms.txt: الكود كان بيستخدم كلاس مش موجود (`LlmsSiteSummary`)

## الملفات (15)
app/routes/api.php، app/routes/web.php، cron/schedule.php، app/Config/project_classmap.php،
app/Controllers/{Ads,AiVisibility,EmailMarketing,GbpProfile,Marketing,SocialMedia,WebsiteBuilder}Controller.php،
app/Services/SocialMedia/{SocialMonthlyPlanService,TikTokAPI}.php، app/Services/WebsiteBuilderService.php،
app/Views/social/index.php

## الرفع
1. في aaPanel ← Files ← فولدر المنصة tourfecto.pro (اللي فيه app وpublic_html): اضغط على فولدر `app` بزرار الماوس اليمين، واختار Compress علشان تاخد نسخة احتياطية.
2. ارفع الـZIP واعمل Unzip، واختار استبدال الملفات.
3. اعمل Reload لـ PHP من App Store ← PHP 8.4 ← Reload، أو امسح OPcache.

## الاختبار
- كل الـ1622 مسار مربوطين بدوال موجودة فعلاً.
- كل الملفات اتفحصت بـ `php -l` ومفيهاش أخطاء.
- اختبارات المشروع نجحت: growth signals، وplatform registry، وsocial monthly plan، وwhatsapp qr، وstripe connect، وai insights.
- `/api/integrations/meta/page-messaging` متضافش، لأن الدالة بتاعته مش موجودة في أي نسخة.
