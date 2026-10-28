# Add and import native products from SEO Results

An available native booking measurement panel previously led to an empty selector with no catalog-entry journey. Customers can now open **SEO Expert → Results → Platform booking sales → Add a product or import from my site** without leaving Results. Manual entry and public-page import both lead to an editable review form. Saving requires name, a nonnegative decimal price, a three-letter currency code and explicit confirmation of the facts and selected website. No price, currency, availability or inclusions are invented. Editing facts clears the previous confirmation.

## What import does

The preview fetches a single product/tour page on the selected customer's domain using SafeHttp, public-IP validation/pinning, HTTPS verification, a 12-second timeout, a 750 KB limit and **zero followed redirects**. Customers should use the page's final public HTTPS URL. No cookies, integration credentials, paid provider or AI calls are involved. Other account/site URLs are rejected before fetching. Genuine query-based routes are supported; common tracking tags are removed from the product identity.

Product/Service/TouristTrip JSON-LD supplies candidate facts. An explicit single Offer supplies price/currency when present. Multiple products require choosing the candidate to review; multiple offers, AggregateOffer ranges, missing/invalid prices and missing currencies stay blank for customer confirmation. Without product schema, the page H1/title and meta description can seed the editable name/description. Text such as “from $99” is not parsed as a confirmed price. HTML/scripts remain untrusted and never execute in the review UI. Preview drafts exist in the browser only and are not saved automatically.

Use a **separate product page for each import**. One source URL has one imported catalog identity per selected website. This is an individual product import, not a full-site catalog crawler. A listing with multiple products can supply a candidate draft, but creating several imports from that same listing URL will return its first saved product. To add another product without a dedicated page, use manual entry.

## What save changes

The existing CRM catalog receives a product and the existing `seo_platform_booking_products` table receives an additive measurement mapping in the same transaction. Prior selections are retained. Builder website foreign keys and tour slugs are left unchanged. Existing catalog CRUD remains available; no existing product is overwritten by this journey.

An internal SKU identifies manual-save retries and repeated source imports; do not edit this reserved `tfseo-...` SKU if retry deduplication must be preserved. The customer websites are locked in a consistent order, as in the existing measurement selection service. A rejected mapping rolls back the product insertion. Products already assigned to another measurement website cannot silently move. The existing 500-product mapping limit is respected.

Repeated saves after a lost response return the same product. Reimporting the same source URL, including its allowed www alias or tracking-tag variants, returns the existing saved facts rather than overwriting name/price/currency. A distinct manual-entry form represents a distinct product. Product creation uses the authenticated account, checks website ownership and the existing CRM `create` permission, and is protected by the existing CSRF and write-rate guards. No caller-supplied owner/site fields override the authenticated route context.

These actions **do not publish to the customer website, create a booking, charge a wallet, move money, issue a refund or import historical/external payment records**. The native sales panel continues to read only real existing platform payment/refund records. Empty catalogs or absent eligible payment records are not evidence that SEO caused zero sales. Product import is separate from integrating a custom site's booking/payment system.

## Hosting update / الرفع على الاستضافة

**على استضافة tourfecto.pro فقط:** خذ نسخة احتياطية ثم استبدل الملفات التالية بنفس المسارات من الحزمة:

- `app/Config/project_classmap.php`
- `app/Controllers/SeoImpactController.php`
- `app/Services/SeoImpact/SeoPlatformProductService.php` — جديد
- `app/routes/api.php`
- `public_html/assets/js/seo-impact.js`

**لا يوجد SQL جديد أو تغيير في ملف ربط العميل أو إضافة WordPress.** التحديث يفترض تثبيت تحديث الحجوزات `4496d40` وتصحيح الجاهزية `181f836` وجدول الربط الموجود. ارفع محتويات `public_html` إلى المسار المطابق عندك، دون إنشاء مجلد متداخل إضافي. أعد تحميل PHP/OPcache وأي worker دائم وحدّث كاش المتصفح. ملفات الاختبارات والتوثيق ونتائج الفحص داخل الحزمة للمراجعة ولا يلزم رفعها للتشغيل.

بعد الرفع: افتح حساب العميل الصحيح واختر موقعه، ثم النتائج ← حجوزات نظام المنصة ← **إضافة رحلة أو استيراد من موقعي**. أدخل رابط صفحة رحلة حقيقية واضغط **قراءة الصفحة للمراجعة**، أو اختر **إضافة يدويًا**. راجع الاسم والسعر والعملة والوصف، وأكّد الموقع ثم احفظ. الرحلة تُضاف وتُربط بالموقع تلقائيًا. راجع رسالة الحفظ التي تعرض البيانات المحفوظة؛ عند إعادة استيراد رحلة موجودة تظهر بياناتها الأصلية ولا يتم تحديث سعرها خلسة.

## Validation and live status

New offline suites use actual parser/service SQL/controller/Panel code: 33 parsing/owner/transport assertions, 6 controller auth/CSRF/rate/permission assertions, 23 guarded MariaDB creation/mapping/idempotency/rollback/financial-preservation assertions, and 16 browser assertions including retries, preserved selections, escaped imports, consent, stale responses after a site switch away and back, and Arabic mobile layout. Fixture schema cleanup was corrected to remove the test catalog's users foreign key before subsequent database suites reset users. No production financial records or customer products were created during development.

Full regression output is included in `docs/seo/platform-product-journey-test-output.txt`. The last live account check before this feature's deployment showed the readiness fix working, an empty catalog and no completed impact comparison. This new entry/import journey requires upload and a fresh live check before claiming that it works on the customer's production account.

Full regression run: **72 groups, 70 passed**. Required phases 1–6, connector, xpay and fx_bank pass. The same unrelated baseline failures remain plans_studio (40/47) and plan_gate (19/24). PHP lint: **1,293 files**, no syntax errors. The 16-assertion browser suite passed again after the small Arabic input-direction/source-wrapping adjustment; its repeated output is also attached.
