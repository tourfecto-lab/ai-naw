# Tourfecto OTA Hub — حزمة أساس التكامل الآمن

هذه حزمة إضافية مدمجة لتجهيز مشروع Tourfecto لطبقة OTA موحدة. **ليست اتصالًا حيًا جاهزًا بكل المنصات**: لا يمكن تفعيل أي مزود دون موافقة API وبيانات اعتماد واختبارات sandbox خاصة به. لا توجد بيانات اعتماد حقيقية مرفقة، ولا يتم إرسال أي طلبات إلى مزود خارجي.

## محتويات الحزمة
- `app/Services/Integrations/OTA/OtaPlatformCatalog.php`: سجل المنصات والقدرات المحتملة.
- `OtaIdentity.php`: توحيد هوية الحجز ومنع التكرار.
- `OtaProviderAdapterInterface.php`: عقد موحد لتطبيق adapter منفصل لكل مزود.
- `OtaSyncPolicy.php`: حواجز قبل الكتابة، تحقق HTTPS، ومفتاح idempotency لأحداث webhook.
- `OtaWebhookVerifier.php`: HMAC-SHA256 عام؛ يستخدم فقط إذا كانت وثائق المزود تنص على نفس طريقة التوقيع.
- `database/migrations/2026_10_11_000002_create_tf_ota_hub.sql`: جداول إضافية بأسماء `tf_ota_*` لتقليل احتمالات التعارض، بما فيها webhook inbox.
- `tests/`: اختبارات PHP مستقلة.
- `docs/IMPLEMENTATION_ROADMAP_AR.md`: خطة المراحل ومتطلبات الإطلاق.

## قبل التركيب
1. خذ نسخة احتياطية من الملفات وقاعدة البيانات.
2. جرّب على نسخة staging مطابقة للاستضافة، وتأكد من إصدار PHP وMySQL.
3. راجع migration مع مخطط قاعدة البيانات الفعلي، ثم نفّذه يدويًا بعد الموافقة. لا تشغله مباشرة على الإنتاج.
4. أضف مسارات الملفات إلى `app/Config/project_classmap.php` مع المحافظة على أي تغييرات محلية:

```php
'OtaPlatformCatalog' => dirname(__DIR__, 2).'/app/Services/Integrations/OTA/OtaPlatformCatalog.php',
'OtaIdentity' => dirname(__DIR__, 2).'/app/Services/Integrations/OTA/OtaIdentity.php',
'OtaProviderAdapterInterface' => dirname(__DIR__, 2).'/app/Services/Integrations/OTA/OtaProviderAdapterInterface.php',
'OtaSyncPolicy' => dirname(__DIR__, 2).'/app/Services/Integrations/OTA/OtaSyncPolicy.php',
'OtaWebhookVerifier' => dirname(__DIR__, 2).'/app/Services/Integrations/OTA/OtaWebhookVerifier.php',
```

5. شغّل الاختبارات:

```bash
php tests/ota_foundation_test.php
php tests/ota_sync_policy_test.php
```

## ما لا تدّعيه الحزمة
لا يوجد بعد adapter إنتاجي مكتمل لكل Booking.com أو Expedia أو Agoda أو Airbnb/Vrbo أو GetYourGuide أو Viator أو Klook أو Tripadvisor أو Google Business Profile أو مزودي PMS. ولا توجد مزامنة حية للحجوزات/الإلغاء/التوافر/الأسعار، ولا إرسال ردود الرسائل والتقييمات. هذه الميزات تتطلب API contract مختلفًا لكل مزود، واعتماد الحساب، واختبارات على sandbox. سجل القدرات لا يعني أن الحساب مخوّل بهذه القدرات.

## قاعدة أمان
يجب أن تكون حالة الاتصال `connected` مع `verified_at` موجود، وأن تكون القدرة المطلوبة مُفعّلة صراحة قبل أي كتابة. لا تخزّن أسرار المزود كنص مكشوف في قاعدة البيانات أو السجلات. لا تعالج webhook قبل التحقق من توقيعه وفق وثائق المزود وحفظ event key فريد.
