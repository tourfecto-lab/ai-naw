# حزمة مراجعة Meta لتطبيق «Tourfecto Messaging» (ماسنجر + إنستجرام)

الهدف: فتح رسايل ماسنجر وإنستجرام لعملاء Tourfecto **الحقيقيين** (دلوقتي بتوصل من أصحاب الأدوار على التطبيق بس).
التطبيق: **Tourfecto Messaging** — App ID `1003405352022041` — ربط الدخول عبر Configuration `1135584219162327`.
كل الأمثلة والنصوص الإنجليزي تحت تتنسخ زي ما هي في فورم Meta. **ما بنلمسش تطبيق المراجعة الأساسي (Graph Tourfecto) ولا tourfecto.pro.**

---

## 1) وضعنا دلوقتي (اتأكدت منه على tourfectoai.pro)

| البند | الحالة |
|---|---|
| استقبال رسايل ماسنجر وإنستجرام والرد عليها (بوت + يدوي) | شغّال، اتجرّب بحسابات حقيقية |
| صور / صوت / فيديو / ملفات من الصندوق | شغّال (صورة اتأكدت على إنستجرام وماسنجر) |
| ردود من خارج المنصة (Business Suite) بتتزامن | شغّال على إنستجرام |
| نافذة 24 ساعة + رسالة واضحة بعدها | شغّال |
| صفحات `/privacy` و`/terms` و`/data-deletion` على tourfectoai.pro | بترد 200 |
| Business Verification للـportfolio **tourfecto** | **مكتملة** (ظاهر في Meta) |
| التطبيق | **Unpublished (Development)** |
| App settings ← Basic | **اتحفظ:** Privacy / Terms / Data deletion (على tourfecto.pro) وApp domains وCategory. **ناقص:** App icon فقط |

## 1.1) زاحف Meta و tourfectoai.pro

- **Sharing Debugger** بيرجّع 403 لصفحات `tourfectoai.pro`، ومفيش أي طلب منه بيظهر في لوج nginx (لوج الموقع بيسجّل طلبات Meta التانية بـ200). يعني الحجب غالباً قبل السيرفر (جدار شبكة Hostinger)، ولسه ما اتأكدش (محتاج ناتج `tcpdump`).
- **ما بيأثر على المراجعة:** المراجع بيدخل بمتصفح عادي (شغّال). الأثر الوحيد إن Meta ما بتقدرش *تجلب* روابط من tourfectoai.pro، فرفع الصور للرسايل بيتم مباشرة لـMeta (شغّال).
- **الحل المطبّق:** روابط الخصوصية والشروط وحذف البيانات في التطبيق بقت على **tourfecto.pro** (Meta بتجلبه 200)، وده بيشاور على صفحات موجودة وما غيّرناش فيها حاجة.
- أصلحنا في الكود: طلبات HEAD كانت بترجّع 404 على كل الصفحات (دلوقتي 200).

## 2) قبل ما تقدّم (Checklist — الترتيب مهم)

1. **App settings ← Basic** واملا الاتنين دول وسجّل (Save Changes):
   - Privacy policy URL: `https://tourfecto.pro/privacy` *(اتحفظ)*
   - Terms of Service URL: `https://tourfecto.pro/terms` *(اتحفظ)*
   - User data deletion: *Data deletion instructions URL* = `https://tourfecto.pro/data-deletion` *(اتحفظ)*
   - App domains: `tourfectoai.pro`
   - Category: Business and pages
   - App icon: لوجو Tourfecto 1024×1024.
   - Contact email: `tourfecto@gmail.com` (موجود).
2. **Data Use Checkup:** في لوحة التطبيق ← Required actions، أكّد استخدام البيانات (Meta بتطلبه قبل Advanced Access).
3. **Tech Provider / Access Verification:** ظاهر في Dashboard «Become a Tech Provider». لازم للتطبيقات اللي بتخدم **أعمال تانية** (عملاءك). ابدأه من الزرار ده (محتاج إثبات الـBusiness اللي عندك، وهو مكتمل).
4. **حساب اختبار للمراجع على Tourfecto** (انت اللي بتنشئه): مستخدم وكلمة سر + موقع مربوط فيه صفحة اختبار. حطه في خانة *Test credentials*.
5. **صفحة فيسبوك + حساب إنستجرام للاختبار:** المراجع بيبعت رسالة بنفسه من حساب Meta تجريبي. جهّز صفحة (مثلاً tourfecto) وإنستجرام مربوطين، وبوت مفعّل عليهم.
6. **اعمل Publish للتطبيق (Live)** بعد ما تخلص (1). Advanced Access لازم التطبيق يكون Live. التطبيق ده منفصل، فنشره مش بيأثر على تطبيق المراجعة الأساسي.
7. **صوّر الفيديو** (السكربت في القسم 5) لكل صلاحية، أو فيديو واحد مقسّم بعناوين.
8. **سياسة الخصوصية:** راجع إنها بتذكر معالجة رسايل ماسنجر/إنستجرام والذكاء الاصطناعي ومدة الاحتفاظ وطريقة الحذف. النص المقترح في القسم 7. **ما عدّلتش الصفحة** (ممنوع نلمس الخصوصية/الشروط وهما في مراجعة تانية). عدّلها بعد ما المراجعات ترجع.

