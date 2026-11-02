# تقدم SEO/AEO/GEO

- **المرحلة 1 (فحص)**: PHPUnit للنطاق 144 اختبار ناجح. فحص الشاشات على الموقع الحي لم يتم (لا جلسة مسجّلة).
- **المرحلة 2 (لوحة الظهور)**: `VisibilityScoreService` + `VisibilityController` + `/visibility` + `GET /api/visibility/overview` + مدخل في القائمة. AEO يرجع `not_measured` لحد مرحلة 4. اختبارات 6 ناجحة (منطق + عزل Tenant). لم تُجرَّب في المتصفح. لا migration.
