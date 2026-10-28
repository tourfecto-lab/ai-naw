# تطويرات النشر على فيسبوك – من الأهم للأقل

> مبني على قراءة: MetaSocialAPI, PublishSocialPostJob, MetaOAuthClient, SocialPostService, SocialMediaController, MetaWebhookController.

## 🔴 أولًا: أخطاء فعلية لازم تتصلح الأول

### 1) نشر الفيديو/Reels على فيسبوك بيفشل
`PublishSocialPostJob.php` سطر ~102 بينادي `$api->publishReelToFacebookPage(...)` لكن الميثود دي **مش موجودة** في `MetaSocialAPI`.
النتيجة: أي فيديو قصير على فيسبوك بيرمي Error والهدف بيتحط `uncertain`.

الحل (لازم يتجرب على صفحة اختبار): Reels API بتتم على 3 مراحل:
```php
public function publishReelToFacebookPage(string $pageId, string $pageToken, string $message, string $videoUrl): array
{
    // 1) start
    $s = $this->post("{$pageId}/video_reels", ['upload_phase' => 'start'], $pageToken);
    if (!$s['success']) return $s;
    $videoId = $s['data']['video_id'] ?? null; $uploadUrl = $s['data']['upload_url'] ?? null;
    if (!$videoId || !$uploadUrl) return ['success'=>false,'error'=>'Reels start failed'];
    // 2) رفع بالرابط: POST على upload_url بهيدر file_url + Authorization: OAuth <token>
    //    (محتاج httpRequest يدعم headers مخصصة - موجود فعلًا)
    // 3) finish
    return $this->post("{$pageId}/video_reels", [
        'upload_phase' => 'finish', 'video_id' => $videoId,
        'video_state' => 'PUBLISHED', 'description' => $message,
    ], $pageToken);
}
```
ولازم بعد finish تعمل polling على حالة الفيديو (`GET /{video_id}?fields=status`) زي ما بتعمل مع إنستجرام.

### 2) مقياس الوصول (reach) مش بيرجع أبدًا
في `getPostMetrics` بتطلب `post_impressions_unique`. Meta شالته (الموثّق: البديل `post_total_media_view_unique`)، فالطلب بيرجع invalid metric والـ reach بيفضل `null` بصمت.
```php
$ins = $api->get($postId.'/insights', ['metric' => 'post_total_media_view_unique']);
```
ملحوظة: القيمة بتتحسب بمنهجية مختلفة (مشاهدات مش reach) فغيّر التسمية في الواجهة لـ "مشاهدين فريدين". وبرضه راجع أي استخدام لـ `page_fans` (اتشال، البديل `page_follows`).

### 3) المنشورات `uncertain` مالهاش مخرج
- `retryPost` بيتعامل مع `failed` بس. `uncertain` (يعني ممكن اتنشر فعلًا) مفيش زرار ولا مهمة تحسمه.
- الحل: Job مصالحة (reconcile) تقرا `GET /{page}/published_posts?limit=25` وتطابق النص/الوقت، أو تخلّي العميل يختار "اتنشر / ماتنشرش". وبكده تمنع النشر المكرر والمنشورات الضايعة.

### 4) الهاشتاجات
`createPost` في الكنترولر بيبعت `content, website_id, media_item_id` بس – `hashtags` مش بتتمرر للـ service. اتأكد إن الواجهة بتدمجها في النص، وإلا بتضيع.

---

## 🟠 ثانيًا: موثوقية (Reliability)

1. **أخطاء Meta بالنص مش بالكود**: `request()` بترجع الرسالة بس. ارجّع `code` و`error_subcode` و`fbtrace_id` و`is_transient`، وابني `friendlyError` على الكود (190/458/460/463/467 = توكن، 4/17/32/613 = حد استخدام، 368 = حظر مؤقت، 100 = باراميتر غلط).
2. **Retry ذكي**: الأخطاء المؤقتة (5xx, transient, rate limit) → إعادة بـ exponential backoff + jitter، والدائمة → فشل فوري. حاليًا أي فشل شبكة بيروح `uncertain`.
3. **صحة الاتصال**: مفيش Cron بيفحص التوكنات. اعمل `debug_token` يومي + `me/permissions`، وعلّم الاتصال `expired/needs_reauth` وابعت إشعار/إيميل **قبل** الانتهاء (60 يوم لتوكن المستخدم) وقبل أي نشر مجدول.
4. **Pre-flight قبل النشر**: HEAD على رابط الصورة/الفيديو (https, 200, content-type, الحجم)، حد الكابشن (فيسبوك 63,206 / إنستجرام 2,200 و30 هاشتاج)، مقاس الصورة، مدة وحجم الفيديو. ده بيمنع أغلب أخطاء "media download failed".
5. **حدود الاستخدام**: اقرا `X-App-Usage` و`X-Business-Use-Case-Usage` من الـ headers وهدّي النشر قبل الحظر. لإنستجرام استخدم `content_publishing_limit` (100 منشور/24 ساعة).
6. **Timeouts**: أضف `CURLOPT_CONNECTTIMEOUT` ومهلة أطول لرفع الفيديو.
7. **الأمان**: ضيف `appsecret_proof` لكل نداء، وابعت التوكن في `Authorization: Bearer` بدل query string (التوكن في `GET me/accounts` بيتسجل في لوجات السيرفر/البروكسي).

