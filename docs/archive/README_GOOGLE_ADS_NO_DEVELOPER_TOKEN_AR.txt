Tourfecto Google Ads Fix - بدون Developer Token

المشكلة:
صفحة Google Ads كانت توقف الربط وتعرض:
GOOGLE_ADS_DEVELOPER_TOKEN ناقص

وأنت لا تملك Developer Token. حاليًا يمكن الاعتماد على OAuth + مشروع Google Cloud المفعّل لـ Google Ads API، لذلك تم جعل Developer Token اختياريًا.

ما الذي تغير:
1) لم يعد ربط Google Ads يتوقف بسبب عدم وجود GOOGLE_ADS_DEVELOPER_TOKEN.
2) Google Ads API يرسل developer-token فقط إذا كان محفوظًا، ولو غير موجود يكمل الطلب بدونه.
3) صفحة حالة Google Ads تفحص فقط:
   GOOGLE_CLIENT_ID
   GOOGLE_CLIENT_SECRET
   GOOGLE_ADS_OAUTH_REDIRECT_URI
4) صفحة التكاملات تحفظ GOOGLE_ADS_OAUTH_REDIRECT_URI في قاعدة البيانات ويقرأه AdsController فعليًا.
5) رسائل الخطأ أصبحت أوضح لو Google رفض المشروع بسبب عدم اعتماد Google Ads API access.
6) الحزمة تشمل تطوير صفحة Admin > Integrations السابق: بحث، فلاتر، ملخص، وحفظ مفاتيح صحيح.

الملفات التي تستبدلها:
app/Controllers/AdsController.php
app/Controllers/AdminController.php
app/Controllers/IntegrationsController.php
app/Services/Ads/GoogleAdsAPI.php
app/Config/integrations.php
app/Core/IntegrationManager.php
app/Services/Integrations/BaseIntegrationService.php
app/Services/OAuth/GoogleOAuthClient.php
app/Services/OAuth/SocialLoginClient.php

بعد التركيب:
1) امسح Cache / OPcache من الاستضافة.
2) من لوحة الأدمن افتح:
   Admin > Integrations > Google Ads API
3) احفظ هذه القيم:
   GOOGLE_CLIENT_ID
   GOOGLE_CLIENT_SECRET
   GOOGLE_ADS_OAUTH_REDIRECT_URI
4) قيمة Redirect URI المتوقعة غالبًا:
   https://tourfecto.pro/ads/connect/google/callback
5) من Google Cloud Console تأكد أن نفس الرابط مضاف في OAuth Client:
   Authorized redirect URIs
6) من Google Cloud Console تأكد أن Google Ads API مفعّل للمشروع.
7) جرّب الربط من:
   /ads/connect/google

ملاحظات مهمة:
- ملف Service Account JSON ليس مطلوبًا لهذا الربط.
- لو Google رجّعت خطأ صلاحيات للمشروع، افتح صفحة Google Ads API Overview في Google Cloud واطلب/فعّل مستوى الوصول المناسب للمشروع.
- لو لديك Developer Token قديم مستقبلًا، يمكنك حفظه من لوحة الأدمن، والكود سيرسله تلقائيًا.
