# النشر الاجتماعي v2 — ملاحظات التركيب

1. بعد رفع الملفات **أعد تشغيل الـ queue worker وPHP-FPM**.
2. الـ migrations (بالترتيب، بعد مراجعتها): `2026_10_18_000001` ثم `000002` ثم `000003` في `database/migrations/`.
   الكود شغال قبلها، لكن الإعادة التلقائية والمصالحة وفحص الصحة والصور المتعددة محتاجينها.
3. راوتس جديدة (اختيارية - من غيرها مفيش حاجة بتتكسر):
   ```
   POST /api/social/posts/{id}/reconcile       -> SocialMediaController@reconcilePost
   POST /api/social/posts/{id}/resolve         -> SocialMediaController@resolvePost
   POST /api/social/posts/{id}/edit-published  -> SocialMediaController@editPublishedPost
   GET  /api/social/health                     -> SocialMediaController@publishHealth
   POST /api/meta/deauthorize                  -> MetaDataCallbackController@deauthorize      (عام: من غير login/CSRF)
   POST /api/meta/data-deletion                -> MetaDataCallbackController@dataDeletion     (عام: من غير login/CSRF)
   GET  /meta/data-deletion-status             -> MetaDataCallbackController@status           (عام)
   ```
4. متغيرات البيئة الجديدة في آخر `env.example` (كلها اختيارية).
5. **لا تنشر والمنشورات بتتنشر:** `SELECT COUNT(*) FROM social_post_targets WHERE status='publishing'` لازم 0.
6. التراجع: رجّع نسخة الملفات القديمة. الـ migrations إضافية فقط (أعمدة NULL وجداول جديدة) ومش لازم تتمسح.
