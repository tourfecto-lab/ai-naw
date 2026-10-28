# دمج Auto SEO (التنفيذ التلقائي) في tourfecto-ai

باتش جاهز يحوّل خدمة تحسين محركات البحث من **تحليل فقط** إلى **تنفيذ تلقائي فعلي** على مواقع العملاء الخارجية.

> ⚠️ **مهم:** أنا لم أرفع أي شيء على مستودعك. الملفات دي محلية عندك، وانت اللي تعملها commit بنفسك بعد المراجعة.

---

## ليه الباتش ده؟

| | Auto-Pilot الحالي (Phase 13) | Auto SEO الجديد (Phase 21) |
|---|---|---|
| نطاق العمل | `generated_websites` فقط (مواقع الـBuilder) | أي موقع خارجي: WordPress / Shopify / HTML |
| طريقة التطبيق | `UPDATE` على أعمدة في الداتابيز | حقن حقيقي في `<head>` عبر `embed.js` |
| العميل محتاج يعدّل كوده؟ | لا ينطبق | لا — سطر `<script>` واحد بس |
| Rollback | موجود (`auto_pilot_change_log`) | موجود (`auto_seo_change_log`) بنفس المنطق |

المنطق الأساسي (`conservative` / `balanced` / `aggressive` + `old_value`/`new_value`/`trigger`) متاخد **من الكود بتاعك** في `2026_08_08_000049_auto_pilot.sql` عشان يفضل متسق مع باقي المشروع.

---

## الملفات

```
github-patch/
├── database/migrations/
│   └── 2026_08_20_000001_auto_seo_embed.sql   ← الجداول والأعمدة الجديدة
├── app/Services/AutoSeo/
│   └── AutoSeoEmbedService.php                 ← منطق الحقن والـRollback
├── app/Controllers/
│   └── AutoSeoController.php                   ← 7 endpoints
├── routes-snippet.php                          ← الأسطر اللي تضيفها في routes
└── README_MERGE_AUTOSEO.md                     ← الملف ده
```

---

## خطوات التركيب

### 1) انسخ الملفات في أماكنها

```bash
cd /path/to/tourfecto-ai

mkdir -p app/Services/AutoSeo
cp github-patch/app/Services/AutoSeo/AutoSeoEmbedService.php  app/Services/AutoSeo/
cp github-patch/app/Controllers/AutoSeoController.php          app/Controllers/
cp github-patch/database/migrations/2026_08_20_000001_auto_seo_embed.sql  database/migrations/
```

### 2) شغّل الميجريشن

```bash
mysql -u USER -p tourfecto_db < database/migrations/2026_08_20_000001_auto_seo_embed.sql
```

> لو `wo_fixes` مش موجود عندك بالاسم ده، احذف آخر بلوك `ALTER TABLE wo_fixes` من ملف الـSQL.

### 3) ضيف المسارات

افتح `app/routes/api.php` وضيف بعد بلوك Website Optimizer:

```php
// Phase 21 (Auto SEO)
$router->post('/api/auto-seo/connect',       'AutoSeoController', 'connect',    ['AuthMiddleware']);
$router->delete('/api/auto-seo/connect',     'AutoSeoController', 'disconnect', ['AuthMiddleware']);
$router->post('/api/auto-seo/mode',          'AutoSeoController', 'setMode',    ['AuthMiddleware']);
$router->post('/api/auto-seo/apply',         'AutoSeoController', 'apply',      ['AuthMiddleware']);
$router->get('/api/auto-seo/logs',           'AutoSeoController', 'logs',       ['AuthMiddleware']);
$router->post('/api/auto-seo/rollback/{id}', 'AutoSeoController', 'rollback',   ['AuthMiddleware']);
```

وفي `app/routes/web.php` (**من غير** AuthMiddleware — لأنه بيتحمّل من متصفح زوار العميل):

```php
$router->get('/embed.js', 'AutoSeoController', 'embedScript');
```

