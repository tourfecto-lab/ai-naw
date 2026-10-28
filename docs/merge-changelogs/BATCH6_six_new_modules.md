# BATCH6 - الموديولات الستة الجديدة (revenue/optimizer/exec/ceo/competitor/content)
### 2026-07-15

## الترتيب اللي اتنفذ بيه (زي ما رشحته)

### 1) revenue-intelligence ✅ إضافة كاملة، صفر تكرار
أول مصدر بيانات إيرادات حقيقي في المنصة. جداول جديدة: `rev_revenue_records`،
`rev_marketing_spend`، `rev_kpi_snapshots`. صفحة `/revenue` بتسجيل إيراد
يدوي + KPIs حقيقية (ROAS، CAC) محسوبة من الإيرادات الفعلية + إنفاق
`ad_campaigns.spend` الموجود. اتجاهلنا جدولي `leads`/`customers` الأصليين
في الموديول لأن `crm_leads`/`crm_contacts` موجودين بالفعل بنفس الغرض.
**بطاقة "الإيرادات المباشرة" في لوحة القيادة التنفيذية اتحولت من "مش
متصلة" لرقم حقيقي.**

### 2) ai-website-optimizer ✅ إضافة كاملة، صفر تكرار
تدقيق تقني حقيقي (مش نتائج وهمية) - بيعمل طلب HTTP فعلي لموقع المستخدم
ويفحص: HTTPS، زمن الاستجابة، title tag، meta description، viewport
للموبايل، alt text للصور، وعينة من الروابط المكسورة. جداول: `wo_audits`،
`wo_audit_findings`، `wo_broken_links`، `wo_fixes` (الأخير معمول له
migration بس مفيش endpoint توليد تلقائي للحلول لسه - محتاج تكامل AI
لاحقًا). اتجاهلنا جدول `sites` الأصلي وربطنا التدقيق مباشرة بجدول
`websites` الموجود عندك. صفحة `/website-optimizer`.

### 3) executive-command-center ⚙️ ترقية مش تكرار
بدل ما نبني صفحة موازية لـ `/dashboard/executive` الموجودة، ضفنا
تخزين حقيقي (`cc_ai_alerts`, `cc_ai_tasks`) بدل التنبيهات المحسوبة
لحظيًا بس، مع أزرار "قرأت"/"تم الإنجاز". باقي جداول الموديول الأصلي
(`cc_metric_snapshots`, `cc_score_snapshots`, `cc_realtime_events`,
`cc_data_source_connections`, `cc_event_cursors`, `cc_dashboard_preferences`)
**اتأجلت عمدًا** - محتاجة بنية Server-Sent Events / webhooks حقيقية
(`StreamController` الأصلي) مش موجودة في المشروع دلوقتي، وربطها غلط
هيدّي واجهة "حية" وهمية.

### 4) ai-ceo-assistant ⚙️ تكامل جزئي (مش موديول منفصل)
بنيناه كإضافة داخل لوحة القيادة التنفيذية نفسها، مش صفحة جديدة، عشان
`executive_reports`/`recommendations`/`ai_queries`/`business_metrics`
الأصليين بيتقاطعوا مع `ai_insights_reports` ولوحة القيادة الموجودة.
اللي اتضاف فعليًا: `ceo_business_context_notes` (ملاحظات سياق بيزنس
يكتبها صاحب الحساب)، `ceo_risk_alerts`، `ceo_growth_opportunities` -
ظاهرين دلوقتي في كارت "فرص نمو ومخاطر (AI CEO)" جوه `/dashboard/executive`.
**ملحوظة:** الجداول موجودة والـ API شغال، لكن مفيش لسه "مولّد" آلي
بيملأها بالمخاطر/الفرص من تحليل حقيقي - محتاج نربطها بمنطق تحليلي
حقيقي (يقارن بيانات CRM/السمعة/الإعلانات) في دفعة قادمة، مش بس واجهة
فاضية.

