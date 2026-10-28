<?php
// تم تعطيل ملف التشخيص ده للإنتاج: كان بيكشف بيانات داخلية (أخطاء، لوج، هيكل الداتابيز) من غير حماية كافية.
// للتشخيص استخدم لوحة الأدمن: /admin/system و /admin/jobs و /admin/security و /admin/logs.
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
exit('Not Found');
