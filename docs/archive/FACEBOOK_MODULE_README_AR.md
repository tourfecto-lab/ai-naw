# موديول النشر على فيسبوك – Tourfecto

الملفات دي مستخرجة من `tourfecto_pro__26_.zip` بنفس هيكل المجلدات الأصلي (تقدر تدمجها فوق المشروع مباشرة).

## خريطة الملفات (مرتبة حسب أهميتها للنشر)
| الملف | وظيفته |
|---|---|
| app/Services/SocialMedia/MetaSocialAPI.php | عميل Graph API: جلب الصفحات، نشر نص/صورة/فيديو فيسبوك، إنستجرام، الإحصائيات |
| app/Jobs/PublishSocialPostJob.php | التنفيذ الفعلي للنشر من الـ Queue (فيسبوك/إنستجرام/تيك توك/يوتيوب) + ترجمة الأخطاء |
| app/Services/OAuth/MetaOAuthClient.php | تسجيل الدخول بفيسبوك، الصلاحيات، تبديل التوكن لطويل المدى |
| app/Controllers/SocialMediaController.php | الـ API بتاع الواجهة: createPost / retryPost / calendar / stats / refreshMetrics |
| app/Services/SocialMedia/SocialPostService.php | إنشاء المنشور والأهداف وجدولتها + حساب الحالة |
| app/Controllers/MetaWebhookController.php | استقبال رسائل Messenger/Instagram |
| app/Services/SocialMedia/SocialMonthlyPlanService.php + Jobs/* | خطط المحتوى الشهرية والنشر المتكرر |
| app/Services/SocialMedia/SocialPostDesigner / SocialBrandStudio / SiteImageImporter / SocialMediaUpload / ArabicShaper | تصميم الصور، هوية العلامة، استيراد صور الموقع، رفع الوسائط |
| app/Services/CreativeStudio/SocialInsightsService.php | تحليلات أفضل وقت نشر |
| app/Models/Social*.php | الموديلز |
| database/migrations/* | جداول السوشيال فقط |
| public_html/assets/js/social-monthly-plans.js (+css) | واجهة الخطط الشهرية |
| tests/Jobs/PublishSocialPostJobTest.php | اختبار موجود |

## ملاحظات
- كلاسات مش موجودة هنا لأنها مشتركة في المشروع: `ExternalExecution`, `PrivateMedia`, `QueueManager`, `PlatformConnection`, `MediaItem`, `Encryption`, `Database`, `Logger`.
- شاشة `/social` نفسها (HTML) جوه `SocialMediaController::index`.
- راجع `IMPROVEMENTS_AR.md` لقائمة التطويرات.