## 3) الصلاحيات اللي هتطلبها

الطلب: **Advanced Access** على ما يلي (الباقي Standard وبيتفعّل تلقائياً):

| الصلاحية | ليه |
|---|---|
| `pages_messaging` | استقبال الرسايل على الصفحة والرد عليها |
| `pages_manage_metadata` | اشتراك الصفحة في الـwebhook (`messages` و`message_echoes`) |
| `pages_show_list` | عرض صفحات صاحب الحساب ليختار الصفحة |
| `instagram_basic` | قراءة حساب إنستجرام الاحترافي المربوط واسمه |
| `instagram_manage_messages` | استقبال رسايل إنستجرام والرد عليها |
| `business_management` | **قرار:** مطلوب لصفحات مملوكة لـBusiness Portfolio (زي صفحة tourfecto عندنا). من غيره صفحات العميل اللي مدارة عبر الـportfolio ما بتظهرش. لو ما محتاجهاش (كل عملاءك بصلاحية مباشرة على الصفحة) **ما تطلبهاش** — طلب صلاحية زيادة بيعرّض المراجعة للرفض. |

## 4) نصوص الاستخدام (انسخها — إنجليزي)

**App description (للتطبيق):**
> Tourfecto is a SaaS platform for tourism and local businesses. Business owners connect their own Facebook Page and linked Instagram professional account to Tourfecto's unified inbox to receive customers' messages and answer them, either manually or with an AI assistant that the owner controls, always within Meta's 24-hour messaging window.

**pages_messaging**
> Business owners connect their Facebook Page to Tourfecto. Customers who message the Page appear in the owner's unified inbox. The owner (or the AI assistant they enabled and can pause at any time) replies from the inbox and the reply is delivered to the customer in Messenger through the Send API. We only send messages in response to a customer's message, inside the 24-hour window. We never message people who have not written to the Page first.

**pages_manage_metadata**
> When the owner turns on message replies for a Page, Tourfecto subscribes that Page to our app's webhook for the messages, messaging_postbacks and message_echoes fields. This is required to receive customer messages in real time. We do not change any other Page setting.

**pages_show_list**
> After the owner logs in with Facebook, Tourfecto lists the Pages the owner administers so the owner can choose the one whose messages should appear in the inbox.

**instagram_basic**
> Tourfecto reads the name and username of the Instagram professional account linked to the chosen Page, and the name and profile picture of customers who message the account, to label conversations in the inbox.

**instagram_manage_messages**
> Customers who message the connected Instagram professional account appear in the same inbox. The owner or the AI assistant they control replies from the inbox and the reply is delivered in Instagram Direct. Replies are sent only within the 24-hour window after the customer's last message.

**business_management** *(فقط لو هتطلبها)*
> Many Pages are owned by a Business Portfolio rather than by an individual profile. Tourfecto needs this permission so that the owner's Business-owned Pages can be listed and connected. We use it only to read the Pages the owner selects in the Facebook login dialog; we do not create, edit or delete any Business asset.

**Data handling (لخانة Data Use):**
> Message content, customer names and profile pictures are stored only to show the conversation to the Page owner who received them. Page access tokens are stored encrypted. Data is deleted on request through /data-deletion and when the owner disconnects the Page or deletes the account. We do not sell data and do not use it to train AI models.

## 5) سكربت الفيديو (≈ 3 دقايق — اتصوّر بصوت أو تعليقات على الشاشة)

> اعرض الـURL والـlogin في كل مرة. ركّز إن كل خطوة بتتم من **حساب المراجع على Tourfecto**.

