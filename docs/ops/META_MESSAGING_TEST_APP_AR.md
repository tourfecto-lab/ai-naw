# تطبيق Meta ثاني للرسائل فقط (ماسنجر + إنستجرام) — للتجربة لحد ما المراجعة الحالية تخلص

**ليه:** مراجعة تطبيق Tourfecto.pro الحالية (Review in progress) ما فيهاش `pages_messaging` ولا `instagram_manage_messages` ولا
`pages_manage_metadata`، وما ينفعش نعدّل التطبيق الأساسي وهي معلّقة. تطبيق ثاني **في وضع Development** يخلّي أصحاب
الأدوار عليه (Admin / Developer / Tester) يستقبلوا ويردّوا على رسايل ماسنجر وإنستجرام **فورًا من غير مراجعة**. العملاء الحقيقيين
محتاجين Advanced Access (انظر "طلب المراجعة" تحت).

**الأمان:** كل ده إضافي ومقفول افتراضيًا. مفيش أي تغيير في تسجيل دخول Meta الأساسي ولا الـcallback ولا الصلاحيات ولا مسار الـwebhook
المقدّمين للمراجعة. لو ما حطّيتش `META_MSG_APP_ID` و`META_MSG_APP_SECRET` المنصة بتشتغل زي ما هي.

## 1) إنشاء التطبيق (على developers.facebook.com، بحسابك)
1. **Create App** ← النوع **Business** ← اسم مثلاً `Tourfecto Messaging (test)` ← اربطه بنفس الـBusiness Portfolio.
2. افضل في وضع **Development** (لا تضغط Publish).
3. **Use cases** ← أضف حالة الاستخدام الخاصة بـ **Messenger** وحالة **Instagram** (messaging). فعّل الصلاحيات:
   `pages_show_list` · `pages_messaging` · `pages_manage_metadata` · `instagram_basic` · `instagram_manage_messages`.
4. **App roles → Roles**: ضيف حساباتك (Administrator/Developer/Tester). ولإنستجرام: **Instagram Testers** ← أضف حساب الإنستجرام
   (وقبل الموافقة على الدعوة من تطبيق إنستجرام: Settings ← Apps and websites).
5. **App settings → Basic**: انسخ **App ID** و**App Secret**.

## 2) إعدادات الدخول
- **Valid OAuth Redirect URIs**: `https://tourfectoai.pro/chat/connect/meta/callback`
  (لو استخدمت Facebook Login for Business: ضيف الرابط ده في الـConfiguration، وسجّل Config ID في `META_MSG_LOGIN_CONFIG_ID`).
- الرابط ده **جديد** ومختلف عن `/ads/connect/meta/callback` بتاع التطبيق الأساسي.

## 3) الـWebhook (تطبيق الرسائل)
في **Webhooks** على التطبيق الجديد:
- **Callback URL**: `https://tourfectoai.pro/api/meta/webhook` (نفس المسار الموجود؛ المنصة بتقبل توقيع أي من التطبيقين).
- **Verify token**: هتلاقيه في لوحة الأدمن (صفحة جاهزية Meta: "Verify token").
- اشترك: كائن **Page** ← `messages` و`messaging_postbacks`. كائن **Instagram** ← `messages`.

## 4) على السيرفر (ملف `.env` لموقع tourfectoai.pro) — بإيدك، المنصة ما بتكتب أسرار
```
META_MSG_APP_ID=<App ID>
META_MSG_APP_SECRET=<App Secret>
# اختياري
# META_MSG_LOGIN_CONFIG_ID=<Config ID لو Facebook Login for Business>
# META_MSG_REDIRECT_URI=https://tourfectoai.pro/chat/connect/meta/callback
```
(أو من إعدادات النظام بنفس الأسماء بحروف صغيرة: `meta_msg_app_id` و`meta_msg_app_secret`.)

## 5) الاستخدام
1. افتح `tourfectoai.pro/chat/settings` ← بطاقة **رسايل فيسبوك وإنستجرام** ← زر **ربط الرسايل عبر تطبيق الرسايل (تجريبي)**.
2. وافق على كل الصلاحيات. بترجع لصفحة الإعدادات وفيها صفحاتك.
3. اختار صفحة واحدة ← **شغّل الرد على الصفحة دي**. لو للصفحة إنستجرام بيتفعّل معاها (رسايل إنستجرام بترد بتوكن الصفحة).
4. جرّب: ابعت رسالة للصفحة (أو للإنستجرام) من حساب له دور على التطبيق، تظهر في الصندوق بشارة ماسنجر/إنستجرام.

## 6) حدود مهمة
- في Development: الرسايل بتوصل **من حسابات ليها دور على التطبيق فقط**. عملاء حقيقيين = محتاج Advanced Access (طلب مراجعة).
- صفحة واحدة لكل موقع. نافذة الرد 24 ساعة (البانر بيظهر في المحادثة).
- أي صفحة اتربطت بالتطبيق الأساسي بتفضل موجودة؛ صفحات تطبيق الرسايل بتتخزن منفصلة (`messenger_page`) وتوكناتها مشفّرة.

## 7) طلب المراجعة لتطبيق الرسايل (لما تحب تفتحه للعملاء)
الصلاحيات: `pages_messaging` و`pages_manage_metadata` و`instagram_manage_messages` (+ `pages_show_list` و`instagram_basic`).
جهّز لكل صلاحية:
- **وصف الاستخدام (انسخه):** "Tourfecto is a SaaS for tourism businesses. Business owners connect their Facebook Page / Instagram professional account to our unified inbox to receive customers' messages and reply to them (manually or with an AI assistant they control) within the 24-hour messaging window. We only read and send messages for the connected Page; we never message users who have not written first."
- **خطوات الاختبار للمراجع:** 1) سجّل الدخول بحساب الاختبار الذي تقدّمه. 2) افتح Chat ← Settings ← "Facebook & Instagram messages" ← Connect. 3) اختر الصفحة وفعّل الرد. 4) ابعت رسالة للصفحة من حساب فيسبوك آخر. 5) افتح Chat (Inbox): تظهر المحادثة بشارة Messenger. 6) اكتب ردًا واضغط Send: يصل للمستخدم.
- **الفيديو (≈2 دقيقة):** تسجيل شاشة بصوت/نص يعرض نفس الخطوات من (2) إلى (6) بوضوح، ثم صفحة الخصوصية وحذف البيانات.
- **صفحات لازم تكون شغّالة:** سياسة الخصوصية، شروط الاستخدام، رابط حذف البيانات (موجودين في المنصة).
- **حساب اختبار** للمراجع (مستخدم + كلمة مرور) وصفحة اختبار لها رسالة جاهزة.