---

## 🟡 ثالثًا: ميزات تخليها احترافية فعلًا (مقارنة بـ Buffer/Metricool/Hootsuite)

**النشر**
- صور متعددة (`attached_media`) وكاروسيل إنستجرام، منشور برابط مع Preview، Stories (فيسبوك وإنستجرام)، Alt text، تاج موقع، أول تعليق تلقائي (للهاشتاجات/الرابط).
- نشر مجدول أصلي عبر `scheduled_publish_time` كخيار (يشتغل حتى لو الكرون وقع)، مع إبقاء الـ Queue الحالي للمنصات التانية.
- تعديل المنشور بعد النشر (`POST /{post-id}`) وحذفه من فيسبوك (`DELETE /{post-id}`) – حاليًا `deletePost` بيمسح من النظام بس.
- نسخة مخصصة من النص لكل منصة + معاينة (Preview) لشكل المنشور قبل النشر.
- UTM تلقائي واختصار روابط وتتبع النقرات.

**سير العمل (Workflow)**
- دورة موافقة (مسودة ← مراجعة ← موافقة) وأدوار (محرر/مدير/عميل) وتعليقات داخلية وسجل تغييرات.
- تقويم بسحب وإفلات، رفع جماعي (CSV/Excel)، قوائم انتظار بأوقات ثابتة، إعادة تدوير المحتوى الدائم (evergreen).
- أفضل وقت نشر مبني على بيانات الصفحة نفسها (موجود جزئيًا في `SocialInsightsService` – وسّعه).

**التفاعل (Inbox)**
- الـ webhook مشترك في `messages` بس. ضيف `feed` (تعليقات/منشنات) علشان تسحب التعليقات وتردّ/تخفي/تحذف بالذكاء الاصطناعي من شاشة واحدة.

**التحليلات**
- لوحة بالمقاييس الجديدة (`post_total_media_view_unique`, `page_follows`)، نمو المتابعين، مقارنة أفضل/أسوأ منشورات، تقارير PDF/CSV مجدولة للعميل، وتجميع كل الصفحات.
- تخزين لقطات يومية (snapshots) بدل نداء Meta في كل ضغطة (`refreshMetrics` حاليًا limit 15 وبيتنفذ داخل الطلب).

---

## 🔵 رابعًا: OAuth ومراجعة Meta (App Review)

1. `MetaOAuthClient::scopes()` بيدمج صلاحيات **الإعلانات** (`ads_management`, `business_management`) دايمًا حتى لو العميل عايز نشر بس. افصل مسار "ربط السوشيال" بصلاحيات أقل (pages_show_list, pages_read_engagement, pages_manage_posts, instagram_basic, instagram_content_publish) → موافقة أسهل من العميل ومراجعة أسرع.
2. `pages_manage_posts` و`instagram_content_publish` و`pages_messaging` محتاجين App Review + Business Verification لأي عميل خارج فريق التطبيق. جهّز: Privacy Policy، Data Deletion Callback URL، Deauthorize Callback، وفيديو شرح لكل صلاحية.
3. اتعامل مع إلغاء الصلاحية من إعدادات فيسبوك (Deauthorize webhook) وعلّم الاتصال فورًا.
4. حدّث `META_API_VERSION` دوريًا (حاليًا الافتراضي v25.0) وضيف اختبار أسبوعي يضرب نداء بسيط ويتنبه لتحذيرات الإيقاف.

---

## 🟣 خامسًا: جودة الكود والبنية

- `SocialMediaController.php` ≈ 1,700 سطر وفيه HTML/JS جوه `index()`. قسّمه: Controller رفيع + Views + `assets/js`.
- `PublishSocialPostJob` فيه منطق 4 منصات في كلاس واحد → استخرج `PlatformPublisherInterface` وكلاس لكل منصة (Facebook/Instagram/TikTok/YouTube).
- أكواد مضغوطة سطر واحد (`if(...)throw ...;`) صعبة القراءة والمراجعة → نسّقها (PSR-12) وشغّل `tools/lint.php` في الـ CI.
- الـ `transport` المحقون في `MetaSocialAPI` ممتاز للاختبار – اكتب عليه اختبارات لكل ميثود (صورة/فيديو/Reels/أخطاء/paging)، والاختبار الحالي للـ Job سطور قليلة جدًا (49 سطر).
- لوحة مراقبة: معدل نجاح النشر لكل صفحة، متوسط زمن النشر، أكثر الأخطاء، وتنبيه لما الفشل يعدّي نسبة.
- حدود حسب الباقة (عدد الصفحات/المنشورات الشهرية) وسجل تدقيق (Audit Log) لكل إجراء.

---

## ترتيب التنفيذ المقترح
1. (يوم) إصلاح `publishReelToFacebookPage` + مقياس reach + hashtags.
2. (2-3 أيام) أخطاء بالكود + Retry + مصالحة `uncertain` + فحص صحة التوكن.
3. (أسبوع) Pre-flight للوسائط + صور متعددة/Stories + تعديل/حذف من فيسبوك.
4. (أسبوعين) Inbox التعليقات + لوحة التحليلات الجديدة + دورة الموافقة.
5. بالتوازي: فصل OAuth للسوشيال وتجهيز App Review.
