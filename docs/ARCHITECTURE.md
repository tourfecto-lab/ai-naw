# البنية المعمارية الجديدة (Enterprise Architecture) — طبقة الأساس

هذا المستند يشرح الطبقة المعمارية الجديدة اللي اتضافت للمشروع، وليه اتبنت
بالشكل ده، وإزاي تتبناها تدريجيًا من غير ما تكسر أي حاجة شغالة.

## 0. الحالة الحالية: "أساس" بس، مش استبدال

**لسه ولا كنترولر ولا Model ولا route اتغيّر.** كل الملفات اللي اتضافت في
المرحلة دي جديدة كليًا وغير متصلة بأي كود شغال فعليًا. الموقع دلوقتي شغال
بالضبط زي ما كان قبل المرحلة دي. الهدف: تبني عليها تدريجيًا لما تكون
مستعد، جزء (feature) واحد في المرة الواحدة، مع اختبار كل خطوة قبل التالية.

## 1. ليه المعمارية دي بالشكل ده تحديدًا؟

المشروع مبني بالكامل بأسماء كلاسات عامة (global namespace) وautoloading
عن طريق classmap مُصنّف يدويًا (مش composer فعلي وقت النشر، لأن الاستضافة
مشتركة على Hostinger بدون SSH/composer). عشان كده كل كلاس جديد هنا:

- **من غير namespace** — عشان يفضل متوافق 100% مع الأسلوب الحالي، ومحدش
  يحتاج يتعلم اصطلاح جديد.
- **مُسجَّل يدويًا في `vendor/composer/autoload_classmap.php` و
  `autoload_static.php`** — بالظبط زي أي كلاس تاني اتضاف للمشروع في وقت
  سابق. لو ضفت كلاس جديد فوق الطبقة دي بنفسك، لازم تعمل نفس الخطوة
  (أو تشغّل `composer dump-autoload` لو عندك بيئة فيها composer).

## 2. الطبقات

```
Controller  →  Service  →  Repository  →  Database
   (HTTP)      (منطق العمل)   (وصول للبيانات)
```

| الطبقة | المكان | المسؤولية |
|---|---|---|
| **Container** | `app/Core/Container.php` | حقن الاعتماديات (DI) — بيحل الكائنات ويربطها مع بعض |
| **Contracts** | `app/Core/Contracts/*.php` | Interfaces (Repository/Service/Cache/Logger/Event/Job) |
| **Repository** | `app/Core/Repository/BaseRepository.php` | وصول لقاعدة البيانات فقط، بيحل مشكلة اختلاف أسماء الأعمدة الحقيقية عن المفترضة تلقائيًا (`detectColumn()`) |
| **Service** | `app/Core/Service/BaseService.php` | منطق العمل، بيستخدم أكتر من Repository مع بعض |
| **Events** | `app/Core/Events/*.php` | إطلاق/الاستماع لأحداث (متزامن — انظر قسم 4) |
| **Queue** | `app/Core/Queue/QueueManager.php` | مهام خلفية عن طريق جدول `jobs` + Cron (انظر قسم 5) |
| **Adapters** | `app/Core/Adapters/*.php` | يلفّوا `Cache`/`Logger` الموجودين فعلاً خلف الـ Contracts الجديدة، بدون تعديل فيهم |
| **Traits** | `app/Traits/*.php` | سلوك قابل لإعادة الاستخدام (Timestamps/Cacheable/LogsActivity) |
| **Helpers** | `app/Helpers/enterprise_helpers.php` | دوال عامة سريعة: `container()`, `event()`, `enqueue()`, `cache_remember()` |

## 3. مثال كامل (WebsiteRepository + WebsiteService)

`app/Repositories/WebsiteRepository.php` و `app/Services/Example/WebsiteService.php`
مثال حقيقي شغال (لو استخدمته) بس **مش متصل بـ `WebsiteController` الحالي
بعد**. بيوضح إزاي:

- `detectColumn()` بيحل مشكلة "الكود بيفترض `main_url` لكن قاعدة البيانات
  الفعلية اسم العمود عندها مختلف" تلقائيًا، بدل التصحيح اليدوي كل مرة
  تظهر فيها المشكلة دي (زي ما حصل مع `is_active`/`status` و`expiry_date`).
- `WebsiteService::registerWebsite()` بيطلق حدث `website.created` بعد
  الإنشاء، فأي جزء تاني في النظام (إشعارات، تحليل تلقائي أول مرة...)
  يقدر "يستمع" له من غير ما الكود الأساسي يعرف عنه حاجة.

