Tourfecto Admin Integrations Page Upgrade

ما الذي تم تطويره:
1) صفحة Admin > Integrations أصبحت أوضح:
   - ملخص أعلى الصفحة: إجمالي التكاملات، المفعلة، التي تحتاج إعداد، ونسبة الجاهزية.
   - بحث باسم التكامل أو الفئة أو اسم المفتاح.
   - فلترة حسب الفئة.
   - فلترة حسب الحالة: مفعلة / تحتاج إعداد.
   - عرض المفاتيح المطلوبة لكل تكامل بشكل واضح.
   - زر إعداد المفاتيح وزر اختبار لكل تكامل.

2) إصلاح خطأ مهم في حفظ المفاتيح:
   - الصفحة كانت تعرض أسماء المفاتيح بشكل مخفي ثم تحاول الحفظ بنفس الاسم المخفي.
   - الآن أسماء المفاتيح تظهر للأدمن فقط كأسماء متغيرات، لكن القيم نفسها لا تظهر.

3) إصلاح ربط Google OAuth مع لوحة الأدمن:
   - GOOGLE_CLIENT_ID يحفظ ويقرأ من google_client_id.
   - GOOGLE_CLIENT_SECRET يحفظ ويقرأ من google_client_secret.
   - لوحة الأدمن هي المصدر الأساسي، و .env احتياطي فقط.

4) دعم مفاتيح التفعيل:
   - التكاملات التي تحتاج STRIPE_ENABLED أو SLACK_ENABLED أو غيرها تظهر هذه المفاتيح في النموذج.
   - اكتب true لتفعيل الخدمة أو false لإيقافها.

5) توافق خلفي:
   - النظام ما زال يقرأ مفاتيح integration_* القديمة لو كانت محفوظة قبل التحديث.
   - عند الحفظ، يتم حفظ النسخة الجديدة الصحيحة مع نسخة توافقية للتكاملات القديمة.

الملفات التي تستبدلها:
app/Controllers/AdminController.php
app/Controllers/IntegrationsController.php
app/Core/IntegrationManager.php
app/Services/Integrations/BaseIntegrationService.php
app/Services/OAuth/GoogleOAuthClient.php
app/Services/OAuth/SocialLoginClient.php

طريقة التركيب:
1) ارفع مجلد app الموجود داخل هذه الحزمة فوق مجلد app الموجود على الاستضافة.
2) وافق على استبدال الملفات المذكورة.
3) امسح Cache / OPcache من لوحة الاستضافة لو موجود.
4) ادخل Admin > Integrations وجرب حفظ Google Client ID و Google Client Secret.
5) بعد الحفظ، افتح Google Business أو Google Login وجرب الربط.

مهم:
القيم السرية لا يتم عرضها بعد الحفظ. لو عايز تغيير Secret، اكتب القيمة الجديدة كاملة واحفظ.
