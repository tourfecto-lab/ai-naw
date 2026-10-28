Tourfecto Google OAuth Admin Panel Fix

المطلوب:
أي تغيير في GOOGLE_CLIENT_ID و GOOGLE_CLIENT_SECRET يتم من لوحة الأدمن، وليس من .env.

النتيجة بعد تركيب التصليح:
1) لوحة الأدمن / system_settings هي المصدر الأساسي لقيم Google OAuth.
2) .env يستخدم فقط كاحتياطي لو القيم غير محفوظة في لوحة الأدمن.
3) ربط Google Business / Google Ads / Analytics / Search Console / YouTube يستخدم نفس قيم لوحة الأدمن.
4) تسجيل الدخول بجوجل يستخدم نفس قيم لوحة الأدمن أيضًا.

الملفات المعدلة:
1) app/Services/OAuth/GoogleOAuthClient.php
2) app/Services/OAuth/SocialLoginClient.php

طريقة التركيب:
1) ارفع مجلد app الموجود داخل هذه الحزمة فوق مجلد app الموجود على الاستضافة.
2) وافق على استبدال الملفين:
   app/Services/OAuth/GoogleOAuthClient.php
   app/Services/OAuth/SocialLoginClient.php
3) ادخل كـ Super Admin إلى:
   Admin > System Settings > Google OAuth
4) ضع Client ID الجديد و Client Secret الجديد من Google Cloud.
5) احفظ الإعدادات.
6) امسح Cache / OPcache من لوحة الاستضافة لو موجود.

مهم:
عند إنشاء OAuth Client جديد في Google Cloud، الأفضل تغيّر الاثنين معًا:
GOOGLE_CLIENT_ID + GOOGLE_CLIENT_SECRET
لأن الـ Secret مرتبط بنفس الـ Client.

مهم جدًا:
الـ Client Secret الذي ظهر في الرسائل يعتبر مكشوفًا. أنشئ Secret جديد من Google Cloud ثم احفظه من لوحة الأدمن.
