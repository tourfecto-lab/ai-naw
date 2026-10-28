# SECURITY AUDIT — Cross-Site Scripting (XSS) في الـ Controllers

**التاريخ:** 2026-08-28
**النطاق:** جميع الملفات في `app/Controllers/` (90 ملف)
**نوع الفحص:** Stored / Reflected XSS — طباعة (echo/print/heredoc) مدخلات مستخدم
أو بيانات قاعدة بيانات في HTML بدون `htmlspecialchars` / escaping مناسب.
**النتيجة:** **10 ثغرات مؤكدة** (6 منها XSS في سياق inline script، 1 Reflected،
1 Reflected عبر Host header، 1 Stored في `<title>`، 1 Stored عبر حقن SEO Server-Side)
— كلها تم إصلاحها.

---

## الملخص

| # | الملف | السطر | النوع | الحالة |
|---|-------|-------|-------|--------|
| 1 | `app/Controllers/WebsiteController.php` | 244/300 | XSS في inline script | ✅ أُصلحت |
| 2 | `app/Controllers/ReputationController.php` | 1370/1410 | XSS في inline script | ✅ أُصلحت |
| 3 | `app/Controllers/SearchConsoleController.php` | 156/196 | XSS في inline script | ✅ أُصلحت |
| 4 | `app/Controllers/GoogleAnalyticsController.php` | 153/193 | XSS في inline script | ✅ أُصلحت |
| 5 | `app/Controllers/ReputationController.php` | 1283/1308 | Reflected XSS | ✅ أُصلحت |
| 6 | `app/Controllers/HomeController.php` | 73 | XSS في attribute context (href) | ✅ أُصلحت |
| 7 | `app/Controllers/WebsiteBuilderController.php` | 799/757 | Stored XSS في `<title>` | ✅ أُصلحت |
| 8 | `app/Services/Seo/SeoProxyService.php` | 204-220 | Stored XSS (كسر `</script>` في JSON-LD/OG) | ✅ أُصلحت |
| 9 | `app/Controllers/SeoProxyController.php` | 60/121 | Stored XSS (تمرير body فيه `injected_code`) | ✅ أُصلحت (على مستوى الخدمة) |
| 10 | `app/Controllers/AutoSeoController.php` | 34/182 | XSS عبر Host header | ✅ أُصلحت |

---

## التفاصيل الكاملة

### 1) `app/Controllers/WebsiteController.php` — سطر 244 / 300 — XSS في inline script
- **الخطر:** `json_encode($sites, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`
  يُحقن عبر `str_replace('__SITES_JSON__', ...)` داخل `<script>` (قائمة المواقع في
  `/websites`). `main_url` / `company_name` بيانات DB قد تحتوي `</script><script>...`
  فتكسر الـ script tag وتنفذ JS عشوائي (ثغرة Stored XSS في سياق script).
- **الإصلاح:** إضافة `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`
  لتهريب `<`, `>`, `&`, `'`, `"` فلا يمكن كسر وسوم الـ script.

### 2) `app/Controllers/ReputationController.php` — سطر 1370 / 1410 — XSS في inline script
- **الخطر:** نفس النمط: `json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`
  (أسماء/عناوين فروع Google Business من API) يُحقن في `<script>` عبر
  `__OPTIONS_JSON__` في شاشة اختيار الفرع (`/reputation/connect/google`).
- **الإصلاح:** نفس إضافة `JSON_HEX_*`.

### 3) `app/Controllers/SearchConsoleController.php` — سطر 156 / 196 — XSS في inline script
- **الخطر:** `json_encode($sitesResult['sites'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`
  (روابط مواقع Search Console من API) يُحقن في `<script>` عبر `__OPTIONS_JSON__`
  في شاشة اختيار الموقع.
- **الإصلاح:** نفس إضافة `JSON_HEX_*`.

### 4) `app/Controllers/GoogleAnalyticsController.php` — سطر 153 / 193 — XSS في inline script
- **الخطر:** `json_encode($result['properties'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`
  (أسماء خصائص GA4 من API) يُحقن في `<script>` عبر `__OPTIONS_JSON__`.
- **الإصلاح:** نفس إضافة `JSON_HEX_*`.

### 5) `app/Controllers/ReputationController.php` — سطر 1283 / 1308 — Reflected XSS
- **الخطر:** `$error = $this->get('error')` (من `?error=` في الـ URL) يُضاف نصًا
  إلى `renderOAuthError()` (سطر 1528) ويُطبع في `$body` بدون escaping — Reflected XSS.
  كذلك `$tokenResult['error']` (سطر 1308) من استجابة OAuth.
- **الإصلاح:** تهريب القيمتين بـ `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` عند
  دمجها في رسالة الخطأ (مع الحفاظ على الرسائل الثابتة كما هي — بما فيها
  `<br><br>` المقصودة في رسائل أخرى).

### 6) `app/Controllers/HomeController.php` — سطر 73 — XSS في attribute context
- **الخطر:** `$l['url']` من `language_switcher_links()` مبني من
  `$_SERVER['REQUEST_URI']` (سطر 138 في `app/Helpers/i18n.php`) ويُدرج في `href`
  بدون تهريب — مسار يعكسه الزائر من الطلب نفسه (Reflected في سياق attribute).