### 4) اربط Auto-Pilot بالتدقيق

في `WebsiteOptimizerController::runAudit()`، بعد حفظ الـfindings مباشرة، ضيف البلوك الموجود في `routes-snippet.php` (السطور المعلّقة في الآخر).

### 5) حدّث الـautoload

```bash
composer dump-autoload -o
```

### 6) اختبر

```bash
# 1. اربط موقع
curl -X POST https://YOURDOMAIN/api/auto-seo/connect \
  -H "Authorization: Bearer TOKEN" \
  -d '{"website_id":1,"method":"script"}'

# 2. هترجعلك embed_code — حطّه في موقع العميل قبل </head>

# 3. تأكد إن السكربت بيرد
curl "https://YOURDOMAIN/embed.js?token=emb_xxxxxxxxxxxxxxxxxxxxxxxx"
```

في Console بتاع موقع العميل المفروض تلاقي الإصلاحات اتحقنت، وفي `<head>` هتلاقي `meta description` و `canonical` و `JSON-LD` ظهروا من غير ما حد يعدّل الكود.

---

## الـEndpoints

| Method | Endpoint | الوظيفة |
|---|---|---|
| POST | `/api/auto-seo/connect` | ربط موقع + توليد `embed_token` و `api_key` |
| DELETE | `/api/auto-seo/connect` | فصل الموقع وإيقاف كل الحقن |
| POST | `/api/auto-seo/mode` | تغيير وضع Auto-Pilot |
| POST | `/api/auto-seo/apply` | تطبيق إصلاح واحد أو كل المؤهل |
| GET | `/api/auto-seo/logs` | سجل التغييرات |
| POST | `/api/auto-seo/rollback/{id}` | تراجع فوري |
| GET | `/embed.js?token=` | السكربت العام (بدون auth) |

---

## أوضاع Auto-Pilot

| الوضع | بيطبّق إيه |
|---|---|
| `off` | تحليل بس — مفيش حقن |
| `conservative` | `critical` + `high` وبشرط `impact_score >= 7` |
| `balanced` | `critical` + `high` + `medium` |
| `aggressive` | كل حاجة قابلة للحقن |

## الحقول القابلة للحقن

`seo_title` · `seo_description` · `canonical_url` · `viewport` · `og_tags` · `json_ld` · `faq_schema` · `speakable` · `image_alt`

الحقول دي **server-side** ومحتاجة proxy أو رفع ملف: `robots_txt` · `llms_txt` · `sitemap`

---

## ملاحظات أمان

- `embed_token` عام (بيظهر في HTML) — بيقرأ بس، مش بيكتب. الشكل: `emb_` + 24 hex.
- `embed_api_key` سرّي — للتحكم البرمجي، **متحطّوش في الفرونت**.
- الـController بيتحقق من `ownsWebsite()` في كل endpoint محمي.
- `embedScript()` بيعمل validate بـregex على التوكن قبل أي استعلام.
- كل الاستعلامات Prepared Statements زي باقي المشروع.

---

## الـcommit

```bash
git checkout -b feat/auto-seo-external-execution
git add app/Services/AutoSeo app/Controllers/AutoSeoController.php \
        database/migrations/2026_08_20_000001_auto_seo_embed.sql \
        app/routes/api.php app/routes/web.php
git commit -m "feat(seo): Phase 21 - Auto SEO execution on external sites via embed.js

- ربط أي موقع خارجي بسكربت واحد (WordPress/Shopify/HTML)
- حقن تلقائي لـ title/meta/canonical/JSON-LD/OG في المتصفح
- أوضاع Auto-Pilot: off/conservative/balanced/aggressive
- سجل تغييرات كامل + Rollback فوري
- امتداد لمنطق Phase 13 Auto-Pilot ليشمل المواقع الخارجية"

git push origin feat/auto-seo-external-execution
```

بعدها افتح PR وراجع الكود قبل الميرچ على `main`.