### 5) competitor-monitoring ⚙️ دمج فوق الموجود، مش تكرار
اتبنى فوق جدول `competitors` الحقيقي (اتأكدت أعمدته من دفعة سابقة
بملف phpMyAdmin فعلي: `competitor_domain`, `competitor_name`, إلخ).
جداول جديدة بادئة `cm_`: `cm_pricing`, `cm_offers`, `cm_content_updates`
(معمول له migration بس مفيش UI لسه)، `cm_google_rankings` (migration
بس)، `cm_alerts`. صفحة `/competitor-monitoring` بتسجّل أسعار/عروض
يدويًا وبتولّد تنبيه تلقائي لو السعر اتغير ٥%+. **اتجاهلنا بالكامل**:
`competitors`/`competitor_websites` الأصليين (تكرار)، `google_ads`/
`meta_ads` (عندك `ad_campaigns`)، `users`/`tenants` (عندك `users`).

### 6) content-studio ❌ مُتجاهَل عمدًا - تكرار شبه كامل
راجعت الموديول لقيت: `content_items` = `social_posts` الموجود،
`content_images` = `media_items` الموجود، `video_plans` = `video_scripts`
الموجود في Creative Studio. الحاجة الوحيدة المفيدة فعليًا (تقويم
المحتوى) بنيتها كـ endpoint واحد (`GET /api/social/calendar`) فوق
البيانات الموجودة أصلًا (`social_posts` + `social_post_targets.scheduled_at`)
- من غير أي جدول جديد - وضفتها كارت في صفحة `/social` الموجودة.
مفيش صفحة `/content-studio` منفصلة، وده قرار متعمد مش نسيان.

## ملفات جديدة
- `app/Controllers/RevenueController.php`
- `app/Controllers/WebsiteOptimizerController.php`
- `app/Controllers/CompetitorMonitoringController.php`
- `app/Controllers/ExecutiveExtrasController.php`
- `database/migrations/2026_07_15_000014_create_revenue_intelligence_tables.sql`
- `database/migrations/2026_07_15_000015_create_website_optimizer_tables.sql`
- `database/migrations/2026_07_15_000016_create_competitor_monitoring_tables.sql`
- `database/migrations/2026_07_15_000017_create_ceo_assistant_and_cc_alerts_tables.sql`

## ملفات معدَّلة
- `app/Controllers/DashboardController.php` - بطاقة إيرادات حقيقية +
  كارت مخاطر/فرص + مهام مقترحة في `/dashboard/executive`
- `app/Controllers/SocialMediaController.php` - إضافة تقويم محتوى
- `app/Core/Controller.php` - مجموعة سايد بار جديدة "الذكاء التجاري"
- `app/routes/web.php` / `app/routes/api.php` - كل الـ routes الجديدة

## تحذير مهم قبل ما تشغّل الـ migrations
زي ما اتأكد النهارده مع جدولي `reviews` و`competitors`، **جداول
المشروع الحقيقية ممكن تختلف عن ملفات الـ migration**. كل الجداول
الجديدة في الدفعة دي بادئة بأسماء فريدة (`rev_`, `wo_`, `cm_`, `ceo_`,
`cc_`) عشان نتفادى أي تصادم، لكن لسه محتاج تشغّل الـ migrations
الجديدة فعليًا على السيرفر (مش موجودة في `_PENDING_TO_RUN_ON_SERVER.sql`
القديم - دي ملفات منفصلة جديدة) وتتأكد إنها اتنفذت صح قبل ما تجرب
الصفحات.

## لسه ناقص/مؤجل عمدًا (مش نسيان)
- `wo_fixes` وتوليد حلول تلقائي بالـ AI لمشاكل الموقع
- `cm_google_rankings`/`cm_content_updates` عندهم migration بس مفيش UI
- AI CEO risks/opportunities لسه بتتضاف يدوي بس، مفيش "مولّد" تحليلي آلي
- executive-command-center الـ realtime streaming (SSE) والـ webhooks