## 4. الأحداث (Events) — ليه متزامنة (synchronous)؟

مفيش Redis أو message broker على الاستضافة الحالية. `EventDispatcher`
بينفذ الـ listeners فورًا جوه نفس الطلب. ده كويس لأي حاجة سريعة (تسجيل،
تحديث كاش، إشعار داخلي). لو listener بياخد وقت طويل (بعت إيميلات كتير،
معالجة صورة)، سيبه يعمل `enqueue()` بدل ما ينفّذ مباشرة.

## 5. الطابور والـ Cron — الحقيقة عن الاستضافة المشتركة

مفيش worker process دائم على Hostinger المشتركة. الحل العملي:

1. جدول `jobs` (migration: `database/migrations/2026_07_13_000001_create_jobs_table.sql`)
   — لازم تشغّله على قاعدة البيانات قبل ما تستخدم `enqueue()`.
2. `cron/process_queue.php` — سكريبت CLI بيسحب المهام المستحقة وينفذها.
3. **لازم تضيفه كـ Cron Job حقيقي من cPanel** (كل دقيقة مثلاً):
   ```
   php /home/USERNAME/domains/YOURSITE.com/cron/process_queue.php
   ```
   من غير الخطوة دي، أي حاجة اتعملها `enqueue()` هتفضل "pending" للأبد
   ومحدش هيعالجها.

هل ده "queue حقيقي" زي Redis/SQS؟ لأ — أقرب وصف "polling queue" بتتفحص
كل دقيقة. لكنه فعليًا الخيار الوحيد المتاح على الاستضافة الحالية بدون
ترقية لـ VPS. لو رقّيت الاستضافة يومًا، تقدر تستبدل `QueueManager` بنسخة
Redis-backed بنفس الـ public API (`push()`/`processDue()`) من غير ما تغيّر
أي كود بينادي عليه.

## 6. خطة التبني التدريجي المقترحة (لما تكون جاهز)

كل خطوة مستقلة وقابلة للاختبار لوحدها قبل الانتقال للي بعدها:

1. **تجربة على جزء واحد بس** (المواقع مثلاً): وصّل `WebsiteController`
   بـ `WebsiteRepository`/`WebsiteService` بدل الاستدعاء المباشر لـ
   `Website` Model، مع الإبقاء على نفس سلوك الـ API تمامًا (نفس شكل الرد
   JSON) عشان الفرونت إند الحالي محتاجش أي تعديل.
2. **تفعيل `AppExceptionHandler`** بعد التأكد إنه بيدي نفس شكل ردود
   الأخطاء الحالية (أو أفضل)، بسطر واحد في `index.php`.
3. **نقل مهمة واحدة تقيلة للطابور** (مثلاً: توليد تقرير AI، أو إرسال
   دفعة رسائل واتساب) كأول استخدام حقيقي لـ `QueueManager`.
4. **تعميم `detectColumn()`** على باقي الـ Repositories الجديدة كل ما
   ظهرت مشكلة اختلاف أعمدة جديدة، بدل التصحيح اليدوي المتكرر.

كل خطوة من دول لازم تتراجع (rollback) بسهولة لو حصلت مشكلة — عشان كده
الأفضل نعملهم واحدة واحدة، مش دفعة واحدة.

## 7. الهجرات الجديدة غير المُنفَّذة بعد (مطلوب تشغيلها عند أول نشر)

جداول AI Chat Platform المضافة مؤخرًا (Learning Loop + In-Chat Quotes)
**لم تُنفَّذ بعد على أي قاعدة بيانات حقيقية** — لا يوجد SSH/MySQL في بيئة
التطوير. عند النشر على الاستضافة:

1. اعمل نسخة احتياطية من قاعدة البيانات.
2. شغّل الملف المجمّع التالي مرة واحدة (يتضمّن `ai_resolution_events` +
   `ai_knowledge_gaps` + `ai_quotes`):
   ```
   mysql -u USER -p DBNAME < database/migrations/_PENDING_TO_RUN_ON_SERVER.sql
   ```
   أو من cPanel → phpMyAdmin → Import → نفس الملف.
3. تحقق من الجداول: `SHOW TABLES LIKE 'ai_%';`

ملفات الهجرة الفردية (للتوثيق/المراجعة):
- `database/migrations/2026_08_16_000001_create_ai_learning_loop_tables.sql`
- `database/migrations/2026_08_16_000002_create_ai_quotes_table.sql`

