# تقدم SEO/AEO/GEO

- **المرحلة 1 (فحص)**: PHPUnit للنطاق 144 اختبار ناجح. فحص الشاشات على الموقع الحي لم يتم (لا جلسة مسجّلة).
- **المرحلة 2 (لوحة الظهور)**: `VisibilityScoreService` + `VisibilityController` + `/visibility` + `GET /api/visibility/overview` + مدخل في القائمة. AEO يرجع `not_measured` لحد مرحلة 4. اختبارات 6 ناجحة (منطق + عزل Tenant). لم تُجرَّب في المتصفح. لا migration.
- **المرحلة 3 (Schema)**: `SeoSchemaValidator` (قواعد TouristTrip/Hotel/LodgingBusiness/Product/Offer/FAQ/HowTo/Review/Breadcrumb/LocalBusiness) + `/seo-schema` (فحص JSON-LD + معاينة مقترح لصفحة من موقع المستخدم نفسه، بلا نشر) + أنواع Hotel/LodgingBusiness/Review/HowTo مسموحة. 9 اختبارات. جُرّبت على الموقع الحي (فحص ومعاينة، ورفض رابط من موقع تاني). غير منجز: مولّد Hotel/FAQ/HowTo الفعلي، وفحص الـSchema الموجود على الصفحة.
