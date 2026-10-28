<?php

/**
 * كل خدمة بتنفّذ الإجراء المتوافق عليه عن طريق كلاس بيطبّق الواجهة دي.
 */
interface AutomationHandler
{
    /**
     * @param array $decision صف automation_decisions
     * @param string $finalText النص النهائي بعد تعديل العميل (لو فيه)
     * @return array success(bool), error(?string), deferred(?bool)
     */
    public function execute(array $decision, string $finalText): array;
}
