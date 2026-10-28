<?php

/**
 * Tourfecto - Base Event
 * @version 1.0.0
 *
 * كلاس أساسي لأي "حدث" في النظام (مثال: WebsiteVerifiedEvent،
 * SubscriptionExpiredEvent، ReviewReplySentEvent). الفكرة إن أي جزء من
 * الكود يقدر "يعلن" إن حاجة حصلت من غير ما يعرف مين هيتصرف بناءً عليها
 * (Observer Pattern) - بيفصل مثلاً "التحليل خلص" عن "ابعت إيميل" و"حدّث
 * الإحصائيات" و"سجّل في الـ audit log"، كل واحدة listener منفصل.
 */
class AppEvent
{
    /** @var string اسم الحدث، مثال: 'website.verified' */
    public $name;

    /** @var array البيانات المرفقة بالحدث */
    public $payload;

    /** @var float وقت إطلاق الحدث (microtime) */
    public $firedAt;

    public function __construct(string $name, array $payload = [])
    {
        $this->name = $name;
        $this->payload = $payload;
        $this->firedAt = microtime(true);
    }
}