- **الإصلاح:** تهريب `$l['url']` بـ `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
  عند بناء `<a href>` في الصفحة الرئيسية.

### 7) `app/Controllers/WebsiteBuilderController.php` — سطر 799 / 757 — Stored XSS في `<title>`
- **الخطر:** `seo_title` عمود DB قابل للتحرير من العميل (يُحفظ خام في
  `SiteDashboardController::updateSeo`) يُمرَّر إلى `siteHeadHtml()` ويُطبع في
  `<title>{$title}</title>` (سطر 757) بدون تهريب — كسر `</title>` ممكن (Stored XSS
  على صفحات الموقع المنشورة /sites/{slug}).
- **الإصلاح:** تهريب `seo_title` / `seo_description` عند قراءتهما من DB
  (سطر 799-800) بـ `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` — الباقي (OG tags)
  يهربها `siteHeadHtml` أصلًا عبر `$esc`.

### 8) `app/Services/Seo/SeoProxyService.php` — سطر 204-220 — Stored XSS (كسر `</script>` في JSON-LD/OG)
- **الخطر:** في `applyRewrite()`، إصلاحات `json_ld` / `faq_schema` / `speakable`
  (سطر 204-212) تُدرج `$json` داخل `<script type="application/ld+json">` —
  `normalizeJsonLd()` تستخدم `json_encode(..., JSON_UNESCAPED_SLASHES)` فلا تتهرب
  `<`/`>` فيكسر أي `</script>` داخل قيمة JSON-LD الـ script tag. وإصلاح `og_tags`
  (سطر 220) يحقن `injected_code` خامًا (قد يحتوي `<script>`) في `<head>`.
  القيم محفوظة في `auto_seo_applied_fixes` من بيانات/مدخلات قابلة للتأثير.
- **الإصلاح:** إضافة `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`
  في `normalizeJsonLd()` + تهريب الـ fallback الخام، وفلترة `og_tags` بحيث تُسمح
  بوسوم `meta` / `link` فقط (باستبعاد `<script`/`<iframe`/`<object`/`<embed`/أي
  معالج أحداث `on*=`).

### 9) `app/Controllers/SeoProxyController.php` — سطر 60 / 121 — Stored XSS (تمرير body)
- **الخطر:** `echo $result['body']` (الوضعان: embed token و CNAME) يمرر HTML
  أعيدت كتابته Server-Side — الخطر الجوهري مصدره `injected_code` المحفوظ الذي
  يدخل body عبر `SeoProxyService::applyRewrite()` (أعلاه). `body` نفسه HTML كامل
  مقصود (مثل Cloudflare HTML Rewriter) لا يمكن تهريبه ككل.
- **الإصلاح:** معالجة جذر الخطر في `SeoProxyService` (البند 8) — تهريب
  JSON-LD + فلترة og_tags؛ بذلك أي `injected_code` خبيث لا يصل HTMLًا قابلًا للكسر
  (ملفات aux النصية مثل robots.txt تُرسل بـ `text/plain` فليست سياق HTML).

### 10) `app/Controllers/AutoSeoController.php` — سطر 34 / 182 — XSS عبر Host header
- **الخطر:** `$proxyHost = $_SERVER['HTTP_HOST']` (خاضع لتحكم المُرسِل في
  الـ Host header) يُدرج خامًا في `<span id="aseoCnameTarget">{$proxyHost}</span>`
  بشاشة إعدادات Auto SEO — كسر `</span>` + حقن script ممكن (Reflected في
  لوحة التحكم عبر header injection).
- **الإصلاح:** تهريب القيمة في `$proxyHostEsc` بـ `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
  قبل حقنها في الـ heredoc (بدون تغيير مصدر القيمة نفسها).

---

## المنهجية
- فحص كل ملف في `app/Controllers/` (90 ملف) يدويًا + بمساعدة أدوات بحث
  (`grep`/`rg`) لأنماط: `echo`/`print`، heredoc، `json_encode` مع
  `JSON_UNESCAPED_*` داخل `<script>`، إدراج `$_SERVER['REQUEST_URI']` / `$_GET`
  في HTML، وقيم DB تُمرَّر لـ `<title>`/attributes.
- التركيز على مسارات HTML (كل الصفحات المبنية عبر `renderPanelPage` /
  `pageShell` / `renderHotelHome` / `renderToursHome` / proxy).
- لم تُعتبر API خالصة تُرجع JSON عبر `json_encode` (بدون سياق HTML) ثغرة.
- الخطوط التي تُهرب القيمة نفسها أصلًا (`htmlspecialchars`/`safeAttr`/`esc()`
  في الـ JS) لم تُسجَّل.

## الإصلاحات (ملخص)
- **نمط inline script JSON:** إضافة `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`
  في 4 مواضع (WebsiteController, ReputationController, SearchConsoleController, GoogleAnalyticsController).
- **Reflected:** تهريب `?error=` و`$tokenResult['error']` و`REQUEST_URI` في href.
- **Stored `<title>`:** تهريب `seo_title`/`seo_description` عند القراءة من DB.
- **SEO proxy:** تهريب JSON-LD + فلترة og_tags على مستوى `SeoProxyService`.
