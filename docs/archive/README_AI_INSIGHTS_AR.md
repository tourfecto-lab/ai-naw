# تحديث مصادر وانطباع الذكاء الاصطناعي: النسخة الآمنة

النسخة دي **مش بتكتب فوق أي ملف موجود على السيرفر**. الـZIP فيه ملفات جديدة بس، وفيه سكربت تثبيت بيعدّل ملفات السيرفر نفسها في مكانها. كل تعديل فيهم سطر واحد، والسكربت بياخد نسخة احتياطية قبل أي تعديل.

## الخطوة ١: صلّح اللي اتبوظ من الرفع اللي فات (لازم)
الرفع اللي فات كتب نسخ قديمة فوق 6 ملفات، وصفحة "ظهورك في الذكاء الصناعي" بقت بتطلع "Could not load data". رجّع الـ6 ملفات دول من النسخة الاحتياطية بتاعتك، أو من hPanel ← Backups ← File backups، واختار نسخة من **قبل** يوم 22-10-2026:

- `app/routes/api.php`
- `app/Services/AiVisibility/AiVisibilityService.php`
- `app/Services/AiVisibility/AiVisibilityMetrics.php`
- `app/Services/AiVisibility/AiVisibilityPresentation.php`
- `app/Views/AiVisibility/index.php`
- `app/Core/ClientNavigation.php`

بعد ما ترجّعهم، افتح `/ai-visibility` واتأكد إن الصفحة رجعت تشتغل قبل ما تكمّل.

## الخطوة ٢: ارفع الـZIP
ارفع `tourfecto-ai-insights-safe.zip` لفولدر المشروع الرئيسي، وهو الفولدر اللي فيه `app` و`cron` و`public_html`، واعمل Extract. الـZIP فيه ملفات جديدة بس:
- `app/Services/AiVisibility/ClaudeVisibilityProvider.php`
- `app/Services/AiInsights/` (4 ملفات)
- `app/Controllers/AiInsightsController.php`
- `app/Views/AiInsights/index.php`
- `app/routes/ai_insights.php`
- `cron/seo_ai_insights.php`
- `tools/install_ai_insights.php`
- `tools/test_ai_insights.php`

## الخطوة ٣: شغّل سكربت التثبيت مرة واحدة
من hPanel ← Advanced ← Cron Jobs، اعمل مهمة بالأمر ده، وشغّلها مرة واحدة، وبعدين امسحها:
```
php /home/USER/domains/tourfecto.pro/tools/install_ai_insights.php
```
(غيّر المسار لمسار فولدر المشروع عندك.) ولو عندك SSH، شغّل نفس الأمر من التيرمينال.

السكربت بيكتب تقرير في `storage/logs/ai_insights_install.txt`. كل سطر فيه حالة من دول:
- **DONE**: اتعدّل، والنسخة الأصلية اتحفظت في `storage/backups/ai-insights-<الوقت>/`
- **ALREADY**: كان متعدّل قبل كده
- **SKIPPED**: ملقاش السطر اللي بيعدّل جنبه، فساب الملف زي ما هو. ساعتها ضيف السطر بإيدك (الجدول تحت)
- **NOTE Claude not registered**: كلاس المزود على السيرفر مختلف عن النسخة اللي التحديث اتعمل عليها، فـ Claude متركّبش علشان ميعملش خطأ. باقي المميزات بتشتغل عادي.

ولو عايز تشوف التغييرات من غير ما يتكتب أي حاجة، ضيف `--dry-run` في آخر الأمر.

## التعديلات اللي السكربت بيعملها (لو حبيت تعملها بإيدك)
| الملف | التعديل |
|---|---|
| `app/routes/api.php` | سطر في آخر الملف: `require __DIR__.'/ai_insights.php';` |
| `app/Core/ClientNavigation.php` | بعد سطر `'ai_visibility'=>[...,'/ai-visibility'],` ضيف: `'ai_insights'=>[$l('مصادر وانطباع الـAI','AI sources and sentiment'),'/ai-insights'],` |
| `AiVisibilityService.php` | `new SonarVisibilityProvider()` تبقى `new SonarVisibilityProvider(),new ClaudeVisibilityProvider()` |
| `AiVisibilityMetrics.php` | `['openai','gemini','perplexity']` تبقى `['openai','gemini','perplexity','claude']` |
| `AiVisibilityPresentation.php` | بعد `'perplexity'=>'Perplexity'` ضيف `,'claude'=>'Claude'` |
| `app/Views/AiVisibility/index.php` | بعد `perplexity:'Perplexity'` ضيف `,claude:'Claude'` |

## بعد التثبيت
- افتح `/ai-visibility` و`/ai-insights`، والاتنين المفروض يفتحوا من غير أخطاء.
- علشان تفعّل Claude: افتح `/ai-insights` ← تبويب **Claude (للأدمن)** ← مفعّل ← حفظ. Claude بيستخدم مفتاح Anthropic اللي محفوظ في إعدادات الربط.
- تحليل الانطباع كل يوم (اختياري): `php /home/USER/domains/tourfecto.pro/cron/seo_ai_insights.php`

## حدود لازم تعرفها
- المصادر والانطباع محسوبين على عينات API الأسبوعية، ومش على تطبيقات الشات نفسها.
- البوتات بتتعرف من الـ User-Agent بس، من غير التحقق من عنوان IP.
- القياس بـ `claude-opus-5-5` بيتكلف تقريبًا من 0.05 لـ 0.12 دولار للسؤال، ولو عايز تكلفة أقل اختار Sonnet أو Haiku.
