# Native booking schema readiness compatibility

All five required tables were present in the customer's database screenshot, but the deployed overview still returned `platform_bookings.available=false`. That screenshot abbreviates column lists and cannot establish whether every required column exists. The service's information_schema parser also had a reproducible portability bug: it indexed only lowercase result labels. MySQL/PDO metadata using `TABLE_NAME`/`COLUMN_NAME` could therefore mark a complete schema unavailable. The original implementation fails the new uppercase-metadata regression test.

The metadata query now uses explicit aliases and normalizes result keys before checking required columns. Both lowercase/uppercase aliases and legacy metadata labels work. Actual missing requirements still disable unsupported queries. The already owner-guarded overview additionally returns `missing_requirements` containing only static `table.column` names, so support can diagnose genuine missing fields through the existing read-only API without asking customers for database screenshots. No credentials, database identity, other-account records or financial values are added to that diagnostic response. The normal UI remains unchanged.

## Hosting update / خطوات الرفع

**على استضافة tourfecto.pro فقط:** استبدل الملف التالي بنفس مساره من الحزمة، ثم أعد تحميل PHP/OPcache وحدّث المتصفح:

`app/Services/SeoImpact/SeoPlatformBookingService.php`

**لا تحتاج تشغيل SQL أو تغيير إضافة العميل لهذا التصحيح.** جدول الربط الموجود يُترك كما هو. ملفا الاختبار والتوثيق ونتيجة الفحص للمراجعة، ولا يلزم رفعهما للتشغيل. هذه الحزمة تصحيح فوق تحديث `4496d40` وتفترض رفع ملفاته السابقة بالفعل.

After upload, use the intended customer's SEO Expert → Results page. If all required columns exist, native measurement becomes available and the product selector is accessible. If it remains unavailable, inspect the owner-authenticated `/api/seo-impact/sites/{website_id}` response's `platform_bookings.missing_requirements` before changing any schema. No SQL auto-repair or schema write is performed by the service.

The last live read showed no internal CRM products for this customer. That is a separate setup condition: the available selector may correctly be empty. Do not invent products, assign another account's products or claim bookings/SEO-attributed revenue from an empty catalog. No production repair is claimed until the PHP correction has been uploaded and checked live.

## Validation

`seo_platform_bookings_readiness_test.php` runs without SQLite or a real database using a mock Database: 9 assertions for both label cases, aliases, valid unconfigured state, genuine missing columns, exact diagnostics, empty schema, ownership ordering and read-only SQL. Existing native payment and browser suites and the required SEO/billing suites are also rerun; complete output is included in `platform-bookings-readiness-test-output.txt`.

Full rerun: **68 groups, 66 passed**. Existing baseline failures remain plans_studio (40/47) and plan_gate (19/24). Required phases 1–6, connector, xpay and fx_bank pass. PHP lint: **1,289 files**, no syntax errors.
