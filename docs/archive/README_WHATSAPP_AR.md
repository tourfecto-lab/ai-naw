# واتساب الرسمي: التحديث + خطوات موافقة Meta

## 1) رفع التحديث (مفيش SQL)
1. ارفع tourfecto-whatsapp-review.zip في /www/wwwroot/tourfecto.pro واعمل Unzip مع Overwrite.
2. Reload لـ PHP 8.4.
3. افتح https://tourfecto.pro/admin/meta-review بحساب الأدمن: هتلاقي كل شرط عليه ✅ أو ❌ ومعاه إزاي يتصلح.

## اللي اتغير في المنصة
- سياسة الخصوصية: قسم جديد WhatsApp Business Platform (البيانات اللي بنوصلها، ليه، التشفير، STOP، الفصل والحذف). الرابط: /privacy#whatsapp
  والقسم بيتضاف لوحده حتى لو نص السياسة متعدل من الأدمن.
- صفحة حذف البيانات: جزء "Disconnecting WhatsApp".
- صفحة جديدة في الأدمن: /admin/meta-review (فحص الجاهزية + نصوص طلب المراجعة + سيناريو الفيديو، كله بزرار نسخ).
- المنصة بقت بتسجل إمتى Meta اتحققت من الـ Webhook وإمتى آخر حدث وصل.
- رابط للصفحة الجديدة في /admin/system جنب إعدادات Meta.

## 2) المطلوب في لوحة الأدمن (/admin/system ← Meta)
- App ID
- App Secret
- WhatsApp Embedded Signup Config ID
(أول ما التلاتة يتحطوا، زرار «اربط واتساب بضغطة» بيظهر للعميل في إعدادات الشات)

## 3) المطلوب في developers.facebook.com
1. WhatsApp ← Configuration:
   - Callback URL: https://tourfecto.pro/api/whatsapp-cloud/webhook
   - Verify token: من /admin/system (بيبدأ بـ tfp_)
   - Verify and save ← واشترك في messages
2. Facebook Login for Business ← Configurations ← Create:
   - النوع: WhatsApp Embedded Signup
   - الصلاحيات: whatsapp_business_management, whatsapp_business_messaging, business_management
   - انسخ Config ID وحطه في الأدمن
3. Facebook Login ← Settings: فعّل Login with the JavaScript SDK، و Allowed Domains = tourfecto.pro
4. App settings ← Basic:
   - App Domains: tourfecto.pro
   - Privacy Policy URL: https://tourfecto.pro/privacy
   - Terms of Service URL: https://tourfecto.pro/terms
   - User data deletion: https://tourfecto.pro/data-deletion
   - App icon 1024×1024 + Category
5. Business Verification في Meta Business Suite (السجل التجاري + فاتورة/مستند بنفس العنوان).
6. الانضمام كمزوّد خدمة تقنية (Tech Provider) من القايمة الجانبية.

## 4) طلب المراجعة (App Review)
- اطلب Advanced Access لـ: whatsapp_business_management, whatsapp_business_messaging (و business_management لو ظهرت).
- لكل صلاحية: الصق النص من /admin/meta-review (زرار نسخ).
- ارفع فيديو واحد (2-4 دقايق، واجهة إنجليزي، يظهر فيه الموبايل) بالسيناريو اللي في الصفحة:
  ربط بضغطة ← الرقم والقوالب ← رسالة تجربة توصل الموبايل ← رد من الموبايل يظهر في الإنبوكس ← رد من الإنبوكس ← STOP يحوّل العميل Opted out ← زرار الفصل.
- اعمل حساب اختبار للمراجِع على المنصة وحط بياناته في Notes for reviewer (ماتستخدمش حسابك الشخصي).

## 5) بعد الموافقة
- انشر التطبيق Live.
- كل عميل بيضيف وسيلة دفع في حساب WhatsApp Business بتاعه (Meta بتحاسب على رسايل التسويق والقوالب).

## أسباب الرفض الأشهر وإزاي اتغطت
| سبب الرفض | الوضع |
|---|---|
| سياسة الخصوصية مش بتشرح بيانات واتساب | ✅ اتضاف قسم كامل |
| الفيديو مش بيوري الصلاحية بتتستخدم فين | ✅ سيناريو جاهز لكل صلاحية |
| مفيش موافقة/إلغاء اشتراك للتسويق | ✅ موافقة مسجلة لكل رقم + STOP تلقائي + القالب لازم فيه STOP |
| الـ Webhook مش متحقق أو الرسايل مش موقّعة | ✅ التوقيع إجباري + الصفحة بتوريك إمتى Meta اتحققت |
| مفيش طريقة للفصل أو الحذف | ✅ زرار فصل + صفحة حذف البيانات |
| المراجِع مش عارف يدخل | ⚠️ لازم تعمل حساب اختبار وتكتب بياناته |
