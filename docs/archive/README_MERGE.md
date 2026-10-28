# طريقة الدمج - نسخة مُتحقق منها ضد موقعك الفعلي

⚠️ **مهم**: النسخة دي من الملفات مبنية بعد ما راجعت ملف الموقع الكامل
اللي بعتهولي فعليًا (`tourfecto_pro__3_.zip`)، ومتأكد إنها **مش هتمسح**
أي ميزة تانية عندك اتضافت باستقلالية (زي `google_maps_api_key`، مسارات
CRM، Competitor Intelligence، إلخ) - كل ده اتفحص يدويًا وموجود.

## لو رفعت الملفات دي بعد `tourfecto_pro__3_.zip` بتاريخ لاحق
لو عدّلت أي حاجة في السيرفر بعد الملف اللي بعتهولي، خصوصًا في:
- `app/Controllers/AdminController.php`
- `app/routes/api.php` أو `app/routes/web.php`
- `public_html/index.php`
- `app/Services/System/SystemSettingsService.php`

**قولّي الأول قبل ما تستبدل** - الملفات دي بترجع تكبر مع الوقت (فيها
ميزات تانية غيري)، والاستبدال المباشر ممكن يمسح أي حاجة جديدة زودتها.
باقي الملفات (خاصة بقسم الإعلانات بس) آمنة للاستبدال المباشر دايمًا.

## ملفات جديدة بالكامل → انسخها زي ما هي
- `app/Services/Ads/GoogleAdsAPI.php`
- `database/migrations/2026_08_08_000041_add_google_ads_platform_support.sql`
- `database/migrations/2026_08_09_000042_add_campaign_publishing_support.sql`
- `database/migrations/2026_08_10_000043_add_campaign_management_columns.sql`
- `docs/merge-changelogs/BATCH7_ads_professional_upgrade_meta_google.md`

## ملفات آمنة للاستبدال الكامل دايمًا (خاصة بقسم الإعلانات بس، محدش
## غيري بيعدّل عليها عادة)
- `app/Controllers/AdsController.php`
- `app/Services/Ads/MetaAdsAPI.php`
- `app/Services/Ads/AdCopyGenerationService.php`
- `app/Services/Ads/AdCampaignService.php`
- `app/Services/OAuth/GoogleOAuthClient.php`
- `app/Services/OAuth/MetaOAuthClient.php`
- `app/Models/AdCampaign.php`
- `public_html/assets/css/panel.css`

## ملفات فيها ميزات تانية معايا - النسخة دي فيها التعديل مدموج بالفعل
## مع النسخة اللي بعتهالي، لكن راجع الملاحظة فوق لو عدّلت حاجة بعد كده
- `app/Controllers/AdminController.php`
- `app/Services/System/SystemSettingsService.php`
- `app/routes/api.php`
- `app/routes/web.php`
- `public_html/index.php`
- `vendor/composer/autoload_classmap.php`
- `.env.example`

## خطوات بعد النسخ
1. شغّل الـ 3 ملفات SQL في `database/migrations/` بالترتيب (000041 →
   000042 → 000043) على قاعدة البيانات.
2. أضف متغيرات البيئة الجديدة (قسم Google Ads في `.env.example`) لملف
   `.env` الحقيقي بتاعك.
3. اطلب Google Ads Developer Token وأدخله من صفحة إعدادات الأدمن
   (الحقل الجديد "🎯 Google Ads" في `/admin` > الإعدادات).

تفاصيل كل تعديل بالظبط موجودة في `docs/merge-changelogs/BATCH7_ads_professional_upgrade_meta_google.md`.
