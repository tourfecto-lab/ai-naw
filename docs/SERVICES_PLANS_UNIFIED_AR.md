# دمج الخدمات × الباقات × الواجهة

## المشكلة
كان فيه 3 مصادر منفصلة لنفس الخدمات ومفيش ربط بينهم:
- `ServiceCenterCatalog` — الـ36 خدمة جوه حساب العميل (`/service-center`)
- `MarketingContent::features()` — صفحات `/features/{slug}` العامة
- `features_json` للباقات — `BillingPlanCatalog` + عدّادات `PlanUsage`

## الحل: `app/Services/Platform/PlatformServiceRegistry.php`
السجل الوحيد اللي بيربط كل خدمة بـ: صفحتها التسويقية + استحقاقات الباقة اللي بتتحكم فيها.
منطق نقي من غير قاعدة بيانات؛ الأسعار والحدود لسه جاية من صفوف الفوترة بس.

| الحالة | المعنى |
|---|---|
| `core` | موجودة في كل الباقات |
| `included` | الباقة فيها حصة/صلاحية للخدمة |
| `pay_per_use` | متاحة، والاستخدام من المحفظة (حصة الباقة = 0) |
| `not_included` | محتاجة ترقية |

## اللي اتربط بيه
- **مركز الخدمات** (`/service-center`): كل خدمة بتعرض حالتها في باقة العميل، الاستخدام (مستخدم / الحد)، زر "Upgrade to …" لأقل باقة فيها الخدمة، وفلتر "Plan coverage".
- **صفحة الأسعار** (`/pricing#services-by-plan`): جدول "الخدمات في كل باقة".
- **صفحات المميزات** (`/features/{slug}`): قسم "متاحة في أنهي باقة؟".
- **API عام**: `GET /api/services/catalog` — الباقات + مصفوفة الخدمات × الباقات.

## إضافة خدمة جديدة
1. أضفها في `ServiceCenterCatalog`.
2. أضف سطرها في `PlatformServiceRegistry::MAP` (الصفحة التسويقية + مفاتيح الاستحقاق).
3. لو مفتاح ميزة جديد في `features_json`، أضفه في `ENTITLEMENTS`.
4. شغّل: `php tools/test_platform_service_registry.php` — بيفشل لو أي خدمة أو صفحة أو عدّاد مش مربوط.

## بعد الرفع
مفيش SQL جديد. الملفات الجديدة/المعدّلة:
- جديد: `app/Services/Platform/PlatformServiceRegistry.php`, `tools/test_platform_service_registry.php`
- معدّل: `ServiceCenterService.php`, `ServiceCenterController.php`, `ServicesController.php`, `MarketingController.php`, `routes/api.php`, `Config/project_classmap.php`, `assets/js/service-center.js`, `assets/css/service-center.css`