1. **0:00** — Tourfecto login بحساب الاختبار ← Chat ← Settings.
2. **0:20** — بطاقة **Facebook & Instagram messages** ← الزرار «Connect messages via the messaging app». اضغط.
3. **0:35** — شاشة Facebook Login for Business: اعرض الصلاحيات (pages_messaging، instagram_manage_messages...) واختار الصفحة وحساب إنستجرام ← Continue ← Save.
4. **1:00** — الرجوع للإعدادات: ظهور الصفحة وإنستجرام المربوط. اختار الصفحة ← **Reply on this page** (ده بيشترك في الـwebhook: `pages_manage_metadata`).
5. **1:20** — من حساب Facebook آخر (المراجع) ابعت رسالة نصية للصفحة. اظهر الرسالة في Chat ← Inbox بشارة **Messenger** واسم العميل وصورته.
6. **1:50** — اظهر رد البوت، واكتب رد يدوي واضغط Send وبيوصل لـMessenger (صوّر شاشة الموبايل/الشات الآخر). اظهر صورة بتتبعت.
7. **2:20** — كرّر نفس الخطوتين 5 و6 لإنستجرام (**Instagram** + `instagram_manage_messages`).
8. **2:50** — اظهر البانر «Free reply window: …h left» وإن الرد بعد 24 ساعة مقفول.
9. **3:00** — اظهر صفحة **/privacy** و**/data-deletion**، وزر Disconnect/تعطيل الرد.

## 6) تعليمات المراجع (Test steps — انسخها)

> 1. Open https://tourfectoai.pro/login and sign in with the test account provided in the credentials field.
> 2. Go to Chat → Settings. In the card "Facebook & Instagram messages" click "Connect messages via the messaging app", log in with Facebook and select the test Page and its Instagram account.
> 3. Choose the Page and click "Reply on this page".
> 4. From another Facebook account (or the Instagram account provided) send a message to the Page / Instagram account.
> 5. Open Chat → Inbox: the conversation appears with a Messenger / Instagram badge. The AI assistant answers automatically; you can also type a reply and press Send – it is delivered to the customer.
> 6. Attach a picture with the + button to see media sending.

(ضيف بيانات حساب الاختبار + اسم الصفحة + حساب الإنستجرام في *Notes for reviewer*.)

## 7) نص مقترح لسياسة الخصوصية (بعد ما المراجعات ترجع)

> **Messenger and Instagram messages.** When you connect your Facebook Page or Instagram professional account to Tourfecto, we receive the messages that customers send to that account, together with the customer's public name and profile picture, in order to show them to you in your inbox and to send your replies. If you enable the AI assistant, message text is processed by an AI service to draft replies that you control. We keep this data for as long as your account is active; you can disconnect the account at any time, and you or your customers can request deletion at /data-deletion.

## 8) أسباب الرفض المعتادة وإزاي نتجنبها

| السبب | اللي بنعمله |
|---|---|
| فيديو ما بيوريش الصلاحية بتتستخدم | الفيديو (قسم 5) بيعرض كل صلاحية في خطوة |
| المراجع ما قدرش يدخل | حساب اختبار شغّال + صفحة وإنستجرام جاهزين، واختبره قبل الإرسال بحساب مختلف |
| سياسة الخصوصية ناقصة | قسم 7 + روابط شغّالة |
| طلب صلاحيات مش مستخدمة | نطلب بس اللي في الجدول، و`business_management` اختياري |
| التطبيق Unpublished | Publish قبل التقديم |
| رد برا نافذة 24 ساعة | البانر واضح والإرسال بيقفل (اتجرّب) |

## 9) بعد الموافقة
1. غيّر Messaging app لـ**Live** لو لسه، وتأكد Advanced Access ظاهر لكل صلاحية.
2. جرّب مع حساب **مش** له دور على التطبيق.
3. حدّث `.env` لـtourfecto.pro بمفاتيح التطبيق (`META_MSG_APP_ID` ... `META_MSG_REDIRECT_URI`) **بإيدك**، وارفع الكود يدوي (tourfecto.pro بيتحدّث يدوي).
4. **غيّر App Secret** (Reset) وحط الجديد.
5. ارجع اطلب `instagram_manage_comments` أو `human_agent` لو هتحتاج رد بعد 24 ساعة (حالياً بنوجّه للـBusiness Suite).

## 10) مخاطر معروفة
- `business_management` صلاحية واسعة؛ لو متطلبتهاش، صفحات الـportfolio مش هتظهر لعملاء مدارين كده.
- ردود بعد 24 ساعة: Meta بتسمح بـ7 أيام عبر Business Suite فقط (بدون Human Agent permission).
- إنستجرام بيتخزّن `other` في جدول الرسايل لحد ما تطبّق migration `2026_10_29_000002_chat_messages_platform_instagram.sql` (اختياري).
- `.env` على tourfectoai.pro فيه `APP_URL=https://tourfecto.pro`؛ لينكات الملفات بتتبني من دومين `META_MSG_REDIRECT_URI` لتفادي ده.
