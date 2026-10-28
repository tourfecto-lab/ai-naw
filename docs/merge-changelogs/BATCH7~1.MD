# ترقية قسم الإعلانات — واجهة احترافية + Google Ads + نشر فعلي
### 2026-08-10

## الهدف من الترقية دي
القسم كان قبل كده بيعمل بس Meta Ads، والحملات كانت بتتحفظ محليًا
كمسودة من غير ما تتنشر فعليًا على أي منصة (حتى Meta). المطلوب كان:
واجهة احترافية، دعم Google Ads بجانب Meta، والأهم: **نشر حقيقي** يخلي
الحملة تشتغل فعليًا مش بس تتسجل في قاعدة البيانات.

---

## ⚠️ حالة الاختبار (مهم جدًا تقرأها قبل الاستخدام)
**الكود ده متجربش على سيرفر حقيقي ولا حساب Meta/Google حقيقي.** اتكتب
بعناية ومنطقي وسليم بنائيًا (فحصت توازن الأقواس والبنية يدويًا في كل
ملف)، لكن أي تكامل API حقيقي محتاج تجربة فعلية قبل ما يتسلّم لعميل.
**جرّبه على حساب تجريبي (Test Ad Account) الأول.**

---

## ملفات جديدة بالكامل

| الملف | الغرض |
|---|---|
| `app/Services/Ads/GoogleAdsAPI.php` | عميل REST كامل لـ Google Ads API: سحب حسابات/حملات حقيقية، إنشاء حملة بحث كاملة (Budget → Campaign → Ad Group → Keywords → RSA Ad)، تشغيل/إيقاف، تعديل ميزانية، إلغاء |
| `database/migrations/2026_08_08_000041_add_google_ads_platform_support.sql` | عمود `platform` على `ad_campaigns` (manual/meta_ads/google_ads) |
| `database/migrations/2026_08_09_000042_add_campaign_publishing_support.sql` | عمود `published_at` على `ad_campaigns` |
| `database/migrations/2026_08_10_000043_add_campaign_management_columns.sql` | عمودين `external_adset_id` و`external_budget_resource` لدعم تعديل الميزانية بعد النشر |
| `docs/merge-changelogs/BATCH7_ads_professional_upgrade_meta_google.md` | هذا الملف |

## ملفات معدَّلة

### `app/Controllers/AdsController.php` (أكبر تعديل)
- **واجهة `/ads` كاملة اتعاد بناؤها**: كارت ربط منفصل لكل منصة (Meta +
  Google)، 4 stat tiles (إنفاق/حملات نشطة/ظهور/نقرات)، تابات فلترة
  (الكل/Meta/Google/يدوية)، عمود "المنصة" بـ badge ملوّن في الجدول.
- **ويزارد الذكاء الاصطناعي**: اختيار المنصة كأول خطوة، حدود حروف
  مختلفة تلقائيًا (Meta: 40/30/220، Google: 30/90/90)، توليد كلمات
  مفتاحية تلقائي لو المنصة Google Ads مع إمكانية حذف أي كلمة قبل الإنشاء.
- **OAuth كامل لـ Google Ads** (مرآة لتدفق Meta الموجود): `connectGoogleAds`,
  `googleAdsOAuthCallback`, `showGoogleAdsAccountPicker`,
  `chooseGoogleAdsAccount`, `getGoogleAdsConnectionStatus`,
  `syncGoogleAdsCampaigns`, `disconnectGoogleAds`.
- **نشر فعلي (الأهم)**: `publishCampaign()` — بياخد الحملة المحفوظة
  محليًا + النصوص المعتمدة، وينشئها فعليًا على Meta أو Google Ads
  (دايمًا بحالة **متوقفة/Paused** كإجراء أمان - العميل لازم يراجع
  ويفعّل بنفسه من حساب المنصة الرسمي).
  - يجيب صورة `og:image` من موقع العميل تلقائيًا لإعلان Meta (لو
    اتلاقتش، الإعلان بيتعمل من غير صورة بدل ما يفشل).
  - لو العميل عنده أكتر من صفحة فيسبوك، بيرجع قائمة يختار منها
    (HTTP 409 مع تفاصيل الصفحات) بدل ما يختار عشوائي.
  - بيبعت تواريخ البداية/النهاية الفعلية للمنصة.
  - بيستخدم توصية استراتيجية المزايدة من الذكاء الاصطناعي فعليًا في
    Google Ads (maximize_conversions لو فيه ذكر "تحويلات"، غير كده
    maximize_clicks).
- **إدارة الحملة بعد النشر**: `toggleCampaignStatus()` (تشغيل/إيقاف
  فعلي على المنصة)، `cancelCampaign()` (إلغاء نهائي)،
  `updateCampaignBudget()` (تعديل الميزانية فعليًا على المنصة).
- **`listKeywords()`**: عرض الكلمات المفتاحية المحفوظة لحملة Google
  Ads (كانت بتتحفظ من قبل بس مفيش حد بيشوفها تاني).
- **إصلاح باج موجود من قبل**: `syncMetaCampaigns()` ما كانش بيحط قيمة
  `platform` عند إضافة حملة جديدة من المزامنة (كانت بتفضل `manual`
  افتراضيًا). كمان إصلاح رسالة "رصيدك مش كافي" في ويزارد الذكاء
  الاصطناعي (كانت بتقرا من مكان غلط في الرد فمكانتش بتظهر).
