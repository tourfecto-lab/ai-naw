<?php

/**
 * Tourfecto - Queue Job Contract
 * @version 1.0.0
 *
 * أي "مهمة" (job) عايزة تتنفذ في الخلفية بدل ما تبطّئ رد الطلب الحالي
 * (مثلاً: إرسال تقرير AI طويل، بعت رسائل واتساب لعدد كبير، معالجة webhook
 * ثقيلة) لازم تعمل implements للعقد ده.
 */
interface QueueJobInterface
{
    /**
     * تنفيذ المهمة فعليًا. أي Exception بتتحسب "فشل" ويُعاد المحاولة حسب
     * إعدادات الطابور (انظر QueueManager::MAX_ATTEMPTS).
     * @param array $payload البيانات اللي اتخزنت وقت enqueue()
     * @return void
     */
    public function handle(array $payload): void;
}
