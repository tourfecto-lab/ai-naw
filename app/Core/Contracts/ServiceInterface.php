<?php

/**
 * Tourfecto - Service Marker Contract
 * @version 1.0.0
 *
 * علامة (marker interface) بس — مش بتفرض methods معيّنة لأن كل Service
 * بيعمل حاجة مختلفة تمامًا عن التاني (تحليل AI مش زي إرسال شات). فايدتها:
 *  - تسمح لـ Container::make() ولأي كود تاني إنه يتحقق "ده Service فعلاً"
 *    عن طريق instanceof بدل التخمين من اسم الكلاس.
 *  - توثيق معماري: أي كلاس بيعمل implements ServiceInterface معناه بيحمل
 *    "منطق عمل" (business logic) مش وصول مباشر لقاعدة البيانات (ده شغل
 *    الـ Repository) ومش استقبال/رد HTTP (ده شغل الـ Controller).
 */
interface ServiceInterface
{
}