- **🔴 إصلاح باج حرج (اتلاقى بعد المراجعة)**: الواجهة (يدوي وويزارد
  الذكاء الاصطناعي) ما كانتش بتبعت `website_id` خالص عند إنشاء أي
  حملة، يعني `ad_campaigns.website_id` كان هيفضل `NULL` دايمًا. ده
  معناه إن **النشر الفعلي (`publishCampaign`) كان هيفشل 100% من المرات**
  لأنه محتاج رابط الموقع من جدول `websites` عن طريق الـ `website_id`
  ده. اتصلح بحيث `create()` يرجع تلقائيًا لأول موقع للعميل لو الواجهة
  ما بعتتش الحقل ده (نفس المنطق المستخدم أصلاً وقت ربط حساب Meta/Google Ads).

### `app/Services/Ads/MetaAdsAPI.php`
- `createCampaign()`: إنشاء حقيقي (Campaign → Ad Set → Ad Creative →
  Ad لكل نسخة معتمدة = A/B تلقائي)، مع دعم صورة اختيارية وتواريخ.
- `updateCampaignStatus()`, `deleteCampaign()`, `updateAdSetBudget()`:
  إدارة حملة موجودة فعلاً.
- `fetchOgImageFromWebsite()`: استخراج صورة تمثيلية من موقع العميل.
- خريطة مبسّطة لأسماء دول عربي/انجليزي شائعة → أكواد ISO للاستهداف
  الجغرافي (مصر افتراضيًا لو الاسم مش معروف).

### `app/Services/Ads/AdCopyGenerationService.php`
- الحدود الحرفية والبرومبت بقوا مبنيين على المنصة (`platform` param)
  بدل ما يكونوا ثابتين على قيم Meta بس.
- توليد كلمات مفتاحية (keywords) جزء من الحزمة الكاملة لما المنصة تكون
  Google Ads.

### `app/Services/Ads/AdCampaignService.php`
- حفظ `platform` وحفظ الكلمات المفتاحية (`ad_keywords`) من حزمة الذكاء
  الاصطناعي عند إنشاء الحملة.

### `app/Services/OAuth/GoogleOAuthClient.php`
- إضافة `SCOPE_ADS` (`https://www.googleapis.com/auth/adwords`).

### `app/Services/OAuth/MetaOAuthClient.php`
- إضافة صلاحية `pages_show_list` كانت ناقصة (لازمة عشان نظام ربط
  صفحات الفيسبوك التلقائي الموجود من قبل يشتغل صح، ولازمة لجلب
  الصفحة وقت النشر).

### `app/Models/AdCampaign.php`
- إضافة `platform`, `published_at`, `external_adset_id`,
  `external_budget_resource` لقائمة `fillable`.

### `app/Services/System/SystemSettingsService.php`
- إعدادات جديدة: `google_ads_developer_token` (سري)،
  `google_ads_login_customer_id` (MCC، اختياري).

### `app/Controllers/AdminController.php`
- بلوك جديد في صفحة إعدادات الأدمن لإدخال Google Ads Developer Token
  و Manager Account ID، مربوط بالكامل بمسارات الحفظ/التحميل الموجودة.

### `app/routes/web.php` و `app/routes/api.php`
- مسارات Google Ads OAuth الكاملة + مسارات النشر والإدارة
  (`publish`, `toggle-status`, `cancel`, `update-budget`, `keywords`).

### `public_html/index.php`
- تسجيل `GoogleAdsAPI.php` في قائمة التحميل اليدوي (السيرفر ده مفيهوش
  Terminal لتشغيل `composer dump-autoload`).

### `public_html/assets/css/panel.css`
- كلاسات جديدة: `ads-platform-choice` (اختيار المنصة في الويزارد)،
  `ads-platform-badge` (شارة لونية لكل منصة في الجدول).

### `.env.example`
- `GOOGLE_ADS_DEVELOPER_TOKEN`, `GOOGLE_ADS_LOGIN_CUSTOMER_ID`,
  `GOOGLE_ADS_OAUTH_REDIRECT_URI`, `GOOGLE_ADS_API_VERSION`.

---

## خطوات لازم تعملها إنت قبل التشغيل
1. شغّل الـ 3 migrations الجديدة بالترتيب (`000041`, `000042`, `000043`).
2. اطلب **Google Ads Developer Token** من ads.google.com (Tools &
   Settings > API Center) — الموافقة الرسمية بتاخد كام يوم من Google.
3. فعّل **Google Ads API** في نفس مشروع Google Cloud بتاعك
   (المستخدم أصلاً لـ `GOOGLE_CLIENT_ID`/`SECRET`).
4. أدخل الـ Developer Token من صفحة إعدادات الأدمن الجديدة.
5. **جرّب على حساب Meta/Google تجريبي الأول** قبل أي عميل حقيقي.

## حدود معروفة (مقبولة كبداية، مش أخطاء)
- استهداف جغرافي لـ Meta محدود بدول عربية شائعة معروفة فقط.
- Google Ads: حملات بحث (Search) بس - مفيش Display/Video/Performance Max.
- بيختار أول صفحة فيسبوك تلقائيًا لو واحدة بس؛ لو أكتر من واحدة، بيسأل العميل.
- مفيش rollback كامل لو النشر فشل في نص السلسلة - بيسجّل الجزء اللي
  نجح (external_campaign_id) عشان العميل يكمّله يدويًا بدل ما يتوه.
