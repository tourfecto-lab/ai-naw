# تصحيح جذري: أسامي أعمدة جدول reviews الحقيقية لا تطابق ملفات الـ migration
### 2026-07-15

## الاكتشاف
بعد ما اتبعتلي `SHOW COLUMNS FROM reviews;` من phpMyAdmin على السيرفر
الفعلي، اتضح إن الجدول الحقيقي **مختلف تمامًا** عن كل ملفات الـ
migration الموجودة في المشروع (المرجعية والقديمة). الفرق:

| اسم افترضته ملفات الـ migration | الاسم الحقيقي على السيرفر |
|---|---|
| `platform` | `source_platform` |
| `platform_review_id` | `external_review_id` |
| `sentiment_label` | `sentiment` (+ قيمة `mixed` إضافية) |
| `auto_reply_generated` | `ai_generated_reply` |
| `reply_sent` (TINYINT 0/1) | غير موجود - الحالة الحقيقية `reply_sent_at` (تاريخ/وقت) |
| `is_processed`, `processed_at`, `needs_attention`, `ip_address`, `user_agent`, `webhook_raw_data` | **غير موجودين خالص** في الجدول الحقيقي |
| — | `reply_status` **موجود بالفعل** (enum: pending/approved/rejected/sent/auto_...) - مش لازم أي migration له |

**النتيجة**: موديول السمعة بالكامل (مش بس الصفحة الجديدة اللي عملتها)
كان مبني على أسامي أعمدة غلط، ومحتمل جدًا ماكانش شغال أصلًا على
قاعدة البيانات الحقيقية من قبل ما أبدأ، مش حاجة سببتها الدفعة دي.

## اكتشاف إضافي مهم: فيه نسختين من كل كلاس Reputation
المشروع فيه مجلدين متوازيين:
- `app/Reputation/` - **كود ميت تمامًا**، مش موجود في `composer.json`
  autoload (لا في psr-4 ولا في classmap) ولا بيتعمله require من أي حتة.
- `app/Services/Reputation/` - **ده الكود الفعلي الشغال** (مؤكد من
  `vendor/composer/autoload_classmap.php` و`tests/bootstrap.php`)،
  وفيه نسخ أحدث ومُصلَّحة فعليًا لـ `GoogleBusinessAPI`/`TripAdvisorAPI`
  (تصحيحات حقيقية لـ base URLs غلط وردود وهمية كانت بترجع "نجاح" من
  غير ما تبعت حاجة فعليًا).

أول مرة صلّحت `app/Reputation/ReputationManager.php` (الكود الميت) قبل
ما اكتشف إنه مش الشغال - رجّعت صلّحت النسخة الصح في `app/Services/Reputation/`.
نفس المجلد المتوازي موجود لـ `app/Security/` vs `app/Services/Security/`
(الشغال هو `Services/Security/GDPRCompliance.php`).

## بگ حقيقي إضافي (مش خاص بأسامي الأعمدة) اتصلح بالمرة
`ReputationController::generateReply()` كان بينادي
`$this->reputationManager->generateReply(...)` لكن الميثود دي ماكانتش
موجودة خالص في `ReputationManager` - يعني زرار "توليد رد" كان هيدّي
Fatal Error "Call to undefined method" لأي مستخدم يدوس عليه. اتضاف
wrapper method بسيطة حوالين `ReplyGenerator->generate()` الموجود بالفعل.

## استراتيجية الإصلاح: Aliasing بدل إعادة كتابة كل استهلاك للبيانات
بدل ما أعدّل كل مكان في الـ JS/PHP بيقرا `r.platform`/`r.sentiment_label`
إلخ (عشرات الأماكن)، خليت كل query بيقرا من `reviews` يعمل alias صريح:
```sql
SELECT reviews.*,
    source_platform AS platform,
    external_review_id AS platform_review_id,
    sentiment AS sentiment_label,
    ai_generated_reply AS auto_reply_generated,
    (reply_sent_at IS NOT NULL) AS reply_sent
FROM reviews ...
```
كده كل الواجهات والـ JS القديمة فضلت شغالة زي ما هي من غير أي تعديل،
وبس طبقة الـ SQL هي اللي اتصلحت. الكتابة (INSERT/UPDATE) بقت بتستخدم
الأسامي الحقيقية مباشرة زي ما لازم.

## ملفات اتصلحت فعليًا
- `app/Models/Review.php` - `$fillable` + كل الميثودز
- `app/Services/Reputation/ReputationManager.php` (**النسخة الشغالة**) -
  كل الـ SQL queries + إضافة `generateReply()`
- `app/Services/Reputation/GoogleReviewSyncService.php` - `reviewExists()`
- `app/Services/Reputation/TripAdvisorReviewSyncService.php` - `reviewExists()`
- `app/Services/Security/GDPRCompliance.php` - `getUserReviews()`
- `app/Models/Website.php` - `getReviewStats()`
- `app/Controllers/ReputationController.php` - كل الـ SQL (showReviews,
  getReviews, getReview, showReview, generateReply, sendReply,
  getAllWebsitesStats, getAllPlatformsStats, وصفحة Overview الجديدة)
- `app/Controllers/DashboardController.php` - `getReviewStats()` (كان
  فيه تصحيح جزئي سابق لعمود sentiment بس، الجزء الخاص بـ reply_sent
  اتصلح دلوقتي)
- عمود `reply_status` **مش محتاج migration** - موجود بالفعل، فحذفنا
  الـ migration اللي بعتها قبل كده.

## قرار محافظ بخصوص reply_status
الـ enum الحقيقي (`pending`,`approved`,`rejected`,`sent`,`auto_...`)
جزء منه (القيمة الخامسة `auto_...`) لسه مقطوع من الصورة ومش مؤكد
100%. الكود دلوقتي بيستخدم بس القيم المؤكدة: `pending` (افتراضي)،
`sent` (لما الرد يتبعت فعليًا)، `rejected` (لما المستخدم يدوس "تجاهل").
لو بعتلي القيمة الخامسة كاملة، ممكن نستخدمها لو ليها معنى مختلف
(زي "رد اتولّد أوتوماتيك من غير ما حد يراجعه").

## لسه محتاج تأكيد منك
1. القيمة الخامسة لـ `reply_status` enum (الجزء المقطوع `auto_...`).
2. تأكيد إن `chat_messages` مفيهاش نفس مشكلة أسامي الأعمدة (مش فحصناها -
   الملف ده بس عن `reviews`). لو حابب، ابعتلي `SHOW COLUMNS FROM chat_messages;`
   ونفحصها بنفس الطريقة قبل ما تظهر نفس المشكلة هناك.
